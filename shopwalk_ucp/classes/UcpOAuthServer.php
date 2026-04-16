<?php
/**
 * OAuth 2.0 authorization_code + refresh_token + PKCE.
 *
 * Interactive flow:
 *   GET  /ucp/v1/oauth/authorize     → login + consent → issue code via redirect
 *   POST /ucp/v1/oauth/token         → exchange code / refresh → access+refresh
 *   POST /ucp/v1/oauth/revoke        → revoke (RFC 7009)
 *   GET  /ucp/v1/oauth/userinfo      → OIDC userinfo for linked WC customer
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpOAuthServer
{
    const ACCESS_TTL  = 3600;          // 1 hour
    const REFRESH_TTL = 30 * 86400;    // 30 days
    const CODE_TTL    = 600;           // 10 minutes

    public static function issueAuthorizationCode(UcpOAuthClient $client, int $idCustomer, array $scopes, string $redirectUri, string $codeChallenge, string $method = 'S256'): string
    {
        $minted = UcpOAuthToken::mint(
            UcpOAuthToken::TYPE_AUTH_CODE,
            $client->client_id,
            $idCustomer,
            $scopes,
            self::CODE_TTL,
            [
                'code_challenge'        => $codeChallenge,
                'code_challenge_method' => $method,
                'redirect_uri'          => $redirectUri,
            ]
        );
        return $minted['token'];
    }

    public static function exchangeCode(string $clientId, string $clientSecret, string $code, string $redirectUri, string $codeVerifier): array
    {
        $client = UcpOAuthClient::findByClientId($clientId);
        if (!$client || !$client->verifySecret($clientSecret)) {
            return self::err('invalid_client', 'Client authentication failed', 401);
        }

        $codeTok = UcpOAuthToken::findValid($code, UcpOAuthToken::TYPE_AUTH_CODE);
        if (!$codeTok || $codeTok->client_id !== $clientId) {
            return self::err('invalid_grant', 'Authorization code is invalid or expired');
        }
        if (!hash_equals((string) $codeTok->redirect_uri, $redirectUri)) {
            return self::err('invalid_grant', 'redirect_uri mismatch');
        }
        if (!self::verifyPkce($codeTok->code_challenge, $codeTok->code_challenge_method, $codeVerifier)) {
            return self::err('invalid_grant', 'PKCE verification failed');
        }

        $codeTok->revoke();

        $scopes   = $codeTok->getScopes();
        $access   = UcpOAuthToken::mint(UcpOAuthToken::TYPE_ACCESS,  $clientId, (int) $codeTok->id_customer, $scopes, self::ACCESS_TTL);
        $refresh  = UcpOAuthToken::mint(UcpOAuthToken::TYPE_REFRESH, $clientId, (int) $codeTok->id_customer, $scopes, self::REFRESH_TTL);

        return [
            'status' => 200,
            'body'   => [
                'access_token'  => $access['token'],
                'refresh_token' => $refresh['token'],
                'token_type'    => 'Bearer',
                'expires_in'    => $access['expires_in'],
                'scope'         => implode(' ', $scopes),
            ],
        ];
    }

    public static function refresh(string $clientId, string $clientSecret, string $refreshToken): array
    {
        $client = UcpOAuthClient::findByClientId($clientId);
        if (!$client || !$client->verifySecret($clientSecret)) {
            return self::err('invalid_client', 'Client authentication failed', 401);
        }
        $tok = UcpOAuthToken::findValid($refreshToken, UcpOAuthToken::TYPE_REFRESH);
        if (!$tok || $tok->client_id !== $clientId) {
            return self::err('invalid_grant', 'Refresh token invalid or expired');
        }

        $scopes = $tok->getScopes();
        $access = UcpOAuthToken::mint(UcpOAuthToken::TYPE_ACCESS, $clientId, (int) $tok->id_customer, $scopes, self::ACCESS_TTL);

        return [
            'status' => 200,
            'body'   => [
                'access_token' => $access['token'],
                'token_type'   => 'Bearer',
                'expires_in'   => $access['expires_in'],
                'scope'        => implode(' ', $scopes),
            ],
        ];
    }

    /**
     * Resolve an Authorization: Bearer header to a valid access token. Returns
     * null on any failure.
     */
    public static function resolveBearer(): ?UcpOAuthToken
    {
        $auth = '';
        if (function_exists('apache_request_headers')) {
            $h = apache_request_headers();
            foreach ($h as $k => $v) {
                if (strcasecmp($k, 'Authorization') === 0) {
                    $auth = (string) $v;
                    break;
                }
            }
        }
        if (!$auth && isset($_SERVER['HTTP_AUTHORIZATION'])) {
            $auth = (string) $_SERVER['HTTP_AUTHORIZATION'];
        }
        if (!$auth && isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
            $auth = (string) $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
        }
        if (stripos($auth, 'Bearer ') !== 0) {
            return null;
        }
        $token = trim(substr($auth, 7));
        return $token ? UcpOAuthToken::findValid($token, UcpOAuthToken::TYPE_ACCESS) : null;
    }

    protected static function verifyPkce(?string $challenge, ?string $method, string $verifier): bool
    {
        if (!$challenge) {
            return false;
        }
        if ($method === 'plain') {
            return hash_equals($challenge, $verifier);
        }
        // S256 (default / preferred)
        $expected = UcpDiscovery::base64UrlEncode(hash('sha256', $verifier, true));
        return hash_equals($challenge, $expected);
    }

    protected static function err(string $code, string $description, int $status = 400): array
    {
        return [
            'status' => $status,
            'body'   => ['error' => $code, 'error_description' => $description],
        ];
    }
}
