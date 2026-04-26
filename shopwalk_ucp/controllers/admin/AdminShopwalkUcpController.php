<?php
/**
 * Admin tab controller → renders dashboard.tpl with UCP status,
 * discovery links, recent agent activity, payment-router adapter state,
 * and a self-test runner. AJAX endpoints drive the live self-test and
 * payment status refresh buttons.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

require_once _PS_MODULE_DIR_ . 'shopwalk_ucp/classes/UcpConfig.php';
require_once _PS_MODULE_DIR_ . 'shopwalk_ucp/classes/UcpSelfTest.php';
require_once _PS_MODULE_DIR_ . 'shopwalk_ucp/classes/UcpDiscovery.php';
require_once _PS_MODULE_DIR_ . 'shopwalk_ucp/classes/UcpPaymentRouter.php';
require_once _PS_MODULE_DIR_ . 'shopwalk_ucp/classes/UcpPaymentAdapterStripe.php';

class AdminShopwalkUcpController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
        $this->display    = 'view';
        $this->meta_title = 'Shopwalk UCP';
    }

    public function initContent()
    {
        parent::initContent();

        $baseUrl      = rtrim(UcpConfig::storeUrl(), '/');
        $discoveryUrl = $baseUrl . '/.well-known/ucp';
        $oauthMetaUrl = $baseUrl . '/.well-known/oauth-authorization-server';
        $ucpBase      = $baseUrl . '/ucp/v1';

        $this->context->smarty->assign([
            'ucp_spec_version'  => Shopwalk_Ucp::UCP_SPEC_VERSION,
            'module_version'    => Shopwalk_Ucp::MODULE_VERSION,
            'discovery_url'     => $discoveryUrl,
            'oauth_meta_url'    => $oauthMetaUrl,
            'ucp_base_url'      => $ucpBase,
            'flush_token'       => UcpConfig::webhookToken(),
            'flush_cron_line'   => '* * * * * curl -fsS "' . $baseUrl
                . '/ucp/v1/internal/webhooks/flush?token=' . UcpConfig::webhookToken() . '" > /dev/null',
            'checks'            => UcpSelfTest::run(),
            'stats'             => self::collectStats(),
            'payment_adapters'  => self::collectPaymentAdapters(),
            'self_test_ajax'    => self::ajaxUrl('SelfTest'),
            'payments_status_ajax' => self::ajaxUrl('PaymentsStatus'),
            'probe_ajax'        => self::ajaxUrl('Probe'),
            'toggle_discovery_ajax' => self::ajaxUrl('ToggleDiscovery'),
            'license_active'    => (string) Configuration::get('SHOPWALK_UCP_LICENSE_KEY') !== '',
            'discovery_paused'  => (bool) Configuration::get('SHOPWALK_UCP_DISCOVERY_PAUSED'),
        ]);

        // Dashboard CSS + JS
        $this->addCSS($this->module->getPathUri() . 'views/css/admin.css');
        $this->addJS($this->module->getPathUri() . 'views/js/admin.js');

        $this->content = $this->context->smarty->fetch(
            _PS_MODULE_DIR_ . 'shopwalk_ucp/views/templates/admin/dashboard.tpl'
        );
        $this->context->smarty->assign('content', $this->content);
    }

    // ─── AJAX endpoints ──────────────────────────────────────────────────

    public function ajaxProcessSelfTest()
    {
        $this->requireValidToken();
        $checks = UcpSelfTest::run();
        $this->context->smarty->assign('checks', $checks);
        $this->jsonResponse([
            'checks'  => $checks,
            'partial' => $this->context->smarty->fetch(
                _PS_MODULE_DIR_ . 'shopwalk_ucp/views/templates/admin/self_test.tpl'
            ),
        ]);
    }

    public function ajaxProcessPaymentsStatus()
    {
        $this->requireValidToken();
        $this->jsonResponse([
            'adapters' => self::collectPaymentAdapters(),
        ]);
    }

    public function ajaxProcessToggleDiscovery()
    {
        $this->requireValidToken();

        $licenseKey = (string) Configuration::get('SHOPWALK_UCP_LICENSE_KEY');
        if ($licenseKey === '') {
            $this->jsonError('not_connected', 'No Shopwalk license configured', 400);
        }

        $enable = (string) Tools::getValue('enable') === '1';
        $action = $enable ? 'enable' : 'disable';
        $apiBase = rtrim((string) Configuration::get('SHOPWALK_UCP_API_BASE') ?: 'https://api.shopwalk.com/api/v1', '/');
        $url = $apiBase . '/plugin/discovery/' . $action;

        $code = 0;
        $body = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST           => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_HTTPHEADER     => [
                    'Content-Type: application/json',
                    'X-SW-License-Key: ' . $licenseKey,
                ],
                CURLOPT_POSTFIELDS     => json_encode(['plugin_key' => $licenseKey]),
            ]);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        if ($code < 200 || $code >= 300) {
            $this->jsonError('upstream_failed', 'Could not reach Shopwalk. Try again in a moment.', 502);
        }

        Configuration::updateValue('SHOPWALK_UCP_DISCOVERY_PAUSED', $enable ? '0' : '1');
        $this->jsonResponse([
            'success' => true,
            'paused'  => !$enable,
        ]);
    }

    public function ajaxProcessProbe()
    {
        $this->requireValidToken();

        $target = Tools::getValue('target', 'discovery');
        $url    = rtrim(UcpConfig::storeUrl(), '/');
        if ($target === 'oauth') {
            $url .= '/.well-known/oauth-authorization-server';
        } else {
            $url .= '/.well-known/ucp';
        }

        $code = 0;
        $body = '';
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_FOLLOWLOCATION => true,
            ]);
            $body = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }

        $this->jsonResponse([
            'url'    => $url,
            'status' => $code,
            'ok'     => $code >= 200 && $code < 400,
            'body'   => mb_substr($body, 0, 2048),
        ]);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────

    protected function requireValidToken()
    {
        // Dual-check: capability gate + CSRF token. PS admin tokens rotate
        // per-admin-user so this stands in for the WP nonce pattern.
        if (!$this->tabAccess['view'] || !$this->tabAccess['edit']) {
            $this->jsonError('forbidden', 'Insufficient privileges', 403);
        }
        $token    = (string) Tools::getValue('token');
        $expected = Tools::getAdminTokenLite('AdminShopwalkUcp');
        if ($token === '' || !hash_equals($expected, $token)) {
            $this->jsonError('invalid_token', 'CSRF token mismatch', 403);
        }
    }

    protected function jsonResponse(array $payload, $status = 200)
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($status);
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function jsonError($code, $message, $status = 400)
    {
        $this->jsonResponse(['error' => $code, 'message' => $message], $status);
    }

    protected static function collectStats()
    {
        $db = Db::getInstance();
        return [
            'sessions_total' => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucp_checkout_sessions`'),
            'sessions_open'  => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_
                . 'ucp_checkout_sessions` WHERE `status` IN ("incomplete","ready_for_complete")'),
            'clients'        => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'ucp_oauth_clients`'),
            'agents_active_7d' => (int) $db->getValue('SELECT COUNT(DISTINCT client_id) FROM `' . _DB_PREFIX_
                . 'ucp_oauth_tokens` WHERE `date_add` >= DATE_SUB(NOW(), INTERVAL 7 DAY)'),
            'subscriptions'  => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_
                . 'ucp_webhook_subscriptions` WHERE `active`=1'),
            'queue_pending'  => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_
                . 'ucp_webhook_queue` WHERE `delivered_at` IS NULL AND `failed_at` IS NULL'),
            'queue_failed'   => (int) $db->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_
                . 'ucp_webhook_queue` WHERE `failed_at` IS NOT NULL'),
        ];
    }

    protected static function collectPaymentAdapters()
    {
        $out = [];
        foreach (UcpPaymentRouter::registry() as $gateway => $class) {
            $adapter = new $class();
            if (!($adapter instanceof UcpPaymentAdapterInterface)) {
                continue;
            }
            $ready  = (bool) $adapter->isReady();
            $configureUrl = '';
            if ($gateway === 'stripe') {
                // Deep-link into the merchant's PS Stripe module settings
                // (official module slug — community forks may differ).
                $configureUrl = 'index.php?controller=AdminModules&configure=stripe_official';
            }

            $out[] = [
                'id'            => $gateway,
                'ready'         => $ready,
                'status_label'  => $ready ? 'Ready' : 'Not configured',
                'configure_url' => $configureUrl,
            ];
        }
        return $out;
    }

    protected static function ajaxUrl($action)
    {
        $token = Tools::getAdminTokenLite('AdminShopwalkUcp');
        return 'index.php?controller=AdminShopwalkUcp'
            . '&ajax=1'
            . '&action=' . urlencode($action)
            . '&token=' . urlencode($token);
    }
}
