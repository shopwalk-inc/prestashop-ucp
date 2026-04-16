<?php
/**
 * Build the /.well-known/ucp JSON document.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpDiscovery
{
    public static function buildDiscoveryDocument(): array
    {
        $base = rtrim(UcpConfig::storeUrl(), '/');
        $ucpV1 = $base . '/ucp/v1';

        $services = [
            'dev.ucp.shopping.checkout' => [
                'version'   => Shopwalk_Ucp::UCP_SPEC_VERSION,
                'spec'      => 'https://ucp.dev/latest/specification/checkout-rest/',
                'transport' => 'rest',
                'schema'    => 'https://ucp.dev/schemas/checkout-rest/' . Shopwalk_Ucp::UCP_SPEC_VERSION . '.json',
                'endpoint'  => $ucpV1,
            ],
            'dev.ucp.shopping.order' => [
                'version'   => Shopwalk_Ucp::UCP_SPEC_VERSION,
                'spec'      => 'https://ucp.dev/latest/specification/order/',
                'transport' => 'rest',
                'schema'    => 'https://ucp.dev/schemas/order/' . Shopwalk_Ucp::UCP_SPEC_VERSION . '.json',
                'endpoint'  => $ucpV1,
            ],
            'dev.ucp.common.identity_linking' => [
                'version'   => Shopwalk_Ucp::UCP_SPEC_VERSION,
                'spec'      => 'https://ucp.dev/latest/specification/identity-linking/',
                'transport' => 'rest',
                'endpoint'  => $ucpV1,
            ],
        ];

        $capabilities = [
            'dev.ucp.shopping.checkout' => [
                'version' => Shopwalk_Ucp::UCP_SPEC_VERSION,
                'spec'    => 'https://ucp.dev/latest/specification/checkout-rest/',
            ],
            'dev.ucp.shopping.order' => [
                'version' => Shopwalk_Ucp::UCP_SPEC_VERSION,
                'spec'    => 'https://ucp.dev/latest/specification/order/',
            ],
            'dev.ucp.common.identity_linking' => [
                'version' => Shopwalk_Ucp::UCP_SPEC_VERSION,
                'spec'    => 'https://ucp.dev/latest/specification/identity-linking/',
            ],
        ];

        return [
            'ucp' => [
                'version'        => Shopwalk_Ucp::UCP_SPEC_VERSION,
                'services'       => $services,
                'capabilities'   => $capabilities,
                'payment_handlers' => (object) [],
                'signing_keys'   => self::signingKeys(),
            ],
            'id'     => $base,
            'name'   => UcpConfig::storeName(),
            'oauth'  => [
                'authorization_server' => $base . '/.well-known/oauth-authorization-server',
            ],
            'platform' => 'prestashop',
            'platform_version' => _PS_VERSION_,
            'plugin' => [
                'name'    => 'Shopwalk UCP',
                'version' => Shopwalk_Ucp::MODULE_VERSION,
            ],
        ];
    }

    public static function buildOAuthMetadata(): array
    {
        $base = rtrim(UcpConfig::storeUrl(), '/');
        $v1 = $base . '/ucp/v1';
        return [
            'issuer'                                => $base,
            'authorization_endpoint'                => $v1 . '/oauth/authorize',
            'token_endpoint'                        => $v1 . '/oauth/token',
            'revocation_endpoint'                   => $v1 . '/oauth/revoke',
            'userinfo_endpoint'                     => $v1 . '/oauth/userinfo',
            'scopes_supported'                      => [
                'ucp:scopes:checkout_session',
                'ucp:scopes:orders',
                'ucp:scopes:webhooks',
                'ucp:scopes:userinfo',
            ],
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['client_secret_post', 'client_secret_basic'],
            'code_challenge_methods_supported'      => ['S256'],
        ];
    }

    protected static function signingKeys(): array
    {
        $publicPem = UcpConfig::publicKeyPem();
        if (!$publicPem) {
            return [];
        }
        $jwk = self::pemToJwk($publicPem, UcpConfig::kid());
        return $jwk ? [$jwk] : [];
    }

    /**
     * Extract modulus + exponent from a PKCS#1/PKIX public key PEM and
     * return a JWK dict. Uses only base64 + openssl_pkey_get_details.
     */
    protected static function pemToJwk(string $pem, string $kid): ?array
    {
        if (!function_exists('openssl_pkey_get_public')) {
            return null;
        }
        $res = openssl_pkey_get_public($pem);
        if (!$res) {
            return null;
        }
        $details = openssl_pkey_get_details($res);
        if (!isset($details['rsa']['n'], $details['rsa']['e'])) {
            return null;
        }
        return [
            'kty' => 'RSA',
            'use' => 'sig',
            'alg' => 'RS256',
            'kid' => $kid,
            'n'   => self::base64UrlEncode($details['rsa']['n']),
            'e'   => self::base64UrlEncode($details['rsa']['e']),
        ];
    }

    public static function base64UrlEncode(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
