<?php
/**
 * Cron endpoint for flushing the outbound webhook queue.
 *
 *   GET /ucp/v1/internal/webhooks/flush?token=<shared-secret>
 *
 * The token is generated on module install (see UcpBootstrap) and shown in
 * the admin dashboard. A system cron pings this URL every minute.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Shopwalk_UcpWebhookflushModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl  = true;

    public function initContent()
    {
        parent::initContent();

        $provided = (string) Tools::getValue('token');
        $expected = UcpConfig::webhookToken();
        if (!$expected || !hash_equals($expected, $provided)) {
            UcpEnvelope::respondError('forbidden', 'flush token invalid', 403);
        }

        $limit = min(200, max(10, (int) Tools::getValue('limit', 50)));
        $stats = UcpWebhookDispatcher::flush($limit);
        UcpEnvelope::respond(UcpEnvelope::ok($stats));
    }
}
