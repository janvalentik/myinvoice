<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Strom složek sekce Dokumenty. Složky jsou virtuální — soubory leží na disku
 * podle hashe, ne podle stromu, takže přesun/přejmenování je čistě DB operace.
 *
 * Vše per-supplier; soft-delete přes deleted_at (koš).
 */
final class DocumentFolderRepository
{
    public function __construct(private readonly Connection $db) {}

    /** @return list<array<string,mixed>> Aktivní složky daného rodiče (NULL = root). */
    public function listChildren(int $supplierId, ?int $parentId): array
    {
        $sql = 'SELECT f.id, f.parent_id, f.name, f.created_at,
                       (SELECT COUNT(*) FROM document_folders c
                          WHERE c.parent_id = f.id AND c.deleted_at IS NULL) AS subfolder_count,
                       (SELECT COUNT(*) FROM documents d
                          WHERE d.folder_id = f.id AND d.deleted_at IS NULL
                            AND d.parent_document_id IS NULL) AS file_count
                  FROM document_folders f
                 WHERE f.supplier_id = ? AND f.deleted_at IS NULL
                   AND ' . ($parentId === null ? 'f.parent_id IS NULL' : 'f.parent_id = ?') . '
                 ORDER BY f.name';
        $params = $parentId === null ? [$supplierId] : [$supplierId, $parentId];
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> Celý aktivní strom dodavatele (pro sidebar). */
    public function listAll(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, parent_id, name, created_at
               FROM document_folders
              WHERE supplier_id = ? AND deleted_at IS NULL
              ORDER BY name'
        );
        $stmt->execute([$supplierId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function find(int $id, int $supplierId, bool $includeTrashed = false): ?array
    {
        $sql = 'SELECT * FROM document_folders WHERE id = ? AND supplier_id = ?'
             . ($includeTrashed ? '' : ' AND deleted_at IS NULL');
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /** Vrátí true, pokud existuje aktivní složka daného jména pod stejným rodičem. */
    public function existsByName(int $supplierId, ?int $parentId, string $name, int $excludeId = 0): bool
    {
        $sql = 'SELECT 1 FROM document_folders
                 WHERE supplier_id = ? AND deleted_at IS NULL AND name = ? AND id <> ?
                   AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?');
        $params = $parentId === null ? [$supplierId, $name, $excludeId] : [$supplierId, $name, $excludeId, $parentId];
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchColumn() !== false;
    }

    /** ID aktivní podsložky daného jména, nebo null. */
    public function findChildIdByName(int $supplierId, ?int $parentId, string $name): ?int
    {
        $sql = 'SELECT id FROM document_folders
                 WHERE supplier_id = ? AND deleted_at IS NULL AND name = ?
                   AND ' . ($parentId === null ? 'parent_id IS NULL' : 'parent_id = ?');
        $params = $parentId === null ? [$supplierId, $name] : [$supplierId, $name, $parentId];
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        $id = $stmt->fetchColumn();
        return $id !== false ? (int) $id : null;
    }

    public function create(int $supplierId, ?int $parentId, string $name, ?int $userId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO document_folders (supplier_id, parent_id, name, created_by)
             VALUES (?, ?, ?, ?)'
        );
        $stmt->execute([$supplierId, $parentId, $name, $userId]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function rename(int $id, int $supplierId, string $name): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE document_folders SET name = ? WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$name, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    public function move(int $id, int $supplierId, ?int $newParentId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE document_folders SET parent_id = ? WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$newParentId, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** ID všech potomků (rekurzivně) + sebe sama — pro detekci cyklu a kaskádní soft-delete. */
    public function descendantIds(int $id, int $supplierId): array
    {
        $all = $this->listAll($supplierId);
        $byParent = [];
        foreach ($all as $f) {
            $byParent[(int) ($f['parent_id'] ?? 0)][] = (int) $f['id'];
        }
        $result = [$id];
        $stack = [$id];
        while ($stack !== []) {
            $cur = array_pop($stack);
            foreach ($byParent[$cur] ?? [] as $child) {
                $result[] = $child;
                $stack[] = $child;
            }
        }
        return array_values(array_unique($result));
    }

    /** Soft-delete složky včetně potomků a všech dokumentů v nich (do koše). */
    public function softDeleteSubtree(int $id, int $supplierId, ?int $userId): array
    {
        $ids = $this->descendantIds($id, $supplierId);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare(
            "UPDATE document_folders SET deleted_at = CURRENT_TIMESTAMP
              WHERE supplier_id = ? AND deleted_at IS NULL AND id IN ($in)"
        );
        $stmt->execute(array_merge([$supplierId], $ids));

        $stmt = $pdo->prepare(
            "UPDATE documents SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ?
              WHERE supplier_id = ? AND deleted_at IS NULL AND folder_id IN ($in)"
        );
        $stmt->execute(array_merge([$userId, $supplierId], $ids));

        return $ids;
    }

    public function restore(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE document_folders SET deleted_at = NULL WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** @return list<array<string,mixed>> Soft-deleted složky (koš). */
    public function listTrashed(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, parent_id, name, deleted_at AS created_at
               FROM document_folders
              WHERE supplier_id = ? AND deleted_at IS NOT NULL
              ORDER BY deleted_at DESC'
        );
        $stmt->execute([$supplierId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Smaže prázdné soft-deleted složky (po vysypání koše). */
    public function purgeTrashed(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM document_folders WHERE supplier_id = ? AND deleted_at IS NOT NULL'
        );
        $stmt->execute([$supplierId]);
        return $stmt->rowCount();
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): array
    {
        return [
            'id'              => (int) $r['id'],
            'parent_id'       => isset($r['parent_id']) && $r['parent_id'] !== null ? (int) $r['parent_id'] : null,
            'name'            => (string) $r['name'],
            'created_at'      => (string) ($r['created_at'] ?? ''),
            'subfolder_count' => isset($r['subfolder_count']) ? (int) $r['subfolder_count'] : 0,
            'file_count'      => isset($r['file_count']) ? (int) $r['file_count'] : 0,
        ];
    }
}
