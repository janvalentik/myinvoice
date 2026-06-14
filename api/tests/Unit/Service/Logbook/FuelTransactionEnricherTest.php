<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Logbook;

use MyInvoice\Service\Logbook\Fuel\FuelTransactionEnricher;
use PHPUnit\Framework\TestCase;

final class FuelTransactionEnricherTest extends TestCase
{
    private FuelTransactionEnricher $enricher;

    protected function setUp(): void
    {
        $this->enricher = new FuelTransactionEnricher();
    }

    /** Jediná transakce bez litrů → doplní přesně z položky faktury. */
    public function testFillsQuantityForSingleTransactionFromInvoiceItem(): void
    {
        $txns = [[
            'fueled_date' => '2025-01-10', 'fuel_type' => 'Nafta',
            'quantity' => null, 'amount_without_vat' => 600.0, 'amount_with_vat' => 726.0, 'is_fuel' => true,
        ]];
        $invoice = [
            'tax_date' => '2025-01-10', 'issue_date' => '2025-01-12',
            'items' => [[
                'description' => 'Nafta', 'quantity' => 20.0, 'unit' => 'l',
                'total_without_vat' => 600.0, 'unit_price_without_vat' => 30.0,
            ]],
        ];

        $out = $this->enricher->enrich($txns, $invoice);

        self::assertSame(20.0, $out[0]['quantity']);
        self::assertSame('l', $out[0]['unit']);
        // unit_price = base / qty = 600 / 20 = 30
        self::assertEqualsWithDelta(30.0, $out[0]['unit_price'], 0.01);
    }

    /** Dvě transakce stejného paliva → úhrn litrů rozdělen proporčně dle základu, součet sedí. */
    public function testSplitsAggregateLitersProportionallyAcrossTransactions(): void
    {
        $txns = [
            ['fueled_date' => '2025-02-05', 'fuel_type' => 'Nafta', 'quantity' => null,
             'amount_without_vat' => 400.0, 'amount_with_vat' => 484.0, 'is_fuel' => true],
            ['fueled_date' => '2025-02-12', 'fuel_type' => 'Nafta', 'quantity' => null,
             'amount_without_vat' => 600.0, 'amount_with_vat' => 726.0, 'is_fuel' => true],
        ];
        $invoice = ['items' => [[
            'description' => 'Nafta', 'quantity' => 50.0, 'unit' => 'l',
            'total_without_vat' => 1000.0, 'unit_price_without_vat' => 20.0,
        ]]];

        $out = $this->enricher->enrich($txns, $invoice);

        // 400/1000*50 = 20 ; 600/1000*50 = 30 ; součet = 50.
        self::assertEqualsWithDelta(20.0, $out[0]['quantity'], 0.01);
        self::assertEqualsWithDelta(30.0, $out[1]['quantity'], 0.01);
        self::assertEqualsWithDelta(50.0, $out[0]['quantity'] + $out[1]['quantity'], 0.02);
        // Stejná jednotková cena → split dle základu je přesný (20).
        self::assertEqualsWithDelta(20.0, $out[0]['unit_price'], 0.05);
        self::assertEqualsWithDelta(20.0, $out[1]['unit_price'], 0.05);
        self::assertGreaterThan($out[0]['quantity'], $out[1]['quantity']);
    }

    /** Litry z detailu (str. 2) se NEpřepisují fallbackem. */
    public function testDoesNotOverwriteExistingQuantity(): void
    {
        $txns = [[
            'fueled_date' => '2025-03-01', 'fuel_type' => 'Natural 95', 'quantity' => 40.0,
            'amount_without_vat' => 1200.0, 'amount_with_vat' => 1452.0, 'is_fuel' => true,
        ]];
        $invoice = ['items' => [[
            'description' => 'Natural 95', 'quantity' => 99.0, 'unit' => 'l', 'total_without_vat' => 1200.0,
        ]]];

        $out = $this->enricher->enrich($txns, $invoice);

        self::assertSame(40.0, $out[0]['quantity']);
    }

    /** Nepalivová položka (plošná cena) se neplní litry. */
    public function testDoesNotFillNonFuelTransaction(): void
    {
        $txns = [[
            'fueled_date' => '2025-03-01', 'fuel_type' => 'Plošná cena', 'quantity' => null,
            'amount_without_vat' => 99.0, 'amount_with_vat' => 119.79, 'is_fuel' => false,
        ]];
        $invoice = ['items' => [[
            'description' => 'Nafta', 'quantity' => 20.0, 'unit' => 'l', 'total_without_vat' => 600.0,
        ]]];

        $out = $this->enricher->enrich($txns, $invoice);

        self::assertNull($out[0]['quantity']);
    }

    /** Chybějící datum → fallback na DUZP (tax_date). */
    public function testFillsMissingDateFromTaxDate(): void
    {
        $txns = [['fueled_date' => '', 'fuel_type' => 'Nafta', 'quantity' => 10.0, 'amount_with_vat' => 300.0, 'is_fuel' => true]];
        $invoice = ['tax_date' => '2025-04-30', 'issue_date' => '2025-05-03', 'items' => []];

        $out = $this->enricher->enrich($txns, $invoice);

        self::assertSame('2025-04-30', $out[0]['fueled_date']);
    }

    /** Bez DUZP → fallback na datum vystavení. */
    public function testFallsBackToIssueDateWhenNoTaxDate(): void
    {
        $txns = [['fueled_date' => '', 'fuel_type' => 'Nafta', 'quantity' => 10.0, 'amount_with_vat' => 300.0, 'is_fuel' => true]];
        $invoice = ['issue_date' => '2025-05-03', 'items' => []];

        $out = $this->enricher->enrich($txns, $invoice);

        self::assertSame('2025-05-03', $out[0]['fueled_date']);
    }
}
