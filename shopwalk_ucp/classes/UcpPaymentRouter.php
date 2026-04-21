<?php
/**
 * UCP Payment Router — dispatches agent-submitted payment credentials to
 * the right PrestaShop-side adapter so the plugin never has to own payment
 * configuration of its own.
 *
 * Agents submit `{ "payment": { "gateway": "stripe", ... } }` on
 * /checkout-sessions/{id}/complete. The router looks up the adapter
 * registered for that gateway id and asks it to authorize the payment
 * against the merchant's already-configured PrestaShop payment module
 * (official Stripe module, Stripe Payments, etc.) — reusing whatever
 * credentials that module already has.
 *
 * PrestaShop has no WordPress-style filter hook. Third parties extend the
 * registry via `UcpPaymentRouter::addAdapter($id, $className)` from their
 * own module's install/boot code, or by setting the Configuration key
 * `SHOPWALK_UCP_EXTRA_ADAPTERS` to a JSON-encoded `{gateway: className}`
 * map. Adapters must implement `UcpPaymentAdapterInterface`.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Contract every payment adapter implements.
 */
interface UcpPaymentAdapterInterface
{
    /**
     * Short, stable identifier ("stripe", "ppcp", "square", …).
     */
    public function id();

    /**
     * Whether this adapter is usable right now. Typically checks that the
     * corresponding PrestaShop payment module is installed and has
     * credentials set.
     */
    public function isReady();

    /**
     * Authorize payment for a PrestaShop order using an agent-supplied
     * credential. Returns ['ok' => true] on success or
     * ['ok' => false, 'error' => '…', 'message' => '…', 'status' => 4xx/5xx,
     * 'soft' => bool] on failure. `soft` == true marks 3DS/requires_action
     * flows where the order should stay pending and the agent should hand
     * the buyer the native payment_url.
     *
     * Implementations MUST advance the order state on success (attach
     * metadata + order note + transition to PS_OS_PAYMENT) so downstream
     * webhook listeners observe the transition consistently.
     *
     * @param Order $order   Already-built PS order.
     * @param array $payment UCP session payment object.
     * @return array
     */
    public function authorize(Order $order, array $payment);

    /**
     * Discovery hint published at /.well-known/ucp so agents can pick a
     * gateway this store accepts before creating a session.
     *
     * @return array
     */
    public function discoveryHint();
}

/**
 * UcpPaymentRouter — central adapter lookup + dispatch.
 */
class UcpPaymentRouter
{
    const EXTRA_ADAPTERS_CONFIG_KEY = 'SHOPWALK_UCP_EXTRA_ADAPTERS';

    /**
     * Runtime-registered adapters. Populated by addAdapter().
     *
     * @var array<string,string> gateway id → adapter class name
     */
    protected static $runtime = [];

    /**
     * Register an adapter at runtime. Third-party modules call this from
     * their own install or boot code.
     */
    public static function addAdapter($gatewayId, $className)
    {
        self::$runtime[(string) $gatewayId] = (string) $className;
    }

    /**
     * Reset runtime adapters — used in tests.
     */
    public static function resetAdapters()
    {
        self::$runtime = [];
    }

    /**
     * Default adapter registry.
     *
     * @return array<string,string> gateway id → adapter class name
     */
    protected static function defaults()
    {
        return [
            'stripe' => 'UcpPaymentAdapterStripe',
        ];
    }

    /**
     * Resolved adapter map: defaults + runtime-registered + Configuration
     * overrides. Values are class names, not instances — adapters are cheap
     * to construct on demand.
     *
     * @return array<string,string>
     */
    public static function registry()
    {
        $adapters = array_merge(self::defaults(), self::$runtime);

        if (class_exists('Configuration')) {
            $raw = (string) Configuration::get(self::EXTRA_ADAPTERS_CONFIG_KEY);
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $gateway => $class) {
                        if (is_string($gateway) && is_string($class)) {
                            $adapters[$gateway] = $class;
                        }
                    }
                }
            }
        }

        // Drop anything that doesn't resolve to a loadable class so the
        // caller never has to defensively check `class_exists` themselves.
        $resolved = [];
        foreach ($adapters as $gateway => $class) {
            if (class_exists($class)) {
                $resolved[$gateway] = $class;
            }
        }
        return $resolved;
    }

    /**
     * Discovery hints advertised at /.well-known/ucp. Filters out adapters
     * that are registered but not usable right now (e.g. the payment
     * module is installed but has no keys configured).
     *
     * @return array<string,array>
     */
    public static function discoveryHints()
    {
        $out = [];
        foreach (self::registry() as $gateway => $class) {
            $adapter = new $class();
            if (!($adapter instanceof UcpPaymentAdapterInterface)) {
                continue;
            }
            if (!$adapter->isReady()) {
                continue;
            }
            $out[$gateway] = $adapter->discoveryHint();
        }
        return $out;
    }

    /**
     * Dispatch payment for a given order + UCP payment object.
     *
     * @param Order $order   The PS order.
     * @param array $payment UCP payment credential.
     * @return array {ok: bool, error?: string, message?: string, status?: int, soft?: bool}
     */
    public static function authorize(Order $order, array $payment)
    {
        $gateway = isset($payment['gateway']) ? (string) $payment['gateway'] : '';
        if ($gateway === '') {
            return [
                'ok'      => false,
                'error'   => 'missing_gateway',
                'message' => 'payment.gateway is required — specify which PrestaShop payment module to use (e.g. "stripe").',
                'status'  => 400,
            ];
        }

        $registry = self::registry();
        if (!isset($registry[$gateway])) {
            $supported = array_keys($registry);
            return [
                'ok'      => false,
                'error'   => 'unsupported_gateway',
                'message' => sprintf(
                    'No adapter registered for payment gateway "%s". This store accepts: %s.',
                    $gateway,
                    $supported ? implode(', ', $supported) : 'none'
                ),
                'status' => 422,
            ];
        }

        $class   = $registry[$gateway];
        $adapter = new $class();
        if (!($adapter instanceof UcpPaymentAdapterInterface)) {
            return [
                'ok'      => false,
                'error'   => 'invalid_adapter',
                'message' => 'Adapter class does not implement UcpPaymentAdapterInterface.',
                'status'  => 500,
            ];
        }
        if (!$adapter->isReady()) {
            return [
                'ok'      => false,
                'error'   => 'gateway_not_ready',
                'message' => sprintf('Payment gateway "%s" is registered but not configured on this store.', $gateway),
                'status'  => 503,
            ];
        }

        return $adapter->authorize($order, $payment);
    }
}
