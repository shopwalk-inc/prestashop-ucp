<?php
/**
 * GET /.well-known/oauth-authorization-server  (RFC 8414).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Shopwalk_UcpOauthmetadataModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl  = true;

    public function initContent()
    {
        parent::initContent();
        UcpEnvelope::respond(UcpDiscovery::buildOAuthMetadata(), 200);
    }
}
