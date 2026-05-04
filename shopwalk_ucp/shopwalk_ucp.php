<?php
/**
 * Shopwalk for PrestaShop — UCP-compliant agent commerce module
 *
 * Makes any PrestaShop store fully purchasable by UCP-compliant AI shopping
 * agents (Shopwalk, OpenAI, Anthropic, LangChain, custom). Implements the
 * Universal Commerce Protocol from https://ucp.dev — checkout sessions,
 * OAuth identity, orders, webhooks. Works standalone without any external
 * service; optional Shopwalk integration ships separately.
 *
 * @author    Shopwalk, Inc.
 * @copyright 2026 Shopwalk, Inc.
 * @license   https://www.gnu.org/licenses/gpl-2.0.html GPL-2.0-or-later
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/UcpBootstrap.php';
require_once __DIR__ . '/classes/UcpConfig.php';
require_once __DIR__ . '/classes/UcpDiscovery.php';
require_once __DIR__ . '/classes/UcpEnvelope.php';
require_once __DIR__ . '/classes/UcpSigning.php';
require_once __DIR__ . '/classes/UcpWebhookDispatcher.php';
require_once __DIR__ . '/classes/UcpPaymentRouter.php';
require_once __DIR__ . '/classes/UcpPaymentAdapterStripe.php';

class Shopwalk_Ucp extends Module
{
    const UCP_SPEC_VERSION = '2026-04-08';
    const MODULE_VERSION   = '0.2.0';

    public function __construct()
    {
        $this->name          = 'shopwalk_ucp';
        $this->tab           = 'administration';
        $this->version       = self::MODULE_VERSION;
        $this->author        = 'Shopwalk, Inc.';
        $this->need_instance = 0;
        $this->bootstrap     = true;
        $this->ps_versions_compliancy = ['min' => '1.7.8.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->l('Shopwalk for PrestaShop');
        $this->description = $this->l('Universal Commerce Protocol adapter for PrestaShop. Exposes UCP-compliant checkout, OAuth identity, orders and webhooks so any AI shopping agent can transact with this store.');
        $this->confirmUninstall = $this->l('Are you sure? Uninstalling removes UCP endpoints, OAuth clients, tokens, sessions and webhook subscriptions. Completed orders stay in PrestaShop.');
    }

    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        include __DIR__ . '/sql/install.php';

        $hooks = [
            'moduleRoutes',
            'actionOrderStatusUpdate',
            'actionOrderStatusPostUpdate',
            'actionObjectOrderAddAfter',
            'displayHeader',
            'paymentOptions',
            'actionDispatcher',
        ];
        foreach ($hooks as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }

        if (!$this->installAdminTab() || !$this->installPaymentModule() || !UcpBootstrap::onInstall($this)) {
            return false;
        }

        UcpConfig::generateSigningKeysIfMissing();

        return true;
    }

    public function uninstall()
    {
        include __DIR__ . '/sql/uninstall.php';
        $this->uninstallAdminTab();
        UcpConfig::deleteAll();
        return parent::uninstall();
    }

    // ─── Admin tab ────────────────────────────────────────────────────────

    protected function installAdminTab()
    {
        $tab              = new Tab();
        $tab->active      = 1;
        $tab->class_name  = 'AdminShopwalkUcp';
        $tab->name        = [];
        foreach (Language::getLanguages(true) as $lang) {
            $tab->name[$lang['id_lang']] = 'Shopwalk UCP';
        }
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentModulesSf') ?: (int) Tab::getIdFromClassName('AdminTools');
        $tab->module    = $this->name;
        return $tab->add();
    }

    protected function uninstallAdminTab()
    {
        $idTab = (int) Tab::getIdFromClassName('AdminShopwalkUcp');
        if ($idTab) {
            $tab = new Tab($idTab);
            return $tab->delete();
        }
        return true;
    }

    protected function installPaymentModule()
    {
        return true;
    }

    // ─── Configuration page ───────────────────────────────────────────────

    public function getContent()
    {
        // All admin UI lives under AdminShopwalkUcpController; redirect the
        // legacy module configure link over to the dedicated controller.
        Tools::redirectAdmin($this->context->link->getAdminLink('AdminShopwalkUcp'));
    }

    // ─── Routes ───────────────────────────────────────────────────────────

    public function hookModuleRoutes()
    {
        return [
            'module-shopwalk_ucp-discovery' => [
                'rule'       => '.well-known/ucp',
                'keywords'   => [],
                'controller' => 'discovery',
                'params'     => ['fc' => 'module', 'module' => 'shopwalk_ucp'],
            ],
            'module-shopwalk_ucp-oauthmetadata' => [
                'rule'       => '.well-known/oauth-authorization-server',
                'keywords'   => [],
                'controller' => 'oauthmetadata',
                'params'     => ['fc' => 'module', 'module' => 'shopwalk_ucp'],
            ],
            'module-shopwalk_ucp-oauthauthorize' => [
                'rule'       => 'ucp/v1/oauth/authorize',
                'keywords'   => [],
                'controller' => 'oauthauthorize',
                'params'     => ['fc' => 'module', 'module' => 'shopwalk_ucp'],
            ],
            'module-shopwalk_ucp-oauthtoken' => [
                'rule'       => 'ucp/v1/oauth/token',
                'keywords'   => [],
                'controller' => 'oauthtoken',
                'params'     => ['fc' => 'module', 'module' => 'shopwalk_ucp'],
            ],
            'module-shopwalk_ucp-oauthrevoke' => [
                'rule'       => 'ucp/v1/oauth/revoke',
                'keywords'   => [],
                'controller' => 'oauthrevoke',
                'params'     => ['fc' => 'module', 'module' => 'shopwalk_ucp'],
            ],
            'module-shopwalk_ucp-oauthuserinfo' => [
                'rule'       => 'ucp/v1/oauth/userinfo',
                'keywords'   => [],
                'controller' => 'oauthuserinfo',
                'params'     => ['fc' => 'module', 'module' => 'shopwalk_ucp'],
            ],
            'module-shopwalk_ucp-checkoutsessions' => [
                'rule'       => 'ucp/v1/checkout-sessions{/:id}{/:action}',
                'keywords'   => [
                    'id'     => ['regexp' => '[a-zA-Z0-9_\-]+', 'param' => 'id'],
                    'action' => ['regexp' => 'complete|cancel', 'param' => 'action'],
                ],
                'controller' => 'checkoutsessions',
                'params'     => ['fc' => 'module', 'module' => 'shopwalk_ucp'],
            ],
            'module-shopwalk_ucp-orders' => [
                'rule'       => 'ucp/v1/orders{/:id}{/:sub}',
                'keywords'   => [
                    'id'  => ['regexp' => '[0-9]+', 'param' => 'id'],
                    'sub' => ['regexp' => 'events', 'param' => 'sub'],
                ],
                'controller' => 'orders',
                'params'     => ['fc' => 'module', 'module' => 'shopwalk_ucp'],
            ],
            'module-shopwalk_ucp-webhooksubscriptions' => [
                'rule'       => 'ucp/v1/webhooks/subscriptions{/:id}',
                'keywords'   => [
                    'id' => ['regexp' => '[a-zA-Z0-9_\-]+', 'param' => 'id'],
                ],
                'controller' => 'webhooksubscriptions',
                'params'     => ['fc' => 'module', 'module' => 'shopwalk_ucp'],
            ],
            'module-shopwalk_ucp-webhookflush' => [
                'rule'       => 'ucp/v1/internal/webhooks/flush',
                'keywords'   => [],
                'controller' => 'webhookflush',
                'params'     => ['fc' => 'module', 'module' => 'shopwalk_ucp'],
            ],
            'module-shopwalk_ucp-checkout' => [
                'rule'       => 'shopwalk-ucp/v1/checkout',
                'keywords'   => [],
                'controller' => 'checkout',
                'params'     => ['fc' => 'module', 'module' => 'shopwalk_ucp'],
            ],
        ];
    }

    // ─── Order lifecycle → webhook events ─────────────────────────────────

    public function hookActionOrderStatusPostUpdate($params)
    {
        if (empty($params['id_order']) || empty($params['newOrderStatus'])) {
            return;
        }
        UcpWebhookDispatcher::enqueueOrderStatusEvent((int) $params['id_order'], $params['newOrderStatus']);
    }

    public function hookActionObjectOrderAddAfter($params)
    {
        if (empty($params['object']) || !($params['object'] instanceof Order)) {
            return;
        }
        UcpWebhookDispatcher::enqueueOrderCreatedEvent((int) $params['object']->id);
    }

    // ─── Payment option ──────────────────────────────────────────────────

    public function hookPaymentOptions($params)
    {
        if (!$this->active) {
            return [];
        }
        require_once __DIR__ . '/classes/UcpPaymentModule.php';
        return UcpPaymentModule::getPaymentOptions($this, $params);
    }

    // ─── Dispatcher (for non-routed entry points, e.g. /.well-known) ─────

    public function hookActionDispatcher($params)
    {
        // Route registration via hookModuleRoutes handles everything for us
        // on shops with friendly URLs enabled. This hook is a no-op kept for
        // forward compatibility with a future fallback dispatcher.
    }
}
