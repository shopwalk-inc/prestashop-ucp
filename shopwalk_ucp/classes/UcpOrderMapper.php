<?php
/**
 * PrestaShop Order → UCP Order Object (spec 2026-04-08).
 *
 * See UCP_SPEC_COMPLIANCE.md §8.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpOrderMapper
{
    public static function map(Order $order, bool $includeEvents = false): array
    {
        $currency = new Currency((int) $order->id_currency);
        $lineItems = self::lineItems($order);

        $body = [
            'id'             => (string) $order->id,
            'label'          => '#' . (int) $order->id,
            'checkout_id'    => self::findCheckoutId((int) $order->id),
            'permalink_url'  => self::orderPermalink($order),
            'currency'       => (string) $currency->iso_code,
            'line_items'     => $lineItems,
            'fulfillment'    => self::fulfillment($order, $lineItems, $includeEvents),
            'adjustments'    => self::adjustments($order),
            'totals'         => UcpTotals::fromOrder($order),
            'messages'       => [],
        ];

        return UcpEnvelope::ok($body, ['dev.ucp.shopping.order']);
    }

    protected static function lineItems(Order $order): array
    {
        $out = [];
        foreach ($order->getProducts() as $i => $p) {
            $qty = (int) $p['product_quantity'];
            $refunded = (int) ($p['product_quantity_refunded'] ?? 0);
            $priceMinor = UcpTotals::toMinor((float) $p['unit_price_tax_incl']);
            $totalMinor = UcpTotals::toMinor((float) $p['total_price_tax_incl']);

            $out[] = [
                'id'   => 'li_' . ($i + 1),
                'item' => [
                    'id'    => (string) (int) $p['product_id'],
                    'title' => (string) $p['product_name'],
                    'price' => $priceMinor,
                ],
                'quantity' => [
                    'original'  => $qty,
                    'total'     => $qty,
                    'fulfilled' => self::fulfilledQty($order, (int) $p['product_id']),
                ],
                'status' => self::mapItemStatus($order->current_state),
                'totals' => [
                    ['type' => 'subtotal', 'amount' => $totalMinor],
                    ['type' => 'total',    'amount' => $totalMinor],
                ],
            ];
        }
        return $out;
    }

    protected static function fulfilledQty(Order $order, int $productId): int
    {
        $state = (int) $order->current_state;
        $delivered = (int) Configuration::get('PS_OS_DELIVERED');
        if ($state === $delivered) {
            $qty = 0;
            foreach ($order->getProducts() as $p) {
                if ((int) $p['product_id'] === $productId) {
                    $qty += (int) $p['product_quantity'];
                }
            }
            return $qty;
        }
        return 0;
    }

    protected static function mapItemStatus(int $currentState): string
    {
        $map = [
            (int) Configuration::get('PS_OS_CANCELED')   => 'canceled',
            (int) Configuration::get('PS_OS_DELIVERED')  => 'delivered',
            (int) Configuration::get('PS_OS_SHIPPING')   => 'shipped',
            (int) Configuration::get('PS_OS_PAYMENT')    => 'processing',
            (int) Configuration::get('PS_OS_PREPARATION') => 'processing',
        ];
        return $map[$currentState] ?? 'processing';
    }

    protected static function fulfillment(Order $order, array $lineItems, bool $includeEvents): array
    {
        $delivery = new Address((int) $order->id_address_delivery);
        $dest = Validate::isLoadedObject($delivery) ? UcpAddress::fromPsAddress($delivery) : [];

        $expectations = [[
            'id'              => 'exp_1',
            'line_items'      => array_map(static function ($li) {
                return ['id' => $li['id'], 'quantity' => (int) ($li['quantity']['original'] ?? 1)];
            }, $lineItems),
            'method_type'     => 'shipping',
            'destination'     => $dest,
            'description'     => self::carrierDescription($order),
            'fulfillable_on'  => 'now',
        ]];

        $out = ['expectations' => $expectations];

        if ($includeEvents) {
            $out['events'] = self::events($order, $lineItems);
        }
        return $out;
    }

    protected static function events(Order $order, array $lineItems): array
    {
        $events = [];
        $history = OrderHistory::getLastOrderState((int) $order->id) ? $order->getHistory((int) $order->id_lang) : [];
        foreach ($history as $i => $h) {
            $events[] = [
                'id'          => 'evt_' . ($i + 1),
                'occurred_at' => self::iso8601((string) ($h['date_add'] ?? $order->date_upd)),
                'type'        => self::mapStateToEvent((int) ($h['id_order_state'] ?? $order->current_state)),
                'line_items'  => array_map(static function ($li) {
                    return ['id' => $li['id'], 'quantity' => (int) ($li['quantity']['original'] ?? 1)];
                }, $lineItems),
                'description' => (string) ($h['ostate_name'] ?? ''),
            ];
        }
        return $events;
    }

    protected static function mapStateToEvent(int $state): string
    {
        if ($state === (int) Configuration::get('PS_OS_CANCELED')) {
            return 'canceled';
        }
        if ($state === (int) Configuration::get('PS_OS_DELIVERED')) {
            return 'delivered';
        }
        if ($state === (int) Configuration::get('PS_OS_SHIPPING')) {
            return 'shipped';
        }
        if ($state === (int) Configuration::get('PS_OS_REFUND')) {
            return 'refund';
        }
        return 'processing';
    }

    protected static function adjustments(Order $order): array
    {
        $out = [];
        $slips = OrderSlip::getOrdersSlip((int) $order->id_customer, (int) $order->id);
        foreach ($slips as $i => $slip) {
            $out[] = [
                'id'          => 'adj_' . ($i + 1),
                'type'        => 'refund',
                'occurred_at' => self::iso8601((string) ($slip['date_add'] ?? '')),
                'totals'      => [
                    ['type' => 'total', 'amount' => -UcpTotals::toMinor((float) ($slip['total_products_tax_incl'] ?? 0))],
                ],
            ];
        }
        return $out;
    }

    protected static function carrierDescription(Order $order): string
    {
        $carrier = new Carrier((int) $order->id_carrier, (int) $order->id_lang);
        if (!Validate::isLoadedObject($carrier)) {
            return 'Shipping';
        }
        $name = $carrier->name;
        $delay = is_array($carrier->delay) ? ($carrier->delay[(int) $order->id_lang] ?? '') : (string) $carrier->delay;
        return trim($name . ($delay ? ' — ' . $delay : ''));
    }

    protected static function findCheckoutId(int $idOrder): string
    {
        $row = Db::getInstance()->getRow('
            SELECT `session_id` FROM `' . _DB_PREFIX_ . 'ucp_checkout_sessions`
             WHERE `id_order` = ' . $idOrder . ' LIMIT 1
        ');
        return $row ? (string) $row['session_id'] : '';
    }

    protected static function orderPermalink(Order $order): string
    {
        $link = Context::getContext()->link;
        return $link->getPageLink('order-detail', true, null, ['id_order' => (int) $order->id]);
    }

    protected static function iso8601(string $mysqlTs): string
    {
        $ts = strtotime($mysqlTs) ?: time();
        return gmdate('Y-m-d\TH:i:s\Z', $ts);
    }
}
