<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * CRUD pro faktury + položky + listing s grupováním po měsících (DUZP).
 *
 * Konvence řazení/grupování:
 *   "month bucket" = COALESCE(tax_date, issue_date) → "YYYY-MM"
 *   pro proformu (tax_date NULL) tedy padá na issue_date
 */
final class InvoiceRepository
{
    public function __construct(private readonly Connection $db) {}

    public function find(int $id): ?array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            'SELECT i.*,
                    c.company_name AS client_company_name, c.main_email AS client_main_email,
                    c.ic AS client_ic, c.dic AS client_dic,
                    c.language AS client_language,
                    c.reverse_charge AS client_reverse_charge,
                    p.name AS project_name, p.hourly_rate AS project_hourly_rate,
                    p.payment_due_days AS project_payment_due_days,
                    p.project_number AS project_number, p.contract_number AS contract_number,
                    p.requires_work_report_approval AS project_requires_approval,
                    cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                    cur.label AS currency_label,
                    cur.account_number AS bank_account_number, cur.bank_code AS bank_code,
                    cur.bank_name AS bank_name, cur.iban AS bank_iban, cur.bic AS bank_bic
               FROM invoices i
               JOIN clients c ON c.id = i.client_id
          LEFT JOIN projects p ON p.id = i.project_id
               JOIN currencies cur ON cur.id = i.currency_id
              WHERE i.id = ?'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) return null;

        $row = $this->castInvoice($row);
        $row['items'] = $this->itemsFor($id);

        // Fakturační emaily projektu (jen popisky, používané v UI hlavičce)
        if (!empty($row['project_id'])) {
            $stmt2 = $pdo->prepare(
                'SELECT email, label FROM project_billing_emails WHERE project_id = ? ORDER BY position'
            );
            $stmt2->execute([(int) $row['project_id']]);
            $row['project_billing_emails'] = $stmt2->fetchAll(PDO::FETCH_ASSOC);
        } else {
            $row['project_billing_emails'] = [];
        }

        // VAT breakdown
        $row['vat_breakdown'] = $this->buildVatBreakdown($row['items']);
        $row['totals'] = [
            'without_vat'        => $row['total_without_vat'],
            'vat'                => $row['total_vat'],
            'with_vat'           => $row['total_with_vat'],
            'rounding'           => $row['rounding'],
            'advance_paid_amount'=> $row['advance_paid_amount'],
            'amount_to_pay'      => $row['amount_to_pay'],
        ];

        return $row;
    }

    public function itemsFor(int $invoiceId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ii.id, ii.invoice_id, ii.description, ii.quantity, ii.unit,
                    ii.unit_price_without_vat, ii.vat_rate_id, ii.vat_rate_snapshot,
                    ii.total_without_vat, ii.total_vat, ii.total_with_vat,
                    ii.order_index, ii.linked_work_report_id,
                    vr.code AS vat_code, vr.label_cs AS vat_label_cs, vr.label_en AS vat_label_en
               FROM invoice_items ii
               JOIN vat_rates vr ON vr.id = ii.vat_rate_id
              WHERE ii.invoice_id = ?
              ORDER BY ii.order_index, ii.id'
        );
        $stmt->execute([$invoiceId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn (array $r) => $this->castItem($r), $rows);
    }

    /**
     * Vrátí faktury seskupené po měsíci podle COALESCE(tax_date, issue_date).
     *
     * Output: ['data' => [{month: '2026-04', total_*, count, items: [...]} ...], 'meta' => ...]
     *
     * Pokud je $perPage > 0, vrací jen daný řez řádků (LIMIT/OFFSET); meta obsahuje
     * total/page/per_page/pages. Pro export CSV / sumy přes celý dataset volat s $perPage = 0.
     */
    public function listGroupedByMonth(array $filters = [], int $page = 1, int $perPage = 0): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['supplier_id'])) {
            $where[] = 'i.supplier_id = ?';
            $params[] = (int) $filters['supplier_id'];
        }

        if (!empty($filters['type'])) {
            $types = is_array($filters['type']) ? $filters['type'] : [$filters['type']];
            $place = implode(',', array_fill(0, count($types), '?'));
            $where[] = "i.invoice_type IN ($place)";
            foreach ($types as $t) $params[] = $t;
        }
        if (!empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : [$filters['status']];
            $place = implode(',', array_fill(0, count($statuses), '?'));
            $where[] = "i.status IN ($place)";
            foreach ($statuses as $s) $params[] = $s;
        }
        if (!empty($filters['client_id'])) {
            $where[] = 'i.client_id = ?';
            $params[] = (int) $filters['client_id'];
        }
        if (!empty($filters['project_id'])) {
            $where[] = 'i.project_id = ?';
            $params[] = (int) $filters['project_id'];
        }
        if (!empty($filters['year'])) {
            $where[] = 'YEAR(COALESCE(i.tax_date, i.issue_date)) = ?';
            $params[] = (int) $filters['year'];
        }
        if (!empty($filters['month'])) {
            $where[] = 'MONTH(COALESCE(i.tax_date, i.issue_date)) = ?';
            $params[] = (int) $filters['month'];
        }
        if (!empty($filters['date_from'])) {
            $where[] = 'COALESCE(i.tax_date, i.issue_date) >= ?';
            $params[] = (string) $filters['date_from'];
        }
        if (!empty($filters['date_to'])) {
            $where[] = 'COALESCE(i.tax_date, i.issue_date) <= ?';
            $params[] = (string) $filters['date_to'];
        }
        if (!empty($filters['unpaid_only'])) {
            $where[] = "i.status IN ('issued','sent','reminded')";
            $where[] = 'i.invoice_type IN ("invoice","credit_note")';
        }
        if (!empty($filters['overdue'])) {
            $where[] = "i.status IN ('issued','sent','reminded') AND i.due_date <= CURDATE()";
        }
        if (!empty($filters['q'])) {
            // Escape % a _ wildcards aby uživatelský input nedělal slow-query DoS / nečekanou shodu
            $q = addcslashes((string) $filters['q'], '%_\\');
            $where[] = '(i.varsymbol LIKE ? OR c.company_name LIKE ?)';
            $params[] = $q . '%';
            $params[] = '%' . $q . '%';
        }

        $whereSql = implode(' AND ', $where);

        $total = null;
        if ($perPage > 0) {
            $cntStmt = $this->db->pdo()->prepare(
                "SELECT COUNT(*) FROM invoices i
                   JOIN clients c ON c.id = i.client_id
              LEFT JOIN projects p ON p.id = i.project_id
                  WHERE $whereSql"
            );
            $cntStmt->execute($params);
            $total = (int) $cntStmt->fetchColumn();
        }

        $sql = "SELECT i.id, i.varsymbol, i.invoice_type, i.parent_invoice_id,
                       i.client_id, i.project_id, i.supplier_id,
                       i.issue_date, i.tax_date, i.due_date,
                       i.currency_id, cur.code AS currency, cur.symbol AS currency_symbol, cur.decimals AS currency_decimals,
                       i.total_without_vat, i.total_vat, i.total_with_vat,
                       i.advance_paid_amount, i.amount_to_pay,
                       i.status, i.sent_at, i.last_reminder_at, i.reminder_count,
                       i.paid_at, i.cancelled_at,
                       c.company_name AS client_company_name,
                       p.name AS project_name,
                       DATE_FORMAT(COALESCE(i.tax_date, i.issue_date), '%Y-%m') AS month_bucket
                  FROM invoices i
                  JOIN clients c ON c.id = i.client_id
             LEFT JOIN projects p ON p.id = i.project_id
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE $whereSql
                 ORDER BY COALESCE(i.tax_date, i.issue_date) DESC, i.id DESC";

        if ($perPage > 0) {
            $offset = max(0, ($page - 1) * $perPage);
            $sql .= " LIMIT ? OFFSET ?";
        }

        // PDO nepodporuje míchání named (:foo) a positional (?) parametrů — vše positional.
        $stmt = $this->db->pdo()->prepare($sql);
        $idx = 1;
        foreach ($params as $v) {
            $stmt->bindValue($idx++, $v);
        }
        if ($perPage > 0) {
            $stmt->bindValue($idx++, $perPage, PDO::PARAM_INT);
            $stmt->bindValue($idx++, $offset,  PDO::PARAM_INT);
        }
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Grupování po měsíci
        $grouped = [];
        foreach ($rows as $row) {
            $row = $this->castInvoice($row);
            $month = (string) $row['month_bucket'];
            if (!isset($grouped[$month])) {
                $grouped[$month] = [
                    'month' => $month,
                    'count' => 0,
                    'totals_per_currency' => [],
                    'invoices' => [],
                ];
            }
            $grouped[$month]['invoices'][] = $row;
            $grouped[$month]['count']++;

            $cur = $row['currency'];
            if (!isset($grouped[$month]['totals_per_currency'][$cur])) {
                $grouped[$month]['totals_per_currency'][$cur] = [
                    'currency'    => $cur,
                    'without_vat' => 0.0,
                    'vat'         => 0.0,
                    'with_vat'    => 0.0,
                ];
            }
            // Storno (cancelled) a interní cancellation se do součtu nepočítají
            if ($row['status'] !== 'cancelled' && $row['invoice_type'] !== 'cancellation') {
                $grouped[$month]['totals_per_currency'][$cur]['without_vat'] += $row['total_without_vat'];
                $grouped[$month]['totals_per_currency'][$cur]['vat']         += $row['total_vat'];
                $grouped[$month]['totals_per_currency'][$cur]['with_vat']    += $row['total_with_vat'];
            }
        }

        // Round totals
        foreach ($grouped as &$m) {
            foreach ($m['totals_per_currency'] as &$t) {
                $t['without_vat'] = round($t['without_vat'], 2);
                $t['vat']         = round($t['vat'], 2);
                $t['with_vat']    = round($t['with_vat'], 2);
            }
            $m['totals_per_currency'] = array_values($m['totals_per_currency']);
        }
        unset($m, $t);

        $meta = ['total' => $total ?? count($rows)];
        if ($perPage > 0) {
            $meta['page']     = $page;
            $meta['per_page'] = $perPage;
            $meta['pages']    = (int) ceil(($total ?? 0) / max(1, $perPage));
        }

        return [
            'data' => array_values($grouped),
            'meta' => $meta,
        ];
    }

    public function createDraft(array $data, int $userId): int
    {
        $pdo = $this->db->pdo();

        // Supplier_id se odvodí z client (immutable per client)
        $clientId = (int) $data['client_id'];
        $stmt = $pdo->prepare('SELECT supplier_id FROM clients WHERE id = ?');
        $stmt->execute([$clientId]);
        $supplierId = (int) $stmt->fetchColumn();
        if ($supplierId === 0) {
            throw new \InvalidArgumentException("Client #$clientId nenalezen.");
        }

        $sql = 'INSERT INTO invoices
            (invoice_type, parent_invoice_id, client_id, project_id, supplier_id,
             issue_date, tax_date, due_date, currency_id, reverse_charge, language,
             note_above_items, note_below_items, advance_paid_amount,
             status, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, "draft", ?)';

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            (string) ($data['invoice_type'] ?? 'invoice'),
            isset($data['parent_invoice_id']) ? (int) $data['parent_invoice_id'] : null,
            $clientId,
            isset($data['project_id']) && $data['project_id'] ? (int) $data['project_id'] : null,
            $supplierId,
            (string) $data['issue_date'],
            ($data['invoice_type'] ?? 'invoice') === 'proforma' ? null : (string) ($data['tax_date'] ?? $data['issue_date']),
            (string) $data['due_date'],
            (int) $data['currency_id'],
            !empty($data['reverse_charge']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            (float) ($data['advance_paid_amount'] ?? 0),
            $userId,
        ]);

        return (int) $pdo->lastInsertId();
    }

    public function updateDraft(int $id, array $data): void
    {
        $sql = 'UPDATE invoices SET
                client_id = ?, project_id = ?,
                issue_date = ?, tax_date = ?, due_date = ?,
                currency_id = ?, reverse_charge = ?, language = ?,
                note_above_items = ?, note_below_items = ?,
                advance_paid_amount = ?
              WHERE id = ?';

        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([
            (int) $data['client_id'],
            isset($data['project_id']) && $data['project_id'] ? (int) $data['project_id'] : null,
            (string) $data['issue_date'],
            empty($data['tax_date']) ? null : (string) $data['tax_date'],
            (string) $data['due_date'],
            (int) $data['currency_id'],
            !empty($data['reverse_charge']) ? 1 : 0,
            (string) ($data['language'] ?? 'cs'),
            $data['note_above_items'] ?? null,
            $data['note_below_items'] ?? null,
            (float) ($data['advance_paid_amount'] ?? 0),
            $id,
        ]);
    }

    public function delete(int $id): void
    {
        // ON DELETE CASCADE smaže invoice_items i work_reports
        $this->db->pdo()->prepare('DELETE FROM invoices WHERE id = ?')->execute([$id]);
    }

    /**
     * Přepíše položky faktury (smaže staré + insertne nové).
     */
    public function replaceItems(int $invoiceId, array $items): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM invoice_items WHERE invoice_id = ?')->execute([$invoiceId]);

        $stmt = $pdo->prepare(
            'INSERT INTO invoice_items
                (invoice_id, description, quantity, unit, unit_price_without_vat,
                 vat_rate_id, vat_rate_snapshot,
                 total_without_vat, total_vat, total_with_vat, order_index)
             VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, 0, ?)'
        );

        $vatRates = $this->loadVatRates();

        foreach (array_values($items) as $i => $item) {
            $vatRateId = (int) ($item['vat_rate_id'] ?? 0);
            $rate = $vatRates[$vatRateId] ?? 0.0;
            $stmt->execute([
                $invoiceId,
                (string) ($item['description'] ?? ''),
                (float) ($item['quantity'] ?? 1),
                (string) ($item['unit'] ?? 'ks'),
                (float) ($item['unit_price_without_vat'] ?? 0),
                $vatRateId,
                $rate,
                (int) ($item['order_index'] ?? $i),
            ]);
        }
    }

    private function loadVatRates(): array
    {
        $rows = $this->db->pdo()->query('SELECT id, rate_percent FROM vat_rates')->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) $out[(int) $r['id']] = (float) $r['rate_percent'];
        return $out;
    }

    private function castInvoice(array $row): array
    {
        $row['id']                  = (int) $row['id'];
        $row['client_id']           = (int) $row['client_id'];
        $row['project_id']          = $row['project_id'] !== null ? (int) $row['project_id'] : null;
        $row['parent_invoice_id']   = isset($row['parent_invoice_id']) && $row['parent_invoice_id'] !== null ? (int) $row['parent_invoice_id'] : null;
        if (isset($row['currency_id']))   $row['currency_id'] = (int) $row['currency_id'];
        if (isset($row['supplier_id']))   $row['supplier_id'] = (int) $row['supplier_id'];
        $row['reverse_charge']      = isset($row['reverse_charge']) ? (bool) $row['reverse_charge'] : false;
        foreach (['total_without_vat', 'total_vat', 'total_with_vat', 'rounding', 'advance_paid_amount', 'amount_to_pay'] as $f) {
            if (array_key_exists($f, $row) && $row[$f] !== null) $row[$f] = (float) $row[$f];
        }
        if (isset($row['client_reverse_charge'])) $row['client_reverse_charge'] = (bool) $row['client_reverse_charge'];
        if (array_key_exists('reminder_count', $row)) $row['reminder_count'] = (int) $row['reminder_count'];
        if (array_key_exists('project_requires_approval', $row)) {
            $row['project_requires_approval'] = $row['project_requires_approval'] !== null
                ? (bool) $row['project_requires_approval']
                : false;
        }
        return $row;
    }

    /**
     * Vygeneruje a uloží nový approval_token, nastaví status='requested'.
     * Vrací nový token.
     */
    public function setApprovalRequested(int $invoiceId): string
    {
        $token = bin2hex(random_bytes(24)); // 48 hex chars
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET approval_status = "requested",
                    approval_token = ?,
                    approval_requested_at = NOW(),
                    approval_decided_at = NULL,
                    approval_decided_by_email = NULL,
                    approval_rejection_reason = NULL
              WHERE id = ?'
        )->execute([$token, $invoiceId]);
        return $token;
    }

    /**
     * Uloží rozhodnutí (approved/rejected). $decidedBy = email klienta (z public formu)
     * nebo email aktuálního uživatele (z admin „Změnit stav"). Token zneplatněn.
     */
    public function setApprovalDecision(int $invoiceId, string $newStatus, ?string $decidedBy, ?string $rejectionReason): void
    {
        if (!in_array($newStatus, ['approved', 'rejected'], true)) {
            throw new \InvalidArgumentException("Invalid approval status: $newStatus");
        }
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET approval_status = ?,
                    approval_token = NULL,
                    approval_decided_at = NOW(),
                    approval_decided_by_email = ?,
                    approval_rejection_reason = ?
              WHERE id = ?'
        )->execute([$newStatus, $decidedBy, $rejectionReason, $invoiceId]);
    }

    /**
     * Reset approval na 'none' (pro admin „Změnit stav" → none). Token zneplatněn.
     */
    public function resetApproval(int $invoiceId): void
    {
        $this->db->pdo()->prepare(
            'UPDATE invoices
                SET approval_status = "none",
                    approval_token = NULL,
                    approval_requested_at = NULL,
                    approval_decided_at = NULL,
                    approval_decided_by_email = NULL,
                    approval_rejection_reason = NULL
              WHERE id = ?'
        )->execute([$invoiceId]);
    }

    /** Najde fakturu podle approval_token (jen aktivní — token nesmí být null). */
    public function findByApprovalToken(string $token): ?array
    {
        $stmt = $this->db->pdo()->prepare('SELECT id FROM invoices WHERE approval_token = ?');
        $stmt->execute([$token]);
        $id = $stmt->fetchColumn();
        if ($id === false) return null;
        return $this->find((int) $id);
    }

    private function castItem(array $row): array
    {
        $row['id']                     = (int) $row['id'];
        $row['invoice_id']             = (int) $row['invoice_id'];
        $row['vat_rate_id']            = (int) $row['vat_rate_id'];
        $row['order_index']            = (int) $row['order_index'];
        $row['quantity']               = (float) $row['quantity'];
        $row['unit_price_without_vat'] = (float) $row['unit_price_without_vat'];
        $row['vat_rate_snapshot']      = (float) $row['vat_rate_snapshot'];
        foreach (['total_without_vat', 'total_vat', 'total_with_vat'] as $f) {
            $row[$f] = (float) $row[$f];
        }
        $row['linked_work_report_id'] = $row['linked_work_report_id'] !== null ? (int) $row['linked_work_report_id'] : null;
        return $row;
    }

    private function buildVatBreakdown(array $items): array
    {
        $bd = [];
        foreach ($items as $item) {
            $rate = (float) $item['vat_rate_snapshot'];
            $key = number_format($rate, 2, '.', '');
            if (!isset($bd[$key])) {
                $bd[$key] = ['rate' => $rate, 'base' => 0.0, 'vat' => 0.0];
            }
            $bd[$key]['base'] += (float) $item['total_without_vat'];
            $bd[$key]['vat']  += (float) $item['total_vat'];
        }
        $out = [];
        foreach ($bd as $b) {
            $out[] = [
                'rate' => $b['rate'],
                'base' => round($b['base'], 2),
                'vat'  => round($b['vat'], 2),
            ];
        }
        usort($out, fn ($a, $b) => $b['rate'] <=> $a['rate']);
        return $out;
    }
}
