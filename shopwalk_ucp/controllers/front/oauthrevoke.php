<?php
/**
 * POST /ucp/v1/oauth/revoke  (RFC 7009)
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Shopwalk_UcpOauthrevokeModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl  = true;

    public function initContent()
    {
        parent::initContent();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            UcpEnvelope::respondError('method_not_allowed', 'POST required', 405);
        }
        $token = (string) Tools::getValue('token');
        $hint  = (string) Tools::getValue('token_type_hint', 'access_token');

        $typeMap = [
            'access_token'  => UcpOAuthToken::TYPE_ACCESS,
            'refresh_token' => UcpOAuthToken::TYPE_REFRESH,
        ];
        $dbType = $typeMap[$hint] ?? UcpOAuthToken::TYPE_ACCESS;

        $tok = UcpOAuthToken::findValid($token, $dbType);
        if (!$tok) {
            // Try the other type — the hint is optional.
            $otherType = $dbType === UcpOAuthToken::TYPE_ACCESS
                ? UcpOAuthToken::TYPE_REFRESH
                : UcpOAuthToken::TYPE_ACCESS;
            $tok = UcpOAuthToken::findValid($token, $otherType);
        }
        if ($tok) {
            $tok->revoke();
        }
        // RFC 7009 §2.2: servers respond 200 regardless of whether the
        // token was valid.
        http_response_code(200);
        exit;
    }
}
