<?php

declare(strict_types=1);

namespace MyInvoice\Service\Logbook;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Orientační stav tachometru u tankování bez vlastního záznamu — z knihy jízd.
 *
 * Heuristika (když tankování spadá do dne s jízdou): tankování mohlo proběhnout na
 * začátku jízdy (natankuji a jedu) nebo na konci (dojedu a doplním). Rozhoduje se:
 *   1) podle ČASU, když je u tankování i jízdy k dispozici (před začátkem → start, po konci → konec),
 *   2) jinak podle SPOTŘEBY: pokrylo-li natankované množství ujetou vzdálenost dne
 *      (litry/spotřeba ≥ ujeto), šlo spíš o tankování na začátku (plná nádrž před jízdou),
 *      jinak o doplnění na konci.
 * Bez jízdy ve stejný den se vezme konec poslední předchozí jízdy (resp. začátek první další).
 * Výsledek se ořízne do intervalu sousedních jízd (loOdo..hiOdo).
 */
final class FuelingOdometerEstimator
{
    /** Záložní spotřeba (l/100 km), když ji nelze z dat spočítat. */
    private const DEFAULT_CONSUMPTION = 7.0;

    public function __construct(private readonly Connection $db) {}

    /**
     * Doplní `odometer_estimated` tankováním bez vlastního `odometer`.
     *
     * @param list<array<string,mixed>> $fuelings
     * @return list<array<string,mixed>>
     */
    public function annotate(int $supplierId, array $fuelings): array
    {
        $carIds = [];
        foreach ($fuelings as $f) {
            if (($f['odometer'] ?? null) === null && ($f['car_id'] ?? null) !== null) {
                $carIds[(int) $f['car_id']] = true;
            }
        }
        if ($carIds === []) return $fuelings;
        $carIds = array_keys($carIds);

        $trips = $this->tripsByCar($supplierId, $carIds);
        $consumption = $this->consumptionByCar($supplierId, $carIds);

        foreach ($fuelings as &$f) {
            if (($f['odometer'] ?? null) !== null) continue;
            $carId = (int) ($f['car_id'] ?? 0);
            if ($carId === 0 || empty($trips[$carId])) continue;
            $est = self::estimate($f, $trips[$carId], $consumption[$carId] ?? self::DEFAULT_CONSUMPTION);
            if ($est !== null) $f['odometer_estimated'] = $est;
        }
        unset($f);
        return $fuelings;
    }

    /**
     * Test seam: odhad pro jedno tankování nad připravenými jízdami (časy jako minuty).
     *
     * @param list<array{date:string,time_start:?int,time_end:?int,odo_start:?int,odo_end:?int}> $trips
     */
    public static function estimate(array $f, array $trips, float $consumption): ?int
    {
        $date = (string) ($f['fueled_date'] ?? '');
        $ft = self::minutes($f['fueled_time'] ?? null);

        $loOdo = null; $hiOdo = null;           // konec poslední jízdy před dnem / začátek první po dni
        $sameStart = null; $sameEnd = null;     // span jízd v týž den
        $sameStartT = null; $sameEndT = null;

        foreach ($trips as $t) {
            if ($t['date'] < $date) {
                if ($t['odo_end'] !== null) $loOdo = $loOdo === null ? $t['odo_end'] : max($loOdo, $t['odo_end']);
            } elseif ($t['date'] > $date) {
                if ($t['odo_start'] !== null) $hiOdo = $hiOdo === null ? $t['odo_start'] : min($hiOdo, $t['odo_start']);
            } else {
                if ($t['odo_start'] !== null) {
                    $sameStart = $sameStart === null ? $t['odo_start'] : min($sameStart, $t['odo_start']);
                    if ($t['time_start'] !== null) $sameStartT = $sameStartT === null ? $t['time_start'] : min($sameStartT, $t['time_start']);
                }
                if ($t['odo_end'] !== null) {
                    $sameEnd = $sameEnd === null ? $t['odo_end'] : max($sameEnd, $t['odo_end']);
                    if ($t['time_end'] !== null) $sameEndT = $sameEndT === null ? $t['time_end'] : max($sameEndT, $t['time_end']);
                }
            }
        }

        if ($sameStart !== null && $sameEnd !== null) {
            $dist = max(0, $sameEnd - $sameStart);
            // 1) Čas tankování vs. jízdy.
            if ($ft !== null) {
                if ($sameStartT !== null && $ft <= $sameStartT) return self::clamp($sameStart, $loOdo, $hiOdo);
                if ($sameEndT !== null && $ft >= $sameEndT)     return self::clamp($sameEnd, $loOdo, $hiOdo);
            }
            // 2) Spotřeba: pokrylo natankované množství ujetou vzdálenost dne?
            $liters = isset($f['quantity']) && $f['quantity'] !== null ? (float) $f['quantity'] : null;
            if ($liters !== null && $consumption > 0 && $dist > 0) {
                $range = $liters / $consumption * 100.0;
                return self::clamp($range >= $dist ? $sameStart : $sameEnd, $loOdo, $hiOdo);
            }
            return self::clamp($sameEnd, $loOdo, $hiOdo);
        }

        return $loOdo ?? $hiOdo;
    }

    private static function clamp(int $x, ?int $lo, ?int $hi): int
    {
        if ($lo !== null && $x < $lo) $x = $lo;
        if ($hi !== null && $x > $hi) $x = $hi;
        return $x;
    }

    /** „HH:MM[:SS]" → minuty od půlnoci, jinak null. */
    private static function minutes(mixed $v): ?int
    {
        if ($v === null) return null;
        if (preg_match('/^(\d{1,2}):(\d{2})/', (string) $v, $m)) return (int) $m[1] * 60 + (int) $m[2];
        return null;
    }

    /**
     * @param list<int> $carIds
     * @return array<int, list<array{date:string,time_start:?int,time_end:?int,odo_start:?int,odo_end:?int}>>
     */
    private function tripsByCar(int $supplierId, array $carIds): array
    {
        $in = implode(',', array_fill(0, count($carIds), '?'));
        $stmt = $this->db->pdo()->prepare(
            "SELECT car_id, trip_date, time_start, time_end, odometer_start, odometer_end
               FROM trips
              WHERE supplier_id = ? AND car_id IN ($in)
              ORDER BY car_id, trip_date, id"
        );
        $stmt->execute(array_merge([$supplierId], $carIds));
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $out[(int) $r['car_id']][] = [
                'date'       => (string) $r['trip_date'],
                'time_start' => self::minutes($r['time_start'] ?? null),
                'time_end'   => self::minutes($r['time_end'] ?? null),
                'odo_start'  => $r['odometer_start'] !== null ? (int) $r['odometer_start'] : null,
                'odo_end'    => $r['odometer_end'] !== null ? (int) $r['odometer_end'] : null,
            ];
        }
        return $out;
    }

    /**
     * Spotřeba l/100 km per auto = Σ litry / Σ ujeto * 100 (jinak výchozí).
     *
     * @param list<int> $carIds
     * @return array<int, float>
     */
    private function consumptionByCar(int $supplierId, array $carIds): array
    {
        $in = implode(',', array_fill(0, count($carIds), '?'));
        $liters = $this->sumByCar(
            "SELECT car_id, COALESCE(SUM(quantity),0) AS s FROM fuelings
              WHERE supplier_id = ? AND car_id IN ($in) AND quantity IS NOT NULL GROUP BY car_id",
            $supplierId, $carIds
        );
        $km = $this->sumByCar(
            "SELECT car_id, COALESCE(SUM(distance_km),0) AS s FROM trips
              WHERE supplier_id = ? AND car_id IN ($in) GROUP BY car_id",
            $supplierId, $carIds
        );
        $out = [];
        foreach ($carIds as $cid) {
            $l = $liters[$cid] ?? 0.0;
            $k = $km[$cid] ?? 0.0;
            $out[$cid] = ($l > 0 && $k > 0) ? $l / $k * 100.0 : self::DEFAULT_CONSUMPTION;
        }
        return $out;
    }

    /**
     * @param list<int> $carIds
     * @return array<int, float>
     */
    private function sumByCar(string $sql, int $supplierId, array $carIds): array
    {
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute(array_merge([$supplierId], $carIds));
        $out = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) $out[(int) $r['car_id']] = (float) $r['s'];
        return $out;
    }
}
