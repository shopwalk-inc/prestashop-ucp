<?php
/**
 * State machine for UcpCheckoutSession: create, update with partial data,
 * run validation, transition statuses, and convert to a PS Order on
 * /complete.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpCheckoutEngine
{
    /**
     * Create a session from the initial POST body. Expects UCP line_items.
     */
    public static function create(string $clientId, ?int $idCustomer, array $body): UcpCheckoutSession
    {
        $currency = strtoupper((string) ($body['currency'] ?? self::defaultCurrency()));
        $session  = UcpCheckoutSession::newSession($clientId, $currency);
        $session->id_customer = $idCustomer;

        $lineItems = self::normalizeLineItems($body['line_items'] ?? []);
        $session->line_items = json_encode($lineItems, JSON_UNESCAPED_SLASHES);

        $cart = self::buildCart($idCustomer, $lineItems);
        $session->id_cart = (int) $cart->id;
        $session->totals  = json_encode(UcpTotals::fromCart($cart), JSON_UNESCAPED_SLASHES);
        $session->messages = json_encode(self::validateCart($cart, $lineItems));
        $session->save();
        return $session;
    }

    /**
     * Apply a partial PUT body: buyer, fulfillment, payment.
     */
    public static function update(UcpCheckoutSession $session, array $body): UcpCheckoutSession
    {
        if (isset($body['buyer'])) {
            $session->buyer = json_encode($body['buyer']);
        }
        if (isset($body['line_items'])) {
            $session->line_items = json_encode(self::normalizeLineItems($body['line_items']));
        }

        $cart = $session->id_cart ? new Cart((int) $session->id_cart) : null;
        if (!$cart || !Validate::isLoadedObject($cart)) {
            $cart = self::buildCart($session->id_customer, json_decode($session->line_items, true) ?: []);
            $session->id_cart = (int) $cart->id;
        }

        // Apply delivery address if present
        if (isset($body['fulfillment']['methods'][0]['destinations'][0])) {
            $dest = $body['fulfillment']['methods'][0]['destinations'][0];
            $buyer = json_decode((string) $session->buyer, true) ?: [];
            $first = (string) ($buyer['first_name'] ?? 'Guest');
            $last  = (string) ($buyer['last_name']  ?? 'Guest');
            $address = UcpAddress::toPsAddress($dest, (int) $cart->id_customer ?: 0, $first, $last);
            $cart->id_address_delivery = (int) $address->id;
            $cart->id_address_invoice  = (int) $address->id;
            $cart->save();
        }

        // Apply selected shipping option if present
        $selectedOption = $body['fulfillment']['methods'][0]['groups'][0]['selected_option_id'] ?? null;
        if ($selectedOption) {
            $carrierId = UcpFulfillment::carrierIdFromOption($selectedOption);
            if ($carrierId > 0) {
                $cart->id_carrier = $carrierId;
                $cart->save();
            }
        }

        if (isset($body['payment'])) {
            $session->payment = json_encode($body['payment']);
        }

        // Re-derive fulfillment + totals + messages
        $dest = isset($body['fulfillment']['methods'][0]['destinations'][0])
            ? $body['fulfillment']['methods'][0]['destinations'][0]
            : self::destinationFromCart($cart);
        $lineItems = json_decode($session->line_items, true) ?: [];
        $lineItemIds = array_map(static function ($li) { return (string) ($li['id'] ?? ''); }, $lineItems);
        $session->fulfillment = json_encode(UcpFulfillment::forCart($cart, $dest ?: [], $selectedOption, $lineItemIds));
        $session->totals      = json_encode(UcpTotals::fromCart($cart));
        $messages             = self::validateCart($cart, $lineItems);

        // Transition to ready_for_complete when every required field is set.
        if (empty($messages) && $session->buyer && $session->fulfillment && $cart->id_carrier) {
            $session->status = UcpCheckoutSession::STATUS_READY_FOR_COMPLETE;
        } elseif ($session->status !== UcpCheckoutSession::STATUS_COMPLETED) {
            $session->status = UcpCheckoutSession::STATUS_INCOMPLETE;
        }

        $session->messages = json_encode($messages);
        $session->save();
        return $session;
    }

    /**
     * Finalize: create PS Order, attempt agent-native payment via the
     * UcpPaymentRouter if the session includes payment credentials, and
     * mark the session completed.
     *
     * Flow:
     *   1. Create order via PaymentModule::validateOrder in PS_OS_PREPARATION
     *      (awaiting payment).
     *   2. If session.payment.gateway is set, dispatch through the router.
     *      - On hard success, transition to PS_OS_PAYMENT.
     *      - On soft fail (3DS), keep in PS_OS_PREPARATION and return a
     *        payment_url so the buyer can complete payment natively.
     *      - On hard fail, transition to PS_OS_ERROR and return an error.
     *   3. If no payment gateway given, fall back to the "pay at store"
     *      Direct Checkout path — keep in PS_OS_PREPARATION and return the
     *      native payment_url as the handoff surface.
     */
    public static function complete(UcpCheckoutSession $session): array
    {
        if ($session->status === UcpCheckoutSession::STATUS_COMPLETED) {
            return [
                'ok'       => true,
                'id_order' => (int) $session->id_order,
            ];
        }
        if ($session->status !== UcpCheckoutSession::STATUS_READY_FOR_COMPLETE) {
            return [
                'ok'     => false,
                'error'  => 'session_not_ready',
                'reason' => 'Call PUT to supply buyer + fulfillment first',
            ];
        }
        if (!$session->id_cart) {
            return ['ok' => false, 'error' => 'session_invalid', 'reason' => 'No cart attached'];
        }

        $cart = new Cart((int) $session->id_cart);
        if (!Validate::isLoadedObject($cart)) {
            return ['ok' => false, 'error' => 'session_invalid', 'reason' => 'Cart disappeared'];
        }

        require_once __DIR__ . '/UcpPaymentModule.php';

        try {
            $payment              = new PaymentModule();
            $payment->name        = 'shopwalk_ucp';
            $payment->displayName = 'Pay via UCP';
            $totalPaid            = (float) $cart->getOrderTotal(true, Cart::BOTH);

            $ok = $payment->validateOrder(
                (int) $cart->id,
                (int) Configuration::get('PS_OS_PREPARATION'),
                $totalPaid,
                'Pay via UCP',
                'UCP checkout session ' . $session->session_id,
                [],
                (int) $cart->id_currency,
                false,
                $cart->secure_key
            );
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'order_create_failed', 'reason' => $e->getMessage()];
        }

        if (!$ok || !$payment->currentOrder) {
            return ['ok' => false, 'error' => 'order_create_failed', 'reason' => 'validateOrder returned false'];
        }

        $orderId = (int) $payment->currentOrder;
        $order   = new Order($orderId);

        $paymentRequest = json_decode((string) $session->payment, true);
        if (!is_array($paymentRequest)) {
            $paymentRequest = [];
        }

        $gateway = isset($paymentRequest['gateway']) ? (string) $paymentRequest['gateway'] : '';
        if ($gateway !== '' && class_exists('UcpPaymentRouter')) {
            $result = UcpPaymentRouter::authorize($order, $paymentRequest);

            if (!empty($result['ok'])) {
                // Hard success — advance to PS_OS_PAYMENT so downstream
                // webhooks + merchant workflows see payment confirmed.
                self::transitionOrderTo($order, (int) Configuration::get('PS_OS_PAYMENT'));
                $session->id_order = $orderId;
                $session->status   = UcpCheckoutSession::STATUS_COMPLETED;
                $session->save();
                return ['ok' => true, 'id_order' => $orderId];
            }

            if (!empty($result['soft'])) {
                // Soft fail (3DS / requires_action). Keep order pending and
                // hand the agent a payment_url.
                $session->id_order = $orderId;
                $session->status   = UcpCheckoutSession::STATUS_COMPLETED;
                $session->save();
                return [
                    'ok'          => true,
                    'id_order'    => $orderId,
                    'payment_url' => self::paymentUrlFor($order),
                    'soft_fail'   => $result,
                ];
            }

            // Hard fail — cancel the order so merchant dashboards don't
            // show a stuck pending. PS_OS_ERROR is the canonical "payment
            // error" state.
            self::transitionOrderTo($order, (int) Configuration::get('PS_OS_ERROR'));
            $session->status = UcpCheckoutSession::STATUS_REQUIRES_ESCALATION;
            $session->save();
            return [
                'ok'      => false,
                'error'   => isset($result['error']) ? (string) $result['error'] : 'payment_failed',
                'reason'  => isset($result['message']) ? (string) $result['message'] : 'Payment failed',
                'status'  => isset($result['status']) ? (int) $result['status'] : 402,
            ];
        }

        // No agent-native payment path — session completes with a
        // payment_url for the Direct Checkout handoff. Order stays in
        // PS_OS_PREPARATION until the buyer pays on the store's checkout.
        $session->id_order = $orderId;
        $session->status   = UcpCheckoutSession::STATUS_COMPLETED;
        $session->save();

        return [
            'ok'          => true,
            'id_order'    => $orderId,
            'payment_url' => self::paymentUrlFor($order),
        ];
    }

    protected static function transitionOrderTo(Order $order, int $idOrderState): void
    {
        if ($idOrderState <= 0) {
            return;
        }
        try {
            $history = new OrderHistory();
            $history->id_order = (int) $order->id;
            $history->changeIdOrderState($idOrderState, (int) $order->id);
            $history->addWithemail();
        } catch (Throwable $e) {
            PrestaShopLogger::addLog(
                '[shopwalk_ucp] Failed to transition order ' . (int) $order->id
                    . ' to state ' . $idOrderState . ': ' . $e->getMessage(),
                2
            );
        }
    }

    protected static function paymentUrlFor(Order $order): string
    {
        if (!$order->id) {
            return '';
        }
        $cart     = new Cart((int) $order->id_cart);
        $customer = new Customer((int) $order->id_customer);
        $module   = Module::getInstanceByName('shopwalk_ucp');
        if (!Validate::isLoadedObject($cart) || !Validate::isLoadedObject($customer) || !$module) {
            return '';
        }
        return Context::getContext()->link->getPageLink(
            'order-confirmation',
            true,
            null,
            [
                'id_cart'   => (int) $cart->id,
                'id_module' => (int) $module->id,
                'id_order'  => (int) $order->id,
                'key'       => $customer->secure_key,
            ]
        );
    }

    public static function cancel(UcpCheckoutSession $session): void
    {
        $session->status = UcpCheckoutSession::STATUS_CANCELED;
        $session->save();
    }

    // ─── Helpers ──────────────────────────────────────────────────────────

    protected static function normalizeLineItems(array $raw): array
    {
        $out = [];
        foreach (array_values($raw) as $i => $li) {
            $item = $li['item'] ?? [];
            $out[] = [
                'id'       => (string) ($li['id'] ?? ('li_' . ($i + 1))),
                'item'     => [
                    'id'       => (string) ($item['id']       ?? ''),
                    'title'    => (string) ($item['title']    ?? ''),
                    'price'    => (int)    ($item['price']    ?? 0),
                    'image_url' => (string) ($item['image_url'] ?? ''),
                ],
                'quantity' => max(1, (int) ($li['quantity'] ?? 1)),
            ];
        }
        return $out;
    }

    protected static function buildCart(?int $idCustomer, array $lineItems): Cart
    {
        $cart = new Cart();
        $cart->id_lang     = (int) Configuration::get('PS_LANG_DEFAULT');
        $cart->id_currency = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_shop     = (int) Context::getContext()->shop->id;
        $cart->id_customer = (int) ($idCustomer ?: 0);
        $cart->save();

        foreach ($lineItems as $li) {
            $productId = (int) ($li['item']['id'] ?? 0);
            $qty       = (int) $li['quantity'];
            if ($productId <= 0 || $qty <= 0) {
                continue;
            }
            $cart->updateQty($qty, $productId, 0, false, 'up');
        }
        return $cart;
    }

    protected static function validateCart(Cart $cart, array $lineItems): array
    {
        $messages = [];
        foreach ($lineItems as $li) {
            $id  = (int) ($li['item']['id'] ?? 0);
            $qty = (int) $li['quantity'];
            if ($id <= 0) {
                $messages[] = self::msg('invalid_product_id', 'Missing item.id', 'unrecoverable');
                continue;
            }
            $product = new Product($id, false);
            if (!Validate::isLoadedObject($product) || !$product->active) {
                $messages[] = self::msg('product_unavailable', 'Product ' . $id . ' is unavailable', 'unrecoverable');
                continue;
            }
            $available = (int) StockAvailable::getQuantityAvailableByProduct($id);
            if ($available < $qty) {
                $messages[] = self::msg('insufficient_stock', 'Product ' . $id . ' has only ' . $available . ' in stock', 'recoverable');
            }
        }
        return $messages;
    }

    protected static function msg(string $code, string $content, string $severity): array
    {
        return [
            'type'     => 'error',
            'code'     => $code,
            'content'  => $content,
            'severity' => $severity,
        ];
    }

    protected static function defaultCurrency(): string
    {
        $iso = Currency::getDefaultCurrency()->iso_code;
        return $iso ? strtoupper($iso) : 'USD';
    }

    protected static function destinationFromCart(Cart $cart): array
    {
        if (!$cart->id_address_delivery) {
            return [];
        }
        $a = new Address((int) $cart->id_address_delivery);
        if (!Validate::isLoadedObject($a)) {
            return [];
        }
        return UcpAddress::fromPsAddress($a);
    }
}
