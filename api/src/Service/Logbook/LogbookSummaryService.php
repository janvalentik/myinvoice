<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Repository\FuelingRepository;
use MyInvoice\Repository\TripRepository;

/**
 * Daňové/účetní souhrny knihy jízd za období — per vozidlo:
 *   • stav tachometru na začátku/konci období (POČÍTANÝ z jízd, nezadává se),
 *   • ujeto celkem + rozpad služební / soukromé / nezařazené km a poměr (krácení),
 *   • tankování: počet, litry, náklad, průměrná spotřeba (l/100 km) + sanity check,
 *   • návaznost tachometru (mezery/překryvy mezi po sobě jdoucími jízdami),
 *   • srovnání s paušálem na dopravu (5000 / 4000 Kč, info — ne závazný výpočet).
 *
 * Vše čistě READ — žádný zápis. Slouží záložce Souhrny i exportům.
 */
final class LogbookSummaryService
{
    private const PAUSAL_FULL = 5000;   // Kč/měsíc — vozidlo jen pro podnikání
    private const PAUSAL_REDUCED = 4000; // Kč/měsíc — vozidlo i pro soukromé účely (§ 24/2/zt)

    public function __construct(
        private readonly TripRepository $trips,
        private readonly FuelingRepository $fuelings,
    ) {}

    /**
     * @return array<string,mixed>{vehicles: list<array<string,mixed>>, totals: array<string,mixed>}
     */
    public function periodSummary(int $supplierId, string $dateFrom, string $dateTo): array
    {
        $trips = $this->trips->listForTenant($supplierId, ['date_from' => $dateFrom, 'date_to' => $dateTo]);
        $fuelings = $this->fuelings->listForTenant($supplierId, ['date_from' => $dateFrom, 'date_to' => $dateTo]);

        /** @var array<int,array<string,mixed>> $byCar */
        $byCar = [];

        foreach ($trips as $t) {
            $cid = (int) $t['car_id'];
            $g = &$this->ensureCar($byCar, $cid, (string) ($t['car_registration'] ?? ''), $t['car_name'] ?? null);
            $dist = (float) $t['distance_km'];
            $g['trips_count']++;
            $g['km'] += $dist;
            if ($t['category_is_private'] === true) $g['private_km'] += $dist;
            elseif ($t['category_is_private'] === false) $g['business_km'] += $dist;
            else $g['uncategorized_km'] += $dist;

            $os = $t['odometer_start'] !== null ? (int) $t['odometer_start'] : null;
            $oe = $t['odometer_end'] !== null ? (int) $t['odometer_end'] : null;
            if ($os !== null) $g['odometer_start'] = $g['odometer_start'] === null ? $os : min((int) $g['odometer_start'], $os);
            if ($oe !== null) $g['odometer_end'] = $g['odometer_end'] === null ? $oe : max((int) $g['odometer_end'], $oe);

            $g['_months'][substr((string) $t['trip_date'], 0, 7)] = true;
            $g['_trips'][] = ['date' => (string) $t['trip_date'], 'time' => (string) ($t['time_start'] ?? ''),
                              'os' => $os, 'oe' => $oe, 'id' => (int) $t['id']];
            unset($g);
        }

        foreach ($fuelings as $f) {
            if ($f['car_id'] === null) continue;
            $cid = (int) $f['car_id'];
            $g = &$this->ensureCar($byCar, $cid, (string) ($f['car_registration'] ?? ''), $f['car_name'] ?? null);
            $g['fuel_count']++;
            $g['fuel_cost'] += (float) $f['amount_with_vat'];
            // Elektrické dobíjení (kWh) se eviduje a počítá zvlášť od kapalného paliva (l).
            if (stripos((string) ($f['unit'] ?? 'l'), 'kwh') !== false) {
                $g['kwh_count']++;
                if ($f['quantity'] !== null) $g['kwh'] += (float) $f['quantity'];
                else $g['kwh_incomplete'] = true;
            } else {
                $g['liters_count']++;
                if ($f['quantity'] !== null) $g['liters'] += (float) $f['quantity'];
                else $g['liters_incomplete'] = true;
            }
            unset($g);
        }

        $vehicles = [];
        foreach ($byCar as $g) $vehicles[] = $this->finalize($g);

        return ['vehicles' => $vehicles, 'totals' => $this->totals($vehicles)];
    }

    /**
     * Najeté km po měsících pro daný rok + srovnání s předchozím rokem (12 hodnot).
     *
     * @return array{year:int, prev_year:int, current:list<float>, previous:list<float>}
     */
    public function monthlyKm(int $supplierId, int $year): array
    {
        return [
            'year' => $year,
            'prev_year' => $year - 1,
            'current' => $this->monthSums($supplierId, $year),
            'previous' => $this->monthSums($supplierId, $year - 1),
        ];
    }

    /** @return list<float> 12 hodnot (leden..prosinec) */
    private function monthSums(int $supplierId, int $year): array
    {
        $rows = $this->trips->listForTenant($supplierId, ['year' => $year]);
        $m = array_fill(0, 12, 0.0);
        foreach ($rows as $t) {
            $mo = (int) substr((string) $t['trip_date'], 5, 2) - 1;
            if ($mo >= 0 && $mo < 12) $m[$mo] += (float) $t['distance_km'];
        }
        return array_map(static fn ($v) => round($v, 1), $m);
    }

    /** Distinct roky, ve kterých existují jízdy — pro výběr období. @return list<int> */
    public function availableYears(int $supplierId): array
    {
        $rows = $this->trips->listForTenant($supplierId, []);
        $years = [];
        foreach ($rows as $t) $years[(int) substr((string) $t['trip_date'], 0, 4)] = true;
        $ys = array_keys($years);
        rsort($ys);
        return $ys;
    }

    /** @return array<string,mixed> reference do $byCar[$cid] */
    private function &ensureCar(array &$byCar, int $cid, string $reg, ?string $name): array
    {
        if (!isset($byCar[$cid])) {
            $byCar[$cid] = [
                'car_id' => $cid, 'registration' => $reg, 'name' => $name,
                'trips_count' => 0, 'km' => 0.0,
                'business_km' => 0.0, 'private_km' => 0.0, 'uncategorized_km' => 0.0,
                'odometer_start' => null, 'odometer_end' => null,
                'fuel_count' => 0, 'fuel_cost' => 0.0,
                'liters' => 0.0, 'liters_count' => 0, 'liters_incomplete' => false,
                'kwh' => 0.0, 'kwh_count' => 0, 'kwh_incomplete' => false,
                '_months' => [], '_trips' => [],
            ];
        }
        return $byCar[$cid];
    }

    /** @param array<string,mixed> $g */
    private function finalize(array $g): array
    {
        $km = (float) $g['km'];
        $private = (float) $g['private_km'];
        $business = (float) $g['business_km'];
        $uncat = (float) $g['uncategorized_km'];
        $liters = (float) $g['liters'];
        $kwh = (float) $g['kwh'];

        // Spotřeba l/100 km a kWh/100 km — počítáme vždy, když známe nějaké množství i km;
        // pokud množství není kompletní (některá tankování bez množství), je orientační (UI označí *).
        $consumption = ($km > 0 && $liters > 0) ? ($liters / $km * 100) : null;
        $consumptionKwh = ($km > 0 && $kwh > 0) ? ($kwh / $km * 100) : null;

        // Návaznost tachometru: seřadit jízdy chronologicky a hledat mezery/překryvy.
        $continuity = $this->continuityDetail($g['_trips']);

        // Paušál na dopravu (info): krácený, pokud byly soukromé km.
        $months = count($g['_months']);
        $rate = $private > 0 ? self::PAUSAL_REDUCED : self::PAUSAL_FULL;

        return [
            'car_id' => $g['car_id'],
            'registration' => $g['registration'],
            'name' => $g['name'],
            'trips_count' => $g['trips_count'],
            'km' => round($km, 1),
            'business_km' => round($business, 1),
            'private_km' => round($private, 1),
            'uncategorized_km' => round($uncat, 1),
            'private_ratio' => $km > 0 ? round($private / $km * 100, 1) : 0.0,
            'business_ratio' => $km > 0 ? round(($business + $uncat) / $km * 100, 1) : 0.0,
            'odometer_start' => $g['odometer_start'],
            'odometer_end' => $g['odometer_end'],
            'fuel_count' => $g['fuel_count'],
            'liters' => round($liters, 2),
            'liters_count' => (int) $g['liters_count'],
            'liters_incomplete' => (bool) $g['liters_incomplete'],
            'kwh' => round($kwh, 2),
            'kwh_count' => (int) $g['kwh_count'],
            'kwh_incomplete' => (bool) $g['kwh_incomplete'],
            'fuel_cost' => round((float) $g['fuel_cost'], 2),
            'avg_consumption' => $consumption !== null ? round($consumption, 1) : null,
            'avg_consumption_kwh' => $consumptionKwh !== null ? round($consumptionKwh, 1) : null,
            'continuity_issues' => count($continuity),
            'continuity_detail' => $continuity,
            'pausal_months' => $months,
            'pausal_rate' => $rate,
            'pausal_year' => $months * $rate,
        ];
    }

    /**
     * Nesrovnalosti v návaznosti tachometru (po seřazení dle data/času) — detailně,
     * ať uživatel vidí, KDE skok je. Gap > 0 = chybějící km (mezera), < 0 = překryv.
     *
     * @return list<array{prev_date:string, prev_end:int, date:string, start:int, gap:int}>
     */
    private function continuityDetail(array $trips): array
    {
        usort($trips, static function ($a, $b) {
            return [$a['date'], $a['time'], $a['id']] <=> [$b['date'], $b['time'], $b['id']];
        });
        $issues = [];
        $prev = null;
        foreach ($trips as $t) {
            if ($prev !== null && $t['os'] !== null && $t['os'] !== $prev['oe']) {
                $issues[] = [
                    'prev_date' => $prev['date'], 'prev_end' => (int) $prev['oe'],
                    'date' => $t['date'], 'start' => (int) $t['os'],
                    'gap' => (int) $t['os'] - (int) $prev['oe'],
                ];
            }
            if ($t['oe'] !== null) $prev = ['date' => $t['date'], 'oe' => $t['oe']];
        }
        return $issues;
    }

    /** @param list<array<string,mixed>> $vehicles */
    private function totals(array $vehicles): array
    {
        $km = $business = $private = $uncat = $liters = $kwh = $cost = 0.0;
        $trips = $fuel = 0; $issues = 0; $litersIncomplete = false; $kwhIncomplete = false;
        foreach ($vehicles as $v) {
            $km += $v['km']; $business += $v['business_km']; $private += $v['private_km'];
            $uncat += $v['uncategorized_km']; $liters += $v['liters']; $kwh += $v['kwh']; $cost += $v['fuel_cost'];
            $trips += $v['trips_count']; $fuel += $v['fuel_count']; $issues += $v['continuity_issues'];
            if ($v['liters_incomplete']) $litersIncomplete = true;
            if ($v['kwh_incomplete']) $kwhIncomplete = true;
        }
        $consumption = ($km > 0 && $liters > 0) ? round($liters / $km * 100, 1) : null;
        $consumptionKwh = ($km > 0 && $kwh > 0) ? round($kwh / $km * 100, 1) : null;
        return [
            'vehicles_count' => count($vehicles),
            'trips_count' => $trips, 'km' => round($km, 1),
            'business_km' => round($business, 1), 'private_km' => round($private, 1), 'uncategorized_km' => round($uncat, 1),
            'private_ratio' => $km > 0 ? round($private / $km * 100, 1) : 0.0,
            'fuel_count' => $fuel, 'liters' => round($liters, 2), 'kwh' => round($kwh, 2), 'fuel_cost' => round($cost, 2),
            'avg_consumption' => $consumption, 'avg_consumption_kwh' => $consumptionKwh,
            'liters_incomplete' => $litersIncomplete, 'kwh_incomplete' => $kwhIncomplete, 'continuity_issues' => $issues,
        ];
    }
}
