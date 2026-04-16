<?php
/**
 * Build UCP fulfillment.methods[] from available PS carriers for the cart's
 * shipping destination.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpFulfillment
{
    /**
     * Returns an array shaped like:
     *   [
     *     "methods" => [
     *       [
     *         "id" => "fm_1",
     *         "type" => "shipping",
     *         "line_item_ids" => [...],
     *         "selected_destination_id" => "dest_X",
     *         "destinations" => [ ... ],
     *         "groups" => [ [
     *           "id" => "fg_1",
     *           "line_item_ids" => [...],
     *           "selected_option_id" => "fo_X",
     *           "options" => [
     *             [
     *               "id" => "fo_<carrier_id>",
     *               "title" => "...",
     *               "description" => "...",
     *               "totals" => [{"type"=>"shipping","amount"=>###}]
     *             ], ...
     *           ]
     *         ] ]
     *       ]
     *     ]
     *   ]
     */
    public static function forCart(Cart $cart, array $destination, ?string $selectedOption = null, array $lineItemIds = []): array
    {
        $options = [];
        if ($cart->id_address_delivery) {
            $carriers = $cart->simulateCarriersOutput();
            foreach ($carriers as $c) {
                $carrierId = (int) $c['id_carrier'];
                $carrier = new Carrier($carrierId, $cart->id_lang);
                $cost = (float) $cart->getPackageShippingCost($carrierId, true);
                $options[] = [
                    'id'          => 'fo_' . $carrierId,
                    'title'       => (string) $c['name'],
                    'description' => (string) ($c['delay'] ?: $carrier->delay[$cart->id_lang] ?? ''),
                    'totals'      => [
                        ['type' => 'shipping', 'amount' => UcpTotals::toMinor($cost)],
                    ],
                ];
            }
        }

        // Fallback: if we couldn't simulate, return the store default carrier
        // with a $0 placeholder — better than an empty method list.
        if (!$options) {
            $options[] = [
                'id'          => 'fo_default',
                'title'       => 'Standard',
                'description' => 'Standard shipping',
                'totals'      => [['type' => 'shipping', 'amount' => 0]],
            ];
        }

        $group = [
            'id'                  => 'fg_1',
            'line_item_ids'       => $lineItemIds,
            'selected_option_id'  => $selectedOption ?: $options[0]['id'],
            'options'             => $options,
        ];

        $method = [
            'id'                     => 'fm_1',
            'type'                   => 'shipping',
            'line_item_ids'          => $lineItemIds,
            'selected_destination_id' => $destination['id'] ?? null,
            'destinations'           => [$destination],
            'groups'                 => [$group],
        ];

        return ['methods' => [$method]];
    }

    /**
     * Parse "fo_123" → 123. Returns 0 for unknown.
     */
    public static function carrierIdFromOption(string $optionId): int
    {
        if (strpos($optionId, 'fo_') !== 0) {
            return 0;
        }
        $n = (int) substr($optionId, 3);
        return $n > 0 ? $n : 0;
    }
}
