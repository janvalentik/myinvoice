<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Export\PurchaseInvoiceExportService;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use ZipArchive;

/**
 * GET /api/purchase-invoices/export?month=YYYY-MM&format=pdf-zip[&date_by=tax|issue]
 *
 * Export přijatých faktur za měsíc jako ZIP s **vendor original PDF**.
 *
 * Priorita per faktura:
 *   1) Pokud pdf_path není NULL → použij archivovaný originál od dodavatele
 *   2) Jinak (v fázi 1 NEDOPLŇUJEME naše generované PDF) → faktura se SKIPNE
 *      s warningem v response headeru X-Export-Warnings.
 *
 * Pozn.: v fázi 6+ (po VAT klasifikaci) může backend dodat fallback PDF s
 * interním rozpisem (pro účetní), aby uživatel neměl gap v archivu.
 *
 * Přístup: admin nebo accountant.
 */
final class ExportPurchaseInvoicesAction
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
        private readonly PurchaseInvoiceExportService $exporter,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        if (!in_array(($user['role'] ?? ''), ['admin', 'accountant'], true)) {
            return Json::error($response, 'forbidden', 'Pouze admin nebo účetní.', 403);
        }

        $q = $request->getQueryParams();
        $month = (string) ($q['month'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
            return Json::error($response, 'validation_failed', 'Parametr month musí být YYYY-MM.', 400);
        }
        $dateBy = (string) ($q['date_by'] ?? 'tax');  // tax|issue|received
        if (!in_array($dateBy, ['tax', 'issue', 'received'], true)) {
            $dateBy = 'tax';
        }
        $format = (string) ($q['format'] ?? 'pdf-zip');
        if (!in_array($format, ['pdf-zip', 'isdoc', 'pohoda'], true)) {
            return Json::error($response, 'unsupported_format', "Neplatný format ({$format}).", 400);
        }

        $sid = SupplierGuard::currentId($request);
        $rows = $this->findInvoices($sid, $month, $dateBy);
        if (empty($rows)) {
            return Json::error($response, 'no_invoices', "Za měsíc {$month} nejsou žádné přijaté faktury.", 404);
        }

        // ISDOC bulk ZIP nebo Pohoda dataPack → delegujeme do separate metod
        if ($format === 'isdoc') {
            return $this->exportIsdocZip($response, $request, $rows, $month, $sid);
        }
        if ($format === 'pohoda') {
            return $this->exportPohodaDataPack($response, $request, $rows, $month, $sid);
        }
        // (pdf-zip pokračuje níže — original code)

        $archiveRoot = $this->resolveArchiveRoot();
        $archiveRootReal = realpath($archiveRoot);

        $tmpZip = tempnam(sys_get_temp_dir(), 'pinv-zip-') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return Json::error($response, 'zip_failed', 'Nelze vytvořit ZIP.', 500);
        }

        $included = 0;
        $skipped = [];
        $isWindows = DIRECTORY_SEPARATOR === '\\';

        foreach ($rows as $r) {
            $vs = (string) ($r['varsymbol'] ?? $r['vendor_invoice_number'] ?? ('id-' . $r['id']));
            $vendor = (string) ($r['vendor_company_name'] ?? 'vendor');

            if (empty($r['pdf_path'])) {
                $skipped[] = "{$vs} ({$vendor}) — žádný archivovaný PDF";
                continue;
            }

            // Resolve relativni path + path-traversal guard (zip-slip protection).
            $abs = $archiveRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $r['pdf_path']);
            $absReal = realpath($abs);
            if ($absReal === false || !is_file($absReal)) {
                $skipped[] = "{$vs} ({$vendor}) — soubor nenalezen na disku";
                continue;
            }
            if ($archiveRootReal !== false) {
                $needle = ($isWindows ? strtolower($archiveRootReal) : $archiveRootReal) . DIRECTORY_SEPARATOR;
                $haystack = $isWindows ? strtolower($absReal) : $absReal;
                if (!str_starts_with($haystack, $needle)) {
                    $skipped[] = "{$vs} ({$vendor}) — path mimo archive root";
                    continue;
                }
            }

            // Sanitize filename pro ZIP entry (zip-slip via varsymbol/vendor name)
            $entryBase = $vs . '-' . $vendor;
            $entryBase = preg_replace('/[^A-Za-z0-9._\\-]/u', '_', $entryBase) ?: 'invoice';
            $entryName = 'Prijata-' . substr($entryBase, 0, 100) . '.pdf';
            $zip->addFile($absReal, $entryName);
            $included++;
        }

        $zip->close();

        if ($included === 0) {
            @unlink($tmpZip);
            return Json::error($response, 'no_archived_pdfs',
                "Žádná z {$month} přijatých faktur nemá archivovaný PDF. " .
                'Pro archivaci nahrávej originál PDF v editoru přijaté faktury (drag & drop).',
                404,
                ['skipped' => $skipped],
            );
        }

        $content = (string) file_get_contents($tmpZip);
        @unlink($tmpZip);

        $this->logger->log('purchase_invoices.exported', $user['id'] ?? null, null, null, [
            'format' => 'pdf-zip', 'month' => $month, 'date_by' => $dateBy,
            'included' => $included, 'skipped_count' => count($skipped),
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        $response->getBody()->write($content);
        $r = $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', 'attachment; filename="purchase-invoices-' . $month . '.zip"')
            ->withHeader('Content-Length', (string) strlen($content));
        if (!empty($skipped)) {
            // Truncate hlavičky aby nebyla too long pro proxy
            $warnings = array_slice($skipped, 0, 10);
            $extra = count($skipped) - count($warnings);
            $msg = implode(' | ', $warnings) . ($extra > 0 ? " (+{$extra} more)" : '');
            $r = $r->withHeader('X-Export-Warnings', mb_substr($msg, 0, 1000));
        }
        return $r;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function findInvoices(int $supplierId, string $month, string $dateBy): array
    {
        $dateExpr = match ($dateBy) {
            'received' => 'pi.received_at',
            'issue'    => 'pi.issue_date',
            default    => 'COALESCE(pi.tax_date, pi.issue_date)',
        };

        $sql = "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number,
                       pi.pdf_path, pi.pdf_original_name,
                       c.company_name AS vendor_company_name
                  FROM purchase_invoices pi
                  JOIN clients c ON c.id = pi.vendor_id
                 WHERE pi.supplier_id = ?
                   AND DATE_FORMAT($dateExpr, '%Y-%m') = ?
                   AND pi.status IN ('received', 'booked', 'paid')
                 ORDER BY $dateExpr, pi.id";
        $stmt = $this->db->pdo()->prepare($sql);
        $stmt->execute([$supplierId, $month]);
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    private function resolveArchiveRoot(): string
    {
        $dir = (string) $this->config->get('purchase_invoice.archive_storage', '');
        if ($dir !== '') return $dir;
        $uploads = (string) $this->config->get('storage.uploads_dir', '');
        if ($uploads !== '') return dirname($uploads) . '/purchase-invoices';
        return __DIR__ . '/../../../../storage/purchase-invoices';
    }

    /**
     * Bulk ISDOC export — ZIP s jedním ISDOC XML per faktura.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function exportIsdocZip(Response $response, Request $request, array $rows, string $month, int $supplierId): Response
    {
        $tmpZip = tempnam(sys_get_temp_dir(), 'pinv-isdoc-') . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            return Json::error($response, 'zip_failed', 'Nelze vytvořit ZIP.', 500);
        }

        $included = 0;
        $errors = [];
        foreach ($rows as $r) {
            try {
                $xml = $this->exporter->toIsdocXml((int) $r['id'], $supplierId);
            } catch (\Throwable $e) {
                $errors[] = "{$r['varsymbol']} — " . $e->getMessage();
                continue;
            }
            $vs = (string) ($r['varsymbol'] ?? ('id-' . $r['id']));
            $vendor = (string) ($r['vendor_company_name'] ?? 'vendor');
            $base = preg_replace('/[^A-Za-z0-9._\\-]/u', '_', $vs . '-' . $vendor) ?: 'invoice';
            $zip->addFromString('Prijata-' . substr($base, 0, 100) . '.isdoc', $xml);
            $included++;
        }
        $zip->close();

        if ($included === 0) {
            @unlink($tmpZip);
            return Json::error($response, 'no_invoices_processed',
                'Nepodařilo se vyexportovat žádnou fakturu.', 500, ['errors' => $errors]);
        }

        $content = (string) file_get_contents($tmpZip);
        @unlink($tmpZip);

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log('purchase_invoices.exported', $user['id'] ?? null, null, null, [
            'format' => 'isdoc-zip', 'month' => $month, 'included' => $included,
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        $response->getBody()->write($content);
        return $response
            ->withHeader('Content-Type', 'application/zip')
            ->withHeader('Content-Disposition', "attachment; filename=\"prijate-isdoc-{$month}.zip\"")
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Export-Warnings', count($errors) > 0 ? (string) count($errors) : '0');
    }

    /**
     * Bulk Pohoda dataPack — jeden XML s `<dataPackItem>` per faktura.
     *
     * Strategy: vyrobíme jednoduchý dataPack wrapper kolem N invoice XML.
     *
     * @param list<array<string,mixed>> $rows
     */
    private function exportPohodaDataPack(Response $response, Request $request, array $rows, string $month, int $supplierId): Response
    {
        $ids = (string) bin2hex(random_bytes(4));
        $packId = "PI-{$month}-{$ids}";

        $items = [];
        $errors = [];
        $itemSeq = 0;
        foreach ($rows as $r) {
            try {
                $xml = $this->exporter->toPohodaXml((int) $r['id'], $supplierId);
            } catch (\Throwable $e) {
                $errors[] = "{$r['varsymbol']} — " . $e->getMessage();
                continue;
            }
            // Extract inner `<pur:purchase>` element from individual XML — pragmatic
            // string-level extraction (full DOM parse je overkill pro PoC).
            $itemSeq++;
            $items[] = [
                'id'   => $itemSeq,
                'vs'   => (string) ($r['varsymbol'] ?? ('id-' . $r['id'])),
                'xml'  => $xml,
            ];
        }

        if (empty($items)) {
            return Json::error($response, 'no_invoices_processed',
                'Nepodařilo se vyexportovat žádnou fakturu.', 500, ['errors' => $errors]);
        }

        // Wrap do dataPack — jednoduchý XML string concat. Funguje pro Pohoda 2.x.
        $dataPack = '<?xml version="1.0" encoding="utf-8"?>' . "\n";
        $dataPack .= '<dat:dataPack version="2.0"';
        $dataPack .= ' id="' . htmlspecialchars($packId, ENT_QUOTES | ENT_XML1) . '"';
        $dataPack .= ' ico="" application="MyInvoice.cz"';
        $dataPack .= ' note="Bulk export přijatých za ' . htmlspecialchars($month, ENT_QUOTES | ENT_XML1) . '"';
        $dataPack .= ' xmlns:dat="http://www.stormware.cz/schema/version_2/data.xsd"';
        $dataPack .= ' xmlns:pur="http://www.stormware.cz/schema/version_2/purchase.xsd"';
        $dataPack .= ' xmlns:typ="http://www.stormware.cz/schema/version_2/type.xsd">' . "\n";
        foreach ($items as $it) {
            $dataPack .= '  <dat:dataPackItem version="2.0" id="' . $it['id'] . '_' . htmlspecialchars($it['vs'], ENT_QUOTES | ENT_XML1) . '">' . "\n";
            // Strip XML declaration z individual XML (jen content)
            $inner = preg_replace('/^<\?xml[^?]*\?>\s*/', '', $it['xml']) ?? $it['xml'];
            $dataPack .= '    ' . $inner . "\n";
            $dataPack .= '  </dat:dataPackItem>' . "\n";
        }
        $dataPack .= '</dat:dataPack>' . "\n";

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $this->logger->log('purchase_invoices.exported', $user['id'] ?? null, null, null, [
            'format' => 'pohoda-datapack', 'month' => $month, 'included' => count($items),
        ], $this->ipMatcher->clientIpFromRequest($request->getServerParams()), $request->getHeaderLine('User-Agent'));

        $response->getBody()->write($dataPack);
        return $response
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"prijate-pohoda-{$month}.xml\"")
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('X-Export-Warnings', count($errors) > 0 ? (string) count($errors) : '0');
    }
}
