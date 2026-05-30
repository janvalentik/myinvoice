<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Validation\PurchaseInvoiceValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * PUT /api/purchase-invoices/{id}
 *
 * Update přijaté faktury. Standardně lze editovat pouze draft.
 * Admin může s `?force=1` upravit i received / booked / paid — cancelled zůstává immutable
 * (storná jsou součástí auditní stopy a nemají se editovat).
 */
final class UpdatePurchaseInvoiceAction
{
    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ClientRepository $clients,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly VatClassificationDefaulter $vatDefaulter,
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

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $isAdmin = (($user['role'] ?? '') === 'admin');
        $isForce = !empty($request->getQueryParams()['force']);

        if ($existing['status'] !== 'draft') {
            // Force-update: admin smí upravit received / booked / paid (s ?force=1).
            // cancelled zůstává immutable (storno = auditní stopa, nemá se editovat).
            if (!$isAdmin || !$isForce || $existing['status'] === 'cancelled') {
                return Json::error($response, 'not_editable',
                    "Faktura ve stavu '{$existing['status']}' nelze upravit. Admin může upravit received/booked/paid s ?force=1.",
                    409);
            }
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $errors = PurchaseInvoiceValidation::invoice($body, $this->repo->vatRateMap());
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Vendor scope check — pokud se mění vendor, musí patřit tenantovi
        $vendor = $this->clients->find((int) $body['vendor_id']);
        if (!SupplierGuard::owns($request, $vendor)) {
            return Json::error($response, 'vendor_not_found', 'Dodavatel neexistuje.', 400);
        }
        if (empty($vendor['is_vendor'])) {
            $this->clients->markAsVendor((int) $vendor['id']);
        }

        // Dodavatel neplátce DPH → bez nároku na odpočet. Default 'none' když neposláno;
        // explicitní volbu respektujeme (vědomý override), ale níže přidáme varování.
        $vendorNonPayer = isset($vendor['is_vat_payer']) && !$vendor['is_vat_payer'];
        if ($vendorNonPayer && !array_key_exists('vat_deduction', $body)) {
            $body['vat_deduction'] = 'none';
        }

        // Auto-default VAT klasifikace pokud uživatel nezadal — na header i items (s multi-tenant scope).
        $this->applyVatClassificationDefaults($body, $supplierId);

        try {
            $this->repo->updateDraft($id, $body, $supplierId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        }
        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
        $this->calc->recompute($id);
        // Rounding override z body (uživatel může ručně upravit v editoru)
        if (array_key_exists('rounding', $body)) {
            $this->repo->setRounding($id, $supplierId, (float) $body['rounding']);
        }
        // Změna daňového uplatnění u už očíslované faktury → přepiš prefix interního
        // čísla na odpovídající typ (PF2602001 → NN2602001). No-op u draftu / ručních čísel.
        $this->repo->reprefixVarsymbol($id, $supplierId);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $action = ($existing['status'] !== 'draft') ? 'purchase_invoice.force_updated' : 'purchase_invoice.updated';
        $this->logger->log($action, $user['id'] ?? null, 'purchase_invoice', $id, null, $ip, $request->getHeaderLine('User-Agent'));

        $invoice = $this->repo->find($id, $supplierId);
        // Non-blocking varování (např. dobropis s kladným součtem — viz issue #35).
        $warnings = PurchaseInvoiceValidation::warnings($invoice ?? []);
        // Neplátce + přesto uplatněn odpočet → upozorni (uživatel vědomě přepsal).
        if ($vendorNonPayer && ($invoice['vat_deduction'] ?? 'full') !== 'none') {
            $warnings[] = 'vendor_non_payer_deduction';
        }
        if (!empty($warnings)) {
            $invoice['_warnings'] = $warnings;
        }
        return Json::ok($response, $invoice);
    }

    /**
     * Auto-default vat_classification_code podle vat_rate na řádcích a header.
     * Aplikuje se jen pokud user nezadal (NULL nebo prázdný string).
     */
    private function applyVatClassificationDefaults(array &$body, int $supplierId): void
    {
        $vatRates = $this->repo->vatRateMap();  // id → rate_percent
        $reverseCharge = !empty($body['reverse_charge']);

        // Items first — needed pro header dominantní sazby
        if (!empty($body['items']) && is_array($body['items'])) {
            foreach ($body['items'] as &$item) {
                if (!empty($item['vat_classification_code'])) continue;
                $rateId = (int) ($item['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $taxDate = $body['tax_date'] ?? $body['issue_date'] ?? null;
                $item['vat_classification_code'] = $this->vatDefaulter->defaultForPurchase($rate, $reverseCharge, $taxDate, $supplierId);
            }
            unset($item);
        }

        // Header default
        if (empty($body['vat_classification_code']) && !empty($body['items'])) {
            $itemsWithTotals = array_map(function ($it) use ($vatRates) {
                $rateId = (int) ($it['vat_rate_id'] ?? 0);
                $rate = (float) ($vatRates[$rateId] ?? 0);
                $qty = (float) ($it['quantity'] ?? 1);
                $price = (float) ($it['unit_price_without_vat'] ?? 0);
                return ['vat_rate' => $rate, 'total_with_vat' => $qty * $price * (1 + $rate / 100)];
            }, (array) $body['items']);
            $body['vat_classification_code'] = $this->vatDefaulter->suggestHeaderForInvoice(
                $itemsWithTotals,
                (bool) ($body['reverse_charge'] ?? false),
                'purchase',
                $body['tax_date'] ?? $body['issue_date'] ?? null,
                $supplierId,
            );
        }
    }
}
