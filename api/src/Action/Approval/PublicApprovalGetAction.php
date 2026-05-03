<?php

declare(strict_types=1);

namespace MyInvoice\Action\Approval;

use MyInvoice\Http\Json;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Repository\WorkReportRepository;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * GET /api/public/approval/{token}
 *
 * Veřejný (bez auth) endpoint — vrací data potřebná pro schvalovací stránku.
 * Token je v invoices.approval_token; po decize je nullován, takže expired/rozhodnutý
 * link vrátí 404.
 *
 * Returns: { invoice: {minimal}, work_report, supplier_name, captcha_site_key }
 */
final class PublicApprovalGetAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly WorkReportRepository $workReports,
        private readonly Config $config,
        private readonly Connection $db,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $token = (string) ($args['token'] ?? '');
        if ($token === '' || !preg_match('/^[a-f0-9]{32,128}$/', $token)) {
            return Json::error($response, 'invalid_token', 'Neplatný odkaz.', 404);
        }

        $invoice = $this->repo->findByApprovalToken($token);
        if ($invoice === null || $invoice['approval_status'] !== 'requested') {
            return Json::error($response, 'token_invalid_or_expired',
                'Tento odkaz byl již použit nebo není platný.', 404);
        }

        $workReport = $this->workReports->findByInvoice((int) $invoice['id']);
        if ($workReport === null) {
            return Json::error($response, 'no_work_report', 'Faktura nemá výkaz práce.', 404);
        }

        // Vrátíme jen omezený set polí — public endpoint, žádné citlivé údaje
        $supplierName = '';
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot'])
                ? json_decode($invoice['supplier_snapshot'], true)
                : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                $supplierName = (string) ($snap['display_name'] ?: ($snap['company_name'] ?? ''));
            }
        }
        if ($supplierName === '') {
            $sid = (int) ($invoice['supplier_id'] ?? 0);
            if ($sid > 0) {
                $stmt = $this->db->pdo()->prepare(
                    'SELECT COALESCE(display_name, company_name) FROM supplier WHERE id = ?'
                );
                $stmt->execute([$sid]);
                $supplierName = (string) ($stmt->fetchColumn() ?: '');
            }
        }

        $publicInvoice = [
            'id'                  => $invoice['id'],
            'varsymbol'           => $invoice['varsymbol'],
            'invoice_type'        => $invoice['invoice_type'],
            'currency'            => $invoice['currency'],
            'language'            => $invoice['language'],
            'client_company_name' => $invoice['client_company_name'] ?? null,
            'project_name'        => $invoice['project_name'] ?? null,
            'total_with_vat'      => $invoice['total_with_vat'] ?? null,
            'amount_to_pay'       => $invoice['amount_to_pay'] ?? null,
            'requested_at'        => $invoice['approval_requested_at'] ?? null,
        ];

        return Json::ok($response, [
            'invoice'          => $publicInvoice,
            'work_report'      => $workReport,
            'supplier_name'    => $supplierName,
            'captcha_site_key' => (string) $this->config->get('captcha.site_key', ''),
            'captcha_provider' => (string) $this->config->get('captcha.provider', 'none'),
        ]);
    }
}
