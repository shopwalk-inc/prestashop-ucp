<?php
/**
 * Agent webhook subscription CRUD.
 *
 *   POST   /ucp/v1/webhooks/subscriptions
 *   GET    /ucp/v1/webhooks/subscriptions/{id}
 *   DELETE /ucp/v1/webhooks/subscriptions/{id}
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Shopwalk_UcpWebhooksubscriptionsModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl  = true;

    public function initContent()
    {
        parent::initContent();

        $tok = UcpOAuthServer::resolveBearer();
        if (!$tok) {
            UcpEnvelope::respondError('invalid_token', 'Bearer token required', 401);
        }

        $method = $_SERVER['REQUEST_METHOD'];
        $id     = (string) Tools::getValue('id', '');

        if ($method === 'POST' && $id === '') {
            $body   = json_decode((string) file_get_contents('php://input'), true) ?: [];
            $cbUrl  = (string) ($body['callback_url'] ?? '');
            $events = $body['event_types'] ?? [];
            if (!Validate::isAbsoluteUrl($cbUrl) || !is_array($events)) {
                UcpEnvelope::respondError('invalid_request', 'callback_url + event_types[] required', 400);
            }
            $reg = UcpWebhookSubscription::register($tok->client_id, $cbUrl, $events);
            UcpEnvelope::respond(UcpEnvelope::ok([
                'id'           => $reg['id'],
                'callback_url' => $cbUrl,
                'event_types'  => $events,
                'secret'       => $reg['secret'],
            ]), 201);
            return;
        }

        if ($method === 'GET' && $id !== '') {
            $sub = UcpWebhookSubscription::findBySubscriptionId($id);
            if (!$sub || $sub->client_id !== $tok->client_id) {
                UcpEnvelope::respondError('not_found', 'Subscription not found', 404);
            }
            UcpEnvelope::respond(UcpEnvelope::ok([
                'id'           => $sub->subscription_id,
                'callback_url' => $sub->callback_url,
                'event_types'  => $sub->getEventTypes(),
                'active'       => (bool) $sub->active,
            ]));
            return;
        }

        if ($method === 'DELETE' && $id !== '') {
            $sub = UcpWebhookSubscription::findBySubscriptionId($id);
            if (!$sub || $sub->client_id !== $tok->client_id) {
                UcpEnvelope::respondError('not_found', 'Subscription not found', 404);
            }
            $sub->active = 0;
            $sub->save();
            http_response_code(204);
            exit;
        }

        UcpEnvelope::respondError('method_not_allowed', 'Route / method combination not supported', 405);
    }
}
