<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\IpMatcher;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices/{id}/transition
 *
 * Přechod stavu přijaté faktury podle state machine:
 *   draft     → received | cancelled
 *   received  → booked | paid | cancelled
 *   booked    → paid | cancelled
 *   paid      → (terminal — jen unmark přes samostatný endpoint, není v fázi 1)
 *   cancelled → (terminal)
 *
 * Body: { target: "received|booked|paid|cancelled", paid_date?: "YYYY-MM-DD" (jen pro paid) }
 *
 * Při přechodu draft→received se automaticky vygeneruje varsymbol, pokud chybí.
 */
final class TransitionPurchaseInvoiceStatusAction
{
    private const TRANSITIONS = [
        // Forward flow (typical lifecycle): draft → received → booked → paid
        'draft'    => ['received', 'cancelled'],
        'received' => ['booked', 'paid', 'cancelled'],
        'booked'   => ['paid', 'cancelled'],
        // Reverse / corrective flows — user občas potřebuje opravit:
        //   paid → received   = unmark paid (omylem označeno)
        //   paid → cancelled  = storno už uhrazené faktury
        //   cancelled → received = un-cancel (vrátit do hry)
        'paid'      => ['received', 'cancelled'],
        'cancelled' => ['received'],
    ];

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response, array $args): Response
    {
        $id = (int) ($args['id'] ?? 0);
        if ($id <= 0) {
            return Json::error($response, 'invalid_id', 'Neplatné ID', 400);
        }

        $supplierId = SupplierGuard::currentId($request);
        $existing = $this->repo->find($id, $supplierId);
        if ($existing === null) {
            return Json::error($response, 'not_found', 'Přijatá faktura nenalezena.', 404);
        }

        $body = (array) ($request->getParsedBody() ?? []);
        $target = (string) ($body['target'] ?? '');

        $currentStatus = (string) $existing['status'];
        $allowed = self::TRANSITIONS[$currentStatus] ?? [];

        if (!in_array($target, $allowed, true)) {
            return Json::error(
                $response,
                'invalid_transition',
                "Z {$currentStatus} nelze přejít na {$target}.",
                409,
                ['allowed' => $allowed],
            );
        }

        $paidDate = null;
        if ($target === 'paid') {
            $paidDate = !empty($body['paid_date']) ? (string) $body['paid_date'] : date('Y-m-d');
            $d = \DateTimeImmutable::createFromFormat('Y-m-d', $paidDate);
            if ($d === false || $d->format('Y-m-d') !== $paidDate) {
                return Json::error($response, 'validation_failed', 'Neplatné paid_date', 400);
            }
        }

        // Při přechodu draft→received vygenerujeme varsymbol pokud chybí
        if ($currentStatus === 'draft' && $target === 'received' && empty($existing['varsymbol'])) {
            try {
                $this->repo->ensureVarsymbol($id, $supplierId);
            } catch (\RuntimeException $e) {
                return Json::error($response, 'internal_error', 'Nepodařilo se vygenerovat varsymbol', 500);
            }
        }

        $this->repo->setStatus($id, $target, $supplierId, $paidDate);

        // Při přechodu z draftu (typicky po manuální kontrole AI-importované faktury)
        // automaticky vyčistit extraction_warning — uživatel data ověřil tím, že
        // posunul stav z konceptu dál. Pokud warning není set, je to no-op.
        if ($currentStatus === 'draft' && $target !== 'cancelled' && !empty($existing['extraction_warning'])) {
            try {
                $this->repo->setExtractionWarning($id, $supplierId, null);
            } catch (\Throwable) {
                // Silent — transition už proběhl, warning clear je jen nice-to-have.
            }
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log("purchase_invoice.transitioned", $user['id'] ?? null, 'purchase_invoice', $id, [
            'from' => $currentStatus,
            'to'   => $target,
        ], $ip, $request->getHeaderLine('User-Agent'));

        return Json::ok($response, $this->repo->find($id, $supplierId));
    }
}
