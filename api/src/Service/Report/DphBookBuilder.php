<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Builder pro **Knihu DPH** (interní VAT žurnál).
 *
 * Není to podání FÚ — je to interní účetní pomůcka která seskupí vystavené
 * i přijaté faktury podle řádků DPH přiznání (DPHDP3) a kombinuje:
 *
 *   - **Vystavené faktury** (invoices) → sekce s prefixem `36.001`, `36.002` …
 *     (řádky 1, 2, … DPHDP3 — uskutečněná plnění)
 *   - **Přijaté faktury** (purchase_invoices) → sekce `15.040`, `15.041` …
 *     (řádky 40, 41 … — přijatá tuzemská), `43.012/43.043` (dovoz služby)
 *
 * Scope = **vystavené + přijaté včetně draftů**. Drafty jsou označeny
 * `is_draft=true` v rows; UI je vizuálně odlišuje (badge "Koncept"). Storno
 * (`status='cancelled'`) je vyloučeno, proformy taky.
 *
 * Section key formát:
 *   - **15.XXX** = řádek pro přijatá plnění (sekce 15)
 *   - **36.XXX** = řádek pro vystavená plnění (sekce 36)
 *   - **43.XXX** = řádek 43 (nárok na odpočet) — pouze secondary z dovozu služby
 *
 * Pokud má klasifikační kód `dphdp3_line_secondary` (typicky dovoz služby:
 * ř.12 + ř.43), pak builder generuje DVĚ sekce ze stejné faktury (data se
 * objeví ve dvou tabulkách na PDF — viz reference DPH_LIST_KH 42026.pdf).
 *
 * Periodicita: **pouze měsíční** (year + month, range 1.-poslední den).
 */
final class DphBookBuilder
{
    public function __construct(
        private readonly Connection $db,
    ) {}

    /**
     * @return array{
     *   period: array{year:int, month:int, start:string, end:string, label:string},
     *   supplier: array<string,mixed>,
     *   sections: list<array<string,mixed>>,
     *   totals: array{base:float, vat:float, total:float}
     * }
     */
    public function build(int $supplierId, int $year, int $month): array
    {
        $supplier = $this->loadSupplier($supplierId);
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end = (new \DateTimeImmutable($start))->modify('last day of this month')->format('Y-m-d');

        // Načti všechny relevantní klasifikační kódy (pro lookup label / dphdp3_line)
        $codes = $this->loadClassifications();

        // Vystavené (36.XXX) — řádky 1..9 DPHDP3 obecně, 40..43 nejsou pro sale
        $issuedRows = $this->collectIssued($supplierId, $start, $end);
        // Přijaté (15.XXX / 43.XXX) — řádky 40..47 obecně, 12+43 pro dovoz služby
        $receivedRows = $this->collectReceived($supplierId, $start, $end);

        // Group do sekcí: každá unique (direction, dphdp3_line, vat_rate) je samostatná sekce.
        // Klasifikace bez kódu nebo bez dphdp3_line spadne do "uncategorized" sekce.
        $sections = [];

        foreach ($issuedRows as $r) {
            $cls = $this->resolveClassification($r['vat_classification_code'] ?? null, $codes, 'sale', (float) $r['vat_rate']);
            $this->addToSection($sections, 'issued', $cls, $r);
        }
        foreach ($receivedRows as $r) {
            $cls = $this->resolveClassification($r['vat_classification_code'] ?? null, $codes, 'purchase', (float) $r['vat_rate']);
            $this->addToSection($sections, 'received', $cls, $r);
            // Secondary line (např. dovoz služby: primary 12 + secondary 43,
            // nebo RC: 3/10 + 43 mirror odpočet po migraci 0044).
            if (!empty($cls['dphdp3_line_secondary'])) {
                $clsSecondary = array_merge($cls, [
                    'dphdp3_line' => $cls['dphdp3_line_secondary'],
                    'dphdp3_line_secondary' => null,
                    'is_secondary' => true,
                ]);
                $this->addToSection($sections, 'received', $clsSecondary, $r);
            }
            // ř. 47 — doplňující sekce pro pořízený majetek (§ 4 odst. 4 písm. c).
            // Stejná logika jako VatClassificationMapper::countsAsFixedAssetLine
            // (primary nebo secondary v 40-45 range = patří do ř. 47).
            if (!empty($r['is_fixed_asset'])) {
                $primary = (int) ($cls['dphdp3_line'] ?? 0);
                $secondary = (int) ($cls['dphdp3_line_secondary'] ?? 0);
                $eligible = ($primary >= 40 && $primary <= 45) || ($secondary >= 40 && $secondary <= 45);
                if ($eligible) {
                    $clsAsset = array_merge($cls, [
                        'dphdp3_line' => '47',
                        'dphdp3_line_secondary' => null,
                        'is_secondary' => true, // nepřičítat do global totals (jinak duplikace)
                    ]);
                    $this->addToSection($sections, 'received', $clsAsset, $r);
                }
            }
        }

        // Convert sections asociativní mapy → indexované pole, seřazené.
        $sectionList = array_values($sections);
        usort($sectionList, function ($a, $b) {
            // Vystavené (36) nahoru, pak přijaté (15), pak secondary (43).
            $oa = $this->sectionOrder($a['key']);
            $ob = $this->sectionOrder($b['key']);
            if ($oa !== $ob) return $oa <=> $ob;
            return strcmp($a['key'], $b['key']);
        });

        // Per-sekce subtotal + global totals
        $totBase = $totVat = $totTotal = 0.0;
        foreach ($sectionList as &$s) {
            $sb = $sv = $st = 0.0;
            foreach ($s['rows'] as $row) {
                $sb += (float) $row['base'];
                $sv += (float) $row['vat'];
                $st += (float) $row['total'];
            }
            $s['subtotal_base']  = $sb;
            $s['subtotal_vat']   = $sv;
            $s['subtotal_total'] = $st;
            // Do globálních totals započítáváme jen non-secondary řádky aby se
            // dovoz služby nezdvojoval. (Sekce 43 je secondary mirror sekce 12.)
            if (empty($s['is_secondary'])) {
                $totBase  += $sb;
                $totVat   += $sv;
                $totTotal += $st;
            }
        }
        unset($s);

        $label = (new \DateTimeImmutable($start))->format('d.m.Y') . ' - ' . (new \DateTimeImmutable($end))->format('d.m.Y');

        return [
            'period' => [
                'year'  => $year,
                'month' => $month,
                'start' => $start,
                'end'   => $end,
                'label' => $label,
            ],
            'supplier' => $supplier,
            'sections' => $sectionList,
            'totals' => [
                'base'  => $totBase,
                'vat'   => $totVat,
                'total' => $totTotal,
            ],
        ];
    }

    /**
     * Vystavené faktury (per řádek/per sazba pokud potřeba).
     *
     * @return list<array<string,mixed>>
     */
    private function collectIssued(int $supplierId, string $start, string $end): array
    {
        // Per-item rozdělení, ale pro Knihu DPH je smysluplnější per-faktura
        // (1 řádek = 1 doklad s primární klasifikací + sazbou). Když faktura
        // má víc sazeb, dělíme ji do víc řádků agregovaných po sazbě.
        $stmt = $this->db->pdo()->prepare("
            SELECT i.id,
                   i.varsymbol AS doc_number,
                   COALESCE(i.tax_date, i.issue_date) AS tax_date,
                   i.issue_date,
                   i.vat_classification_code,
                   i.status,
                   i.exchange_rate,
                   COALESCE(cur.code, 'CZK') AS currency,
                   c.company_name AS counterparty_name,
                   c.dic AS counterparty_dic,
                   COALESCE(it.description, '') AS description,
                   COALESCE(ii.vat_classification_code, i.vat_classification_code) AS line_class_code,
                   ii.vat_rate_snapshot AS vat_rate,
                   SUM(ii.total_without_vat) AS base,
                   SUM(ii.total_vat) AS vat,
                   SUM(ii.total_with_vat) AS total
              FROM invoices i
              JOIN clients c ON c.id = i.client_id
              JOIN invoice_items ii ON ii.invoice_id = i.id
         LEFT JOIN (
                SELECT invoice_id, MIN(description) AS description
                  FROM invoice_items
                 GROUP BY invoice_id
              ) it ON it.invoice_id = i.id
         LEFT JOIN currencies cur ON cur.id = i.currency_id
             WHERE i.supplier_id = ?
               AND i.status != 'cancelled'
               AND i.invoice_type != 'proforma'
               AND COALESCE(i.tax_date, i.issue_date) BETWEEN ? AND ?
          GROUP BY i.id, COALESCE(ii.vat_classification_code, i.vat_classification_code), ii.vat_rate_snapshot
          ORDER BY COALESCE(i.tax_date, i.issue_date), i.id
        ");
        $stmt->execute([$supplierId, $start, $end]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn ($r) => $this->normalizeRow($r, 'issued'), $rows);
    }

    /**
     * Přijaté faktury (per sazba).
     *
     * @return list<array<string,mixed>>
     */
    private function collectReceived(int $supplierId, string $start, string $end): array
    {
        $stmt = $this->db->pdo()->prepare("
            SELECT pi.id,
                   pi.varsymbol AS doc_number,
                   pi.vendor_invoice_number AS original_doc_number,
                   COALESCE(pi.tax_date, pi.issue_date) AS tax_date,
                   pi.issue_date,
                   pi.vat_classification_code,
                   pi.status,
                   pi.exchange_rate,
                   pi.reverse_charge,
                   COALESCE(cur.code, 'CZK') AS currency,
                   c.company_name AS counterparty_name,
                   c.dic AS counterparty_dic,
                   COALESCE(it.description, '') AS description,
                   COALESCE(pii.vat_classification_code, pi.vat_classification_code) AS line_class_code,
                   pii.vat_rate_snapshot AS vat_rate,
                   (CASE WHEN pii.is_fixed_asset = 1 OR pi.is_fixed_asset = 1 THEN 1 ELSE 0 END) AS is_fixed_asset,
                   -- is_reverse_charge klasifikace (kódy 5/23) — skalární subquery místo JOIN,
                   -- aby globální + per-tenant varianta kódu nezdvojily SUM. Scope na tenanta.
                   (SELECT MAX(vc.is_reverse_charge) FROM vat_classifications vc
                     WHERE vc.code = COALESCE(pii.vat_classification_code, pi.vat_classification_code)
                       AND (vc.supplier_id IS NULL OR vc.supplier_id = pi.supplier_id)) AS code_is_rc,
                   SUM(pii.total_without_vat) AS base,
                   SUM(pii.total_vat) AS vat,
                   SUM(pii.total_with_vat) AS total
              FROM purchase_invoices pi
              JOIN clients c ON c.id = pi.vendor_id
              JOIN purchase_invoice_items pii ON pii.purchase_invoice_id = pi.id
         LEFT JOIN (
                SELECT purchase_invoice_id, MIN(description) AS description
                  FROM purchase_invoice_items
                 GROUP BY purchase_invoice_id
              ) it ON it.purchase_invoice_id = pi.id
         LEFT JOIN currencies cur ON cur.id = pi.currency_id
             WHERE pi.supplier_id = ?
               AND pi.status != 'cancelled'
               AND COALESCE(pi.tax_date, pi.issue_date) BETWEEN ? AND ?
          GROUP BY pi.id, COALESCE(pii.vat_classification_code, pi.vat_classification_code),
                   pii.vat_rate_snapshot,
                   (CASE WHEN pii.is_fixed_asset = 1 OR pi.is_fixed_asset = 1 THEN 1 ELSE 0 END)
          ORDER BY COALESCE(pi.tax_date, pi.issue_date), pi.id
        ");
        $stmt->execute([$supplierId, $start, $end]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        return array_map(fn ($r) => $this->normalizeRow($r, 'received'), $rows);
    }

    /**
     * Normalizace + CZK přepočet. Vrací row se všemi sloupci pro PDF/UI.
     *
     * @param array<string,mixed> $r
     * @return array<string,mixed>
     */
    private function normalizeRow(array $r, string $direction): array
    {
        $rate = ($r['currency'] === 'CZK' || !$r['exchange_rate']) ? 1.0 : (float) $r['exchange_rate'];
        $vatRate = (float) ($r['vat_rate'] ?? 0);
        $baseRaw = (float) $r['base'];
        $vatRaw  = (float) $r['vat'];
        // Reverse charge: vendor fakturoval bez DPH (vatRaw=0) — Kniha DPH musí
        // ukázat samovyměřenou daň (jako DPHDP3), jinak by řádek byl 0 a uživatele
        // matlo. Per memory [[project_multicurrency_purchase]] vat se počítá nad
        // basem v invoice currency, pak se přepočte kurzem.
        // RC detekce shodná s DphPriznaniBuilder: per-faktura flag NEBO příznak
        // is_reverse_charge na klasifikaci (kódy 5/23) — jinak by Kniha DPH a DPHDP3
        // u dokladu bez flagu rozcházely (samovyměřená daň 0 vs. dopočtená).
        $isRc = !empty($r['reverse_charge']) || !empty($r['code_is_rc']);
        if ($direction === 'received' && $vatRaw == 0.0 && $isRc && $vatRate > 0) {
            $vatRaw = round($baseRaw * $vatRate / 100, 2);
        }
        $totalRaw = (float) $r['total'];
        if ($totalRaw == 0.0 && $baseRaw + $vatRaw != 0.0) {
            // RC + bez total → dopočti z base + vypočtené daně
            $totalRaw = $baseRaw + $vatRaw;
        }
        return [
            'invoice_id'          => (int) $r['id'],
            'direction'           => $direction,                // issued | received
            'doc_number'          => $r['doc_number'],          // naše interní VS
            'original_doc_number' => $r['original_doc_number'] ?? null,
            'tax_date'            => $r['tax_date'],
            'accounting_date'     => $r['issue_date'],          // jako "zaúčtování"
            'description'         => (string) ($r['description'] ?? ''),
            'counterparty_name'   => (string) ($r['counterparty_name'] ?? ''),
            'counterparty_dic'    => (string) ($r['counterparty_dic'] ?? ''),
            'vat_classification_code' => $r['line_class_code'] ?? $r['vat_classification_code'] ?? null,
            'vat_rate'            => $vatRate,
            'currency'            => (string) $r['currency'],
            'exchange_rate'       => $rate,
            'base'                => $baseRaw * $rate,
            'vat'                 => $vatRaw  * $rate,
            'total'               => $totalRaw * $rate,
            'status'              => (string) $r['status'],
            'is_draft'            => ($r['status'] === 'draft'),
            'is_fixed_asset'      => (bool) ($r['is_fixed_asset'] ?? false),
        ];
    }

    /**
     * Resolve classification — vrátí pole se sloupci nebo "uncategorized" fallback.
     *
     * @param array<string,array<string,mixed>> $codes
     * @return array<string,mixed>
     */
    private function resolveClassification(?string $code, array $codes, string $direction, float $vatRate): array
    {
        if ($code && isset($codes[$code])) {
            $c = $codes[$code];
            return [
                'code'                  => (string) $c['code'],
                'label'                 => (string) $c['label'],
                'direction'             => (string) $c['direction'],
                'dphdp3_line'           => $c['dphdp3_line'] ? (string) $c['dphdp3_line'] : null,
                'dphdp3_line_secondary' => $c['dphdp3_line_secondary'] ? (string) $c['dphdp3_line_secondary'] : null,
                'kh_section'            => $c['kh_section'] ? (string) $c['kh_section'] : null,
                'vat_rate'              => $c['vat_rate'] !== null ? (float) $c['vat_rate'] : $vatRate,
            ];
        }
        // Fallback — uncategorized; použijeme implicit mapping podle direction + sazby
        $line = null;
        if ($direction === 'sale') {
            $line = abs($vatRate - 21.0) < 0.5 ? '1' : (abs($vatRate - 12.0) < 0.5 ? '2' : null);
        } else { // purchase
            $line = abs($vatRate - 21.0) < 0.5 ? '40' : (abs($vatRate - 12.0) < 0.5 ? '41' : null);
        }
        return [
            'code'                  => '',
            'label'                 => '(bez klasifikace)',
            'direction'             => $direction,
            'dphdp3_line'           => $line,
            'dphdp3_line_secondary' => null,
            'kh_section'            => null,
            'vat_rate'              => $vatRate,
        ];
    }

    /**
     * Připojí row do správné sekce, vytvoří sekci pokud neexistuje.
     *
     * @param array<string,array<string,mixed>> $sections by-ref
     * @param array<string,mixed> $cls
     * @param array<string,mixed> $row
     */
    private function addToSection(array &$sections, string $directionScope, array $cls, array $row): void
    {
        $sectionPrefix = $directionScope === 'issued' ? '36' : '15';
        // Sekce s line=43 jsou secondary (dovoz služby / RC mirror — nárok na odpočet)
        if ($cls['dphdp3_line'] === '43') {
            $sectionPrefix = '43';
        }
        // Sekce ř.47 = doplňující údaj o hodnotě pořízeného majetku
        if ($cls['dphdp3_line'] === '47') {
            $sectionPrefix = '47';
        }
        $line = $cls['dphdp3_line'] ?: '000';
        $linePadded = str_pad($line, 3, '0', \STR_PAD_LEFT);
        // Sekce klíč: NN.LLL (NN=15/36/43, LLL=padded line). Sazba je v label.
        $key = $sectionPrefix . '.' . $linePadded;

        if (!isset($sections[$key])) {
            $sections[$key] = [
                'key'             => $key,
                'direction'       => $directionScope === 'issued' ? 'USKUTEČNĚNÁ' : 'PŘIJATÁ',
                'label'           => $this->buildSectionLabel($sectionPrefix, $line, (float) $cls['vat_rate'], $directionScope),
                'dphdp3_line'     => $line,
                'vat_rate'        => (float) $cls['vat_rate'],
                'is_secondary'    => !empty($cls['is_secondary']),
                'rows'            => [],
                'subtotal_base'   => 0.0,
                'subtotal_vat'    => 0.0,
                'subtotal_total'  => 0.0,
            ];
        }
        // Doplň KH section do row (zobrazuje se v poslední koloně PDF: A.4, B.2, …)
        $rowWithKh = array_merge($row, [
            'kh_section' => $cls['kh_section'],
        ]);
        $sections[$key]['rows'][] = $rowWithKh;
    }

    /**
     * Label sekce — paste-and-modify ze stylu reference PDF:
     *   "15 ř.040 - PŘIJATÁ: Z tuzemska - sazba 21 %"
     *   "36 ř.001 - USKUTEČNĚNÁ: Základ daně"
     *   "43 ř.012 - PŘIJATÁ: Z dovozu služby - sazba"
     *   "43 ř.043 - PŘIJATÁ: Z dovozu služby - sazba"
     */
    private function buildSectionLabel(string $prefix, string $line, float $vatRate, string $directionScope): string
    {
        $direction = $directionScope === 'issued' ? 'USKUTEČNĚNÁ' : 'PŘIJATÁ';
        $rateLabel = $vatRate > 0 ? sprintf(' %g %%', $vatRate) : '';
        if ($prefix === '36') {
            // Vystavené: "Základ daně" nebo sazba podle řádku
            $what = ($line === '1' || $line === '2') ? 'Základ daně' : 'Plnění';
            return sprintf('%s ř.%s - %s: %s', $prefix, str_pad($line, 3, '0', \STR_PAD_LEFT), $direction, $what . $rateLabel);
        }
        if ($prefix === '43') {
            return sprintf('%s ř.%s - %s: Z reverse charge / dovozu služby - sazba%s', $prefix, str_pad($line, 3, '0', \STR_PAD_LEFT), $direction, $rateLabel);
        }
        if ($prefix === '47') {
            return sprintf('%s ř.%s - PŘIJATÁ: Hodnota pořízeného majetku (§ 4 odst. 4 písm. c)', $prefix, str_pad($line, 3, '0', \STR_PAD_LEFT));
        }
        // 15.XXX = přijaté
        // Speciální popisy pro 40/41 (tuzemsko), 12 (dovoz služby), 7 (dovoz zboží), atd.
        $what = match ($line) {
            '40', '41', '42' => 'Z tuzemska - sazba',
            '12'             => 'Z dovozu služby - sazba',
            '7'              => 'Dovoz zboží ze 3. země',
            '3', '4'         => 'Pořízení z EU',
            default          => 'Plnění',
        };
        return sprintf('%s ř.%s - %s: %s%s', $prefix, str_pad($line, 3, '0', \STR_PAD_LEFT), $direction, $what, $rateLabel);
    }

    private function sectionOrder(string $key): int
    {
        // 36.XXX = vystavené (0), 15.XXX = přijaté (1), 43.XXX = RC/dovoz mirror (2),
        // 47.XXX = hodnota pořízeného majetku doplňující údaj (3).
        $prefix = substr($key, 0, 2);
        return match ($prefix) {
            '36' => 0,
            '15' => 1,
            '43' => 2,
            '47' => 3,
            default => 9,
        };
    }

    /**
     * @return array<string, array<string,mixed>> code => row
     */
    private function loadClassifications(): array
    {
        $stmt = $this->db->pdo()->query("
            SELECT code, label, direction, dphdp3_line, dphdp3_line_secondary,
                   kh_section, vat_rate, is_reverse_charge
              FROM vat_classifications
             WHERE archived = 0
          ORDER BY supplier_id IS NULL DESC, supplier_id ASC, display_order ASC
        ");
        $out = [];
        if ($stmt !== false) {
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $r) {
                $out[(string) $r['code']] = $r;
            }
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private function loadSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.id, s.company_name, s.street, s.city, s.zip,
                    COALESCE(c.iso2, 'CZ') AS country_iso2,
                    s.ic, s.dic, s.is_vat_payer
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \RuntimeException("Supplier #{$supplierId} nenalezen.");
        }
        return $row;
    }
}
