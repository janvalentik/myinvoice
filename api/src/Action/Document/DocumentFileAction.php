<?php

declare(strict_types=1);

namespace MyInvoice\Action\Document;

use MyInvoice\Http\Json;
use MyInvoice\Repository\DocumentRepository;
use MyInvoice\Service\Document\DocumentStorage;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * Servírování souborů dokumentů. Bezpečně:
 *   - default Content-Disposition: attachment + X-Content-Type-Options: nosniff,
 *   - inline preview JEN pro PDF a rastrové obrázky (jinak attachment),
 *   - HTML/SVG/skripty se nikdy neservírují jako text/html (anti stored XSS).
 */
final class DocumentFileAction
{
    use DocumentActionTrait;

    /** MIME, které smíme poslat inline (prohlížeč je renderuje bezpečně). */
    private const INLINE_MIME = [
        'application/pdf', 'image/jpeg', 'image/png', 'image/gif', 'image/webp',
    ];

    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentStorage $storage,
    ) {}

    /** GET /api/documents/{id}/download */
    public function download(Request $request, Response $response, array $args): Response
    {
        return $this->serve($request, $response, $args, false);
    }

    /** GET /api/documents/{id}/preview — inline jen pro PDF/obrázky. */
    public function preview(Request $request, Response $response, array $args): Response
    {
        return $this->serve($request, $response, $args, true);
    }

    /** GET /api/documents/{id}/thumb — náhled (JPG), nebo 404. */
    public function thumb(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $doc = $this->documents->findRaw($id, $sid, true);
        if ($doc === null || ($doc['thumb_status'] ?? '') !== 'generated' || empty($doc['thumb_path'])) {
            return Json::error($response, 'not_found', 'Náhled není k dispozici.', 404);
        }
        $path = DocumentStorage::thumbsDir($sid) . '/' . basename((string) $doc['thumb_path']);
        if (!is_file($path)) {
            return Json::error($response, 'not_found', 'Náhled nenalezen.', 404);
        }
        $stream = new Stream(fopen($path, 'rb'));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'image/jpeg')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'private, max-age=86400')
            ->withBody($stream);
    }

    /** GET /api/documents/bulk-download?ids=1,2,3 — ZIP vybraných dokumentů. */
    public function bulkDownload(Request $request, Response $response): Response
    {
        ini_set('display_errors', '0');
        $sid = $this->supplierId($request);
        $raw = (string) ($request->getQueryParams()['ids'] ?? '');
        $ids = array_values(array_filter(array_map('intval', explode(',', $raw))));
        if ($ids === []) {
            return Json::error($response, 'no_ids', 'Nebyly vybrány žádné dokumenty.', 400);
        }
        if (!class_exists(\ZipArchive::class)) {
            return Json::error($response, 'zip_unsupported', 'ZIP není na serveru dostupné.', 500);
        }

        $tmp = $this->storage->tmpPath($sid);
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            return Json::error($response, 'zip_failed', 'Nepodařilo se vytvořit ZIP.', 500);
        }
        $used = [];
        $added = 0;
        foreach ($ids as $id) {
            $doc = $this->documents->findRaw($id, $sid, false);
            if ($doc === null) continue;
            $path = $this->storage->pathFor($sid, (string) $doc['sha256'], (string) $doc['filename']);
            if (!is_file($path)) continue;
            // Unikátní název v ZIPu (víc dokumentů může sdílet original_name).
            $name = $this->storage->sanitizeFilename((string) $doc['original_name']);
            $entry = $name;
            $n = 1;
            while (isset($used[$entry])) {
                $entry = pathinfo($name, PATHINFO_FILENAME) . '-' . (++$n)
                    . (pathinfo($name, PATHINFO_EXTENSION) !== '' ? '.' . pathinfo($name, PATHINFO_EXTENSION) : '');
            }
            $used[$entry] = true;
            if ($zip->addFile($path, $entry)) $added++;
        }
        $zip->close();

        if ($added === 0) {
            @unlink($tmp);
            return Json::error($response, 'not_found', 'Žádný soubor k zabalení.', 404);
        }

        $stream = new Stream(fopen($tmp, 'rb'));
        @unlink($tmp); // smaž po otevření handle (Linux drží inode); na Win zůstane do uzavření
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="dokumenty.zip"')
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($stream);
    }

    private function serve(Request $request, Response $response, array $args, bool $wantInline): Response
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        $sid = $this->supplierId($request);
        $id = (int) ($args['id'] ?? 0);
        $doc = $this->documents->findRaw($id, $sid, true);
        if ($doc === null) {
            return Json::error($response, 'not_found', 'Dokument nenalezen.', 404);
        }
        $path = $this->storage->pathFor($sid, (string) $doc['sha256'], (string) $doc['filename']);
        if (!is_file($path)) {
            return Json::error($response, 'not_found', 'Soubor nenalezen na disku.', 404);
        }

        $mime = (string) $doc['mime_type'];
        $docType = (string) $doc['doc_type'];
        $canInline = $wantInline
            && in_array($mime, self::INLINE_MIME, true)
            && in_array($docType, ['pdf', 'image'], true);

        $original = (string) $doc['original_name'];
        $safe = preg_replace('/[\r\n"\\\\]/', '_', $original);
        // Pro neinline servírujeme generický octet-stream, ať prohlížeč nikdy
        // nerenderuje obsah (anti XSS u HTML/SVG, které prošly jako 'other').
        $serveMime = $canInline ? $mime : 'application/octet-stream';
        $disposition = ($canInline ? 'inline' : 'attachment') . "; filename=\"{$safe}\"";

        $stream = new Stream(fopen($path, 'rb'));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', $serveMime)
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox; style-src 'unsafe-inline'")
            ->withHeader('Cache-Control', 'private, no-store')
            ->withBody($stream);
    }
}
