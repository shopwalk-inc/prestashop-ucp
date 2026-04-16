<?php
/**
 * Checkout session endpoints.
 *
 *   POST /ucp/v1/checkout-sessions
 *   GET  /ucp/v1/checkout-sessions/{id}
 *   PUT  /ucp/v1/checkout-sessions/{id}
 *   POST /ucp/v1/checkout-sessions/{id}/complete
 *   POST /ucp/v1/checkout-sessions/{id}/cancel
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Shopwalk_UcpCheckoutsessionsModuleFrontController extends ModuleFrontController
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

        $id     = (string) Tools::getValue('id', '');
        $action = (string) Tools::getValue('action', '');
        $method = $_SERVER['REQUEST_METHOD'];

        if ($method === 'POST' && $id === '' && $action === '') {
            $this->create($tok);
            return;
        }
        if ($method === 'POST' && $id !== '' && $action === 'complete') {
            $this->complete($tok, $id);
            return;
        }
        if ($method === 'POST' && $id !== '' && $action === 'cancel') {
            $this->cancel($tok, $id);
            return;
        }
        if ($method === 'GET' && $id !== '') {
            $this->fetch($tok, $id);
            return;
        }
        if ($method === 'PUT' && $id !== '') {
            $this->update($tok, $id);
            return;
        }

        UcpEnvelope::respondError('method_not_allowed', 'Route / method combination not supported', 405);
    }

    protected function create(UcpOAuthToken $tok): void
    {
        $body    = self::readJsonBody();
        $session = UcpCheckoutEngine::create($tok->client_id, (int) ($tok->id_customer ?: 0) ?: null, $body);
        UcpEnvelope::respond($session->toUcpObject(), 201);
    }

    protected function fetch(UcpOAuthToken $tok, string $sessionId): void
    {
        $session = UcpCheckoutSession::findBySessionId($sessionId);
        if (!$session || $session->client_id !== $tok->client_id) {
            UcpEnvelope::respondError('not_found', 'Session not found', 404);
        }
        UcpEnvelope::respond($session->toUcpObject(), 200);
    }

    protected function update(UcpOAuthToken $tok, string $sessionId): void
    {
        $session = UcpCheckoutSession::findBySessionId($sessionId);
        if (!$session || $session->client_id !== $tok->client_id) {
            UcpEnvelope::respondError('not_found', 'Session not found', 404);
        }
        if ($session->status === UcpCheckoutSession::STATUS_COMPLETED
            || $session->status === UcpCheckoutSession::STATUS_CANCELED) {
            UcpEnvelope::respondError('session_finalized', 'Session is already ' . $session->status, 409);
        }

        $body    = self::readJsonBody();
        $session = UcpCheckoutEngine::update($session, $body);
        UcpEnvelope::respond($session->toUcpObject(), 200);
    }

    protected function complete(UcpOAuthToken $tok, string $sessionId): void
    {
        $session = UcpCheckoutSession::findBySessionId($sessionId);
        if (!$session || $session->client_id !== $tok->client_id) {
            UcpEnvelope::respondError('not_found', 'Session not found', 404);
        }

        $res = UcpCheckoutEngine::complete($session);
        if (!$res['ok']) {
            $session = UcpCheckoutSession::findBySessionId($sessionId);
            $session->status = UcpCheckoutSession::STATUS_REQUIRES_ESCALATION;
            $session->save();
            UcpEnvelope::respondError((string) $res['error'], (string) ($res['reason'] ?? ''), 422);
        }

        $session = UcpCheckoutSession::findBySessionId($sessionId);
        UcpEnvelope::respond($session->toUcpObject(), 200);
    }

    protected function cancel(UcpOAuthToken $tok, string $sessionId): void
    {
        $session = UcpCheckoutSession::findBySessionId($sessionId);
        if (!$session || $session->client_id !== $tok->client_id) {
            UcpEnvelope::respondError('not_found', 'Session not found', 404);
        }
        UcpCheckoutEngine::cancel($session);
        UcpEnvelope::respond($session->toUcpObject(), 200);
    }

    protected static function readJsonBody(): array
    {
        $raw = file_get_contents('php://input') ?: '';
        $d   = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
}
