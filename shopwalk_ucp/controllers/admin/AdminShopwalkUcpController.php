<?php
/**
 * WP admin tab controller → renders dashboard.tpl with UCP status,
 * discovery links, recent agent activity, and self-test output.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'shopwalk_ucp/classes/UcpConfig.php';
require_once _PS_MODULE_DIR_ . 'shopwalk_ucp/classes/UcpSelfTest.php';
require_once _PS_MODULE_DIR_ . 'shopwalk_ucp/classes/UcpDiscovery.php';

class AdminShopwalkUcpController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->display = 'view';
        $this->meta_title = 'Shopwalk UCP';
    }

    public function initContent()
    {
        parent::initContent();

        $discoveryUrl = rtrim(UcpConfig::storeUrl(), '/') . '/.well-known/ucp';
        $oauthMetaUrl = rtrim(UcpConfig::storeUrl(), '/') . '/.well-known/oauth-authorization-server';
        $ucpBase      = rtrim(UcpConfig::storeUrl(), '/') . '/ucp/v1';

        $stats = [
            'sessions_total'    => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucp_checkout_sessions`'),
            'sessions_open'     => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucp_checkout_sessions` WHERE `status` IN ("incomplete","ready_for_complete")'),
            'clients'           => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucp_oauth_clients`'),
            'subscriptions'     => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucp_webhook_subscriptions` WHERE `active`=1'),
            'queue_pending'     => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucp_webhook_queue` WHERE `delivered_at` IS NULL AND `failed_at` IS NULL'),
            'queue_failed'      => (int) Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucp_webhook_queue` WHERE `failed_at` IS NOT NULL'),
        ];

        $this->context->smarty->assign([
            'ucp_spec_version' => Shopwalk_Ucp::UCP_SPEC_VERSION,
            'module_version'   => Shopwalk_Ucp::MODULE_VERSION,
            'discovery_url'    => $discoveryUrl,
            'oauth_meta_url'   => $oauthMetaUrl,
            'ucp_base_url'     => $ucpBase,
            'flush_token'      => UcpConfig::webhookToken(),
            'flush_cron_line'  => '* * * * * curl -fsS "' . rtrim(UcpConfig::storeUrl(), '/') . '/ucp/v1/internal/webhooks/flush?token=' . UcpConfig::webhookToken() . '" > /dev/null',
            'checks'           => UcpSelfTest::run(),
            'stats'            => $stats,
        ]);

        $this->content = $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'shopwalk_ucp/views/templates/admin/dashboard.tpl'
        );
        $this->context->smarty->assign('content', $this->content);
    }
}
