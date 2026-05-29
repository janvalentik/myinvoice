<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

/**
 * Pure-function výpočty částek faktury — bez DB, bez závislostí.
 * Volá se z `InvoiceCalculator` (DB layer) i přímo z testů.
 *
 * Pravidla:
 *  - Per item: total_without_vat = round(quantity * unit_price, 2)
 *              total_vat         = round(total_without_vat * (rate / 100), 2)
 *              total_with_vat    = total_without_vat + total_vat
 *  - Reverse charge (přenesená daňová povinnost): nominální sazba (21 %) ZŮSTÁVÁ pro
 *    zobrazení i breakdown, ale daň = 0 (dodavatel ji nevybírá, odvede ji zákazník).
 *  - Faktura: SUM jednotlivých položek
 */
final class InvoiceMath
{
    /**
     * @param list<array{quantity: float|int, unit_price_without_vat: float|int, vat_rate_snapshot: float|int}> $items
     * @return array{
     *     items: list<array{base: float, vat: float, with: float, rate: float}>,
     *     totals: array{without_vat: float, vat: float, with_vat: float},
     *     vat_breakdown: list<array{rate: float, base: float, vat: float}>
     * }
     */
    public static function compute(array $items, bool $reverseCharge = false): array
    {
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
}
