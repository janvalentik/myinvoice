<?php

declare(strict_types=1);

namespace MyInvoice\Service\Mail;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\WorkReportRepository;

/**
 * Sestavuje template variables pro invoice_approval.{cs|en}.{html|txt}.twig.
 */
final class ApprovalEmailVarsBuilder
{
    public function __construct(
        private readonly Connection $db,
        private readonly Config $config,
        private readonly WorkReportRepository $workReports,
    ) {}

    /**
     * @param string $approvalToken  Token uložený v invoices.approval_token
     * @param bool   $isTest         True = TEST odeslání, používá dummy token v URL
     */
    public function build(array $invoice, string $approvalToken, bool $isTest, string $locale): array
    {
        $appUrl = rtrim((string) $this->config->get('app.url', ''), '/');
        $approvalUrl = $appUrl . '/approval/' . $approvalToken;

        $varsymbolOrId = $invoice['varsymbol'] ?: ('DRAFT-' . $invoice['id']);
        // Doplň pole, které se používá ve šabloně (subject + filename)
        $invoice['varsymbol_or_id'] = $varsymbolOrId;

        $supplierName = $this->resolveSupplierName($invoice);
        $subject = $locale === 'en'
            ? "Work report — please approve ({$varsymbolOrId})"
            : "Žádost o schválení výkazu práce ({$varsymbolOrId})";
        if ($isTest) $subject = '[TEST] ' . $subject;
        if ($supplierName !== '') $subject .= " — {$supplierName}";

        return [
            'invoice'       => $invoice,
            'work_report'   => $this->workReports->findByInvoice((int) $invoice['id']),
            'approval_url'  => $approvalUrl,
            'is_test'       => $isTest,
            'subject'       => $subject,
            'supplier'      => $this->loadSupplierFooter($invoice),
        ];
    }

    private function resolveSupplierName(array $invoice): string
    {
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot'])
                ? json_decode($invoice['supplier_snapshot'], true)
                : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                return (string) ($snap['display_name'] ?: ($snap['company_name'] ?? ''));
            }
        }
        $sid = (int) ($invoice['supplier_id'] ?? 0);
        if ($sid <= 0) return '';
        $stmt = $this->db->pdo()->prepare('SELECT COALESCE(display_name, company_name) FROM supplier WHERE id = ?');
        $stmt->execute([$sid]);
        return (string) ($stmt->fetchColumn() ?: '');
    }

    private function loadSupplierFooter(array $invoice): ?array
    {
        if (!empty($invoice['supplier_snapshot'])) {
            $snap = is_string($invoice['supplier_snapshot'])
                ? json_decode($invoice['supplier_snapshot'], true)
                : $invoice['supplier_snapshot'];
            if (is_array($snap)) {
                return [
                    'company_name' => $snap['company_name'] ?? '',
                    'display_name' => $snap['display_name'] ?? null,
                    'tagline'      => $snap['tagline'] ?? null,
                    'street'       => $snap['street'] ?? '',
                    'city'         => $snap['city'] ?? '',
                    'zip'          => $snap['zip'] ?? '',
                    'country'      => $snap['country_name_cs'] ?? '',
                    'email'        => $snap['email'] ?? null,
                    'phone'        => $snap['phone'] ?? null,
                    'web'          => $snap['web'] ?? null,
                ];
            }
        }
        $sid = (int) ($invoice['supplier_id'] ?? 0);
        if ($sid <= 0) return null;
        $stmt = $this->db->pdo()->prepare(
            'SELECT s.company_name, s.display_name, s.tagline, s.street, s.city, s.zip,
                    s.email, s.phone, s.web, co.name_cs AS country
               FROM supplier s
          LEFT JOIN countries co ON co.id = s.country_id
              WHERE s.id = ?'
        );
        $stmt->execute([$sid]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: null;
    }
}
