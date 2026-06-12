<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice\Attachment;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceAttachmentRepository;
use MyInvoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Psr7\Stream;

final class DownloadAttachmentAction
{
    public function __construct(
        private readonly InvoiceRepository $invoices,
        private readonly InvoiceAttachmentRepository $attachments,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        ini_set('display_errors', '0');
        ini_set('html_errors', '0');

        $id = (int) ($args['id'] ?? 0);
        $attId = (int) ($args['attId'] ?? 0);

        $invoice = $this->invoices->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }

        $att = $this->attachments->find($attId, $id);
        if ($att === null) {
            return Json::error($response, 'not_found', 'Příloha nenalezena.', 404);
        }

        $supplierId = (int) ($invoice['supplier_id'] ?? 0);
        $path = $this->attachments->pathFor($supplierId, $id, (string) $att['filename']);
        if (!is_file($path)) {
            return Json::error($response, 'not_found', 'Soubor nenalezen na disku.', 404);
        }

        $download = !empty($request->getQueryParams()['download']);
        $original = (string) $att['original_name'];
        $safe = preg_replace('/[\r\n"\\\\]/', '_', $original);
        $disposition = ($download ? 'attachment' : 'inline') . "; filename=\"{$safe}\"";

        $stream = new Stream(fopen($path, 'rb'));
        return $response
            ->withStatus(200)
            ->withHeader('Content-Type', (string) $att['mime_type'])
            ->withHeader('Content-Disposition', $disposition)
            ->withHeader('Content-Length', (string) filesize($path))
            ->withHeader('Cache-Control', 'no-store')
            // Defense-in-depth: nezáviset jen na globálním nosniff z web.config —
            // zabraň MIME sniffingu a aktivnímu obsahu přímo na response (parita
            // s DocumentFileAction).
            ->withHeader('X-Content-Type-Options', 'nosniff')
            ->withHeader('Content-Security-Policy', "default-src 'none'; sandbox")
            ->withBody($stream);
    }
}
