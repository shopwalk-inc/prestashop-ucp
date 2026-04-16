<?php
/**
 * GET /ucp/v1/oauth/authorize
 *
 * Minimal consent surface: require the buyer to be logged in as a
 * PrestaShop customer; issue an authorization code and redirect back to
 * the agent's redirect_uri with the code. Non-logged-in visitors bounce
 * through the storefront login and are returned here.
 *
 * PKCE required: S256 (or plain, discouraged).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Shopwalk_UcpOauthauthorizeModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    public function initContent()
    {
        parent::initContent();

        $clientIdParam = (string) Tools::getValue('client_id');
        $redirectUri   = (string) Tools::getValue('redirect_uri');
        $responseType  = (string) Tools::getValue('response_type');
        $state         = (string) Tools::getValue('state');
        $scope         = (string) Tools::getValue('scope');
        $challenge     = (string) Tools::getValue('code_challenge');
        $method        = (string) Tools::getValue('code_challenge_method', 'S256');

        if ($responseType !== 'code' || !$clientIdParam || !$redirectUri || !$challenge) {
            self::badRequest('invalid_request', 'response_type=code, client_id, redirect_uri, code_challenge required');
        }

        $client = UcpOAuthClient::findByClientId($clientIdParam);
        if (!$client) {
            self::badRequest('invalid_client', 'unknown client_id');
        }
        if (!in_array($redirectUri, $client->redirectUris(), true)) {
            self::badRequest('invalid_request', 'redirect_uri not registered for client');
        }

        $customer = $this->context->customer;
        if (!$customer || !$customer->isLogged()) {
            $back = $this->context->link->getModuleLink('shopwalk_ucp', 'oauthauthorize', $_GET, true);
            Tools::redirect($this->context->link->getPageLink('authentication', true, null, [
                'back' => $back,
            ]));
            return;
        }

        $scopes = array_values(array_filter(explode(' ', $scope)));
        if (!$scopes) {
            $scopes = $client->scopes();
        }

        // Issue the auth code immediately — full consent screen is a
        // hardening follow-up. Agents identified by their pre-registered
        // UCP profile are trusted for the initial release.
        $code = UcpOAuthServer::issueAuthorizationCode(
            $client,
            (int) $customer->id,
            $scopes,
            $redirectUri,
            $challenge,
            $method
        );

        $params = ['code' => $code];
        if ($state !== '') {
            $params['state'] = $state;
        }
        $sep = strpos($redirectUri, '?') === false ? '?' : '&';
        Tools::redirect($redirectUri . $sep . http_build_query($params));
    }

    protected static function badRequest(string $code, string $msg): void
    {
        UcpEnvelope::respondError($code, $msg, 400);
    }
}
