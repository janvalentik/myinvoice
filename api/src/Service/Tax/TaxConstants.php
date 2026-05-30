<?php

declare(strict_types=1);

namespace MyInvoice\Service\Tax;

/**
 * Roční daňové konstanty (CZ) — referenční DEFAULTY / fallback.
 *
 * V produkci se čtou přes {@see \MyInvoice\Repository\TaxConstantsRepository},
 * který vrací admin override z tabulky `tax_constants` (migrace 0079), a teprve
 * když override pro daný rok není, spadne na tyhle hodnoty. Tahle třída je tedy
 * jediný zdroj výchozích čísel v kódu (a fallback pro testy/CLI bez DB).
 *
 * Hodnoty ověřeny k 2026-05 dle Finanční správy / ČSSZ / VZP:
 *  - paušální daň 2025 8 716/16 745/27 139 Kč/měs, 2026 9 984/16 745/27 139 Kč/měs
 *  - průměrná mzda 2025 46 557 Kč, 2026 48 967 Kč → hranice 23 % = 36×
 *  - vyměřovací základ: sociální 55 % zisku, zdravotní 50 % zisku (§7)
 *  - min. roční vyměřovací základ: soc. hlavní 35 % (2025) / 40 % (2026) prům. mzdy,
 *    zdravotní 50 % prům. mzdy × 12
 */
final class TaxConstants
{
    /**
     * @return array<string, mixed> konstanty pro daný rok
     */
    public static function forYear(int $year): array
    {
        return self::TABLE[$year] ?? self::TABLE[2026];
    }

    public static function availableYears(): array
    {
        return array_keys(self::TABLE);
    }

    private const TABLE = [
        2025 => [
            'year' => 2025,
            // Paušální daň — roční částka dle pásma (12× měsíční záloha)
            'pausal_annual' => ['band1' => 104592, 'band2' => 200940, 'band3' => 325668],
            // Stropy pásem dle příjmu × výdajového paušálu (§7a ZDP).
            // Klíč = sazba výdajového paušálu; hodnota = strop pro [band1, band2, band3].
            // Pozn.: SummaryAction::FLAT_TAX_BANDS drží zjednodušenou (činnost-neutrální)
            // variantu týchž stropů; sjednotit dashboard na tento zdroj je follow-up.
            'band_ceilings' => [
                30 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                40 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                60 => ['band1' => 1500000, 'band2' => 2000000, 'band3' => 2000000],
                80 => ['band1' => 2000000, 'band2' => 2000000, 'band3' => 2000000],
            ],
            // Slevy a zvýhodnění
            'credit_taxpayer' => 30840,
            'credit_spouse'   => 24840,
            'child_credits'   => [15204, 22320, 27840], // 1., 2., 3.+ dítě (3.+ se opakuje)
            // Daň z příjmu
            'tax_rate_low'        => 0.15,
            'tax_rate_high'       => 0.23,
            'tax_high_threshold'  => 1676052, // 36× průměrné mzdy 2025 (46 557)
            // Pojistné — sazby a vyměřovací základy (% ze zisku §7)
            'social_rate'         => 0.292,
            'health_rate'         => 0.135,
            'social_assessment_pct' => 0.55, // sociální: 55 % zisku
            'health_assessment_pct' => 0.50, // zdravotní: 50 % zisku
            'social_min_base_main'      => 195540, // 35 % × 46 557 × 12
            'social_min_base_secondary' => 61476,  // min. roční zákl. vedlejší činnost
            'health_min_base'           => 279342, // 50 % × 46 557 × 12
            // Výdajové paušály — strop uplatnitelných výdajů dle sazby
            'expense_caps' => [30 => 600000, 40 => 800000, 60 => 1200000, 80 => 1600000],
            // Odpočty — stropy
            'mortgage_cap' => 150000,
            'pension_cap'  => 48000,
            // DPH
            'vat_limit_low'  => 2000000,
            'vat_limit_high' => 2536500,
        ],
        2026 => [
            'year' => 2026,
            'pausal_annual' => ['band1' => 119808, 'band2' => 200940, 'band3' => 325668],
            'band_ceilings' => [
                30 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                40 => ['band1' => 1000000, 'band2' => 1500000, 'band3' => 2000000],
                60 => ['band1' => 1500000, 'band2' => 2000000, 'band3' => 2000000],
                80 => ['band1' => 2000000, 'band2' => 2000000, 'band3' => 2000000],
            ],
            'credit_taxpayer' => 30840,
            'credit_spouse'   => 24840,
            'child_credits'   => [15204, 22320, 27840],
            'tax_rate_low'        => 0.15,
            'tax_rate_high'       => 0.23,
            'tax_high_threshold'  => 1762812, // 36× průměrné mzdy 2026 (48 967)
            'social_rate'         => 0.292,
            'health_rate'         => 0.135,
            'social_assessment_pct' => 0.55,
            'health_assessment_pct' => 0.50,
            'social_min_base_main'      => 235044, // 40 % × 48 967 × 12
            'social_min_base_secondary' => 64644,  // min. roční zákl. vedlejší činnost
            'health_min_base'           => 293802, // 50 % × 48 967 × 12
            'expense_caps' => [30 => 600000, 40 => 800000, 60 => 1200000, 80 => 1600000],
            'mortgage_cap' => 150000,
            'pension_cap'  => 48000,
            'vat_limit_low'  => 2000000,
            'vat_limit_high' => 2536500,
        ],
    ];
}
