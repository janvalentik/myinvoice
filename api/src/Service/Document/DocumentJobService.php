<?php

declare(strict_types=1);

namespace MyInvoice\Service\Document;

use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Repository\ImportJobRepository;

/**
 * Worker služba pro background joby sekce Dokumenty (vzor MonthlyExportService):
 *   - document_zip_import  → rozbalí nahraný ZIP a naimportuje (vč. ZFO uvnitř),
 *   - document_zip_export  → sestaví ZIP z vybraných dokumentů ke stažení.
 *
 * Artefakty jobů leží v storage/documents/sup-{id}/_jobs/.
 */
final class DocumentJobService
{
    /** Throttling DB zápisů progresu / kontrol zrušení. */
    private const PROGRESS_EVERY = 1;       // progres po každém souboru (responzivní UI)
    private const CANCEL_CHECK_EVERY = 5;

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly DocumentIngestService $ingest,
        private readonly DocumentStorage $storage,
        private readonly DocumentRepository $documents,
    ) {}

    public function run(int $jobId): void
    {
        $job = $this->jobs->findById($jobId);
        if ($job === null) return;
        if (!$this->jobs->markRunning($jobId)) return; // race — jiný worker už běží

        $sid = (int) $job['supplier_id'];
        $params = is_array($job['params'] ?? null) ? $job['params'] : [];
        $source = (string) $job['source'];

        try {
            if ($source === 'document_zip_import') {
                $this->runImport($jobId, $sid, $params, (int) ($job['created_by'] ?? 0) ?: null);
            } elseif ($source === 'document_zip_export') {
                $this->runExport($jobId, $sid, $params);
            } else {
                $this->jobs->markFailed($jobId, "Source '{$source}' není podporován.");
            }
        } catch (\Throwable $e) {
            $this->jobs->markFailed($jobId, $e->getMessage());
        }
    }

    /** @param array<string,mixed> $params */
    private function runImport(int $jobId, int $sid, array $params, ?int $userId): void
    {
        $zipPath = (string) ($params['zip_path'] ?? '');
        $folderId = isset($params['folder_id']) && $params['folder_id'] !== null ? (int) $params['folder_id'] : null;

        if ($zipPath === '' || !is_file($zipPath)) {
            $this->jobs->markFailed($jobId, 'ZIP soubor jobu nenalezen.');
            return;
        }

        $this->jobs->appendLog($jobId, 'Rozbaluji ZIP…');
        $entries = $this->ingest->extractZip($zipPath);
        $this->jobs->updateProgress($jobId, [
            'total_items'  => count($entries),
            'current_step' => 'Importuji soubory',
        ]);

        $tick = 0;
        $cancelled = false;
        $res = $this->ingest->ingestZipEntries(
            $entries,
            $sid,
            $folderId,
            $userId,
            function (int $processed, int $total, int $created) use ($jobId, &$tick): void {
                $tick++;
                if ($tick % self::PROGRESS_EVERY === 0 || $processed === $total) {
                    $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $created]);
                }
            },
            function () use ($jobId, &$cancelled): bool {
                static $n = 0;
                $n++;
                if ($n % self::CANCEL_CHECK_EVERY !== 0) return false;
                $cancelled = $this->jobs->isCancelRequested($jobId);
                return $cancelled;
            },
        );

        @unlink($zipPath);

        $this->jobs->updateProgress($jobId, [
            'processed'     => count($entries),
            'created_count' => count($res['created_ids']),
            'skipped_count' => count($res['skipped']),
        ]);

        if ($res['cancelled']) {
            $this->jobs->appendLog($jobId, 'Zrušeno uživatelem (vytvořeno ' . count($res['created_ids']) . ').');
            $this->jobs->markCancelled($jobId);
            return;
        }
        $this->jobs->appendLog($jobId, 'Hotovo: ' . count($res['created_ids']) . ' souborů, '
            . count($res['skipped']) . ' přeskočeno.');
        $this->jobs->markCompleted($jobId);
    }

    /** @param array<string,mixed> $params */
    private function runExport(int $jobId, int $sid, array $params): void
    {
        $ids = array_values(array_filter(array_map('intval', (array) ($params['ids'] ?? []))));
        if ($ids === []) {
            $this->jobs->markFailed($jobId, 'Nebyly vybrány žádné dokumenty.');
            return;
        }
        if (!class_exists(\ZipArchive::class)) {
            $this->jobs->markFailed($jobId, 'ext-zip není dostupné.');
            return;
        }

        $jobsDir = DocumentStorage::baseDir($sid) . '/_jobs';
        if (!is_dir($jobsDir) && !@mkdir($jobsDir, 0755, true) && !is_dir($jobsDir)) {
            $this->jobs->markFailed($jobId, 'Úložiště jobů není zapisovatelné.');
            return;
        }
        $relPath = 'sup-' . $sid . '/_jobs/export-' . $jobId . '.zip';
        $outPath = \MyInvoice\Infrastructure\Config\RuntimePaths::storage('documents') . '/' . $relPath;

        $this->jobs->updateProgress($jobId, ['total_items' => count($ids), 'current_step' => 'Balím dokumenty']);
        $this->jobs->appendLog($jobId, 'Sestavuji ZIP z ' . count($ids) . ' dokumentů…');

        $zip = new \ZipArchive();
        if ($zip->open($outPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            $this->jobs->markFailed($jobId, 'Nepodařilo se vytvořit ZIP.');
            return;
        }

        $used = [];
        $added = 0;
        $processed = 0;
        foreach ($ids as $id) {
            if ($processed % self::CANCEL_CHECK_EVERY === 0 && $this->jobs->isCancelRequested($jobId)) {
                $zip->close();
                @unlink($outPath);
                $this->jobs->markCancelled($jobId);
                return;
            }
            $doc = $this->documents->findRaw($id, $sid, false);
            $processed++;
            if ($doc === null) { $this->jobs->updateProgress($jobId, ['processed' => $processed]); continue; }
            $path = $this->storage->pathFor($sid, (string) $doc['sha256'], (string) $doc['filename']);
            if (!is_file($path)) { $this->jobs->updateProgress($jobId, ['processed' => $processed]); continue; }

            $name = $this->storage->sanitizeFilename((string) $doc['original_name']);
            $entry = $name;
            $n = 1;
            while (isset($used[$entry])) {
                $ext = pathinfo($name, PATHINFO_EXTENSION);
                $entry = pathinfo($name, PATHINFO_FILENAME) . '-' . (++$n) . ($ext !== '' ? '.' . $ext : '');
            }
            $used[$entry] = true;
            if ($zip->addFile($path, $entry)) $added++;
            if ($processed % self::PROGRESS_EVERY === 0) {
                $this->jobs->updateProgress($jobId, ['processed' => $processed, 'created_count' => $added]);
            }
        }
        $zip->close();

        if ($added === 0 || !is_file($outPath)) {
            @unlink($outPath);
            $this->jobs->markFailed($jobId, 'Žádný soubor k zabalení.');
            return;
        }

        $size = (int) filesize($outPath);
        $this->jobs->setResult($jobId, $relPath, 'dokumenty-' . $jobId . '.zip', $size, 'application/zip');
        $this->jobs->updateProgress($jobId, ['processed' => count($ids), 'created_count' => $added]);
        $this->jobs->appendLog($jobId, 'Hotovo: ' . $added . ' souborů, ' . round($size / 1024, 1) . ' KB.');
        $this->jobs->markCompleted($jobId);
    }
}
