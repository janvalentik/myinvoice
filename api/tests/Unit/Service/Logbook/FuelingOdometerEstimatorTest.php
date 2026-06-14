<?php

declare(strict_types=1);

namespace Tests\Unit\Service\Logbook;

use MyInvoice\Service\Logbook\FuelingOdometerEstimator;
use PHPUnit\Framework\TestCase;

final class FuelingOdometerEstimatorTest extends TestCase
{
    /** @var list<array{date:string,time_start:?int,time_end:?int,odo_start:?int,odo_end:?int}> */
    private array $trips;

    protected function setUp(): void
    {
        $this->trips = [
            ['date' => '2026-01-10', 'time_start' => 8 * 60, 'time_end' => 12 * 60, 'odo_start' => 100000, 'odo_end' => 100200],
            ['date' => '2026-01-20', 'time_start' => null, 'time_end' => null, 'odo_start' => 100500, 'odo_end' => 100800],
        ];
    }

    /** Čas tankování před začátkem jízdy → tankováno na začátku (odo_start). */
    public function testTimeBeforeTripStartUsesStart(): void
    {
        $f = ['fueled_date' => '2026-01-10', 'fueled_time' => '07:30', 'quantity' => 50.0];
        self::assertSame(100000, FuelingOdometerEstimator::estimate($f, $this->trips, 7.0));
    }

    /** Čas tankování po konci jízdy → tankováno na konci (odo_end). */
    public function testTimeAfterTripEndUsesEnd(): void
    {
        $f = ['fueled_date' => '2026-01-10', 'fueled_time' => '13:00', 'quantity' => 50.0];
        self::assertSame(100200, FuelingOdometerEstimator::estimate($f, $this->trips, 7.0));
    }

    /** Bez času: velké tankování (dojezd ≥ ujeto) → spíš na začátku jízdy. */
    public function testLargeFillWithoutTimeUsesStart(): void
    {
        // 50 l / 7 * 100 = 714 km ≥ 200 km ujeto → start
        $f = ['fueled_date' => '2026-01-10', 'fueled_time' => null, 'quantity' => 50.0];
        self::assertSame(100000, FuelingOdometerEstimator::estimate($f, $this->trips, 7.0));
    }

    /** Bez času: malé doplnění (dojezd < ujeto) → spíš na konci jízdy. */
    public function testSmallFillWithoutTimeUsesEnd(): void
    {
        // 10 l / 7 * 100 = 143 km < 200 km ujeto → konec
        $f = ['fueled_date' => '2026-01-10', 'fueled_time' => null, 'quantity' => 10.0];
        self::assertSame(100200, FuelingOdometerEstimator::estimate($f, $this->trips, 7.0));
    }

    /** Bez jízdy ve stejný den → konec poslední předchozí jízdy. */
    public function testNoSameDayTripUsesLastEndBefore(): void
    {
        $f = ['fueled_date' => '2026-01-15', 'fueled_time' => '10:00', 'quantity' => 40.0];
        self::assertSame(100200, FuelingOdometerEstimator::estimate($f, $this->trips, 7.0));
    }

    /** Tankování úplně před první jízdou → začátek první další jízdy. */
    public function testBeforeAllTripsUsesFirstStart(): void
    {
        $f = ['fueled_date' => '2026-01-01', 'fueled_time' => '10:00', 'quantity' => 40.0];
        self::assertSame(100000, FuelingOdometerEstimator::estimate($f, $this->trips, 7.0));
    }
}
