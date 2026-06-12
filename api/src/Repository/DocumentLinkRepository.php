<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Polymorfní vazba dokument ↔ entita (client/invoice/purchase_invoice/project). */
final class DocumentLinkRepository
{
    public const ENTITY_TYPES = ['client', 'invoice', 'purchase_invoice', 'project'];

    public function __construct(private readonly Connection $db) {}

    /** @return list<array{entity_type:string,entity_id:int,label:string}> Vazby dokumentu s popiskem entity. */
    public function linksForDocument(int $documentId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT entity_type, entity_id FROM document_links WHERE document_id = ? ORDER BY entity_type, entity_id'
        );
        $stmt->execute([$documentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $type = (string) $r['entity_type'];
            $eid = (int) $r['entity_id'];
            $out[] = [
                'entity_type' => $type,
                'entity_id'   => $eid,
                'label'       => $this->labelFor($type, $eid, $supplierId),
            ];
        }
        return $out;
    }

    /**
     * Ověří, že cílová entita existuje a patří danému dodavateli (scope guard pro
     * zakládání vazby). Zrcadlí WHERE klauzule z labelFor(). Projects nemají
     * supplier_id → scope přes klienta.
     */
    public function entityBelongsToSupplier(string $type, int $id, int $supplierId): bool
    {
        if (!in_array($type, self::ENTITY_TYPES, true) || $id <= 0) {
            return false;
        }
        $sql = match ($type) {
            'client'           => 'SELECT 1 FROM clients WHERE id = ? AND supplier_id = ? LIMIT 1',
            'invoice'          => 'SELECT 1 FROM invoices WHERE id = ? AND supplier_id = ? LIMIT 1',
            'purchase_invoice' => 'SELECT 1 FROM purchase_invoices WHERE id = ? AND supplier_id = ? LIMIT 1',
            'project'          => 'SELECT 1 FROM projects p JOIN clients c ON c.id = p.client_id WHERE p.id = ? AND c.supplier_id = ? LIMIT 1',
        };
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$id, $supplierId]);
        return $stmt->fetchColumn() !== false;
    }

    public function attach(int $documentId, string $entityType, int $entityId): void
    {
        if (!in_array($entityType, self::ENTITY_TYPES, true)) return;
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO document_links (document_id, entity_type, entity_id) VALUES (?, ?, ?)'
        );
        $stmt->execute([$documentId, $entityType, $entityId]);
    }

    public function detach(int $documentId, string $entityType, int $entityId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM document_links WHERE document_id = ? AND entity_type = ? AND entity_id = ?'
        );
        $stmt->execute([$documentId, $entityType, $entityId]);
    }

    /**
     * Lidsky čitelný popisek entity (per-supplier ověřeno joinem).
     * Vrací bohatší informaci než jen číslo — např. „VS · firma · částka".
     */
    private function labelFor(string $type, int $id, int $supplierId): string
    {
        $pdo = $this->db->pdo();
        try {
            switch ($type) {
                case 'client':
                    $stmt = $pdo->prepare('SELECT company_name, ic FROM clients WHERE id = ? AND supplier_id = ?');
                    $stmt->execute([$id, $supplierId]);
                    $r = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$r) return '#' . $id;
                    return trim((string) $r['company_name'] . ($r['ic'] ? ' · IČ ' . $r['ic'] : ''));
                case 'invoice':
                    $stmt = $pdo->prepare(
                        'SELECT i.varsymbol, i.issue_date, i.total_with_vat, c.company_name,
                                COALESCE(cur.code, \'CZK\') AS currency
                           FROM invoices i
                           JOIN clients c ON c.id = i.client_id
                      LEFT JOIN currencies cur ON cur.id = i.currency_id
                          WHERE i.id = ? AND i.supplier_id = ?'
                    );
                    $stmt->execute([$id, $supplierId]);
                    $r = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$r) return '#' . $id;
                    return $this->invoiceLabel((string) ($r['varsymbol'] ?? ''), (string) $r['company_name'], $r['issue_date'], $r['total_with_vat'], (string) $r['currency'], $id);
                case 'purchase_invoice':
                    $stmt = $pdo->prepare(
                        'SELECT pi.varsymbol, pi.vendor_invoice_number, pi.issue_date, pi.total_with_vat,
                                c.company_name, COALESCE(cur.code, \'CZK\') AS currency
                           FROM purchase_invoices pi
                           JOIN clients c ON c.id = pi.vendor_id
                      LEFT JOIN currencies cur ON cur.id = pi.currency_id
                          WHERE pi.id = ? AND pi.supplier_id = ?'
                    );
                    $stmt->execute([$id, $supplierId]);
                    $r = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$r) return '#' . $id;
                    $num = (string) ($r['varsymbol'] ?: $r['vendor_invoice_number'] ?: '');
                    return $this->invoiceLabel($num, (string) $r['company_name'], $r['issue_date'], $r['total_with_vat'], (string) $r['currency'], $id);
                case 'project':
                    // projects nemá supplier_id — scope přes klienta.
                    $stmt = $pdo->prepare(
                        'SELECT p.name, p.project_number, c.company_name
                           FROM projects p JOIN clients c ON c.id = p.client_id
                          WHERE p.id = ? AND c.supplier_id = ?'
                    );
                    $stmt->execute([$id, $supplierId]);
                    $r = $stmt->fetch(PDO::FETCH_ASSOC);
                    if (!$r) return '#' . $id;
                    return trim((string) $r['name'] . ($r['company_name'] ? ' · ' . $r['company_name'] : ''));
            }
        } catch (\Throwable) {
            // snášíme — sloupec/tabulka se může lišit; fallback na #id
        }
        return '#' . $id;
    }

    private function invoiceLabel(string $num, string $company, mixed $date, mixed $total, string $currency, int $id): string
    {
        $parts = [];
        $parts[] = $num !== '' ? $num : ('#' . $id);
        if ($company !== '') $parts[] = $company;
        if ($total !== null) $parts[] = number_format((float) $total, 0, ',', ' ') . ' ' . $currency;
        if ($date) $parts[] = (string) $date;
        return implode(' · ', $parts);
    }
}
