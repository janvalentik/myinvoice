<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** ISDS metadata datové zprávy (ZFO kontejner). */
final class DmsMessageRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @param array<string,mixed> $m */
    public function insert(int $documentId, array $m): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO document_dms_messages
                (document_id, dm_id, direction, sender_box_id, sender_name, sender_address,
                 sender_type, recipient_box_id, recipient_name, recipient_address, annotation,
                 sender_ref_number, sender_ident, recipient_ref_number, recipient_ident,
                 dm_type, dm_status, delivery_time, acceptance_time, envelope_xml)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $documentId,
            $m['dm_id'] ?? null,
            $m['direction'] ?? 'unknown',
            $m['sender_box_id'] ?? null,
            $m['sender_name'] ?? null,
            $m['sender_address'] ?? null,
            $m['sender_type'] ?? null,
            $m['recipient_box_id'] ?? null,
            $m['recipient_name'] ?? null,
            $m['recipient_address'] ?? null,
            $m['annotation'] ?? null,
            $m['sender_ref_number'] ?? null,
            $m['sender_ident'] ?? null,
            $m['recipient_ref_number'] ?? null,
            $m['recipient_ident'] ?? null,
            $m['dm_type'] ?? null,
            $m['dm_status'] ?? null,
            $m['delivery_time'] ?? null,
            $m['acceptance_time'] ?? null,
            $m['envelope_xml'] ?? null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function findByDocument(int $documentId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT dm_id, direction, sender_box_id, sender_name, sender_address, sender_type,
                    recipient_box_id, recipient_name, recipient_address, annotation,
                    sender_ref_number, sender_ident, recipient_ref_number, recipient_ident,
                    dm_type, dm_status, delivery_time, acceptance_time
               FROM document_dms_messages WHERE document_id = ? LIMIT 1'
        );
        $stmt->execute([$documentId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }
}
