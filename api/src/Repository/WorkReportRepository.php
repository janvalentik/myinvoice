<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

final class WorkReportRepository
{
    public function __construct(private readonly Connection $db) {}

    public function findByInvoice(int $invoiceId): ?array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT * FROM work_reports WHERE invoice_id = ?');
        $stmt->execute([$invoiceId]);
        $wr = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($wr === false) return null;

        $wr['total_hours']          = (float) $wr['total_hours'];
        $wr['total_amount']         = (float) $wr['total_amount'];
        $wr['vat_rate_id']          = isset($wr['vat_rate_id']) ? (int) $wr['vat_rate_id'] : null;
        $wr['material_title']       = $wr['material_title'] !== null ? (string) $wr['material_title'] : null;
        $wr['material_total']       = (float) ($wr['material_total'] ?? 0);
        $wr['material_vat_rate_id'] = isset($wr['material_vat_rate_id']) ? (int) $wr['material_vat_rate_id'] : null;
        $wr['items']                = $this->itemsFor((int) $wr['id']);
        $wr['materials']            = $this->materialsFor((int) $wr['id']);
        return $wr;
    }

    /** @return list<array<string,mixed>> */
    private function itemsFor(int $workReportId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, description, work_date, hours, rate, total_amount, order_index
               FROM work_report_items
              WHERE work_report_id = ?
           ORDER BY order_index, id'
        );
        $stmt->execute([$workReportId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['hours']        = (float) $r['hours'];
            $r['rate']         = (float) $r['rate'];
            $r['total_amount'] = (float) $r['total_amount'];
            $r['order_index']  = (int) $r['order_index'];
            $r['id']           = (int) $r['id'];
            $r['work_date']    = $r['work_date'] ?: null;
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function materialsFor(int $workReportId): array
    {
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id, description, quantity, unit, unit_price, total_amount, order_index
               FROM work_report_materials
              WHERE work_report_id = ?
           ORDER BY order_index, id'
        );
        $stmt->execute([$workReportId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) {
            $r['id']           = (int) $r['id'];
            $r['quantity']     = (float) $r['quantity'];
            $r['unit']         = (string) $r['unit'];
            $r['unit_price']   = (float) $r['unit_price'];
            $r['total_amount'] = (float) $r['total_amount'];
            $r['order_index']  = (int) $r['order_index'];
        }
        return $rows;
    }

    /**
     * Uloží work_report (upsert) + nahradí items (část PRÁCE).
     * Nesahá na materiál (material_*, work_report_materials).
     * Vrací id work_reportu.
     */
    public function save(int $invoiceId, ?int $projectId, string $title, array $items, ?int $vatRateId = null): int
    {
        $pdo = $this->db->pdo();
        $existing = $this->findByInvoice($invoiceId);

        $totalHours  = 0.0;
        $totalAmount = 0.0;
        foreach ($items as $it) {
            $totalHours  += (float) ($it['hours'] ?? 0);
            $totalAmount += (float) ($it['hours'] ?? 0) * (float) ($it['rate'] ?? 0);
        }

        // project_id je nullable — faktura nemusí mít zakázku.
        $projectIdParam = ($projectId !== null && $projectId > 0) ? $projectId : null;

        if ($existing) {
            $id = (int) $existing['id'];
            $pdo->prepare(
                'UPDATE work_reports SET project_id=?, title=?, total_hours=?, total_amount=?, vat_rate_id=? WHERE id=?'
            )->execute([$projectIdParam, $title, $totalHours, $totalAmount, $vatRateId, $id]);
        } else {
            $pdo->prepare(
                'INSERT INTO work_reports (invoice_id, project_id, title, total_hours, total_amount, vat_rate_id)
                 VALUES (?,?,?,?,?,?)'
            )->execute([$invoiceId, $projectIdParam, $title, $totalHours, $totalAmount, $vatRateId]);
            $id = (int) $pdo->lastInsertId();
        }

        // Nahradit items
        $pdo->prepare('DELETE FROM work_report_items WHERE work_report_id = ?')->execute([$id]);
        $insert = $pdo->prepare(
            'INSERT INTO work_report_items (work_report_id, description, work_date, hours, rate, total_amount, order_index)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($items as $idx => $it) {
            $hours = (float) ($it['hours'] ?? 0);
            $rate  = (float) ($it['rate'] ?? 0);
            $workDate = isset($it['work_date']) ? trim((string) $it['work_date']) : '';
            $insert->execute([
                $id,
                (string) ($it['description'] ?? ''),
                $workDate !== '' ? $workDate : null,
                $hours,
                $rate,
                round($hours * $rate, 2),
                (int) ($it['order_index'] ?? $idx),
            ]);
        }

        return $id;
    }

    /**
     * Uloží část MATERIÁL téže work_reports řádky (upsert) + nahradí work_report_materials.
     * Řádka work_reports vznikne lazy, pokud ještě neexistuje (title = materialTitle fallback).
     * Nesahá na práci (title/total_hours/total_amount/work_report_items).
     * Vrací id work_reportu.
     *
     * @param list<array<string,mixed>> $materials
     */
    public function saveMaterials(int $invoiceId, ?int $projectId, ?string $materialTitle, ?int $materialVatRateId, array $materials): int
    {
        $pdo = $this->db->pdo();
        $existing = $this->findByInvoice($invoiceId);

        $materialTotal = 0.0;
        foreach ($materials as $m) {
            $materialTotal += round((float) ($m['quantity'] ?? 0) * (float) ($m['unit_price'] ?? 0), 2);
        }

        $projectIdParam = ($projectId !== null && $projectId > 0) ? $projectId : null;

        if ($existing) {
            $id = (int) $existing['id'];
            $pdo->prepare(
                'UPDATE work_reports SET material_title=?, material_total=?, material_vat_rate_id=? WHERE id=?'
            )->execute([$materialTitle, $materialTotal, $materialVatRateId, $id]);
        } else {
            // Lazy vznik řádky — práce zatím prázdná (title je NOT NULL → fallback na materialTitle).
            $title = ($materialTitle !== null && $materialTitle !== '') ? $materialTitle : 'Materiál';
            $pdo->prepare(
                'INSERT INTO work_reports (invoice_id, project_id, title, total_hours, total_amount, material_title, material_total, material_vat_rate_id)
                 VALUES (?,?,?,0,0,?,?,?)'
            )->execute([$invoiceId, $projectIdParam, $title, $materialTitle, $materialTotal, $materialVatRateId]);
            $id = (int) $pdo->lastInsertId();
        }

        // Nahradit řádky materiálu
        $pdo->prepare('DELETE FROM work_report_materials WHERE work_report_id = ?')->execute([$id]);
        $insert = $pdo->prepare(
            'INSERT INTO work_report_materials (work_report_id, description, quantity, unit, unit_price, total_amount, order_index)
             VALUES (?,?,?,?,?,?,?)'
        );
        foreach ($materials as $idx => $m) {
            $quantity  = (float) ($m['quantity'] ?? 0);
            $unitPrice = (float) ($m['unit_price'] ?? 0);
            $unit      = trim((string) ($m['unit'] ?? 'ks'));
            $insert->execute([
                $id,
                (string) ($m['description'] ?? ''),
                $quantity,
                $unit !== '' ? $unit : 'ks',
                $unitPrice,
                round($quantity * $unitPrice, 2),
                (int) ($m['order_index'] ?? $idx),
            ]);
        }

        return $id;
    }

    /** Existuje sazba DPH v číselníku? (validace vat_rate_id z výkazu.) */
    public function vatRateExists(int $vatRateId): bool
    {
        $stmt = $this->db->pdo()->prepare('SELECT 1 FROM vat_rates WHERE id = ?');
        $stmt->execute([$vatRateId]);
        return $stmt->fetchColumn() !== false;
    }

    public function deleteByInvoice(int $invoiceId): bool
    {
        $pdo = $this->db->pdo();
        return $pdo->prepare('DELETE FROM work_reports WHERE invoice_id = ?')->execute([$invoiceId]);
    }
}
