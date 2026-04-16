<?php
/**
 * Minor-unit typed totals. UCP uses cents (no floats) and a `[{type, amount}]`
 * array keyed by concept, not a flat dict.
 *
 * See UCP_SPEC_COMPLIANCE.md §3.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class UcpTotals
{
    /**
     * Convert a PS Cart to UCP totals.
     */
    public static function fromCart(Cart $cart): array
    {
        $subtotal = (float) $cart->getOrderTotal(false, Cart::ONLY_PRODUCTS);
        $shipping = (float) $cart->getTotalShippingCost(null, true);
        $tax      = (float) $cart->getOrderTotal(true, Cart::BOTH) - (float) $cart->getOrderTotal(false, Cart::BOTH);
        $discount = (float) $cart->getOrderTotal(true, Cart::ONLY_DISCOUNTS);
        $total    = (float) $cart->getOrderTotal(true, Cart::BOTH);

        return self::buildTotals($subtotal, $shipping, $tax, -$discount, $total);
    }

    /**
     * Convert a PS Order to UCP totals.
     */
    public static function fromOrder(Order $order): array
    {
        $subtotal = (float) $order->total_products;
        $shipping = (float) $order->total_shipping;
        $tax      = (float) ($order->total_paid_tax_incl - $order->total_paid_tax_excl);
        $discount = (float) $order->total_discounts;
        $total    = (float) $order->total_paid;

        return self::buildTotals($subtotal, $shipping, $tax, -$discount, $total);
    }

    protected static function buildTotals(float $subtotal, float $shipping, float $tax, float $discount, float $total): array
    {
        $totals = [
            ['type' => 'subtotal', 'amount' => self::toMinor($subtotal)],
        ];
        if ($shipping > 0) {
            $totals[] = ['type' => 'shipping', 'amount' => self::toMinor($shipping)];
        }
        if (abs($tax) > 0.00001) {
            $totals[] = ['type' => 'tax', 'amount' => self::toMinor($tax)];
        }
        if (abs($discount) > 0.00001) {
            $totals[] = ['type' => 'discount', 'amount' => self::toMinor($discount)];
        }
        $totals[] = ['type' => 'total', 'amount' => self::toMinor($total)];
        return $totals;
    }

    public static function toMinor(float $amount): int
    {
        return (int) round($amount * 100);
    }

    public static function fromMinor(int $minor): float
    {
        return $minor / 100.0;
    }
}
