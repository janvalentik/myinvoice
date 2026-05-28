<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Bootstrap;
use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\RuntimePaths;
use MyInvoice\Repository\DocumentFolderRepository;
use MyInvoice\Repository\ImportJobRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\BackgroundProcess;
use MyInvoice\Service\Document\DocumentStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Psr\Http\Message\UploadedFileInterface;
use Slim\Psr7\Stream;

/**
 * Background joby sekce Dokumenty (vzor reports/monthly-export):
 *   - rozbalení nahraného ZIP (document_zip_import),
 *   - ZIP export vybraných dokumentů (document_zip_export).
 *
 * Lifecycle: start → spawn worker → frontend polluje status → download výsledku.
 */
final class DocumentJobsAction
{
    use DocumentActionTrait;

    private const SOURCES = ['document_zip_import', 'document_zip_export'];

    public function __construct(
        private readonly ImportJobRepository $jobs,
        private readonly DocumentStorage $storage,
        private readonly DocumentFolderRepository $folders,
        private readonly ActivityLogger $logger,
    ) {}

    /** POST /api/documents/zip-import — nahraj ZIP, rozbal na pozadí. */
    public function zipImport(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        if ($sid <= 0) return Json::error($response, 'no_supplier', 'Chybí kontext dodavatele.', 400);
        $userId = $this->userId($request);

        $body = (array) $request->getParsedBody();
        $folderId = $this->optInt($body['folder_id'] ?? null);
        if ($folderId !== null && $this->folders->find($folderId, $sid) === null) {
            return Json::error($response, 'folder_not_found', 'Cílová složka nenalezena.', 404);
        }

        $files = $request->getUploadedFiles();
        $file = $files['file'] ?? null;
        if (is_array($file)) $file = $file[0] ?? null;
        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return Json::error($response, 'no_file', 'Žádný ZIP nebyl odeslán.', 400);
        }
        $name = trim((string) $file->getClientFilename());
        if (strtolower(pathinfo($name, PATHINFO_EXTENSION)) !== 'zip') {
            return Json::error($response, 'not_zip', 'Soubor není ZIP.', 415);
        }

        // Ulož ZIP do _jobs (musí přežít, než ho worker zpracuje).
        $jobsDir = DocumentStorage::baseDir($sid) . '/_jobs';
        if (!is_dir($jobsDir) && !@mkdir($jobsDir, 0755, true) && !is_dir($jobsDir)) {
            return Json::error($response, 'storage_not_writable', 'Úložiště jobů není zapisovatelné.', 500);
        }
        $zipPath = $jobsDir . '/import-' . bin2hex(random_bytes(8)) . '.zip';
        try {
            $file->moveTo($zipPath);
        } catch (\Throwable) {
            return Json::error($response, 'move_failed', 'Nahrání ZIP selhalo.', 500);
        }

        $jobId = $this->jobs->create($sid, 'document_zip_import', [
            'zip_path'  => $zipPath,
            'folder_id' => $folderId,
            'zip_name'  => $name,
        ], $userId ?? 0);
        $this->spawnWorker($jobId);
        $this->logger->log('document.zip_import_started', $userId, 'document', null,
            ['job_id' => $jobId, 'zip' => $name], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued']);
    }

    /** POST /api/documents/export {ids[]} — sestav ZIP na pozadí. */
    public function export(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        if ($sid <= 0) return Json::error($response, 'no_supplier', 'Chybí kontext dodavatele.', 400);
        $userId = $this->userId($request);

        $body = (array) $request->getParsedBody();
        $ids = array_values(array_filter(array_map('intval', (array) ($body['ids'] ?? []))));
        if ($ids === []) return Json::error($response, 'no_ids', 'Nebyly vybrány žádné dokumenty.', 400);

        $jobId = $this->jobs->create($sid, 'document_zip_export', ['ids' => $ids], $userId ?? 0);
        $this->spawnWorker($jobId);
        $this->logger->log('document.zip_export_started', $userId, 'document', null,
            ['job_id' => $jobId, 'count' => count($ids)], $this->clientIp($request), $request->getHeaderLine('User-Agent'), $sid);

        return Json::ok($response, ['job_id' => $jobId, 'status' => 'queued']);
    }

    /** GET /api/documents/jobs */
    public function list(Request $request, Response $response): Response
    {
        $sid = $this->supplierId($request);
        $all = $this->jobs->listForTenant($sid, null, 30);
        $jobs = array_values(array_filter($all, static fn($j) => in_array($j['source'], self::SOURCES, true)));
        return Json::ok($response, ['jobs' => array_map([$this, 'jobView'], $jobs)]);
    }

    /** GET /api/documents/jobs/{id} */
    public function status(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $sid);
        if ($job === null || !in_array($job['source'], self::SOURCES, true)) {
            return Json::error($response, 'not_found', 'Job nenalezen.', 404);
        }
        return Json::ok($response, $this->jobView($job));
    }

    /** GET /api/documents/jobs/{id}/download */
    public function download(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        $sid = $this->supplierId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $sid);
        if ($job === null || ($job['status'] ?? '') !== 'completed' || empty($job['result_path'])) {
            return Json::error($response, 'not_found', 'Výsledek není k dispozici.', 404);
        }
        $base = RuntimePaths::storage('documents');
        $abs = $base . '/' . ltrim((string) $job['result_path'], '/\\');
        // Path-traversal guard.
        $real = realpath($abs) ?: '';
        $baseReal = realpath($base) ?: $base;
        $n = static fn(string $p): string => DIRECTORY_SEPARATOR === '\\' ? strtolower(str_replace('\\', '/', $p)) : $p;
        if ($real === '' || !str_starts_with($n($real) . '/', rtrim($n($baseReal), '/') . '/') || !is_file($real)) {
            return Json::error($response, 'not_found', 'Soubor výsledku nenalezen.', 404);
        }
        $stream = new Stream(fopen($real, 'rb'));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', (string) ($job['result_mime'] ?: 'application/zip'))
            ->withHeader('Content-Disposition', 'attachment; filename="' . preg_replace('/[\r\n"\\\\]/', '_', (string) ($job['result_name'] ?: 'export.zip')) . '"')
            ->withHeader('Content-Length', (string) filesize($real))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($stream);
    }

    /** POST /api/documents/jobs/{id}/cancel */
    public function cancel(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $ok = $this->jobs->requestCancel((int) ($args['id'] ?? 0), $sid);
        return Json::ok($response, ['ok' => $ok, 'cancel_requested' => true]);
    }

    /** DELETE /api/documents/jobs/{id} */
    public function delete(Request $request, Response $response, array $args): Response
    {
        $sid = $this->supplierId($request);
        $job = $this->jobs->find((int) ($args['id'] ?? 0), $sid);
        if ($job === null || !in_array($job['source'], self::SOURCES, true)) {
            return Json::error($response, 'not_found', 'Job nenalezen.', 404);
        }
        if (!empty($job['result_path'])) {
            $abs = RuntimePaths::storage('documents') . '/' . ltrim((string) $job['result_path'], '/\\');
            if (is_file($abs)) @unlink($abs);
        }
        $this->jobs->delete((int) $job['id'], $sid);
        return Json::ok($response, ['ok' => true]);
    }

    private function spawnWorker(int $jobId): void
    {
        BackgroundProcess::spawnPhp(
            Bootstrap::rootDir() . '/api/bin/import-worker.php',
            ['--job-id=' . $jobId],
            RuntimePaths::log('import-worker.log'),
            Bootstrap::rootDir(),
        );
    }

    /** @param array<string,mixed> $j */
    private function jobView(array $j): array
    {
        return [
            'id'               => (int) $j['id'],
            'source'           => (string) $j['source'],
            'status'           => (string) $j['status'],
            'total_items'      => $j['total_items'] !== null ? (int) $j['total_items'] : null,
            'processed'        => (int) $j['processed'],
            'created_count'    => (int) $j['created_count'],
            'skipped_count'    => (int) $j['skipped_count'],
            'failed_count'     => (int) $j['failed_count'],
            'current_step'     => $j['current_step'] ?? null,
            'last_error'       => $j['last_error'] ?? null,
            'cancel_requested' => (bool) $j['cancel_requested'],
            'result_name'      => $j['result_name'] ?? null,
            'result_size'      => $j['result_size'] ?? null,
            'created_at'       => (string) ($j['created_at'] ?? ''),
            'finished_at'      => $j['finished_at'] ?? null,
        ];
    }
}
