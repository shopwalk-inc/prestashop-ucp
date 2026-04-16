<?php
/**
 * Wrappers over Configuration:: — groups UCP options and manages the signing
 * keypair used for outbound webhook and discovery-doc signatures.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpConfig
{
    // ─── Configuration keys ───────────────────────────────────────────────

    const K_PRIVATE_PEM    = 'SHOPWALK_UCP_PRIVATE_PEM';
    const K_PUBLIC_PEM     = 'SHOPWALK_UCP_PUBLIC_PEM';
    const K_KID            = 'SHOPWALK_UCP_KID';
    const K_WEBHOOK_TOKEN  = 'SHOPWALK_UCP_WEBHOOK_TOKEN';
    const K_SESSION_TTL    = 'SHOPWALK_UCP_SESSION_TTL';

    public static function storeName(): string
    {
        return (string) Configuration::get('PS_SHOP_NAME');
    }

    public static function storeUrl(): string
    {
        return Tools::getShopDomainSsl(true);
    }

    public static function sessionTtl(): int
    {
        return (int) (Configuration::get(self::K_SESSION_TTL) ?: 1800);
    }

    public static function webhookToken(): string
    {
        return (string) Configuration::get(self::K_WEBHOOK_TOKEN);
    }

    public static function kid(): string
    {
        return (string) Configuration::get(self::K_KID);
    }

    public static function privateKeyPem(): string
    {
        return (string) Configuration::get(self::K_PRIVATE_PEM);
    }

    public static function publicKeyPem(): string
    {
        return (string) Configuration::get(self::K_PUBLIC_PEM);
    }

    public static function deleteAll(): void
    {
        $keys = [
            self::K_PRIVATE_PEM,
            self::K_PUBLIC_PEM,
            self::K_KID,
            self::K_WEBHOOK_TOKEN,
            self::K_SESSION_TTL,
        ];
        foreach ($keys as $k) {
            Configuration::deleteByName($k);
        }
    }

    /**
     * Generate a fresh RSA keypair for webhook signing if none exists yet.
     * Uses OpenSSL — PrestaShop requires the openssl ext on install so we
     * can rely on it being available.
     */
    public static function generateSigningKeysIfMissing(): void
    {
        if (Configuration::get(self::K_PRIVATE_PEM)) {
            return;
        }
        if (!function_exists('openssl_pkey_new')) {
            return;
        }

        $res = openssl_pkey_new([
            'digest_alg'       => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if (!$res) {
            return;
        }

        openssl_pkey_export($res, $privatePem);
        $details = openssl_pkey_get_details($res);
        $publicPem = $details['key'] ?? '';

        Configuration::updateValue(self::K_PRIVATE_PEM, (string) $privatePem);
        Configuration::updateValue(self::K_PUBLIC_PEM, (string) $publicPem);
        Configuration::updateValue(self::K_KID, 'key-' . substr(bin2hex(random_bytes(6)), 0, 10));
    }
}
