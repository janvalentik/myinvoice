<?php

declare(strict_types=1);

namespace MyInvoice\Action\Approval;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\InvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Mail\ApprovalEmailVarsBuilder;
use MyInvoice\Service\Mail\Mailer;
use MyInvoice\Service\Pdf\WorkReportPdfRenderer;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/invoices/{id}/request-approval
 *
 * Pre-conditions:
 *  - faktura existuje a patří aktuálnímu supplier
 *  - faktura je draft
 *  - faktura má linked work_report
 *  - projekt vyžaduje requires_work_report_approval
 *
 * Effects:
 *  - vygeneruje approval_token, status='requested'
 *  - pošle email invoice_approval na project_billing_emails (fallback client_main_email)
 *  - jako příloha jen PDF výkazu (Vykaz-XYZ.pdf), ne celá faktura
 *  - audit: invoice.approval_requested
 */
final class RequestApprovalAction
{
    public function __construct(
        private readonly InvoiceRepository $repo,
        private readonly Connection $db,
        private readonly WorkReportPdfRenderer $renderer,
        private readonly Mailer $mailer,
        private readonly ApprovalEmailVarsBuilder $varsBuilder,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        $invoice = $this->repo->find($id);
        if (!SupplierGuard::owns($request, $invoice)) {
            return Json::error($response, 'not_found', 'Faktura nenalezena.', 404);
        }
        if ($invoice['status'] !== 'draft') {
            return Json::error($response, 'invalid_state', 'Ke schválení lze poslat jen draft fakturu.', 409);
        }
        if (empty($invoice['project_id']) || !($invoice['project_requires_approval'] ?? false)) {
            return Json::error($response, 'not_required', 'Zakázka nevyžaduje schválení výkazu.', 409);
        }

        // Příjemci: project_billing_emails (fallback client_main_email)
        $to = $this->resolveApprovalRecipients($invoice);
        if (empty($to)) {
            return Json::error($response, 'no_recipients', 'Zakázka nemá fakturační email a klient nemá hlavní email.', 400);
        }

        // Render PDF výkazu (Vykaz-XYZ.pdf)
        try {
            $pdfPath = $this->renderer->render($id);
        } catch (\Throwable $e) {
            return Json::error($response, 'pdf_failed', 'Nepodařilo se vygenerovat PDF výkazu: ' . $e->getMessage(), 500);
        }

        // Vygeneruj nový token (přepíše dřívější requested/rejected)
        $token = $this->repo->setApprovalRequested($id);
        $invoice = $this->repo->find($id);

        $locale = (string) ($invoice['language'] ?? 'cs');
        $vars = $this->varsBuilder->build($invoice, $token, false, $locale);

        try {
            $this->mailer->sendTemplate(
                'invoice_approval',
                $locale,
                $to,
                $vars,
                null,
                [],
                [],
                [['path' => $pdfPath, 'name' => basename($pdfPath), 'contentType' => 'application/pdf']],
            );
        } catch (\Throwable $e) {
            return Json::error($response, 'send_failed', 'Email se nepodařilo odeslat: ' . $e->getMessage(), 502);
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('invoice.approval_requested', $user['id'] ?? null, 'invoice', $id, [
            'to' => $to,
            'pdf_path' => basename($pdfPath),
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, [
            'sent_to' => $to,
            'sent_at' => date('Y-m-d H:i:s'),
            'invoice' => $this->repo->find($id),
        ]);
    }

    /**
     * Schvalovací email jde:
     *  - na project_billing_emails (pokud existují) — primárně
     *  - jinak na client_main_email
     * (NIKDY nesměšujeme — když jsou project emails, klient se nemusí dozvědět)
     */
    private function resolveApprovalRecipients(array $invoice): array
    {
        $emails = [];
        if (!empty($invoice['project_id'])) {
            $stmt = $this->db->pdo()->prepare(
                'SELECT email FROM project_billing_emails WHERE project_id = ? ORDER BY position'
            );
            $stmt->execute([$invoice['project_id']]);
            foreach ($stmt->fetchAll(\PDO::FETCH_COLUMN) as $em) {
                $em = trim((string) $em);
                if ($em !== '' && filter_var($em, FILTER_VALIDATE_EMAIL)) {
                    $emails[] = $em;
                }
            }
        }
        if (empty($emails) && !empty($invoice['client_main_email'])) {
            $main = trim((string) $invoice['client_main_email']);
            if (filter_var($main, FILTER_VALIDATE_EMAIL)) {
                $emails[] = $main;
            }
        }
        return $emails;
    }
}
