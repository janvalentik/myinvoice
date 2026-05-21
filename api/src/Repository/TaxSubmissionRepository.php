<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;

/**
 * Archiv generovaných EPO XML výkazů (DPHDP3, DPHKH1, DPHSHV, DPFDP5, DPPDP9).
 *
 * **NEpodává** se přes MyInvoice — uživatel stahuje XML a podává ručně na EPO portálu.
 * Tato tabulka jen archivuje **co bylo kdy vygenerováno** pro audit accountability.
 */
final class TaxSubmissionRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Archivovat vygenerovaný XML. Vrátí ID záznamu.
     *
     * @param array<string,mixed> $summary
     * @param list<string> $validationErrors
     */
    public function archive(
        int $supplierId,
        string $formCode,
        int $year,
        ?int $month,
        ?int $quarter,
        string $xml,
        array $summary,
        string $validationStatus,
        array $validationErrors,
        ?int $generatedBy,
    ): int {
        $stmt = $this->db->pdo()->prepare(
            "INSERT INTO tax_submissions
                (supplier_id, form_code, period_year, period_month, period_quarter,
                 xml_content, xml_size_bytes, xml_sha256,
                 validation_status, validation_errors, summary_json, generated_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $stmt->execute([
            $supplierId, $formCode, $year, $month, $quarter,
            $xml, strlen($xml), hash('sha256', $xml),
            $validationStatus,
            !empty($validationErrors) ? json_encode($validationErrors, JSON_UNESCAPED_UNICODE) : null,
            json_encode($summary, JSON_UNESCAPED_UNICODE),
            $generatedBy,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    /**
     * Seznam záznamů per tenant. Vrátí bez `xml_content` (jen metadata) pro list view.
     *
     * @return list<array<string,mixed>>
     */
    public function list(int $supplierId, int $limit = 100): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT id, supplier_id, form_code, period_year, period_month, period_quarter,
                    xml_size_bytes, xml_sha256, validation_status, validation_errors,
                    summary_json, generated_by, generated_at, notes
               FROM tax_submissions
              WHERE supplier_id = ?
           ORDER BY generated_at DESC
              LIMIT ?"
        );
        $stmt->execute([$supplierId, $limit]);
        return array_map(fn ($r) => $this->normalize($r), $stmt->fetchAll(\PDO::FETCH_ASSOC));
    }

    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM tax_submissions WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalize($row) : null;
    }

    public function delete(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare('DELETE FROM tax_submissions WHERE id = ? AND supplier_id = ?');
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    private function normalize(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['supplier_id'] = (int) $row['supplier_id'];
        $row['period_year'] = (int) $row['period_year'];
        $row['period_month'] = $row['period_month'] !== null ? (int) $row['period_month'] : null;
        $row['period_quarter'] = $row['period_quarter'] !== null ? (int) $row['period_quarter'] : null;
        $row['xml_size_bytes'] = (int) $row['xml_size_bytes'];
        if (isset($row['summary_json']) && $row['summary_json'] !== null) {
            $row['summary'] = json_decode((string) $row['summary_json'], true) ?: null;
            unset($row['summary_json']);
        }
        if (isset($row['validation_errors']) && $row['validation_errors'] !== null) {
            $row['validation_errors'] = json_decode((string) $row['validation_errors'], true) ?: [];
        } else {
            $row['validation_errors'] = [];
        }
        return $row;
    }
}
