<?php
/**
 * Tests for UcpTotals — minor-unit (cents) conversion + typed totals.
 *
 * Covers the toMinor helper and the buildTotals omission rules (zero
 * shipping/tax/discount lines stripped) via a reflection-friendly
 * protected method call through the adapter's public API.
 */

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../classes/UcpTotals.php';

final class UcpTotalsTest extends TestCase
{
    public function testToMinorRoundsCorrectly()
    {
        $this->assertSame(1999, UcpTotals::toMinor(19.99));
        $this->assertSame(100,  UcpTotals::toMinor(1.0));
        $this->assertSame(0,    UcpTotals::toMinor(0.0));
        // round-half-away-from-zero
        $this->assertSame(2000, UcpTotals::toMinor(19.995));
    }

    public function testFromMinorReturnsFloatAmount()
    {
        $this->assertSame(19.99, UcpTotals::fromMinor(1999));
        $this->assertSame(0.0,   UcpTotals::fromMinor(0));
    }

    public function testBuildTotalsOmitsZeroShippingTaxDiscount()
    {
        $totals = self::invokeBuild(100.00, 0, 0, 0, 100.00);
        $types  = array_column($totals, 'type');

        $this->assertSame(['subtotal', 'total'], $types);
        $this->assertSame(10000, $totals[0]['amount']);
        $this->assertSame(10000, $totals[1]['amount']);
    }

    public function testBuildTotalsIncludesAllWhenPresent()
    {
        $totals = self::invokeBuild(100.00, 5.00, 8.25, -10.00, 103.25);
        $map    = [];
        foreach ($totals as $t) {
            $map[$t['type']] = $t['amount'];
        }

        $this->assertSame(10000, $map['subtotal']);
        $this->assertSame(500,   $map['shipping']);
        $this->assertSame(825,   $map['tax']);
        $this->assertSame(-1000, $map['discount']);
        $this->assertSame(10325, $map['total']);
    }

    public function testBuildTotalsPreservesOrderSubtotalFirstTotalLast()
    {
        $totals = self::invokeBuild(100.00, 5.00, 8.25, -10.00, 103.25);
        $types  = array_column($totals, 'type');

        $this->assertSame('subtotal', $types[0]);
        $this->assertSame('total', end($types));
    }

    /**
     * Call the protected UcpTotals::buildTotals() via reflection so we can
     * assert its omission logic without materializing a PS Cart.
     */
    protected static function invokeBuild(float $subtotal, float $shipping, float $tax, float $discount, float $total): array
    {
        $ref = new ReflectionMethod('UcpTotals', 'buildTotals');
        $ref->setAccessible(true);
        return $ref->invoke(null, $subtotal, $shipping, $tax, $discount, $total);
    }
}
