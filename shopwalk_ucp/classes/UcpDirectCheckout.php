<?php
/**
 * UCP Direct Checkout — Shopwalk extension for pay-at-store handoff.
 *
 * This is the Tier-2 fallback path. When an agent can't (or won't) run the
 * full UCP checkout session state machine, it can POST the whole order in
 * one shot to `/shopwalk-ucp/v1/checkout`. This class creates a PS Order,
 * attaches Shopwalk metadata, and returns a `payment_url` the agent hands
 * off to the buyer for completion on the store's native checkout page.
 *
 * NOT part of the UCP spec — lives under a separate namespace on purpose.
 * Requires `X-License-Key` header (merchant's Shopwalk license key). When
 * Tier 2 is not activated (no license key configured), the endpoint
 * responds with 401 so the agent immediately knows to fall back to the
 * standard UCP session flow.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpDirectCheckout
{
    /**
     * Order TTL (seconds). Pending Shopwalk orders older than this are
     * candidates for a hourly cleanup cron in Tier 2. v0.1 records the
     * `_expires_at` meta but doesn't cancel automatically.
     */
    const ORDER_TTL = 1800;

    /**
     * Create a PrestaShop order from an agent-submitted body. Returns an
     * array shaped like:
     *
     *  [
     *    'status' => 201,
     *    'body'   => [
     *      'order_id'       => 12345,
     *      'order_reference'=> 'ABCDEFGHI',
     *      'status'         => 'pending',
     *      'payment_url'    => '…',
     *      'subtotal'       => 1299, // minor units
     *      'shipping_total' => 599,
     *      'tax_total'      => 104,
     *      'total'          => 2002,
     *      'currency'       => 'USD',
     *      'items'          => [ ... ],
     *      'expires_at'     => '…',
     *    ],
     *  ]
     *
     * On failure returns `['status' => 4xx, 'body' => ['error' => '…', 'message' => '…']]`.
     */
    public static function createOrderFromRequest(array $body)
    {
        $items = isset($body['items']) && is_array($body['items']) ? $body['items'] : [];
        if (!$items) {
            return self::err(400, 'invalid_request', 'items[] is required and must be non-empty.');
        }

        $validated = [];
        foreach ($items as $idx => $item) {
            $productId     = (int) (isset($item['product_id']) ? $item['product_id'] : 0);
            $combinationId = (int) (isset($item['combination_id']) ? $item['combination_id'] : 0);
            $quantity      = (int) (isset($item['quantity']) ? $item['quantity'] : 1);

            if ($productId <= 0) {
                return self::err(400, 'invalid_product', sprintf('items[%d].product_id is required.', $idx));
            }
            if ($quantity <= 0) {
                return self::err(400, 'invalid_quantity', sprintf('items[%d].quantity must be at least 1.', $idx));
            }

            $product = new Product($productId, false);
            if (!Validate::isLoadedObject($product) || !$product->active) {
                return self::err(404, 'product_not_found', sprintf('Product %d not found or inactive.', $productId));
            }

            $available = (int) StockAvailable::getQuantityAvailableByProduct($productId, $combinationId ?: null);
            if ($available < $quantity) {
                return self::err(422, 'insufficient_stock', sprintf(
                    'Product %d has only %d in stock (requested %d).',
                    $productId,
                    $available,
                    $quantity
                ));
            }

            $validated[] = [
                'product_id'     => $productId,
                'combination_id' => $combinationId,
                'quantity'       => $quantity,
                'title'          => (string) $product->name[Context::getContext()->language->id] ?? '',
                'price'          => (float) $product->getPrice(),
            ];
        }

        $customer = self::ensureCustomer(isset($body['customer']) && is_array($body['customer']) ? $body['customer'] : []);
        if (!$customer) {
            return self::err(400, 'invalid_customer', 'customer.email is required.');
        }

        $shippingRaw = isset($body['shipping_address']) && is_array($body['shipping_address']) ? $body['shipping_address'] : [];
        $address     = self::buildShippingAddress($customer, $shippingRaw, $body);
        if (!$address) {
            return self::err(400, 'invalid_shipping_address', 'shipping_address fields required.');
        }

        $cart = new Cart();
        $cart->id_customer        = (int) $customer->id;
        $cart->id_address_delivery = (int) $address->id;
        $cart->id_address_invoice  = (int) $address->id;
        $cart->id_lang             = (int) Configuration::get('PS_LANG_DEFAULT');
        $cart->id_currency         = (int) Configuration::get('PS_CURRENCY_DEFAULT');
        $cart->id_shop             = (int) Context::getContext()->shop->id;
        $cart->secure_key          = $customer->secure_key;
        $cart->save();

        foreach ($validated as $v) {
            $cart->updateQty($v['quantity'], $v['product_id'], $v['combination_id'] ?: null, false, 'up');
        }

        // Pick the first carrier available (defensive — merchants usually
        // have at least one). If none available, 422.
        $carriers = Carrier::getCarriersForOrder((int) Country::getIdZone((int) $address->id_country));
        if (!$carriers) {
            return self::err(422, 'no_carriers_available', 'No shipping carrier available for this destination.');
        }
        $cart->id_carrier = (int) $carriers[0]['id_carrier'];
        $cart->save();

        $module = Module::getInstanceByName('shopwalk_ucp');
        if (!$module) {
            return self::err(500, 'module_not_loaded', 'Shopwalk UCP module not loadable.');
        }

        $paymentModule              = new PaymentModule();
        $paymentModule->name        = 'shopwalk_ucp';
        $paymentModule->displayName = 'Pay via UCP';

        $totalPaid = (float) $cart->getOrderTotal(true, Cart::BOTH);

        try {
            $ok = $paymentModule->validateOrder(
                (int) $cart->id,
                (int) Configuration::get('PS_OS_PREPARATION'),
                $totalPaid,
                'Pay via UCP',
                'Shopwalk Direct Checkout',
                self::metadataExtraVars(isset($body['metadata']) ? (array) $body['metadata'] : []),
                (int) $cart->id_currency,
                false,
                $customer->secure_key
            );
        } catch (Throwable $e) {
            return self::err(500, 'order_create_failed', $e->getMessage());
        }

        if (!$ok || !$paymentModule->currentOrder) {
            return self::err(500, 'order_create_failed', 'PaymentModule::validateOrder returned false.');
        }

        $orderId = (int) $paymentModule->currentOrder;
        $order   = new Order($orderId);

        $paymentUrl = Context::getContext()->link->getPageLink(
            'order-confirmation',
            true,
            null,
            [
                'id_cart'   => (int) $cart->id,
                'id_module' => (int) $module->id,
                'id_order'  => $orderId,
                'key'       => $customer->secure_key,
            ]
        );

        $currency = new Currency((int) $order->id_currency);

        $responseItems = [];
        foreach ($validated as $v) {
            $responseItems[] = [
                'product_id' => $v['product_id'],
                'name'       => $v['title'],
                'quantity'   => $v['quantity'],
                'price'      => UcpTotals::toMinor($v['price']),
            ];
        }

        return [
            'status' => 201,
            'body'   => [
                'order_id'        => $orderId,
                'order_reference' => (string) $order->reference,
                'status'          => 'pending',
                'payment_url'     => $paymentUrl,
                'subtotal'        => UcpTotals::toMinor((float) $order->total_products_wt),
                'shipping_total'  => UcpTotals::toMinor((float) $order->total_shipping),
                'tax_total'       => UcpTotals::toMinor(
                    (float) $order->total_paid_tax_incl - (float) $order->total_paid_tax_excl
                ),
                'total'           => UcpTotals::toMinor((float) $order->total_paid),
                'currency'        => (string) $currency->iso_code,
                'items'           => $responseItems,
                'expires_at'      => date('Y-m-d\TH:i:s\Z', time() + self::ORDER_TTL),
            ],
        ];
    }

    /**
     * Return or create a PS Customer for the agent-provided buyer.
     */
    protected static function ensureCustomer(array $buyer)
    {
        $email = isset($buyer['email']) ? trim((string) $buyer['email']) : '';
        if ($email === '' || !Validate::isEmail($email)) {
            return null;
        }

        $idCustomer = (int) Customer::customerExists($email, true, true);
        if ($idCustomer > 0) {
            return new Customer($idCustomer);
        }

        $customer = new Customer();
        $customer->firstname = isset($buyer['first_name']) ? (string) $buyer['first_name'] : 'Guest';
        $customer->lastname  = isset($buyer['last_name']) ? (string) $buyer['last_name'] : 'Guest';
        $customer->email     = $email;
        $customer->passwd    = Tools::hash(Tools::passwdGen(16));
        $customer->is_guest  = 1;
        $customer->active    = 1;
        $customer->add();
        return $customer;
    }

    protected static function buildShippingAddress(Customer $customer, array $addr, array $body)
    {
        if (!$addr) {
            return null;
        }

        $address = new Address();
        $address->id_customer = (int) $customer->id;
        $address->firstname   = isset($addr['first_name']) ? (string) $addr['first_name'] : $customer->firstname;
        $address->lastname    = isset($addr['last_name']) ? (string) $addr['last_name'] : $customer->lastname;
        $address->address1    = isset($addr['address_1']) ? (string) $addr['address_1'] : '';
        $address->address2    = isset($addr['address_2']) ? (string) $addr['address_2'] : '';
        $address->city        = isset($addr['city']) ? (string) $addr['city'] : '';
        $address->postcode    = isset($addr['postcode']) ? (string) $addr['postcode'] : '';
        $address->alias       = 'Shopwalk Direct Checkout';

        $countryIso = strtoupper((string) (isset($addr['country']) ? $addr['country'] : 'US'));
        $idCountry  = (int) Country::getByIso($countryIso);
        $address->id_country = $idCountry ?: (int) Configuration::get('PS_COUNTRY_DEFAULT');

        if (!empty($addr['state']) && $address->id_country) {
            $idState = (int) State::getIdByIso(strtoupper((string) $addr['state']), $address->id_country);
            if ($idState) {
                $address->id_state = $idState;
            }
        }

        if (isset($body['customer']['phone'])) {
            $address->phone = (string) $body['customer']['phone'];
        }

        if ($address->address1 === '' || $address->city === '' || $address->postcode === '') {
            return null;
        }

        $address->save();
        return $address;
    }

    /**
     * Convert Shopwalk metadata into Order::validateOrder extra_vars so it
     * survives on the order record. `transaction_id`/`shopwalk_order_id`
     * are the load-bearing fields — rest is free-form.
     */
    protected static function metadataExtraVars(array $metadata)
    {
        if (!$metadata) {
            return [];
        }
        $extra = [];
        if (isset($metadata['order_id'])) {
            $extra['transaction_id'] = (string) $metadata['order_id'];
        }
        return $extra;
    }

    protected static function err($status, $code, $message)
    {
        return [
            'status' => (int) $status,
            'body'   => [
                'error'   => $code,
                'message' => $message,
            ],
        ];
    }
}
