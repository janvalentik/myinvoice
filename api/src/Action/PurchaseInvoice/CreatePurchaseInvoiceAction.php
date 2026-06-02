<?php

declare(strict_types=1);

namespace MyInvoice\Action\PurchaseInvoice;

use MyInvoice\Action\Invoice\HandlesVarsymbolDuplicate;
use MyInvoice\Http\Json;
use MyInvoice\Http\SupplierGuard;
use MyInvoice\Middleware\AuthMiddleware;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\ActivityLogger;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;
use MyInvoice\Service\IpMatcher;
use MyInvoice\Service\Report\VatClassificationDefaulter;
use MyInvoice\Service\Validation\PurchaseInvoiceValidation;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;

/**
 * POST /api/purchase-invoices
 *
 * Vytvoří draft přijaté faktury + insertne items + přepočte sumy.
 * Vendor musí existovat a patřit aktuálnímu tenantovi.
 */
final class CreatePurchaseInvoiceAction
{
    use HandlesVarsymbolDuplicate;

    public function __construct(
        private readonly PurchaseInvoiceRepository $repo,
        private readonly ClientRepository $clients,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly VatClassificationDefaulter $vatDefaulter,
        private readonly ActivityLogger $logger,
        private readonly IpMatcher $ipMatcher,
    ) {}

    public function __invoke(Request $request, Response $response): Response
    {
        $supplierId = SupplierGuard::currentId($request);
        if ($supplierId === 0) {
            return Json::error($response, 'no_supplier', 'Chybí supplier kontext.', 400);
        }

        $body = (array) ($request->getParsedBody() ?? []);

        $errors = PurchaseInvoiceValidation::invoice($body, $this->repo->vatRateMap());
        if (!empty($errors)) {
            return Json::error($response, 'validation_failed', 'Validace selhala', 400, ['fields' => $errors]);
        }

        // Vendor musí existovat a patřit tenantovi (anti-cross-tenant injection)
        $vendor = $this->clients->find((int) $body['vendor_id']);
        if (!SupplierGuard::owns($request, $vendor)) {
            return Json::error($response, 'vendor_not_found', 'Dodavatel neexistuje.', 400);
        }

        // Auto-set is_vendor=1 pokud dosud nebyl označen jako dodavatel (může být dosud jen customer).
        if (empty($vendor['is_vendor'])) {
            $this->clients->markAsVendor((int) $vendor['id']);
        }

        // Dodavatel neplátce DPH → odpočet nelze uplatnit. Když volající vat_deduction
        // explicitně neposlal, vynutíme 'none' (bezpečný default); když zvolil jinak,
        // respektujeme to (vědomý override v editoru), ale níže přidáme varování.
        $vendorNonPayer = isset($vendor['is_vat_payer']) && !$vendor['is_vat_payer'];
        if ($vendorNonPayer && !array_key_exists('vat_deduction', $body)) {
            $body['vat_deduction'] = 'none';
        }

        $user = (array) $request->getAttribute(AuthMiddleware::ATTR_USER, []);
        $userId = (int) ($user['id'] ?? 0);

        // Auto-default VAT klasifikace pokud user nezadal (s multi-tenant scope)
        $this->applyVatClassificationDefaults($body, $supplierId);

        try {
            $id = $this->repo->createDraft($body, $userId, $supplierId);
        } catch (\InvalidArgumentException $e) {
            return Json::error($response, 'integrity_violation', $e->getMessage(), 400);
        } catch (\PDOException $e) {
            // Ruční interní číslo koliduje s existujícím (uq_pi_supplier_varsymbol) → 409.
            if ($dupMsg = self::varsymbolDuplicateMessage($e, $body['varsymbol'] ?? null)) {
                return Json::error($response, 'varsymbol_duplicate', $dupMsg, 409);
            }
            throw $e;
        }

        $this->repo->replaceItems($id, (array) ($body['items'] ?? []));
        // Ruční rekapitulace DPH dle dokladu (§ 73) — uložit PŘED recompute, aby ji
        // kalkulátor zapekl do řádkových totálů.
        if (array_key_exists('vat_overrides', $body)) {
            $this->repo->setVatOverrides($id, $supplierId, is_array($body['vat_overrides']) ? $body['vat_overrides'] : null);
        }
        $this->calc->recompute($id);

        $ip = $this->ipMatcher->clientIpFromRequest($request->getServerParams());
        $this->logger->log('purchase_invoice.created', $userId, 'purchase_invoice', $id, [
            'vendor_id'    => $body['vendor_id'],
            'document_kind' => $body['document_kind'] ?? 'invoice',
        ], $ip, $request->getHeaderLine('User-Agent'));

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
        return Json::ok($response, $invoice, 201);
    }

    /**
     * Auto-default vat_classification_code (purchase) podle vat_rate na řádcích a header.
     */
    private function applyVatClassificationDefaults(array &$body, int $supplierId): void
    {
        $vatRates = $this->repo->vatRateMap();
        $reverseCharge = !empty($body['reverse_charge']);

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
