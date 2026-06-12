<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Pdf\PdfArchiveService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

/**
 * GET /api/invoices/{id}/pdfs/{archiveId} — stáhne konkrétní archivovanou verzi PDF.
 *
 * Dvojitá autorizace:
 *   1. SupplierGuard (invoice patří current supplier)
 *   2. archive ID musí patřit této invoice (zkontroluje pathFor)
 */
final class DownloadArchivedPdfAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly PdfArchiveService $archive,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        $id = (int) ($args['id'] ?? 0);
        $archiveId = (int) ($args['archiveId'] ?? 0);

        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $path = $this->archive->pathFor($archiveId, $id);
        if ($path === null) {
            return Json::error($response, 'not_found', 'Archivní PDF nenalezeno.', 404);
        }

        $download = !empty($request->getQueryParams()['download']);
        $filename = basename($path);
        // Historické archive entries mohly skončit s `.archcopy-XXXXXXXX` suffixem
        // za `.pdf` (legacy bug v PdfArchiveService::archiveCopy). Pro účely
        // serve filename to odstraníme, aby Content-Disposition končil na .pdf.
        $filename = preg_replace('/\.archcopy-[0-9a-f]+$/', '', $filename);
        // Defense-in-depth: filename pochází z DB (kontrolovaný formát), ale escapuj
        // CR/LF/" pro jistotu (header injection / Content-Disposition split).
        $safeFilename = preg_replace('/[\r\n"\\\\]/', '_', $filename);
        $disposition = $download ? "attachment; filename=\"{$safeFilename}\"" : "inline; filename=\"{$safeFilename}\"";

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('pdf.archive_downloaded', $user['id'] ?? null, 'invoice', $id, [
            'archive_id' => $archiveId,
            'filename'   => $filename,
        ], $ip, $request->getHeaderLine('User-Agent'));

        $stream = new Stream(fopen($path, 'rb'));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', 'application/pdf')
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            // Defense-in-depth: nosniff + sandbox přímo na response (parita s DocumentFileAction)
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withBody($stream);
    }
}
