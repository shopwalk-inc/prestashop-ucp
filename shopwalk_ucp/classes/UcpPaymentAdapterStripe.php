<?php
/**
 * UCP Payment Adapter — Stripe.
 *
 * Reuses the merchant's already-installed PrestaShop Stripe module
 * credentials. The UCP plugin itself never asks the merchant for a Stripe
 * secret key. Agents submit a Stripe PaymentMethod id (`pm_xxx`) they have
 * already tokenized on their side; this adapter authorizes it as a
 * PaymentIntent with manual capture against the merchant's existing Stripe
 * connection.
 *
 * Supports both the official PrestaShop Stripe module (slug
 * `stripe_official`, since PS 1.7.5) and older `stripe_payment` variants.
 * Reads live / test keys off the `Configuration` table based on the
 * module's own "mode" switch, and logs which module/mode was picked so
 * merchants can debug mis-selection from the admin log.
 *
 * On success the PS order transitions from PS_OS_PREPARATION to
 * PS_OS_PAYMENT (Stripe authorized + PaymentIntent captured at
 * fulfillment time by the merchant's normal workflow).
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpPaymentAdapterStripe implements UcpPaymentAdapterInterface
{
    /**
     * Configuration keys written by the official `stripe_official` module.
     * These match the module's own constants. We read both live + test
     * values and pick based on the module's mode switch.
     */
    const KEY_LIVE_SECRET     = 'STRIPE_LIVE_SECRET_KEY';
    const KEY_LIVE_PUBLISHABLE = 'STRIPE_LIVE_PUBLISHABLE_KEY';
    const KEY_TEST_SECRET     = 'STRIPE_TEST_SECRET_KEY';
    const KEY_TEST_PUBLISHABLE = 'STRIPE_TEST_PUBLISHABLE_KEY';
    const KEY_MODE            = 'STRIPE_MODE'; // '1' = live, '0' = test

    /**
     * Older `stripe_payment` / community forks often used these instead.
     */
    const LEGACY_KEY_SECRET      = 'STRIPE_SECRET_KEY';
    const LEGACY_KEY_PUBLISHABLE = 'STRIPE_KEY';

    public function id()
    {
        return 'stripe';
    }

    public function isReady()
    {
        return $this->secretKey() !== '';
    }

    public function discoveryHint()
    {
        return [
            'gateway'         => 'stripe',
            'credential'      => 'payment_method_id',
            'tokenize_from'   => 'https://js.stripe.com/v3/',
            'publishable_key' => $this->publishableKey(),
            'mode'            => $this->isLiveMode() ? 'live' : 'test',
        ];
    }

    public function authorize(Order $order, array $payment)
    {
        $secretKey = $this->secretKey();
        if ($secretKey === '') {
            return [
                'ok'      => false,
                'error'   => 'stripe_not_configured',
                'message' => 'PrestaShop Stripe module is not installed or has no secret key configured.',
                'status'  => 503,
            ];
        }

        $pmId = '';
        if (isset($payment['payment_method_id'])) {
            $pmId = (string) $payment['payment_method_id'];
        } elseif (isset($payment['stripe_payment_method_id'])) {
            $pmId = (string) $payment['stripe_payment_method_id'];
        } elseif (isset($payment['credential']['payment_method_id'])) {
            $pmId = (string) $payment['credential']['payment_method_id'];
        }

        if ($pmId === '') {
            return [
                'ok'      => false,
                'error'   => 'missing_payment_method',
                'message' => 'payment.payment_method_id (a Stripe PaymentMethod id like "pm_…") is required for the stripe gateway.',
                'status'  => 400,
            ];
        }

        $amountCents = (int) round((float) $order->total_paid * 100);
        $currency    = strtolower(self::orderCurrencyIso($order));

        $form = [
            'amount'             => (string) $amountCents,
            'currency'           => $currency,
            'payment_method'     => $pmId,
            'capture_method'     => 'manual',
            'confirm'            => 'true',
            'description'        => sprintf('Order #%d via UCP', (int) $order->id),
            'metadata[order_id]' => (string) (int) $order->id,
            'metadata[source]'   => 'ucp',
        ];

        $customerId = '';
        if (isset($payment['customer_id'])) {
            $customerId = (string) $payment['customer_id'];
        } elseif (isset($payment['stripe_customer_id'])) {
            $customerId = (string) $payment['stripe_customer_id'];
        }
        if ($customerId !== '') {
            $form['customer'] = $customerId;
        }

        $response = self::postForm(
            'https://api.stripe.com/v1/payment_intents',
            $secretKey,
            $form
        );

        if ($response['error']) {
            return [
                'ok'      => false,
                'error'   => 'stripe_unreachable',
                'message' => 'Stripe API unreachable: ' . $response['error'],
                'status'  => 502,
            ];
        }

        $result = json_decode((string) $response['body'], true);
        if (!is_array($result)) {
            $result = [];
        }
        $status = (int) $response['status'];

        if ($status >= 400 || empty($result['id'])) {
            $msg = 'Stripe declined the payment.';
            if (isset($result['error']['message'])) {
                $msg = (string) $result['error']['message'];
            }
            return [
                'ok'      => false,
                'error'   => 'stripe_declined',
                'message' => $msg,
                'status'  => 402,
            ];
        }

        $piStatus = isset($result['status']) ? (string) $result['status'] : '';
        if ($piStatus !== 'requires_capture' && $piStatus !== 'succeeded') {
            // 3DS / off-session step required — surface as soft fail so the
            // caller keeps the order in pending and hands the buyer a
            // payment_url.
            return [
                'ok'                => false,
                'soft'              => true,
                'error'             => 'stripe_requires_action',
                'message'           => 'Payment requires additional buyer action (3D Secure). Resubmit with a confirmed PaymentMethod or hand the buyer order.payment_url.',
                'status'            => 402,
                'payment_intent_id' => (string) $result['id'],
            ];
        }

        $order->payment = 'Pay via UCP';
        $order->transaction_id = (string) $result['id'];
        $order->save();

        if (class_exists('Message')) {
            $note = sprintf(
                'Authorized via UCP → PrestaShop Stripe credentials. PaymentIntent: %s (manual capture).',
                (string) $result['id']
            );
            // Best-effort order note — skipped silently if PS fails.
            try {
                $msg = new Message();
                $msg->message    = $note;
                $msg->id_order   = (int) $order->id;
                $msg->private    = 1;
                $msg->add();
            } catch (Exception $e) {
                PrestaShopLogger::addLog(
                    '[shopwalk_ucp] Failed to attach Stripe order note: ' . $e->getMessage(),
                    2
                );
            }
        }

        return [
            'ok'                => true,
            'payment_intent_id' => (string) $result['id'],
        ];
    }

    /**
     * Pick the secret key for the merchant's current Stripe mode. Checks
     * `stripe_official` keys first, then legacy community keys. Logs which
     * module / mode was used so self-test can surface it.
     */
    protected function secretKey()
    {
        if (!class_exists('Configuration')) {
            return '';
        }

        $live = (string) Configuration::get(self::KEY_MODE) === '1';
        $officialKey = $live
            ? (string) Configuration::get(self::KEY_LIVE_SECRET)
            : (string) Configuration::get(self::KEY_TEST_SECRET);

        if ($officialKey !== '') {
            PrestaShopLogger::addLog(
                '[shopwalk_ucp] Stripe adapter resolved secret key from stripe_official ('
                    . ($live ? 'live' : 'test') . ')',
                1,
                null,
                'Module',
                0,
                true
            );
            return $officialKey;
        }

        $legacy = (string) Configuration::get(self::LEGACY_KEY_SECRET);
        if ($legacy !== '') {
            PrestaShopLogger::addLog(
                '[shopwalk_ucp] Stripe adapter resolved secret key from legacy stripe_payment key',
                1,
                null,
                'Module',
                0,
                true
            );
            return $legacy;
        }

        return '';
    }

    protected function publishableKey()
    {
        if (!class_exists('Configuration')) {
            return '';
        }
        $live = (string) Configuration::get(self::KEY_MODE) === '1';
        $pub = $live
            ? (string) Configuration::get(self::KEY_LIVE_PUBLISHABLE)
            : (string) Configuration::get(self::KEY_TEST_PUBLISHABLE);
        if ($pub !== '') {
            return $pub;
        }
        return (string) Configuration::get(self::LEGACY_KEY_PUBLISHABLE);
    }

    protected function isLiveMode()
    {
        if (!class_exists('Configuration')) {
            return false;
        }
        return (string) Configuration::get(self::KEY_MODE) === '1';
    }

    /**
     * Pull the ISO code for an order's currency. Defensive — falls back to
     * USD so Stripe still receives a valid request.
     */
    protected static function orderCurrencyIso(Order $order)
    {
        if (!class_exists('Currency')) {
            return 'USD';
        }
        $cur = new Currency((int) $order->id_currency);
        $iso = isset($cur->iso_code) ? (string) $cur->iso_code : '';
        return $iso !== '' ? $iso : 'USD';
    }

    /**
     * Low-level form POST used for the Stripe API call. Prefers curl when
     * available, falls back to stream wrappers otherwise. Returns:
     * `['status' => int, 'body' => string, 'error' => string]`.
     */
    protected static function postForm($url, $secretKey, array $fields)
    {
        $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST           => true,
                CURLOPT_POSTFIELDS     => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $secretKey,
                    'Content-Type: application/x-www-form-urlencoded',
                ],
            ]);
            $resp = curl_exec($ch);
            $err  = curl_error($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [
                'status' => $code,
                'body'   => $resp === false ? '' : (string) $resp,
                'error'  => $err,
            ];
        }

        // Stream wrapper fallback. Good enough on hosts where curl is
        // disabled (rare, but happens on locked-down shared hosts).
        $ctx = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Authorization: Bearer " . $secretKey . "\r\n"
                                    . "Content-Type: application/x-www-form-urlencoded\r\n",
                'content'       => $body,
                'timeout'       => 30,
                'ignore_errors' => true,
            ],
        ]);
        $resp = @file_get_contents($url, false, $ctx);
        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (preg_match('#^HTTP/\S+\s+(\d+)#', $line, $m)) {
                    $status = (int) $m[1];
                }
            }
        }
        return [
            'status' => $status,
            'body'   => $resp === false ? '' : (string) $resp,
            'error'  => $resp === false ? 'stream_request_failed' : '',
        ];
    }
}
