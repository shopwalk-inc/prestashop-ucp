<?php
/**
 * GET /ucp/v1/oauth/userinfo
 *
 * Returns the OIDC userinfo claim set for the PrestaShop customer bound
 * to the access token.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Shopwalk_UcpOauthuserinfoModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl  = true;

    public function initContent()
    {
        parent::initContent();

        $tok = UcpOAuthServer::resolveBearer();
        if (!$tok || !$tok->id_customer) {
            UcpEnvelope::respondError('invalid_token', 'Bearer token required', 401);
        }

        $customer = new Customer((int) $tok->id_customer);
        if (!Validate::isLoadedObject($customer)) {
            UcpEnvelope::respondError('invalid_token', 'Customer not found', 401);
        }

        UcpEnvelope::respond([
            'sub'          => (string) $customer->id,
            'email'        => $customer->email,
            'email_verified' => true,
            'given_name'   => $customer->firstname,
            'family_name'  => $customer->lastname,
        ], 200);
    }
}
