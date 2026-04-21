<?php
/**
 * Direct Checkout (Tier 2, Shopwalk extension).
 *
 *   POST /shopwalk-ucp/v1/checkout
 *
 * Creates a PrestaShop order from an agent-submitted body and returns a
 * `payment_url` the buyer completes on the store's native checkout page.
 * Separate from the UCP spec's /ucp/v1/checkout-sessions flow on purpose
 * — this is the Shopwalk Direct Checkout fallback.
 *
 * Auth: X-License-Key header (merchant's Shopwalk license key). When the
 * license isn't configured, the endpoint returns 401 so the agent can
 * fall back to the standard UCP session flow immediately.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class Shopwalk_UcpCheckoutModuleFrontController extends ModuleFrontController
{
    public $ajax = true;
    public $ssl  = true;

    public function initContent()
    {
        parent::initContent();

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            UcpEnvelope::respondError('method_not_allowed', 'Only POST is supported here', 405);
        }

        if (!$this->licenseKeyValid()) {
            UcpEnvelope::respondError(
                'invalid_license_key',
                'A valid X-License-Key header is required. This store has not activated Shopwalk Direct Checkout.',
                401
            );
        }

        require_once _PS_MODULE_DIR_ . 'shopwalk_ucp/classes/UcpDirectCheckout.php';

        $body = $this->readJsonBody();
        $res  = UcpDirectCheckout::createOrderFromRequest($body);

        http_response_code((int) $res['status']);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($res['body'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }

    protected function licenseKeyValid()
    {
        $stored = (string) Configuration::get('SHOPWALK_UCP_LICENSE_KEY');
        if ($stored === '') {
            return false;
        }
        $header = '';
        if (isset($_SERVER['HTTP_X_LICENSE_KEY'])) {
            $header = (string) $_SERVER['HTTP_X_LICENSE_KEY'];
        } elseif (isset($_SERVER['HTTP_X_SW_LICENSE_KEY'])) {
            $header = (string) $_SERVER['HTTP_X_SW_LICENSE_KEY'];
        }
        return $header !== '' && hash_equals($stored, $header);
    }

    protected function readJsonBody()
    {
        $raw = file_get_contents('php://input') ?: '';
        $d   = json_decode($raw, true);
        return is_array($d) ? $d : [];
    }
}
