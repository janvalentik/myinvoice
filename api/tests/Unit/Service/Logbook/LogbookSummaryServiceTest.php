<?php

declare(strict_types=1);

namespace MyInvoice\Tests\Unit\Service\Logbook;

use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Repository\TripRepository;
use MyInvoice\Service\Logbook\LogbookSummaryService;
use PHPUnit\Framework\TestCase;

/**
 * Souhrn knihy jízd — zejména DVOJÍ evidence paliva (l) a elektřiny (kWh) u PHEV:
 * litry a kWh se nesmí sčítat do jedné spotřeby, počítají se dvě samostatné.
 */
final class LogbookSummaryServiceTest extends TestCase
{
    public function testPhevSplitsFuelAndElectricityIntoTwoConsumptions(): void
    {
        $trips = $this->createStub(TripRepository::class);
        $trips->method('listForTenant')->willReturn([
            $this->trip(60.0), // celkem 100 km, služební
            $this->trip(40.0),
        ]);

        $fuelings = $this->createStub(FuelingRepository::class);
        $fuelings->method('listForTenant')->willReturn([
            $this->fueling(5.0, 'l', 200.0),     // 5 l benzínu
            $this->fueling(18.0, 'kWh', 120.0),  // 18 kWh dobito
        ]);

        $service = new LogbookSummaryService($trips, $fuelings);
        $out = $service->periodSummary(1, '2026-01-01', '2026-12-31');

        self::assertCount(1, $out['vehicles']);
        $v = $out['vehicles'][0];

        self::assertSame(100.0, $v['km']);
        self::assertSame(2, $v['fuel_count']);

        // Palivo (litry) — 5 l / 100 km => 5,0 l/100 km.
        self::assertSame(5.0, $v['liters']);
        self::assertSame(1, $v['liters_count']);
        self::assertSame(5.0, $v['avg_consumption']);
        self::assertFalse($v['liters_incomplete']);

        // Elektřina (kWh) — 18 kWh / 100 km => 18,0 kWh/100 km.
        self::assertSame(18.0, $v['kwh']);
        self::assertSame(1, $v['kwh_count']);
        self::assertSame(18.0, $v['avg_consumption_kwh']);
        self::assertFalse($v['kwh_incomplete']);

        // Náklad je společný (litry + kWh).
        self::assertSame(320.0, $v['fuel_cost']);

        // Totals zrcadlí obě jednotky.
        self::assertSame(5.0, $out['totals']['liters']);
        self::assertSame(18.0, $out['totals']['kwh']);
        self::assertSame(5.0, $out['totals']['avg_consumption']);
        self::assertSame(18.0, $out['totals']['avg_consumption_kwh']);
    }

    public function testMissingChargedAmountMarksKwhIncompleteButFuelStaysComplete(): void
    {
        $trips = $this->createStub(TripRepository::class);
        $trips->method('listForTenant')->willReturn([$this->trip(100.0)]);

        $fuelings = $this->createStub(FuelingRepository::class);
        $fuelings->method('listForTenant')->willReturn([
            $this->fueling(7.0, 'l', 250.0),
            $this->fueling(null, 'kWh', 90.0), // dobíjení bez známých kWh
        ]);

        $service = new LogbookSummaryService($trips, $fuelings);
        $v = $service->periodSummary(1, '2026-01-01', '2026-12-31')['vehicles'][0];

        self::assertFalse($v['liters_incomplete']);
        self::assertTrue($v['kwh_incomplete']);
        self::assertSame(0.0, $v['kwh']);            // žádné kWh k sečtení
        self::assertNull($v['avg_consumption_kwh']); // bez kWh nelze spotřebu spočítat
        self::assertSame(7.0, $v['avg_consumption']);
    }

    /** @return array<string,mixed> */
    private function trip(float $km): array
    {
        return [
            'id' => 1, 'car_id' => 1, 'car_registration' => '1AB 2345', 'car_name' => 'PHEV',
            'distance_km' => $km, 'category_is_private' => false,
            'odometer_start' => null, 'odometer_end' => null,
            'trip_date' => '2026-03-01', 'time_start' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function fueling(?float $qty, string $unit, float $amount): array
    {
        return [
            'car_id' => 1, 'car_registration' => '1AB 2345', 'car_name' => 'PHEV',
            'amount_with_vat' => $amount, 'quantity' => $qty, 'unit' => $unit,
        ];
    }
}
