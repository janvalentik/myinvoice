<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Service\Logbook\Fuel\FuelKeywords;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FuelKeywordsTest extends TestCase
{
    #[DataProvider('fuelCases')]
    public function testIsFuel(string $desc, bool $expected): void
    {
        self::assertSame($expected, FuelKeywords::isFuel($desc));
    }

    public static function fuelCases(): array
    {
        return [
            ['Prémiová nafta', true],
            ['Natural 95', true],
            ['Diesel plus', true],
            ['NAFTA MOTOROVÁ', true],
            ['AdBlue', true],
            ['Benzín BA95', true],
            ['Verva Diesel', true],
            // Elektrické dobíjení = také „palivo" (energie).
            ['Nabíjení elektromobilu', true],
            ['Dobíjení 23,5 kWh', true],
            ['AC nabíjení', true],
            ['Wallbox', true],
            // Nepalivové služby — non-fuel vyhrává i kdyby obsahovaly palivové slovo.
            ['Mytí vozu', false],
            ['Plošná cena', false],
            ['Dálniční známka', false],
            ['Parkovné', false],
            ['', false],
        ];
    }

    #[DataProvider('electricCases')]
    public function testIsElectric(string $desc, bool $expected): void
    {
        self::assertSame($expected, FuelKeywords::isElectric($desc));
    }

    public static function electricCases(): array
    {
        return [
            ['Nabíjení elektromobilu', true],
            ['Dobíjení 23,5 kWh', true],
            ['Wallbox AC', true],
            ['Prémiová nafta', false],
            ['Natural 95', false],
            ['', false],
        ];
    }

    #[DataProvider('unitCases')]
    public function testCanonicalUnit(?string $unit, string $desc, string $expected): void
    {
        self::assertSame($expected, FuelKeywords::canonicalUnit($unit, $desc));
    }

    public static function unitCases(): array
    {
        return [
            // Explicitní jednotka kWh vyhrává.
            ['kWh', 'Nabíjení', 'kWh'],
            ['kwh', '', 'kWh'],
            // Bez jednoznačné jednotky rozhoduje popis.
            [null, 'Dobíjení elektromobilu', 'kWh'],
            ['', 'AC nabíjení', 'kWh'],
            // Kapalné palivo zůstává v litrech.
            ['l', 'Natural 95', 'l'],
            [null, 'Prémiová nafta', 'l'],
            // Litrová jednotka přebije i elektrický popis (ochrana proti chybnému dokladu).
            ['l', 'Nabíjení', 'l'],
        ];
    }

    public function testNonFuelService(): void
    {
        self::assertTrue(FuelKeywords::isNonFuelService('Mytí vozu'));
        self::assertTrue(FuelKeywords::isNonFuelService('Plošná cena'));
        self::assertFalse(FuelKeywords::isNonFuelService('Prémiová nafta'));
    }

    public function testNormalizeStripsDiacritics(): void
    {
        self::assertSame('premiova nafta', FuelKeywords::normalize('  Prémiová   Nafta '));
    }
}
