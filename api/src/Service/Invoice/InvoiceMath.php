<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Pure-function výpočty částek faktury — bez DB, bez závislostí.
 * Volá se z `InvoiceCalculator` (DB layer) i přímo z testů.
 *
 * Pravidla (režim ZDOLA, default — ceny položek jsou BEZ DPH):
 *  - Per item: total_without_vat = round(quantity * unit_price, 2)
 *              total_vat         = round(total_without_vat * (rate / 100), 2)
 *              total_with_vat    = total_without_vat + total_vat
 *  - Reverse charge (přenesená daňová povinnost): nominální sazba (21 %) ZŮSTÁVÁ pro
 *    zobrazení i breakdown, ale daň = 0 (dodavatel ji nevybírá, odvede ji zákazník).
 *  - Faktura: SUM jednotlivých položek
 *
 * Režim SHORA ($pricesIncludeVat = true — ceny položek jsou VČETNĚ DPH, typicky
 * účtenky/paragony/B2C): zdrojem pravdy řádku je cena S DPH (gross). DPH se počítá
 * koeficientovou metodou (§ 37 ZDPH): vat = round(gross * rate/(100+rate), 2),
 * base = gross - vat. Aby součet řádkové daně přesně odpovídal dani z celkového
 * základu dané sazby (a tedy sedělo DPH přiznání / KH / kniha DPH, které sčítají
 * uložené řádkové total_vat), dorovnáme zaokrouhlovací reziduum per sazba na
 * řádku s nejvyšší částkou (rounding distribution).
 */
final class InvoiceMath
{
    /**
     * @param list<array{quantity: float|int, unit_price_without_vat: float|int, vat_rate_snapshot: float|int}> $items
     *        V režimu shora je `unit_price_without_vat` chápán jako cena S DPH (gross).
     * @return array{
     *     items: list<array{base: float, vat: float, with: float, rate: float}>,
     *     totals: array{without_vat: float, vat: float, with_vat: float},
     *     vat_breakdown: list<array{rate: float, base: float, vat: float}>
     * }
     */
    public static function compute(array $items, bool $reverseCharge = false, bool $pricesIncludeVat = false): array
    {
        if ($pricesIncludeVat) {
            return self::computeTopDown($items, $reverseCharge);
        }

        $perItem = [];
        $totalWithoutVat = 0.0;
        $totalVat = 0.0;
        $vatBuckets = [];

        foreach ($items as $item) {
            $qty   = (float) $item['quantity'];
            $price = (float) $item['unit_price_without_vat'];
            // Nominální sazba zůstává (zobrazení + breakdown bucket); u RC se nuluje jen DAŇ.
            $rate  = (float) $item['vat_rate_snapshot'];

            $base = round($qty * $price, 2);
            $vat  = $reverseCharge ? 0.0 : round($base * ($rate / 100.0), 2);
            $with = round($base + $vat, 2);

            $perItem[] = ['base' => $base, 'vat' => $vat, 'with' => $with, 'rate' => $rate];
            $totalWithoutVat += $base;
            $totalVat        += $vat;

            $key = number_format($rate, 2, '.', '');
            if (!isset($vatBuckets[$key])) {
                $vatBuckets[$key] = ['rate' => $rate, 'base' => 0.0, 'vat' => 0.0];
            }
            $vatBuckets[$key]['base'] += $base;
            $vatBuckets[$key]['vat']  += $vat;
        }

        $totalWithoutVat = round($totalWithoutVat, 2);
        $totalVat        = round($totalVat, 2);
        $totalWithVat    = round($totalWithoutVat + $totalVat, 2);

        $breakdown = array_values(array_map(static fn (array $b) => [
            'rate' => $b['rate'],
            'base' => round($b['base'], 2),
            'vat'  => round($b['vat'], 2),
        ], $vatBuckets));
        usort($breakdown, static fn ($a, $b) => $b['rate'] <=> $a['rate']);

        return [
            'items'         => $perItem,
            'totals'        => [
                'without_vat' => $totalWithoutVat,
                'vat'         => $totalVat,
                'with_vat'    => $totalWithVat,
            ],
            'vat_breakdown' => $breakdown,
        ];
    }

    /**
     * Režim SHORA — ceny položek jsou včetně DPH (gross). Viz docblock třídy.
     *
     * @param list<array{quantity: float|int, unit_price_without_vat: float|int, vat_rate_snapshot: float|int}> $items
     * @return array{
     *     items: list<array{base: float, vat: float, with: float, rate: float}>,
     *     totals: array{without_vat: float, vat: float, with_vat: float},
     *     vat_breakdown: list<array{rate: float, base: float, vat: float}>
     * }
     */
    private static function computeTopDown(array $items, bool $reverseCharge): array
    {
        // 1. průchod — per řádek gross + provizorní daň koeficientem; seskup dle sazby.
        $lines = [];      // i => ['gross','rate','vat','base']
        $rateGroups = []; // rateKey => ['rate','grossSum','idx'[],'maxIdx','maxGross']
        foreach ($items as $i => $item) {
            $qty   = (float) $item['quantity'];
            $price = (float) $item['unit_price_without_vat']; // v tomto režimu = cena S DPH
            $rate  = (float) $item['vat_rate_snapshot'];

            $gross = round($qty * $price, 2);
            // Koeficient rate/(100+rate); u rate=0 i RC vychází daň 0.
            $vat   = ($reverseCharge || $rate <= 0.0) ? 0.0 : round($gross * $rate / (100.0 + $rate), 2);
            $base  = round($gross - $vat, 2);
            $lines[$i] = ['gross' => $gross, 'rate' => $rate, 'vat' => $vat, 'base' => $base];

            $key = number_format($rate, 2, '.', '');
            if (!isset($rateGroups[$key])) {
                $rateGroups[$key] = ['rate' => $rate, 'grossSum' => 0.0, 'idx' => [], 'maxIdx' => $i, 'maxGross' => -1.0];
            }
            $rateGroups[$key]['grossSum'] += $gross;
            $rateGroups[$key]['idx'][] = $i;
            if (abs($gross) > $rateGroups[$key]['maxGross']) {
                $rateGroups[$key]['maxGross'] = abs($gross);
                $rateGroups[$key]['maxIdx']   = $i;
            }
        }

        // 2. průchod — dorovnat zaokrouhlovací reziduum daně per sazba na nejsilnějším
        // řádku, aby SUM(řádkový vat) == daň z celkového gross dané sazby (koeficient).
        foreach ($rateGroups as $g) {
            if ($reverseCharge || $g['rate'] <= 0.0) {
                continue; // daň 0 → není co dorovnávat
            }
            $grossSum  = round($g['grossSum'], 2);
            $targetVat = round($grossSum * $g['rate'] / (100.0 + $g['rate']), 2);
            $sumLineVat = 0.0;
            foreach ($g['idx'] as $i) {
                $sumLineVat += $lines[$i]['vat'];
            }
            $residual = round($targetVat - round($sumLineVat, 2), 2);
            if ($residual !== 0.0) {
                $mi = $g['maxIdx'];
                $lines[$mi]['vat']  = round($lines[$mi]['vat'] + $residual, 2);
                $lines[$mi]['base'] = round($lines[$mi]['gross'] - $lines[$mi]['vat'], 2);
            }
        }

        // 3. sestav výstup v původním pořadí.
        $perItem = [];
        $totalWithoutVat = 0.0;
        $totalVat = 0.0;
        $vatBuckets = [];
        foreach ($items as $i => $item) {
            $l = $lines[$i];
            $perItem[] = ['base' => $l['base'], 'vat' => $l['vat'], 'with' => $l['gross'], 'rate' => $l['rate']];
            $totalWithoutVat += $l['base'];
            $totalVat        += $l['vat'];

            $key = number_format($l['rate'], 2, '.', '');
            if (!isset($vatBuckets[$key])) {
                $vatBuckets[$key] = ['rate' => $l['rate'], 'base' => 0.0, 'vat' => 0.0];
            }
            $vatBuckets[$key]['base'] += $l['base'];
            $vatBuckets[$key]['vat']  += $l['vat'];
        }

        $totalWithoutVat = round($totalWithoutVat, 2);
        $totalVat        = round($totalVat, 2);
        $totalWithVat    = round($totalWithoutVat + $totalVat, 2);

        $breakdown = array_values(array_map(static fn (array $b) => [
            'rate' => $b['rate'],
            'base' => round($b['base'], 2),
            'vat'  => round($b['vat'], 2),
        ], $vatBuckets));
        usort($breakdown, static fn ($a, $b) => $b['rate'] <=> $a['rate']);

        return [
            'items'         => $perItem,
            'totals'        => [
                'without_vat' => $totalWithoutVat,
                'vat'         => $totalVat,
                'with_vat'    => $totalWithVat,
            ],
            'vat_breakdown' => $breakdown,
        ];
    }
}
