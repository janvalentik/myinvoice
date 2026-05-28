<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/** Tagy dokumentů (per-supplier) + mapování dokument↔tag. */
final class DocumentTagRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array{id:int,name:string}> */
    public function listForSupplier(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.id, t.name, COUNT(m.document_id) AS usage_count
               FROM document_tags t
               LEFT JOIN document_tag_map m ON m.tag_id = t.id
              WHERE t.supplier_id = ?
              GROUP BY t.id, t.name
              ORDER BY t.name'
        );
        $stmt->execute([$supplierId]);
        return array_map(static fn(array $r): array => [
            'id'          => (int) $r['id'],
            'name'        => (string) $r['name'],
            'usage_count' => (int) $r['usage_count'],
        ], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<string> Názvy tagů přiřazených dokumentu. */
    public function tagsForDocument(int $documentId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT t.name FROM document_tags t
               JOIN document_tag_map m ON m.tag_id = t.id
              WHERE m.document_id = ?
              ORDER BY t.name'
        );
        $stmt->execute([$documentId]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /** Najde-nebo-vytvoří tag a vrátí jeho id. */
    public function upsertTag(int $supplierId, string $name): int
    {
        $name = mb_substr(trim($name), 0, 64);
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare('SELECT id FROM document_tags WHERE supplier_id = ? AND name = ?');
        $stmt->execute([$supplierId, $name]);
        $id = $stmt->fetchColumn();
        if ($id !== false) {
            return (int) $id;
        }
        $stmt = $pdo->prepare('INSERT INTO document_tags (supplier_id, name) VALUES (?, ?)');
        $stmt->execute([$supplierId, $name]);
        return (int) $pdo->lastInsertId();
    }

    public function attach(int $documentId, int $tagId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT IGNORE INTO document_tag_map (document_id, tag_id) VALUES (?, ?)'
        );
        $stmt->execute([$documentId, $tagId]);
    }

    public function detach(int $documentId, int $tagId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM document_tag_map WHERE document_id = ? AND tag_id = ?'
        );
        $stmt->execute([$documentId, $tagId]);
    }

    /** Smaže tagy dodavatele, které už nejsou na žádném dokumentu (osamocené). */
    public function purgeOrphans(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE t FROM document_tags t
              LEFT JOIN document_tag_map m ON m.tag_id = t.id
              WHERE t.supplier_id = ? AND m.tag_id IS NULL'
        );
        $stmt->execute([$supplierId]);
        return $stmt->rowCount();
    }

    /** Nahradí celou sadu tagů dokumentu novými názvy (najde-nebo-vytvoří). */
    public function setTags(int $supplierId, int $documentId, array $names): void
    {
        $pdo = $this->db->pdo();
        $pdo->prepare('DELETE FROM document_tag_map WHERE document_id = ?')->execute([$documentId]);
        $seen = [];
        foreach ($names as $name) {
            $name = mb_substr(trim((string) $name), 0, 64);
            if ($name === '' || isset($seen[$name])) continue;
            $seen[$name] = true;
            $this->attach($documentId, $this->upsertTag($supplierId, $name));
        }
    }
}
