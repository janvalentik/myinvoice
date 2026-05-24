<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Mapper VAT klasifikací — code → dphdp3_line, kh_section, sazba.
 *
 * Pro každého tenanta načte:
 *   - Globální seed kódy (supplier_id IS NULL)
 *   - Per-tenant override (supplier_id = $supplierId) — pokud existuje, vyhraje
 */
final class VatClassificationMapper
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Monthly DPH trend za posledních N měsíců (default 12). Z crm_monthly_summary
     * (pre-aggregated, rychlé).
     *
     * Filter currency=CZK — DPH přiznání je vždy v CZK; sumovat EUR + CZK by dalo
     * nesmyslné hodnoty.
     *
     * @return list<array{period:string, vat_output:float, vat_input:float, vat_due:float}>
     */
    public function monthlyDphTrend(int $supplierId, int $monthsBack = 12): array
    {
        $start = (new \DateTimeImmutable())->modify('-' . $monthsBack . ' months')->format('Y-m');
        $stmt = $this->db->pdo()->prepare(
            "SELECT period_ym, vat_output, vat_input
               FROM crm_monthly_summary
              WHERE supplier_id = ? AND period_ym >= ? AND currency = 'CZK'
           ORDER BY period_ym ASC"
        );
        $stmt->execute([$supplierId, $start]);
        return array_map(function ($r) {
            $out = (float) $r['vat_output'];
            $in  = (float) $r['vat_input'];
            return [
                'period'     => (string) $r['period_ym'],
                'vat_output' => $out,
                'vat_input'  => $in,
                'vat_due'    => $out - $in,
            ];
        }, $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    /**
     * Vrátí mapu code → {label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge}
     *
     * @return array<string, array{label:string, direction:string, dphdp3_line:?string,
     *                              kh_section:?string, vat_rate:?float, is_reverse_charge:bool}>
     */
    public function loadMap(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT code, label, direction, dphdp3_line, dphdp3_line_secondary,
                    kh_section, vat_rate, is_reverse_charge
               FROM vat_classifications
              WHERE (supplier_id IS NULL OR supplier_id = ?)
                AND archived = 0
           ORDER BY supplier_id IS NULL ASC, display_order ASC'
        );
        $stmt->execute([$supplierId]);
        $map = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $map[(string) $r['code']] = [
                'label'                 => (string) $r['label'],
                'direction'             => (string) $r['direction'],
                'dphdp3_line'           => $r['dphdp3_line'] !== null ? (string) $r['dphdp3_line'] : null,
                'dphdp3_line_secondary' => $r['dphdp3_line_secondary'] !== null ? (string) $r['dphdp3_line_secondary'] : null,
                'kh_section'            => $r['kh_section'] !== null ? (string) $r['kh_section'] : null,
                'vat_rate'              => $r['vat_rate'] !== null ? (float) $r['vat_rate'] : null,
                'is_reverse_charge'     => (bool) $r['is_reverse_charge'],
            ];
        }
        return $map;
    }

    /**
     * Aggregace pro DPH přiznání DPHDP3 — vrátí summary per řádek výkazu.
     *
     * Z invoices + purchase_invoices + their items podle období (rok+měsíc nebo kvartál).
     * Quarterly: $month = 0 (Q1 = leden-březen pro $year) nebo 3/6/9/12 (poslední měsíc kvartálu).
     * Pro každou fakturu/řádek najde vat_classification_code (item-level override → invoice-level fallback).
     *
     * @param int $year     Rok (např. 2026)
     * @param int $month    Měsíc (1-12) nebo 0 (= roční přehled)
     * @param string $period 'monthly' | 'quarterly' — quarterly bere celý kvartál
     *                       odpovídající danému $month (Q = ceil($month / 3))
     * @return array<string, array{base:float, vat:float, count:int, label:string}>
     */
    public function aggregateForDphPriznani(int $supplierId, int $year, int $month, string $period = 'monthly'): array
    {
        $map = $this->loadMap($supplierId);
        // Quarterly: spočítej kvartál (1-4) z měsíce + rozsah
        if ($period === 'quarterly') {
            $quarter = (int) ceil($month / 3);
            $qStartMonth = ($quarter - 1) * 3 + 1; // 1, 4, 7, 10
            $qEndMonth   = $quarter * 3;            // 3, 6, 9, 12
            $start = sprintf('%04d-%02d-01', $year, $qStartMonth);
            $end = (new \DateTimeImmutable(sprintf('%04d-%02d-01', $year, $qEndMonth)))
                ->modify('last day of this month')->format('Y-m-d');
        } else {
            $start = sprintf('%04d-%02d-01', $year, $month);
            $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');
        }

        $byLine = [];
        $invoiceLineSeen = []; // per (table:invId) × line → bool

        // Vystavené (revenue side).
        // Auto-default code přes CASE: pokud chybí vat_classification_code, derivuj z reverse_charge
        // + vat_rate_snapshot. Tím nepropadají DPH přiznání řádky tichou klasifikační dírou
        // (historická data + recent imports bez auto-classifier). Per-řádek granularita
        // kvůli aplikaci exchange_rate (faktury v EUR/USD → základy v CZK pro DPHDP3).
        $rows = $this->db->pdo()->prepare(
            "SELECT
                  i.id                       AS inv_id,
                  COALESCE(i.exchange_rate, 1) AS rate,
                  COALESCE(cur.code, 'CZK')  AS currency,
                  COALESCE(
                      ii.vat_classification_code,
                      i.vat_classification_code,
                      CASE
                          WHEN i.reverse_charge = 1 THEN '20'  -- EU/RC sale default
                          WHEN ii.vat_rate_snapshot >= 21 THEN '1'   -- 21% tuzemsko
                          WHEN ii.vat_rate_snapshot >= 12 THEN '2'   -- 12% tuzemsko
                          WHEN ii.vat_rate_snapshot = 0  THEN '3'   -- osvobozeno
                          ELSE NULL
                      END
                  ) AS code,
                  COALESCE(ii.total_without_vat, 0) AS base,
                  COALESCE(ii.total_vat, 0)         AS vat
             FROM invoices i
             JOIN invoice_items ii ON ii.invoice_id = i.id
        LEFT JOIN currencies cur ON cur.id = i.currency_id
            WHERE i.supplier_id = ?
              AND i.status NOT IN ('draft', 'cancelled')
              AND i.invoice_type != 'proforma'
              AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?"
        );
        $rows->execute([$supplierId, $start, $end]);
        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $code = $r['code'];
            if (!$code) continue;
            $clsf = $map[$code] ?? null;
            if ($clsf === null || $clsf['dphdp3_line'] === null) continue;

            $rate = ($r['currency'] === 'CZK') ? 1.0 : (float) $r['rate'];
            $baseCzk = round((float) $r['base'] * $rate, 2);
            $vatCzk  = round((float) $r['vat']  * $rate, 2);

            $this->addLine($byLine, (string) $clsf['dphdp3_line'], $baseCzk, $vatCzk,
                (int) $r['inv_id'] * 10 + 1 /* "sale" namespace */, $invoiceLineSeen, (string) $clsf['label']);
        }

        // Přijaté (cost side — nárok na odpočet)
        //
        // Per-řádek granularita (ne per-faktura agregace), protože musíme:
        //   1. Aplikovat invoice.exchange_rate (faktury v EUR/USD → základy v CZK)
        //   2. Pro reverse-charge (pi.reverse_charge=1) dopočítat samovyměřenou
        //      daň = base × vat_rate_snapshot/100, protože pii.total_vat=0
        //      (vendor fakturuje bez DPH, příjemce daň přiznává sám)
        //   3. Mirrorovat RC plnění do ř. 43 jako odpočet (dphdp3_line_secondary)
        //   4. Vyčlenit řádky s is_fixed_asset=1 do ř. 47 (doplňující údaj
        //      k ř. 40 — hodnota majetku § 4 odst. 4 písm. c)
        $rows = $this->db->pdo()->prepare(
            "SELECT
                  pi.id                       AS inv_id,
                  pi.reverse_charge,
                  COALESCE(pi.exchange_rate, 1) AS rate,
                  COALESCE(cur.code, 'CZK')   AS currency,
                  COALESCE(
                      pii.vat_classification_code,
                      pi.vat_classification_code,
                      CASE
                          WHEN pi.reverse_charge = 1 THEN '5'   -- RC purchase default
                          WHEN pii.vat_rate_snapshot >= 21 THEN '40' -- 21% nárok
                          WHEN pii.vat_rate_snapshot >= 12 THEN '41' -- 12% nárok
                          ELSE NULL
                      END
                  ) AS code,
                  pii.vat_rate_snapshot       AS vat_rate,
                  COALESCE(pii.total_without_vat, 0) AS base,
                  COALESCE(pii.total_vat, 0)         AS vat,
                  (CASE WHEN pii.is_fixed_asset = 1 OR pi.is_fixed_asset = 1
                        THEN 1 ELSE 0 END)         AS is_fixed_asset
             FROM purchase_invoices pi
             JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
        LEFT JOIN currencies cur ON cur.id = pi.currency_id
            WHERE pi.supplier_id = ?
              AND pi.status NOT IN ('draft', 'cancelled')
              AND COALESCE(pi.tax_date, pi.issue_date) BETWEEN ? AND ?"
        );
        $rows->execute([$supplierId, $start, $end]);

        foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $r) {
            $code = $r['code'];
            if (!$code) continue;
            $clsf = $map[$code] ?? null;
            if ($clsf === null || $clsf['dphdp3_line'] === null) continue;

            $rate = ($r['currency'] === 'CZK') ? 1.0 : (float) $r['rate'];
            $baseRaw = (float) $r['base'];
            $vatRaw  = (float) $r['vat'];
            $vatRate = (float) $r['vat_rate'];

            // RC: vendor fakturoval bez DPH (pii.total_vat=0), my si daň samovyměříme
            //     na základ × sazbu. Per memory ([[project_multicurrency_purchase]]):
            //     EUR base × kurz = CZK base; CZK daň = CZK base × sazba/100.
            if ($vatRaw == 0.0 && ((bool) $r['reverse_charge'] || (bool) ($clsf['is_reverse_charge'] ?? false))) {
                $vatRaw = round($baseRaw * $vatRate / 100, 2);
            }

            $baseCzk = round($baseRaw * $rate, 2);
            $vatCzk  = round($vatRaw  * $rate, 2);

            $primary = $clsf['dphdp3_line'];
            $secondary = $clsf['dphdp3_line_secondary'] ?? null;
            // "purchase" namespace pro count distinct (× 10 + 2), aby se nemíchalo se sale.
            $invId = (int) $r['inv_id'] * 10 + 2;

            // Primary line (output side u RC, nebo přímý odpočet u tuzemska)
            $this->addLine($byLine, $primary, $baseCzk, $vatCzk, $invId, $invoiceLineSeen, (string) $clsf['label']);

            // Secondary line (typicky ř. 43 — mirror odpočet u RC)
            if ($secondary !== null && $secondary !== '' && $secondary !== $primary) {
                $this->addLine($byLine, $secondary, $baseCzk, $vatCzk, $invId, $invoiceLineSeen, (string) $clsf['label']);
            }

            // ř. 47 — doplňující údaj o hodnotě pořízeného majetku.
            // Patří sem řádky, jejichž odpočet je na ř. 40-45 (tuzemsko 40/41,
            // dovoz CÚ 42, RC mirror 43). Tedy buď primary, nebo secondary v 40-45.
            // Hodnota = základ v CZK (XSD `nar_maj` = jediný decimal atribut).
            $assetEligibleLine = $this->countsAsFixedAssetLine($primary)
                ? $primary
                : (($secondary !== null && $this->countsAsFixedAssetLine($secondary)) ? $secondary : null);
            if ((int) $r['is_fixed_asset'] === 1 && $assetEligibleLine !== null) {
                $this->addLine($byLine, '47', $baseCzk, $vatCzk, $invId, $invoiceLineSeen, 'Hodnota pořízeného majetku (§ 4 odst. 4 písm. c)');
            }
        }

        return $byLine;
    }

    /**
     * @param array<string, array{base:float, vat:float, count:int, label:string}> $byLine by-ref
     * @param array<string, bool> $invoiceLineSeen by-ref
     */
    private function addLine(array &$byLine, string $line, float $baseCzk, float $vatCzk, int $invId, array &$invoiceLineSeen, string $label): void
    {
        if (!isset($byLine[$line])) {
            $byLine[$line] = ['base' => 0.0, 'vat' => 0.0, 'count' => 0, 'label' => $label];
        }
        $byLine[$line]['base'] += $baseCzk;
        $byLine[$line]['vat']  += $vatCzk;
        $seenKey = $invId . ':' . $line;
        if (!isset($invoiceLineSeen[$seenKey])) {
            $invoiceLineSeen[$seenKey] = true;
            $byLine[$line]['count']++;
        }
    }

    /**
     * Smí dané plnění figurovat na ř. 47 (hodnota pořízeného majetku)?
     *
     * Doplňující údaj k odpočtu — vstup do ř. 40-45 (tuzemsko 40/41, dovoz CÚ 42,
     * RC mirror 43, korekce 44, registrace 45). NE pro výstupové řádky 3-13
     * samotné (ty se počítají odděleně přes secondary='43' mirror).
     */
    private function countsAsFixedAssetLine(string $primaryLine): bool
    {
        $n = (int) $primaryLine;
        return $n >= 40 && $n <= 45;
    }
}
