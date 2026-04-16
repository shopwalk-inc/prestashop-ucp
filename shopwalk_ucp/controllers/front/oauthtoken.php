<?php
/**
 * POST /ucp/v1/oauth/token
 *
 * Grant types: authorization_code, refresh_token.
 * Auth: client_secret_post  or  client_secret_basic.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Shopwalk_UcpOauthtokenModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl  = true;

    public function initContent()
    {
        parent::initContent();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            UcpEnvelope::respondError('method_not_allowed', 'POST required', 405);
        }

        [$clientId, $clientSecret] = self::clientCredentials();

        $grant = (string) Tools::getValue('grant_type');
        switch ($grant) {
            case 'authorization_code':
                $res = UcpOAuthServer::exchangeCode(
                    $clientId,
                    $clientSecret,
                    (string) Tools::getValue('code'),
                    (string) Tools::getValue('redirect_uri'),
                    (string) Tools::getValue('code_verifier')
                );
                break;
            case 'refresh_token':
                $res = UcpOAuthServer::refresh(
                    $clientId,
                    $clientSecret,
                    (string) Tools::getValue('refresh_token')
                );
                break;
            default:
                UcpEnvelope::respondError('unsupported_grant_type', 'grant_type must be authorization_code or refresh_token', 400);
                return;
        }

        http_response_code((int) $res['status']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($res['body'], JSON_UNESCAPED_SLASHES);
        exit;
    }

    protected static function clientCredentials(): array
    {
        $id     = (string) Tools::getValue('client_id');
        $secret = (string) Tools::getValue('client_secret');
        if ($id && $secret) {
            return [$id, $secret];
        }
        $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
        if (stripos($auth, 'Basic ') === 0) {
            $decoded = base64_decode(substr($auth, 6), true) ?: '';
            if (strpos($decoded, ':') !== false) {
                [$basicId, $basicSecret] = explode(':', $decoded, 2);
                return [urldecode($basicId), urldecode($basicSecret)];
            }
        }
        return [$id, $secret];
    }
}
