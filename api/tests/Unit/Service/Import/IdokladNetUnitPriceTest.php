<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Import;

use MyInvoice\Service\Import\IdokladImportService;
use PHPUnit\Framework\TestCase;

/**
 * Regrese #80 — mapování ceny z iDoklad v3 položky.
 *
 * iDoklad v3 GET model NEMÁ top-level `UnitPrice` — cena je vnořená v `Prices.UnitPrice`
 * a autoritativní netto je `Prices.TotalWithoutVat`. Dřív se četlo `$line['UnitPrice']`,
 * které vždy chybělo → 0 → všechny importované faktury (vydané i přijaté) měly 0 Kč.
 */
final class IdokladNetUnitPriceTest extends TestCase
{
    public function testRealV3ItemShapeIsNotZero(): void
    {
        // Přesný tvar, který způsobil #80: žádný top-level UnitPrice, cena ve `Prices`.
        $line = [
            'Name'   => 'Konzultace',
            'Amount' => 2.0,
            'VatRate' => 21.0,
            'PriceType' => 0, // WithVat (default)
            'Prices' => [
                'UnitPrice'       => 121.0,
                'TotalWithoutVat' => 200.0,
                'TotalWithVat'    => 242.0,
                'TotalVat'        => 42.0,
            ],
        ];

        // Autoritativní netto: TotalWithoutVat / Amount = 200 / 2 = 100.
        self::assertSame(100.0, IdokladImportService::idokladNetUnitPrice($line, 21.0));
    }

    public function testFallbackConvertsWithVatUnitPriceToNet(): void
    {
        // Prices bez TotalWithoutVat → fallback na UnitPrice, PriceType=WithVat → odečíst DPH.
        $line = [
            'Amount'    => 1.0,
            'PriceType' => 0, // WithVat
            'Prices'    => ['UnitPrice' => 121.0],
        ];
        self::assertSame(100.0, IdokladImportService::idokladNetUnitPrice($line, 21.0));
    }

    public function testFallbackKeepsWithoutVatUnitPrice(): void
    {
        $line = [
            'Amount'    => 1.0,
            'PriceType' => 1, // WithoutVat
            'Prices'    => ['UnitPrice' => 100.0],
        ];
        self::assertSame(100.0, IdokladImportService::idokladNetUnitPrice($line, 21.0));
    }

    public function testFallbackAppliesItemDiscount(): void
    {
        $line = [
            'Amount'             => 1.0,
            'PriceType'          => 1, // WithoutVat
            'DiscountPercentage' => 10.0,
            'Prices'             => ['UnitPrice' => 100.0],
        ];
        self::assertSame(90.0, IdokladImportService::idokladNetUnitPrice($line, 21.0));
    }

    public function testLegacyTopLevelUnitPriceStillWorks(): void
    {
        // Defenzivně: kdyby přišel plochý tvar bez Prices.
        $line = ['Amount' => 1.0, 'PriceType' => 1, 'UnitPrice' => 50.0];
        self::assertSame(50.0, IdokladImportService::idokladNetUnitPrice($line, 21.0));
    }

    public function testZeroVatReverseChargeLineStaysNet(): void
    {
        $line = [
            'Amount'  => 1.0,
            'VatRate' => 0.0,
            'Prices'  => ['UnitPrice' => 1000.0, 'TotalWithoutVat' => 1000.0],
        ];
        self::assertSame(1000.0, IdokladImportService::idokladNetUnitPrice($line, 0.0));
    }

    public function testEmptyItemIsZeroNotError(): void
    {
        self::assertSame(0.0, IdokladImportService::idokladNetUnitPrice([], 21.0));
    }

    // ── Kurz (#80 audit): ExchangeRate je na ExchangeRateAmount jednotek ──────────

    public function testExchangeRateAmountOneUnchanged(): void
    {
        self::assertSame(25.5, IdokladImportService::idokladExchangeRate(['ExchangeRate' => 25.5, 'ExchangeRateAmount' => 1]));
    }

    public function testExchangeRateDividedByAmount(): void
    {
        // HUF: 6.5 CZK za 100 HUF → 0.065 za 1 HUF.
        self::assertSame(0.065, IdokladImportService::idokladExchangeRate(['ExchangeRate' => 6.5, 'ExchangeRateAmount' => 100]));
    }

    public function testExchangeRateMissingIsNull(): void
    {
        self::assertNull(IdokladImportService::idokladExchangeRate([]));
    }

    public function testExchangeRateAmountMissingDefaultsToOne(): void
    {
        self::assertSame(24.7, IdokladImportService::idokladExchangeRate(['ExchangeRate' => 24.7]));
    }
}
