<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Invoice;

use MyInvoice\Service\Invoice\VarsymbolGenerator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Testuje makePeriodKey() — privátní metoda pro převod period scope (year/month/none)
 * na klíč pro invoice_counters.period.
 *
 * Klíčové invarianty:
 *   - month → 'YYYYMM' (zpětná kompatibilita s legacy CHAR(6))
 *   - year  → 'YYYY'
 *   - none  → 'ALL'
 *   - neznámý period → fallback na month (silent default)
 */
final class VarsymbolPeriodKeyTest extends TestCase
{
    private VarsymbolGenerator $gen;
    private \ReflectionMethod $makePeriodKey;

    protected function setUp(): void
    {
        $this->gen = (new ReflectionClass(VarsymbolGenerator::class))->newInstanceWithoutConstructor();
        $this->makePeriodKey = (new ReflectionClass(VarsymbolGenerator::class))->getMethod('makePeriodKey');
    }

    private function key(string $period, string $date): string
    {
        return $this->makePeriodKey->invoke($this->gen, $period, new \DateTimeImmutable($date));
    }

    public function testMonthScopeProducesYYYYMM(): void
    {
        self::assertSame('202604', $this->key('month', '2026-04-15'));
        self::assertSame('202612', $this->key('month', '2026-12-31'));
        self::assertSame('202601', $this->key('month', '2026-01-01'));
    }

    public function testYearScopeProducesYYYY(): void
    {
        self::assertSame('2026', $this->key('year', '2026-04-15'));
        self::assertSame('2025', $this->key('year', '2025-12-31'));
        self::assertSame('2027', $this->key('year', '2027-01-01'));
    }

    public function testNoneScopeProducesAll(): void
    {
        // Period 'none' = jediný globální counter pro daný (supplier, type)
        self::assertSame('ALL', $this->key('none', '2026-04-15'));
        self::assertSame('ALL', $this->key('none', '1970-01-01'));
        self::assertSame('ALL', $this->key('none', '2099-12-31'));
    }

    public function testUnknownPeriodFallsBackToMonth(): void
    {
        // Defensive — neplatné hodnoty (špatný DB záznam, future enum value)
        // se chovají jako 'month' (legacy default), nepadají.
        self::assertSame('202604', $this->key('quarter', '2026-04-15'));
        self::assertSame('202604', $this->key('', '2026-04-15'));
    }

    public function testMonthBoundary(): void
    {
        // Hraniční datum — poslední ms měsíce vs první ms následujícího
        self::assertSame('202604', $this->key('month', '2026-04-30 23:59:59'));
        self::assertSame('202605', $this->key('month', '2026-05-01 00:00:00'));
    }

    public function testYearBoundary(): void
    {
        self::assertSame('2026', $this->key('year', '2026-12-31 23:59:59'));
        self::assertSame('2027', $this->key('year', '2027-01-01 00:00:00'));
    }
}
