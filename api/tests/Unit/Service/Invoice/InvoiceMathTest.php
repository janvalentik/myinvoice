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

    // ─── Režim SHORA (prices_include_vat = true) ────────────────────────────
    // Cena položky je VČETNĚ DPH (gross); DPH se počítá koeficientem rate/(100+rate).
    // Zde je veškeré daňové riziko — celek MUSÍ sedět na haléř a SUM(řádkový vat)
    // per sazba == round(SUM(gross) × koeficient), aby DPHDP3/KH/kniha seděly.

    public function testTopDownSingleItem21PctMatchesToTheCent(): void
    {
        // Klasický problém z plánu: 33 Kč s DPH @21 %. Zdola by ×1,21 nesedělo (32,9967).
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21],
        ], pricesIncludeVat: true);

        self::assertSame(27.27, $r['totals']['without_vat']);
        self::assertSame(5.73,  $r['totals']['vat']);
        self::assertSame(33.00, $r['totals']['with_vat']); // sedí přesně
        self::assertSame(27.27, $r['items'][0]['base']);
        self::assertSame(5.73,  $r['items'][0]['vat']);
        self::assertSame(33.00, $r['items'][0]['with']);
        self::assertSame(27.27, $r['vat_breakdown'][0]['base']);
        self::assertSame(5.73,  $r['vat_breakdown'][0]['vat']);
    }

    public function testTopDownReceipt344MatchesPlanExpectation(): void
    {
        // Účtenka 344 Kč s DPH @21 % → base 284,30 / DPH 59,70 (viz plán).
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 344.00, 'vat_rate_snapshot' => 21],
        ], pricesIncludeVat: true);

        self::assertSame(284.30, $r['totals']['without_vat']);
        self::assertSame(59.70,  $r['totals']['vat']);
        self::assertSame(344.00, $r['totals']['with_vat']);
    }

    public function testTopDownRoundingDistributionAcrossSameRateLines(): void
    {
        // Tři řádky 33 Kč s DPH @21 %. Per-řádek vat = 5,73 → součet 17,19, ale daň
        // z celého grossu 99 koeficientem = round(99×21/121) = 17,18. Reziduum −0,01
        // se dorovná na nejsilnějším řádku (zde první), aby SUM(vat) == 17,18.
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21],
            ['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21],
            ['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21],
        ], pricesIncludeVat: true);

        // SUM řádkové daně přesně odpovídá koeficientové dani z celkového grossu.
        self::assertSame(17.18, $r['totals']['vat']);
        self::assertSame(81.82, $r['totals']['without_vat']);
        self::assertSame(99.00, $r['totals']['with_vat']);
        // Reziduum dorovnáno na prvním (nejsilnějším) řádku: 5,72 místo 5,73.
        self::assertSame(5.72,  $r['items'][0]['vat']);
        self::assertSame(27.28, $r['items'][0]['base']);
        self::assertSame(5.73,  $r['items'][1]['vat']);
        self::assertSame(5.73,  $r['items'][2]['vat']);
        // Breakdown per sazba sedí s koeficientem (klíčové pro KH/DPHDP3).
        self::assertSame(81.82, $r['vat_breakdown'][0]['base']);
        self::assertSame(17.18, $r['vat_breakdown'][0]['vat']);
        // Invariant: součet řádkové daně == daň z celkového grossu koeficientem.
        $lineVatSum = $r['items'][0]['vat'] + $r['items'][1]['vat'] + $r['items'][2]['vat'];
        self::assertSame(round(99.0 * 21 / 121, 2), round($lineVatSum, 2));
    }

    public function testTopDownMixedRatesEachMatchesCoefficient(): void
    {
        // Mix 21/12/0, vše s DPH. Každá sazba se počítá zvlášť koeficientem.
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 100.00, 'vat_rate_snapshot' => 21], // vat 17,36 / base 82,64
            ['quantity' => 1, 'unit_price_without_vat' => 100.00, 'vat_rate_snapshot' => 12], // vat 10,71 / base 89,29
            ['quantity' => 1, 'unit_price_without_vat' => 100.00, 'vat_rate_snapshot' => 0],  // vat 0     / base 100
        ], pricesIncludeVat: true);

        self::assertSame(300.00, $r['totals']['with_vat']); // gross total přesně
        self::assertSame(28.07,  $r['totals']['vat']);      // 17,36 + 10,71
        self::assertSame(271.93, $r['totals']['without_vat']);

        // Breakdown DESC dle sazby: 21, 12, 0
        self::assertSame(21.0, $r['vat_breakdown'][0]['rate']);
        self::assertSame(82.64, $r['vat_breakdown'][0]['base']);
        self::assertSame(17.36, $r['vat_breakdown'][0]['vat']);
        self::assertSame(12.0, $r['vat_breakdown'][1]['rate']);
        self::assertSame(89.29, $r['vat_breakdown'][1]['base']);
        self::assertSame(10.71, $r['vat_breakdown'][1]['vat']);
        self::assertSame(0.0, $r['vat_breakdown'][2]['rate']);
        self::assertSame(100.00, $r['vat_breakdown'][2]['base']);
        self::assertSame(0.00, $r['vat_breakdown'][2]['vat']);
    }

    public function testTopDownReverseChargeZeroesTaxAndKeepsGrossAsBase(): void
    {
        // RC + prices_include_vat: daň 0 (odvede zákazník), základ = celý gross.
        $r = InvoiceMath::compute([
            ['quantity' => 1, 'unit_price_without_vat' => 121.00, 'vat_rate_snapshot' => 21],
        ], reverseCharge: true, pricesIncludeVat: true);

        self::assertSame(121.00, $r['totals']['without_vat']);
        self::assertSame(0.00,   $r['totals']['vat']);
        self::assertSame(121.00, $r['totals']['with_vat']);
        self::assertSame(21.0,   $r['vat_breakdown'][0]['rate']); // nominální sazba zůstává
        self::assertSame(0.00,   $r['vat_breakdown'][0]['vat']);
    }

    public function testTopDownZeroRateLeavesPriceUnchanged(): void
    {
        // Neplátce / osvobozeno (sazba 0) — cena beze změny v obou režimech.
        $r = InvoiceMath::compute([
            ['quantity' => 2, 'unit_price_without_vat' => 500.00, 'vat_rate_snapshot' => 0],
        ], pricesIncludeVat: true);

        self::assertSame(1000.00, $r['totals']['without_vat']);
        self::assertSame(0.00,    $r['totals']['vat']);
        self::assertSame(1000.00, $r['totals']['with_vat']);
    }

    public function testBottomUpUnchangedWhenFlagFalse(): void
    {
        // Regrese: stejná data zdola (default) vs. shora dají RŮZNÝ základ/daň —
        // potvrzuje, že příznak skutečně přepíná metodu a default zůstává beze změny.
        $items = [['quantity' => 1, 'unit_price_without_vat' => 33.00, 'vat_rate_snapshot' => 21]];

        $down = InvoiceMath::compute($items); // zdola (default)
        self::assertSame(33.00, $down['totals']['without_vat']);
        self::assertSame(6.93,  $down['totals']['vat']);   // 33 × 0,21
        self::assertSame(39.93, $down['totals']['with_vat']);

        $up = InvoiceMath::compute($items, pricesIncludeVat: true); // shora
        self::assertSame(27.27, $up['totals']['without_vat']);
        self::assertSame(5.73,  $up['totals']['vat']);
        self::assertSame(33.00, $up['totals']['with_vat']);
    }
}
