<?php
/**
 * GET /.well-known/ucp
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Shopwalk_UcpDiscoveryModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl  = true;

    public function initContent()
    {
        parent::initContent();
        UcpEnvelope::respond(UcpDiscovery::buildDiscoveryDocument(), 200);
    }
}
