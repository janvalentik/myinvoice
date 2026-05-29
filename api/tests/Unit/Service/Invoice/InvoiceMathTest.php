<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\InvoiceMath;
use PHPUnit\Framework\TestCase;

final class InvoiceMathTest extends TestCase
{
    public function testSingleItem21Pct(): void
    {
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 1000.00, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(1000.00, $r['totals']['without_vat']);
        self::assertSame(210.00,  $r['totals']['vat']);
        self::assertSame(1210.00, $r['totals']['with_vat']);
        self::assertCount(1, $r['vat_breakdown']);
        self::assertSame(21.0, $r['vat_breakdown'][0]['rate']);
    }

    public function testMultipleItemsMixedRates(): void
    {
        // 21 % a 12 % v jedné faktuře — typicky např. služba + jídlo
        $r = InvoiceMath::compute([
            ['quantity' => 2, 'unit_price_without_vat' => 500.00,  'vat_rate_snapshot' => 21],  // base 1000, VAT 210
            ['quantity' => 5, 'unit_price_without_vat' => 100.00,  'vat_rate_snapshot' => 12],  // base 500,  VAT 60
            ['quantity' => 1, 'unit_price_without_vat' => 50.00,   'vat_rate_snapshot' => 0],   // base 50,   VAT 0
        ]);
        self::assertSame(1550.00, $r['totals']['without_vat']);
        self::assertSame(270.00,  $r['totals']['vat']);
        self::assertSame(1820.00, $r['totals']['with_vat']);

        // Breakdown seřazený sestupně podle rate: 21, 12, 0
        self::assertCount(3, $r['vat_breakdown']);
        self::assertSame(21.0, $r['vat_breakdown'][0]['rate']);
        self::assertSame(12.0, $r['vat_breakdown'][1]['rate']);
        self::assertSame(0.0,  $r['vat_breakdown'][2]['rate']);

        self::assertSame(1000.00, $r['vat_breakdown'][0]['base']);
        self::assertSame(210.00,  $r['vat_breakdown'][0]['vat']);
        self::assertSame(500.00,  $r['vat_breakdown'][1]['base']);
        self::assertSame(60.00,   $r['vat_breakdown'][1]['vat']);
    }

    public function testReverseChargeKeepsNominalRateButZeroesVat(): void
    {
        // Reverse charge (přenesená daň. povinnost): nominální sazby (21/12 %) ZŮSTÁVAJÍ
        // pro zobrazení i breakdown, ale daň = 0 (odvede ji zákazník).
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 1000.00, 'vat_rate_snapshot' => 21],
            ['quantity' => 1, 'unit_price_without_vat' => 500.00,  'vat_rate_snapshot' => 12],
        ], reverseCharge: true);
        self::assertSame(1500.00, $r['totals']['without_vat']);
        self::assertSame(0.00,    $r['totals']['vat']);
        self::assertSame(1500.00, $r['totals']['with_vat']);
        // Sazby zůstávají (seřazeno DESC), daň u každé 0
        self::assertCount(2, $r['vat_breakdown']);
        self::assertSame(21.0,    $r['vat_breakdown'][0]['rate']);
        self::assertSame(1000.00, $r['vat_breakdown'][0]['base']);
        self::assertSame(0.00,    $r['vat_breakdown'][0]['vat']);
        self::assertSame(12.0,   $r['vat_breakdown'][1]['rate']);
        self::assertSame(500.00, $r['vat_breakdown'][1]['base']);
        self::assertSame(0.00,   $r['vat_breakdown'][1]['vat']);
    }

    public function testEmptyItemsReturnsZeros(): void
    {
        $r = InvoiceMath::compute([]);
        self::assertSame(0.0, $r['totals']['without_vat']);
        self::assertSame(0.0, $r['totals']['vat']);
        self::assertSame(0.0, $r['totals']['with_vat']);
        self::assertSame([], $r['vat_breakdown']);
    }

    public function testRoundingHalfPenny(): void
    {
        // 7.255 → 7.26 (PHP round half-away-from-zero default)
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 7.255, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(7.26, $r['totals']['without_vat']);
        // VAT z 7.26 * 0.21 = 1.5246 → 1.52
        self::assertSame(1.52, $r['totals']['vat']);
        self::assertSame(8.78, $r['totals']['with_vat']);
    }

    public function testDecimalQuantity(): void
    {
        // Hodiny: 1.5 × 1500 Kč/h = 2250
        $r = InvoiceMath::compute([
            ['quantity' => 1.5, 'unit_price_without_vat' => 1500.00, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(2250.00, $r['totals']['without_vat']);
        self::assertSame(472.50,  $r['totals']['vat']);
        self::assertSame(2722.50, $r['totals']['with_vat']);
    }

    public function testPerItemTotalsReturned(): void
    {
        $r = InvoiceMath::compute([
            ['quantity' => 2, 'unit_price_without_vat' => 100.00, 'vat_rate_snapshot' => 21],
        ]);
        self::assertSame(200.00, $r['items'][0]['base']);
        self::assertSame(42.00,  $r['items'][0]['vat']);
        self::assertSame(242.00, $r['items'][0]['with']);
        self::assertSame(21.0,   $r['items'][0]['rate']);
    }

    public function testZeroVatRateDoesNotProduceVatTax(): void
    {
        // Položky se sazbou 0% (osvobozené)
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 1000.00, 'vat_rate_snapshot' => 0],
        ]);
        self::assertSame(1000.00, $r['totals']['without_vat']);
        self::assertSame(0.00,    $r['totals']['vat']);
        self::assertSame(1000.00, $r['totals']['with_vat']);
    }

    public function testNegativeDiscountLineReducesTotalsAndBreakdown(): void
    {
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 1000.00, 'vat_rate_snapshot' => 21],
            ['quantity' => 1, 'unit_price_without_vat' => -100.00, 'vat_rate_snapshot' => 21],
        ]);

        self::assertSame(900.00, $r['totals']['without_vat']);
        self::assertSame(189.00, $r['totals']['vat']);
        self::assertSame(1089.00, $r['totals']['with_vat']);
        self::assertCount(1, $r['vat_breakdown']);
        self::assertSame(900.00, $r['vat_breakdown'][0]['base']);
        self::assertSame(189.00, $r['vat_breakdown'][0]['vat']);
    }
}
