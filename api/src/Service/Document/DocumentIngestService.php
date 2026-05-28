<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document;

use MyInvoice\Repository\DmsMessageRepository;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\DocumentRepository;

/**
 * Orchestruje uložení nahraného souboru do sekce Dokumenty:
 *   - běžný soubor → uložit + extrahovat text + náhled,
 *   - ZFO → uložit kontejner + rozbalit metadata zprávy + přílohy jako děti,
 *   - ZIP (režim explode) → bezpečně rozbalit + rekonstruovat strom složek.
 *
 * Sdílí logiku rekonstrukce stromu složek (z relativních cest) i pro upload
 * celého adresáře z prohlížeče (webkitdirectory).
 */
final class DocumentIngestService
{
    private const ZIP_FILE_CAP = 300 * 1024 * 1024;

    public function __construct(
        private readonly DocumentStorage $storage,
        private readonly DocumentRepository $documents,
        private readonly DocumentFolderRepository $folders,
        private readonly DmsMessageRepository $dms,
        private readonly ZfoExtractor $zfo,
        private readonly ZipImporter $zipImporter,
        private readonly DocumentTextExtractor $textExtractor,
        private readonly ThumbnailGenerator $thumbnails,
    ) {}

    /**
     * Hlavní vstup pro jeden nahraný soubor (z dočasné cesty).
     *
     * @return array{kind:string,created_ids:list<int>,container_id:?int,skipped:list<array{name:string,reason:string}>}
     * @throws DocumentException
     */
    public function ingestUploadedTemp(
        string $tmpPath,
        int $supplierId,
        ?int $folderId,
        string $originalName,
        ?int $userId,
        string $zipMode = 'keep',
    ): array {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        // ZFO — auto-rozbalení
        if ($ext === 'zfo') {
            $head = (string) @file_get_contents($tmpPath, false, null, 0, 64);
            if (ZfoExtractor::looksLikeZfo($head)) {
                return $this->handleZfo($tmpPath, $supplierId, $folderId, $originalName, $userId);
            }
        }

        // ZIP — režim explode (rozbalit a kategorizovat)
        if ($ext === 'zip' && $zipMode === 'explode') {
            return $this->handleZipExplode($tmpPath, $supplierId, $folderId, $userId);
        }

        // Běžný soubor (vč. ZIP v režimu keep)
        $stored = $this->storage->storeFromTemp($tmpPath, $supplierId, $originalName);
        $id = $this->insertAndProcess($stored, $supplierId, $folderId, $originalName, $userId, 'manual', null);
        return ['kind' => 'plain', 'created_ids' => [$id], 'container_id' => null, 'skipped' => []];
    }

    /**
     * Najde-nebo-vytvoří cestu složek z relativních segmentů pod baseFolderId.
     * @param list<string> $segments
     */
    public function ensureFolderPath(int $supplierId, ?int $baseFolderId, array $segments, ?int $userId): ?int
    {
        $cur = $baseFolderId;
        foreach ($segments as $seg) {
            $seg = trim($seg);
            if ($seg === '') continue;
            $existing = $this->folders->findChildIdByName($supplierId, $cur, $seg);
            $cur = $existing ?? $this->folders->create($supplierId, $cur, $seg, $userId);
        }
        return $cur;
    }

    // ───────────────────────── interní ─────────────────────────

    /**
     * @param array{sha256:string,filename:string,size_bytes:int,mime_type:string,doc_type:string,abs_path:string,ext:string} $stored
     */
    private function insertAndProcess(
        array $stored,
        int $supplierId,
        ?int $folderId,
        string $originalName,
        ?int $userId,
        string $source,
        ?int $parentId,
    ): int {
        $id = $this->documents->insert([
            'supplier_id'        => $supplierId,
            'folder_id'          => $folderId,
            // title = původní (čitelný) název pro zobrazení; na disk se nepoužívá.
            'title'              => mb_substr(trim($originalName), 0, 255) ?: 'dokument',
            'description'        => null,
            'original_name'      => $originalName,
            'filename'           => $stored['filename'],
            'sha256'             => $stored['sha256'],
            'mime_type'          => $stored['mime_type'],
            'size_bytes'         => $stored['size_bytes'],
            'doc_type'           => $stored['doc_type'],
            'source'             => $source,
            'parent_document_id' => $parentId,
            'uploaded_by'        => $userId,
        ]);
        $this->postProcess($id, $stored, $supplierId);
        return $id;
    }

    /** Extrakce textu + náhled — best-effort, nikdy nepoloží ingest. */
    private function postProcess(int $id, array $stored, int $supplierId): void
    {
        try {
            $res = $this->textExtractor->extract($stored['abs_path'], $stored['doc_type'], $stored['ext']);
            $this->documents->setText($id, $res['text'], $res['status']);
        } catch (\Throwable) {
            $this->documents->setText($id, null, 'failed');
        }
        try {
            $res = $this->thumbnails->generate($stored['abs_path'], $stored['doc_type'], $stored['sha256'], $supplierId);
            $this->documents->setThumb($id, $res['path'], $res['status']);
        } catch (\Throwable) {
            $this->documents->setThumb($id, null, 'failed');
        }
    }

    /** @return array{kind:string,created_ids:list<int>,container_id:?int,skipped:list<array{name:string,reason:string}>} */
    private function handleZfo(string $tmpPath, int $supplierId, ?int $folderId, string $originalName, ?int $userId): array
    {
        $stored = $this->storage->storeFromTemp($tmpPath, $supplierId, $originalName);
        $containerId = $this->insertAndProcess($stored, $supplierId, $folderId, $originalName, $userId, 'manual', null);

        $created = [$containerId];
        $skipped = [];

        $der = (string) @file_get_contents($stored['abs_path']);
        try {
            $parsed = $this->zfo->extract($der);
        } catch (DocumentException $e) {
            // Nepodařilo se rozbalit — kontejner zůstane jako prostý soubor.
            return ['kind' => 'zfo', 'created_ids' => $created, 'container_id' => $containerId, 'skipped' => [
                ['name' => $originalName, 'reason' => $e->errorCode],
            ]];
        }

        $this->dms->insert($containerId, $parsed['metadata']);

        /** @var array<string,int> $byBaseName  basename → doc id (pro P7S asociaci) */
        $byBaseName = [];
        $p7sChildren = [];
        foreach ($parsed['attachments'] as $att) {
            try {
                $childStored = $this->storage->storeFromBytes($att['bytes'], $supplierId, $att['name']);
            } catch (DocumentException $e) {
                $skipped[] = ['name' => $att['name'], 'reason' => $e->errorCode];
                continue;
            }
            $childId = $this->insertAndProcess($childStored, $supplierId, $folderId, $att['name'], $userId, 'zfo_extract', $containerId);
            $created[] = $childId;

            $base = pathinfo($att['name'], PATHINFO_FILENAME);
            $byBaseName[$base] = $childId;
            if ($childStored['doc_type'] === 'p7s') {
                $p7sChildren[$childId] = $base;
            }
        }

        // P7S asociace: podpis ukazuje na podepsaný dokument se shodným basename.
        foreach ($p7sChildren as $sigId => $base) {
            if (isset($byBaseName[$base]) && $byBaseName[$base] !== $sigId) {
                $this->documents->setSignatureFor($sigId, $byBaseName[$base]);
            }
        }

        return ['kind' => 'zfo', 'created_ids' => $created, 'container_id' => $containerId, 'skipped' => $skipped];
    }

    /** @return array{kind:string,created_ids:list<int>,container_id:?int,skipped:list<array{name:string,reason:string}>} */
    private function handleZipExplode(string $tmpPath, int $supplierId, ?int $folderId, ?int $userId): array
    {
        if ((int) @filesize($tmpPath) > self::ZIP_FILE_CAP) {
            throw new DocumentException('zip_total_too_large', 'ZIP archiv je příliš velký.', 413);
        }
        $entries = $this->zipImporter->extractEntries($tmpPath);
        $res = $this->ingestZipEntries($entries, $supplierId, $folderId, $userId);
        @unlink($tmpPath);
        return ['kind' => 'zip', 'created_ids' => $res['created_ids'], 'container_id' => null, 'skipped' => $res['skipped']];
    }

    /**
     * Zpracuje již rozbalené ZIP entries (sdílí synchronní upload i background job).
     * $onProgress($processed, $total, $createdSoFar) — volitelný hlásič pokroku.
     * $isCancelled():bool — volitelná kontrola zrušení (job).
     *
     * @param list<array{segments:list<string>,name:string,bytes:string}> $entries
     * @return array{created_ids:list<int>,skipped:list<array{name:string,reason:string}>,cancelled:bool}
     */
    public function ingestZipEntries(
        array $entries,
        int $supplierId,
        ?int $folderId,
        ?int $userId,
        ?callable $onProgress = null,
        ?callable $isCancelled = null,
    ): array {
        $created = [];
        $skipped = [];
        $total = count($entries);
        $processed = 0;
        $cancelled = false;

        foreach ($entries as $entry) {
            if ($isCancelled !== null && $isCancelled()) { $cancelled = true; break; }

            $targetFolder = $this->ensureFolderPath($supplierId, $folderId, $entry['segments'], $userId);
            try {
                $stored = $this->storage->storeFromBytes($entry['bytes'], $supplierId, $entry['name']);
                if (strtolower($stored['ext']) === 'zfo'
                    && ZfoExtractor::looksLikeZfo((string) @file_get_contents($stored['abs_path'], false, null, 0, 64))) {
                    $sub = $this->ingestStoredZfo($stored, $supplierId, $targetFolder, $entry['name'], $userId);
                    $created = array_merge($created, $sub['created_ids']);
                    $skipped = array_merge($skipped, $sub['skipped']);
                } else {
                    $created[] = $this->insertAndProcess($stored, $supplierId, $targetFolder, $entry['name'], $userId, 'zip_extract', null);
                }
            } catch (DocumentException $e) {
                $skipped[] = ['name' => implode('/', array_merge($entry['segments'], [$entry['name']])), 'reason' => $e->errorCode];
            }
            $processed++;
            if ($onProgress !== null) $onProgress($processed, $total, count($created));
        }

        return ['created_ids' => $created, 'skipped' => $skipped, 'cancelled' => $cancelled];
    }

    /** Bezpečně rozbalí ZIP z cesty na entries (pro job — vrací entries k ingestu). */
    public function extractZip(string $zipPath): array
    {
        if ((int) @filesize($zipPath) > self::ZIP_FILE_CAP) {
            throw new DocumentException('zip_total_too_large', 'ZIP archiv je příliš velký.', 413);
        }
        return $this->zipImporter->extractEntries($zipPath);
    }

    /** ZFO již uložené na disku (z entry ZIPu) → kontejner + rozbalení. */
    private function ingestStoredZfo(array $stored, int $supplierId, ?int $folderId, string $originalName, ?int $userId): array
    {
        $containerId = $this->insertAndProcess($stored, $supplierId, $folderId, $originalName, $userId, 'zip_extract', null);
        $created = [$containerId];
        $skipped = [];
        try {
            $parsed = $this->zfo->extract((string) @file_get_contents($stored['abs_path']));
        } catch (DocumentException $e) {
            return ['created_ids' => $created, 'skipped' => [['name' => $originalName, 'reason' => $e->errorCode]]];
        }
        $this->dms->insert($containerId, $parsed['metadata']);
        foreach ($parsed['attachments'] as $att) {
            try {
                $childStored = $this->storage->storeFromBytes($att['bytes'], $supplierId, $att['name']);
            } catch (DocumentException $e) {
                $skipped[] = ['name' => $att['name'], 'reason' => $e->errorCode];
                continue;
            }
            $created[] = $this->insertAndProcess($childStored, $supplierId, $folderId, $att['name'], $userId, 'zfo_extract', $containerId);
        }
        return ['created_ids' => $created, 'skipped' => $skipped];
    }
}
