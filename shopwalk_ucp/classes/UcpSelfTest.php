<?php
/**
 * Admin dashboard diagnostic runner.
 *
 * Runs the 10 baseline UCP checks shared across all UCP adapter plugins
 * (signing keys, friendly URLs, tables, discovery reachability, OAuth
 * metadata reachability, payment gateway registered, adapter ready,
 * cron webhook flush reachability) plus PrestaShop-specific checks:
 * PS version, PHP version, PS_REWRITING_SETTINGS.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpSelfTest
{
    const MIN_PS_VERSION  = '1.7.8.0';
    const MIN_PHP_VERSION = '7.4.0';

    public static function run(): array
    {
        $baseUrl = rtrim(UcpConfig::storeUrl(), '/');

        return [
            self::checkPrestaShopVersion(),
            self::checkPhpVersion(),
            self::check('OpenSSL extension',          function_exists('openssl_pkey_new')),
            self::check('Signing keypair generated',  UcpConfig::privateKeyPem() !== ''),
            self::check('Webhook flush token',        UcpConfig::webhookToken() !== ''),
            self::check('Admin tab installed',        (int) Tab::getIdFromClassName('AdminShopwalkUcp') > 0),
            self::check('Friendly URLs enabled',      (bool) Configuration::get('PS_REWRITING_SETTINGS'),
                'Enable under Shop Parameters → Traffic & SEO — required for /.well-known and /ucp/v1 routes'),
            self::check('SSL enabled on this shop',   (bool) Configuration::get('PS_SSL_ENABLED')),
            self::tableRow('oauth_clients'),
            self::tableRow('oauth_tokens'),
            self::tableRow('checkout_sessions'),
            self::tableRow('webhook_subscriptions'),
            self::tableRow('webhook_queue'),
            self::fetch('/.well-known/ucp reachable', $baseUrl . '/.well-known/ucp'),
            self::fetch('OAuth authorize endpoint reachable', $baseUrl . '/ucp/v1/oauth/authorize', [400, 401, 405]),
            self::checkPaymentModuleRegistered(),
            self::checkAnyAdapterReady(),
            self::checkCronFlush($baseUrl),
        ];
    }

    // ─── PS-specific ─────────────────────────────────────────────────────

    protected static function checkPrestaShopVersion(): array
    {
        $ok = version_compare(_PS_VERSION_, self::MIN_PS_VERSION, '>=');
        return [
            'label'   => 'PrestaShop version >= ' . self::MIN_PS_VERSION,
            'status'  => $ok ? 'pass' : 'fail',
            'message' => 'Detected ' . _PS_VERSION_,
        ];
    }

    protected static function checkPhpVersion(): array
    {
        $ok = version_compare(PHP_VERSION, self::MIN_PHP_VERSION, '>=');
        return [
            'label'   => 'PHP version >= ' . self::MIN_PHP_VERSION,
            'status'  => $ok ? 'pass' : 'fail',
            'message' => 'Detected ' . PHP_VERSION,
        ];
    }

    protected static function checkPaymentModuleRegistered(): array
    {
        $m  = Module::getInstanceByName('shopwalk_ucp');
        $id = $m ? (int) Module::getModuleIdByName('shopwalk_ucp') : 0;
        return [
            'label'   => 'Payment gateway "Pay via UCP" registered',
            'status'  => $id > 0 ? 'pass' : 'fail',
            'message' => $id > 0 ? 'Module id ' . $id : 'Module not registered',
        ];
    }

    protected static function checkAnyAdapterReady(): array
    {
        $registry = class_exists('UcpPaymentRouter') ? UcpPaymentRouter::registry() : [];
        if (!$registry) {
            return [
                'label'   => 'At least one UcpPaymentAdapter ready',
                'status'  => 'warn',
                'message' => 'No adapters registered. Orders fall back to pay-at-store (Direct Checkout).',
            ];
        }

        foreach ($registry as $gateway => $class) {
            $adapter = new $class();
            if ($adapter instanceof UcpPaymentAdapterInterface && $adapter->isReady()) {
                return [
                    'label'   => 'At least one UcpPaymentAdapter ready',
                    'status'  => 'pass',
                    'message' => 'Ready: ' . $gateway,
                ];
            }
        }

        return [
            'label'   => 'At least one UcpPaymentAdapter ready',
            'status'  => 'warn',
            'message' => 'Adapters registered but none configured. Agents will use pay-at-store handoff.',
        ];
    }

    protected static function checkCronFlush(string $baseUrl): array
    {
        $urlNoToken = $baseUrl . '/ucp/v1/internal/webhooks/flush';
        $urlToken   = $urlNoToken . '?token=' . UcpConfig::webhookToken();

        $codeBad  = self::httpStatus($urlNoToken);
        $codeGood = self::httpStatus($urlToken);

        $unauthorizedOk = in_array($codeBad, [401, 403], true);
        $authorizedOk   = $codeGood >= 200 && $codeGood < 400;

        return [
            'label'   => 'Webhook flush cron reachable',
            'status'  => ($unauthorizedOk && $authorizedOk) ? 'pass' : 'warn',
            'message' => 'Without token: HTTP ' . $codeBad . ' · With token: HTTP ' . $codeGood,
        ];
    }

    // ─── Shared helpers ──────────────────────────────────────────────────

    protected static function check(string $label, bool $ok, string $hint = ''): array
    {
        return [
            'label'   => $label,
            'status'  => $ok ? 'pass' : 'fail',
            'message' => $ok ? 'OK' : ($hint ?: 'Not available'),
        ];
    }

    protected static function fetch(string $label, string $url, array $acceptedNon2xx = []): array
    {
        $code = self::httpStatus($url);
        $ok   = ($code >= 200 && $code < 400) || in_array($code, $acceptedNon2xx, true);
        return [
            'label'   => $label,
            'status'  => $ok ? 'pass' : 'fail',
            'message' => 'HTTP ' . $code . ($ok ? '' : ' — check friendly URL rewrite'),
        ];
    }

    protected static function httpStatus(string $url): int
    {
        if (!function_exists('curl_init')) {
            return 0;
        }
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 5,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_NOBODY         => true,
            CURLOPT_SSL_VERIFYPEER => false,
        ]);
        curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return $code;
    }

    protected static function tableRow(string $suffix): array
    {
        $table  = _DB_PREFIX_ . 'ucp_' . $suffix;
        $exists = (bool) Db::getInstance()->getValue('SHOW TABLES LIKE "' . pSQL($table) . '"');
        return self::check('Table ' . $table, $exists);
    }
}
