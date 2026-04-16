<?php
/**
 * Admin dashboard diagnostic runner.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpSelfTest
{
    public static function run(): array
    {
        return [
            self::check('Signing keypair generated',  UcpConfig::privateKeyPem() !== ''),
            self::check('Webhook flush token',        UcpConfig::webhookToken() !== ''),
            self::check('Admin tab installed',        (int) Tab::getIdFromClassName('AdminShopwalkUcp') > 0),
            self::check('OpenSSL extension',          function_exists('openssl_pkey_new')),
            self::check('Friendly URLs enabled',      (bool) Configuration::get('PS_REWRITING_SETTINGS'), 'Enable under Shop Parameters → Traffic & SEO'),
            self::check('SSL enabled on this shop',   (bool) Configuration::get('PS_SSL_ENABLED')),
            self::fetch('/.well-known/ucp reachable', '/.well-known/ucp'),
            self::fetch('/.well-known/oauth-authorization-server reachable', '/.well-known/oauth-authorization-server'),
            self::tableRow('oauth_clients'),
            self::tableRow('oauth_tokens'),
            self::tableRow('checkout_sessions'),
            self::tableRow('webhook_subscriptions'),
            self::tableRow('webhook_queue'),
        ];
    }

    protected static function check(string $label, bool $ok, string $hint = ''): array
    {
        return [
            'label'   => $label,
            'status'  => $ok ? 'pass' : 'fail',
            'message' => $ok ? 'OK' : ($hint ?: 'Not available'),
        ];
    }

    protected static function fetch(string $label, string $path): array
    {
        $url = rtrim(UcpConfig::storeUrl(), '/') . $path;
        $code = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 5,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_NOBODY         => true,
            ]);
            curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
        }
        $ok = $code >= 200 && $code < 400;
        return [
            'label'   => $label,
            'status'  => $ok ? 'pass' : 'fail',
            'message' => $ok ? ('HTTP ' . $code) : ('HTTP ' . $code . ' — check friendly URL rewrite'),
        ];
    }

    protected static function tableRow(string $suffix): array
    {
        $table = _DB_PREFIX_ . 'ucp_' . $suffix;
        $exists = (bool) Db::getInstance()->getValue('SHOW TABLES LIKE "' . pSQL($table) . '"');
        return self::check('Table ' . $table, $exists);
    }
}
