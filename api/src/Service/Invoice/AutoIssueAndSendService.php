<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Mail\InvoiceEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\InvoicePdfRenderer;
use MyInvoice\Service\Stats\StatsRecomputer;

/**
 * Po schválení výkazu klientem (nebo manuálním přepnutí na 'approved'):
 *  1. Pokud je faktura draft → vystaví ji (varsymbol + snapshots + status='issued')
 *  2. Vyrenderuje PDF
 *  3. Pošle email se standardní šablonou invoice_send na příjemce z resolveRecipients()
 *  4. Status posune na 'sent'
 *  5. Loguje invoice.issued (jen pokud byla draft) + invoice.sent
 *
 * Vrací sumář pro audit/payload caller akcí.
 *
 * @phpstan-type Result array{issued: bool, sent_to: list<string>, varsymbol: string|null}
 */
final class AutoIssueAndSendService
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly VarsymbolGenerator $varsymbol,
        private readonly SnapshotBuilder $snapshots,
        private readonly InvoicePdfRenderer $renderer,
        private readonly Mailer $mailer,
        private readonly InvoiceEmailVarsBuilder $varsBuilder,
        private readonly ActivityLogger $logger,
        private readonly StatsRecomputer $stats,
        private readonly Config $config,
    ) {}

    /**
     * @param int      $invoiceId
     * @param ?int     $userId   user, který akci spustil (může být null pro public approval)
     * @param string   $ip       IP pro audit log
     * @param string   $ua       User-Agent pro audit log
     * @return array{issued: bool, sent_to: list<string>, varsymbol: string|null}
     */
    public function run(int $invoiceId, ?int $userId, string $ip, string $ua): array
    {
        $invoice = $this->repo->find($invoiceId);
        if ($invoice === null) {
            throw new \RuntimeException("Invoice #$invoiceId not found");
        }

        $issued = false;
        // 1. Vystavit pokud draft
        if ($invoice['status'] === 'draft') {
            $issueDate = new \DateTimeImmutable($invoice['issue_date']);
            $supplierId = (int) $invoice['supplier_id'];
            $varsymbol = $this->varsymbol->next($supplierId, $invoice['invoice_type'], $issueDate);
            $snapshots = $this->snapshots->build(
                (int) $invoice['client_id'],
                (int) $invoice['currency_id'],
                $supplierId,
            );
            $stmt = $this->db->pdo()->prepare(
                'UPDATE invoices SET
                    varsymbol         = ?,
                    client_snapshot   = ?,
                    supplier_snapshot = ?,
                    bank_snapshot     = ?,
                    status            = "issued"
                 WHERE id = ? AND status = "draft"'
            );
            $stmt->execute([
                $varsymbol,
                json_encode($snapshots['client'],   JSON_UNESCAPED_UNICODE),
                json_encode($snapshots['supplier'], JSON_UNESCAPED_UNICODE),
                $snapshots['bank'] !== null ? json_encode($snapshots['bank'], JSON_UNESCAPED_UNICODE) : null,
                $invoiceId,
            ]);
            $issued = true;
            $this->logger->log('invoice.issued', $userId, 'invoice', $invoiceId, [
                'varsymbol'   => $varsymbol,
                'auto_reason' => 'work_report_approved',
            ], $ip, $ua);
            $this->stats->recomputeForInvoiceId($invoiceId);
            // Invalidate cache: draft PDF (s pdf_path = "Faktura-draft-NN.pdf") by zůstal
            // platný a renderer by vrátil starý draft PDF místo nového se správným
            // varsymbolem, snapshoty a všemi 2. stránkami (např. výkazem víceprací).
            $this->renderer->invalidate($invoiceId);
            $invoice = $this->repo->find($invoiceId);
        }

        // 2. PDF
        $pdfPath = $this->renderer->render($invoiceId);

        // 3. Příjemci (stejná logika jako SendEmailAction)
        $to = $this->resolveRecipients($invoice);
        $cc = [];
        if ((bool) $this->config->get('smtp.cc_supplier_on_send', false)) {
            $stmt = $this->db->pdo()->prepare('SELECT email FROM supplier WHERE id = ?');
            $stmt->execute([(int) $invoice['supplier_id']]);
            $supplierEmail = trim((string) $stmt->fetchColumn());
            if ($supplierEmail !== ''
                && filter_var($supplierEmail, FILTER_VALIDATE_EMAIL)
                && !in_array($supplierEmail, $to, true)
            ) {
                $cc[] = $supplierEmail;
            }
        }

        if (empty($to)) {
            // Faktura je vystavená, ale nemá komu poslat — vrátit info, caller rozhodne co dál
            return ['issued' => $issued, 'sent_to' => [], 'varsymbol' => $invoice['varsymbol'] ?? null];
        }

        $locale = (string) ($invoice['language'] ?? 'cs');
        $vars = $this->varsBuilder->build($invoice, false, $locale);

        $this->mailer->sendTemplate(
            'invoice_send',
            $locale,
            $to,
            $vars,
            null,
            $cc,
            [],
            [['path' => $pdfPath, 'name' => basename($pdfPath), 'contentType' => 'application/pdf']],
        );

        $newStatus = $invoice['status'] === 'issued' ? 'sent' : $invoice['status'];
        $this->db->pdo()->prepare('UPDATE invoices SET status = ?, sent_at = NOW() WHERE id = ?')
            ->execute([$newStatus, $invoiceId]);

        $this->logger->log('invoice.sent', $userId, 'invoice', $invoiceId, [
            'to' => $to, 'cc' => $cc,
            'pdf_path' => basename($pdfPath),
            'auto_reason' => 'work_report_approved',
        ], $ip, $ua);

        return ['issued' => $issued, 'sent_to' => $to, 'varsymbol' => $invoice['varsymbol'] ?? null];
    }

    /** Stejná logika jako SendEmailAction::resolveRecipients. */
    private function resolveRecipients(array $invoice): array
    {
        $emails = [];
        if (!empty($invoice['client_main_email'])) {
            $emails[] = $invoice['client_main_email'];
        }
        if (!empty($invoice['project_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT email FROM project_billing_emails WHERE project_id = ? ORDER BY position'
            );
            $stmt->execute([$invoice['project_id']]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $em) {
                $em = trim((string) $em);
                if ($em !== '' && !in_array($em, $emails, true)) {
                    $emails[] = $em;
                }
            }
        }
        return $emails;
    }
}
