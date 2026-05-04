<?php

declare(strict_types=1);

namespace MyInvoice\Action\Invoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Repository\InvoiceRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/invoices/export.csv  — CSV pro účetní (UTF-8 BOM, ; oddělovač)
 * Stejné filtry jako /api/invoices.
 */
final class ExportCsvAction
{
    public function __construct(private readonly InvoiceRepository $repo) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $q = $request->getQueryParams();
        $filter = (array) ($q['filter'] ?? []);
        $filters = [
            'q'           => $q['q'] ?? null,
            'status'      => $filter['status']    ?? null,
            'type'        => $filter['type']      ?? null,
            'client_id'   => $filter['client_id'] ?? null,
            'year'        => $filter['year']      ?? null,
            'date_from'   => $filter['date_from'] ?? null,
            'date_to'     => $filter['date_to']   ?? null,
            'currency'    => $filter['currency']  ?? null,
            'supplier_id' => SupplierGuard::currentId($request),
        ];
        foreach (['status', 'type'] as $f) {
            if (is_string($filters[$f]) && str_contains($filters[$f], ',')) {
                $filters[$f] = explode(',', $filters[$f]);
            }
        }

        $groups = $this->repo->listGroupedByMonth($filters);
        $rows = [];
        foreach (($groups['data'] ?? []) as $g) {
            foreach ($g['invoices'] ?? [] as $inv) {
                $rows[] = $inv;
            }
        }

        // CSV
        $fp = fopen('php://temp', 'w+');
        fwrite($fp, "\xEF\xBB\xBF"); // UTF-8 BOM (Excel)
        fputcsv($fp, [
            'VS', 'Typ', 'Klient', 'Zakázka', 'Vystaveno', 'DUZP', 'Splatnost',
            'Měna', 'Bez DPH', 'DPH', 'Celkem', 'K úhradě', 'Stav', 'Zaplaceno',
        ], ';', '"', '\\');
        foreach ($rows as $r) {
            fputcsv($fp, [
                $r['varsymbol'] ?? '',
                $r['invoice_type'] ?? '',
                $r['client_company_name'] ?? '',
                $r['project_name'] ?? '',
                $r['issue_date'] ?? '',
                $r['tax_date'] ?? '',
                $r['due_date'] ?? '',
                $r['currency'] ?? '',
                number_format((float) $r['total_without_vat'], 2, '.', ''),
                number_format((float) $r['total_vat'], 2, '.', ''),
                number_format((float) $r['total_with_vat'], 2, '.', ''),
                number_format((float) $r['amount_to_pay'], 2, '.', ''),
                $r['status'] ?? '',
                $r['paid_at'] ?? '',
            ], ';', '"', '\\');
        }
        rewind($fp);
        $csv = (string) stream_get_contents($fp);
        fclose($fp);

        $filename = 'invoices-' . date('Y-m-d') . '.csv';
        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"');
    }
}
