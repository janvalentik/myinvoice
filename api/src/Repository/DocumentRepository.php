<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Dokumenty sekce Dokumenty. Vše per-supplier, soft-delete přes deleted_at (koš).
 * Fyzické soubory leží na disku (viz DocumentStorage); tady jen metadata.
 */
final class DocumentRepository
{
    public function __construct(private readonly Connection $db) {}

    private const COLS = 'id, supplier_id, folder_id, title, description, original_name,
        filename, sha256, mime_type, size_bytes, doc_type, source, parent_document_id,
        signature_for_id, text_status, thumb_path, thumb_status, uploaded_by,
        deleted_at, created_at';

    /**
     * @param array{
     *   supplier_id:int, folder_id:?int, title:string, description:?string,
     *   original_name:string, filename:string, sha256:string, mime_type:string,
     *   size_bytes:int, doc_type:string, source?:string, parent_document_id?:?int,
     *   signature_for_id?:?int, uploaded_by:?int
     * } $d
     */
    public function insert(array $d): int
    {
        $stmt = $this->db->pdo()->prepare(
            'INSERT INTO documents
                (supplier_id, folder_id, title, description, original_name, filename,
                 sha256, mime_type, size_bytes, doc_type, source, parent_document_id,
                 signature_for_id, uploaded_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $d['supplier_id'],
            $d['folder_id'] ?? null,
            $d['title'],
            $d['description'] ?? null,
            $d['original_name'],
            $d['filename'],
            $d['sha256'],
            $d['mime_type'],
            $d['size_bytes'],
            $d['doc_type'],
            $d['source'] ?? 'manual',
            $d['parent_document_id'] ?? null,
            $d['signature_for_id'] ?? null,
            $d['uploaded_by'] ?? null,
        ]);
        return (int) $this->db->pdo()->lastInsertId();
    }

    public function find(int $id, int $supplierId, bool $includeTrashed = false): ?array
    {
        $sql = 'SELECT * FROM documents WHERE id = ? AND supplier_id = ?'
             . ($includeTrashed ? '' : ' AND deleted_at IS NULL');
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->hydrate($row) : null;
    }

    /** Surový řádek (vč. filename/sha) — pro download/preview. */
    public function findRaw(int $id, int $supplierId, bool $includeTrashed = false): ?array
    {
        $sql = 'SELECT * FROM documents WHERE id = ? AND supplier_id = ?'
             . ($includeTrashed ? '' : ' AND deleted_at IS NULL');
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    /**
     * Aktivní dokumenty ve složce (top-level — ne přílohy ZFO).
     * @return list<array<string,mixed>>
     */
    public function listInFolder(int $supplierId, ?int $folderId, ?string $docType = null): array
    {
        $sql = 'SELECT ' . self::COLS . ' FROM documents
                 WHERE supplier_id = ? AND deleted_at IS NULL AND parent_document_id IS NULL
                   AND ' . ($folderId === null ? 'folder_id IS NULL' : 'folder_id = ?');
        $params = $folderId === null ? [$supplierId] : [$supplierId, $folderId];
        if ($docType !== null && $docType !== '') {
            $sql .= ' AND doc_type = ?';
            $params[] = $docType;
        }
        $sql .= ' ORDER BY created_at DESC, id DESC';
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Přílohy ZFO kontejneru. @return list<array<string,mixed>> */
    public function listChildren(int $parentId, int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM documents
              WHERE parent_document_id = ? AND supplier_id = ? AND deleted_at IS NULL
              ORDER BY id'
        );
        $stmt->execute([$parentId, $supplierId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    public function listTrash(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ' . self::COLS . ' FROM documents
              WHERE supplier_id = ? AND deleted_at IS NOT NULL AND parent_document_id IS NULL
              ORDER BY deleted_at DESC, id DESC'
        );
        $stmt->execute([$supplierId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Fulltext hledání v metadatech i obsahu (MariaDB FULLTEXT, NATURAL LANGUAGE).
     * Fallback na LIKE pro krátké dotazy (pod min. délkou tokenu fulltextu).
     * @return list<array<string,mixed>>
     */
    public function search(int $supplierId, string $q, int $limit = 50): array
    {
        $q = trim($q);
        if ($q === '') return [];

        // LIMIT inlinujeme jako int (vlastní hodnota) — native prepared statements
        // neumí LIMIT s parametrem typu string. Placeholdery jsou poziční (?),
        // protože MySQL native prepare nepovoluje opakování pojmenovaného placeholderu.
        $lim = max(1, min(500, $limit));
        $like = '%' . $q . '%';

        if (mb_strlen($q) >= 3) {
            $sql = 'SELECT ' . self::COLS . ',
                       (MATCH(title, description) AGAINST (? IN NATURAL LANGUAGE MODE) * 2
                        + MATCH(content_text) AGAINST (? IN NATURAL LANGUAGE MODE)) AS score
                      FROM documents
                     WHERE supplier_id = ? AND deleted_at IS NULL
                       AND (MATCH(title, description) AGAINST (? IN NATURAL LANGUAGE MODE)
                            OR MATCH(content_text) AGAINST (? IN NATURAL LANGUAGE MODE)
                            OR title LIKE ? OR original_name LIKE ?)
                     ORDER BY score DESC, created_at DESC
                     LIMIT ' . $lim;
            $params = [$q, $q, $supplierId, $q, $q, $like, $like];
        } else {
            $sql = 'SELECT ' . self::COLS . ', 0 AS score FROM documents
                     WHERE supplier_id = ? AND deleted_at IS NULL
                       AND (title LIKE ? OR original_name LIKE ? OR description LIKE ?)
                     ORDER BY created_at DESC
                     LIMIT ' . $lim;
            $params = [$supplierId, $like, $like, $like];
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    public function updateMeta(int $id, int $supplierId, string $title, ?string $description): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET title = ?, description = ?
              WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL'
        );
        $stmt->execute([$title, $description, $id, $supplierId]);
        return $stmt->rowCount() >= 0;
    }

    public function setText(int $id, ?string $text, string $status): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET content_text = ?, text_status = ? WHERE id = ?'
        );
        $stmt->execute([$text, $status, $id]);
    }

    public function setSignatureFor(int $id, int $signedDocumentId): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET signature_for_id = ? WHERE id = ?'
        );
        $stmt->execute([$signedDocumentId, $id]);
    }

    public function setThumb(int $id, ?string $thumbPath, string $status): void
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET thumb_path = ?, thumb_status = ? WHERE id = ?'
        );
        $stmt->execute([$thumbPath, $status, $id]);
    }

    public function move(int $id, int $supplierId, ?int $folderId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET folder_id = ?
              WHERE id = ? AND supplier_id = ? AND deleted_at IS NULL AND parent_document_id IS NULL'
        );
        $stmt->execute([$folderId, $id, $supplierId]);
        return $stmt->rowCount() > 0;
    }

    /** Soft-delete dokumentu + jeho příloh (ZFO děti). */
    public function softDelete(int $id, int $supplierId, ?int $userId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET deleted_at = CURRENT_TIMESTAMP, deleted_by = ?
              WHERE supplier_id = ? AND deleted_at IS NULL AND (id = ? OR parent_document_id = ?)'
        );
        $stmt->execute([$userId, $supplierId, $id, $id]);
        return $stmt->rowCount() > 0;
    }

    public function restore(int $id, int $supplierId): bool
    {
        $stmt = $this->db->pdo()->prepare(
            'UPDATE documents SET deleted_at = NULL, deleted_by = NULL
              WHERE supplier_id = ? AND (id = ? OR parent_document_id = ?)'
        );
        $stmt->execute([$supplierId, $id, $id]);
        return $stmt->rowCount() > 0;
    }

    /** Surové řádky v koši (vč. filename/sha) — pro fyzické mazání. @return list<array<string,mixed>> */
    public function listTrashedRaw(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, sha256, filename, thumb_path FROM documents
              WHERE supplier_id = ? AND deleted_at IS NOT NULL'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public function hardDeleteTrashed(int $supplierId): int
    {
        $stmt = $this->db->pdo()->prepare(
            'DELETE FROM documents WHERE supplier_id = ? AND deleted_at IS NOT NULL'
        );
        $stmt->execute([$supplierId]);
        return $stmt->rowCount();
    }

    /**
     * Počet (i smazaných) dokumentů daného dodavatele se stejným sha256, kromě dané sady id.
     * Pro dedup-aware fyzické mazání: soubor smažeme jen když je výsledek 0.
     */
    public function countBySha(int $supplierId, string $sha256, array $excludeIds = []): int
    {
        $sql = 'SELECT COUNT(*) FROM documents WHERE supplier_id = ? AND sha256 = ?';
        $params = [$supplierId, $sha256];
        if ($excludeIds !== []) {
            $in = implode(',', array_fill(0, count($excludeIds), '?'));
            $sql .= " AND id NOT IN ($in)";
            $params = array_merge($params, array_map('intval', $excludeIds));
        }
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Dokumenty s daným tagem (globálně přes všechny složky). @return list<array<string,mixed>> */
    public function listByTag(int $supplierId, string $tag): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.* FROM documents d
                JOIN document_tag_map m ON m.document_id = d.id
                JOIN document_tags t ON t.id = m.tag_id
              WHERE d.supplier_id = ? AND d.deleted_at IS NULL AND d.parent_document_id IS NULL
                AND t.name = ?
              ORDER BY d.title'
        );
        $stmt->execute([$supplierId, $tag]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** Dokumenty navázané na entitu (oboustranné provázání). @return list<array<string,mixed>> */
    public function listByEntity(int $supplierId, string $entityType, int $entityId): array
    {
        // d.* (ne self::COLS) — join s document_links zavádí sloupec created_at i tam,
        // takže nekvalifikované názvy by byly nejednoznačné. hydrate() si vybere potřebné.
        $stmt = $this->db->pdo()->prepare(
            'SELECT d.* FROM documents d
                JOIN document_links l ON l.document_id = d.id
              WHERE d.supplier_id = ? AND d.deleted_at IS NULL
                AND l.entity_type = ? AND l.entity_id = ?
              ORDER BY d.created_at DESC'
        );
        $stmt->execute([$supplierId, $entityType, $entityId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @param array<string,mixed> $r */
    private function hydrate(array $r): array
    {
        return [
            'id'                 => (int) $r['id'],
            'supplier_id'        => (int) $r['supplier_id'],
            'folder_id'          => $r['folder_id'] !== null ? (int) $r['folder_id'] : null,
            'title'              => (string) $r['title'],
            'description'        => $r['description'] !== null ? (string) $r['description'] : null,
            'original_name'      => (string) $r['original_name'],
            'filename'           => (string) $r['filename'],
            'sha256'             => (string) $r['sha256'],
            'mime_type'          => (string) $r['mime_type'],
            'size_bytes'         => (int) $r['size_bytes'],
            'doc_type'           => (string) $r['doc_type'],
            'source'             => (string) $r['source'],
            'parent_document_id' => $r['parent_document_id'] !== null ? (int) $r['parent_document_id'] : null,
            'signature_for_id'   => $r['signature_for_id'] !== null ? (int) $r['signature_for_id'] : null,
            'text_status'        => (string) $r['text_status'],
            'thumb_status'       => (string) $r['thumb_status'],
            'has_thumb'          => ($r['thumb_status'] ?? '') === 'generated',
            'uploaded_by'        => $r['uploaded_by'] !== null ? (int) $r['uploaded_by'] : null,
            'deleted_at'         => $r['deleted_at'] !== null ? (string) $r['deleted_at'] : null,
            'created_at'         => (string) $r['created_at'],
        ];
    }
}
