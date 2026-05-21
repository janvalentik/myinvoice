<?php

declare(strict_types=1);

namespace MyInvoice\Service\Import;

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\ClientRepository;
use MyInvoice\Repository\PurchaseInvoiceRepository;
use MyInvoice\Service\Invoice\PurchaseInvoiceCalculator;

/**
 * Wrapper kolem AnthropicClient — extrakuje data z PDF a vytvoří purchase_invoice draft.
 *
 * Pipeline:
 *   1. AnthropicClient.extractInvoice() → JSON s vendor/customer/items
 *   2. Validate strukturu (povinná pole, sanity checks proti hallucinations)
 *   3. Cross-tenant guard (customer.ic vs tenant.ic)
 *   4. ClientResolver.resolveVendor() pro vendor (ARES enrich pokud IČO)
 *   5. Mapper na purchase_invoice draft
 *
 * Tato třída je pro PHASE 2c MVP. V další iteraci:
 *   - ISDOC priorita (pokud PDF má ISDOC embed, použij IsdocParser; AI jen fallback)
 *   - Confidence scoring (AI vrátí confidence per pole; uložit pro review UI)
 *   - Cost tracking per request (input/output tokens)
 */
final class AiPdfExtractor
{
    public function __construct(
        private readonly Connection $db,
        private readonly AnthropicClient $anthropic,
        private readonly ClientResolver $clientResolver,
        private readonly PurchaseInvoiceRepository $repo,
        private readonly PurchaseInvoiceCalculator $calc,
        private readonly PdfIsdocExtractor $pdfIsdoc,
        private readonly IsdocParser $isdoc,
        private readonly IsdocToPurchaseInvoiceMapper $isdocMapper,
        private readonly Config $config,
        private readonly \MyInvoice\Service\Currency\CnbExchangeRateClient $cnb,
    ) {}

    /**
     * Extract + create draft purchase_invoice.
     *
     * @return array{ok:bool, purchase_invoice_id?:int, vendor_id?:int, source:string,
     *               error?:string, ai_data?:array<string,mixed>, model?:string,
     *               usage?:array<string,int>}
     */
    public function extractAndCreate(int $supplierId, int $userId, string $pdfBytes, ?string $modelOverride = null, ?string $originalFilename = null): array
    {
        // Dedup check — pokud PDF se stejným SHA-256 už existuje u tenanta, vrať existing.
        $sha256 = hash('sha256', $pdfBytes);
        $existingId = $this->repo->findIdByPdfHash($supplierId, $sha256);
        if ($existingId !== null) {
            return [
                'ok'                  => true,
                'purchase_invoice_id' => $existingId,
                'source'              => 'duplicate',
                'duplicate'           => true,
                'message'             => 'PDF je již importován jako faktura #' . $existingId,
            ];
        }

        // ISDOC priorita — pokud PDF/A-3 obsahuje embedded ISDOC, použij parser (přesnější, zdarma).
        $isdocXml = $this->pdfIsdoc->extract($pdfBytes);
        if ($isdocXml !== null) {
            try {
                $parsed = $this->isdoc->parse($isdocXml);
                if (!empty($parsed['invoices'])) {
                    $r = $this->isdocMapper->map($parsed['invoices'][0], $supplierId, $userId);
                    // Attach PDF k vytvořené přijaté faktuře
                    $this->attachPdf((int) $r['purchase_invoice_id'], $supplierId, $pdfBytes, $originalFilename);
                    return [
                        'ok'                  => true,
                        'purchase_invoice_id' => $r['purchase_invoice_id'],
                        'vendor_id'           => $r['vendor_id'],
                        'source'              => 'isdoc_embedded',
                    ];
                }
            } catch (\Throwable $e) {
                // ISDOC fail → spadnout do AI fallback
            }
        }

        // AI extraction fallback
        $extracted = $this->anthropic->extractInvoice($supplierId, $pdfBytes, $modelOverride);
        if (!$extracted['ok']) {
            return ['ok' => false, 'error' => $extracted['error'] ?? 'AI extrakce selhala', 'source' => 'ai_failed'];
        }
        $data = $extracted['data'];

        $validationError = $this->validateAiData($data);
        if ($validationError !== null) {
            return [
                'ok'      => false,
                'error'   => 'AI extrakce neprošla validací: ' . $validationError,
                'ai_data' => $data,
                'source'  => 'ai_invalid',
                'model'   => $extracted['model'] ?? null,
                'usage'   => $extracted['usage'] ?? null,
            ];
        }

        // Cross-tenant guard — customer.ic musí matchovat tenant
        $tenantIc = $this->fetchTenantIc($supplierId);
        $customerIc = $this->normalizeIc((string) ($data['customer']['ic'] ?? ''));
        if ($tenantIc !== null && $customerIc !== null && $customerIc !== $tenantIc) {
            return [
                'ok'      => false,
                'error'   => "Faktura adresovaná jinému plátci (customer IČO: {$customerIc}, tenant: {$tenantIc}).",
                'ai_data' => $data,
                'source'  => 'wrong_tenant',
            ];
        }

        // Resolve vendor (s ARES enrich + create pokud nový)
        $vendorData = (array) ($data['vendor'] ?? []);
        if (empty($vendorData['ic']) && empty($vendorData['company_name'])) {
            return ['ok' => false, 'error' => 'AI nevrátila vendor data', 'ai_data' => $data, 'source' => 'no_vendor'];
        }
        $resolved = $this->clientResolver->resolveVendor($vendorData, $supplierId);

        // Create purchase invoice draft
        try {
            $invoiceId = $this->createDraft($data, $supplierId, $userId, $resolved['id']);
            // Attach PDF — uložit do archive a updatnout pdf_path/hash/size na faktuře
            $this->attachPdf($invoiceId, $supplierId, $pdfBytes, $originalFilename);
            return [
                'ok'                  => true,
                'purchase_invoice_id' => $invoiceId,
                'vendor_id'           => $resolved['id'],
                'source'              => 'ai',
                'model'               => $extracted['model'] ?? null,
                'usage'               => $extracted['usage'] ?? null,
                'ai_data'             => $data,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'error'   => 'Vytvoření draft selhalo: ' . $e->getMessage(),
                'ai_data' => $data,
                'source'  => 'create_failed',
            ];
        }
    }

    /**
     * Validation — anti-hallucination check.
     */
    private function validateAiData(array $data): ?string
    {
        if (!isset($data['vendor']) || !is_array($data['vendor'])) {
            return 'chybí vendor objekt';
        }
        if (empty($data['vendor']['company_name']) && empty($data['vendor']['ic'])) {
            return 'vendor nemá ani company_name ani IČO';
        }
        if (empty($data['vendor_invoice_number'])) {
            return 'chybí vendor_invoice_number';
        }
        if (empty($data['issue_date']) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $data['issue_date'])) {
            return 'invalid issue_date (musí být YYYY-MM-DD)';
        }
        $currency = strtoupper((string) ($data['currency'] ?? ''));
        if ($currency === '' || !preg_match('/^[A-Z]{3}$/', $currency)) {
            return 'invalid currency (musí být ISO 4217, např. CZK)';
        }
        if (!isset($data['items']) || !is_array($data['items']) || empty($data['items'])) {
            return 'chybí items (alespoň jedna položka)';
        }
        foreach ($data['items'] as $i => $item) {
            if (empty($item['description'])) return "item[{$i}] chybí description";
            if (!isset($item['quantity'])) return "item[{$i}] chybí quantity";
            if (!isset($item['unit_price_without_vat'])) return "item[{$i}] chybí unit_price_without_vat";
        }
        return null;
    }

    private function createDraft(array $data, int $supplierId, int $userId, int $vendorId): int
    {
        $vatRates = $this->loadVatRateMap();
        $defaultVatRateId = $this->matchVatRateId($vatRates, 0.0);

        $items = [];
        foreach ($data['items'] as $idx => $line) {
            $rate = (float) ($line['vat_rate'] ?? 0);
            $items[] = [
                'description'            => (string) $line['description'],
                'quantity'               => (float) $line['quantity'],
                'unit'                   => (string) ($line['unit'] ?? 'ks'),
                'unit_price_without_vat' => (float) $line['unit_price_without_vat'],
                'vat_rate_id'            => $this->matchVatRateId($vatRates, $rate) ?? $defaultVatRateId,
                'order_index'            => $idx,
            ];
        }

        $payload = [
            'vendor_id'             => $vendorId,
            'vendor_invoice_number' => $this->sanitizeVendorNumber((string) $data['vendor_invoice_number']),
            'document_kind'         => 'invoice',
            'issue_date'            => (string) $data['issue_date'],
            'tax_date'              => isset($data['tax_date']) && $data['tax_date'] ? (string) $data['tax_date'] : null,
            'due_date'              => (string) ($data['due_date'] ?? $data['issue_date']),
            'received_at'           => date('Y-m-d'),
            'currency_id'           => $this->resolveCurrencyId((string) $data['currency'], $supplierId),
            'exchange_rate'         => null,
            'exchange_rate_source'  => 'manual',
            'reverse_charge'        => false,
            // Rozdíl mezi přesným total a zaokrouhleným total z PDF (např. 229 - 228.69 = 0.31).
            // Calculator pak respektuje uložený total_with_vat = sum(items) + rounding.
            'rounding'              => $this->computeRounding($data),
            'language'              => 'cs',
            'items'                 => $items,
        ];
        $id = $this->repo->createDraft($payload, $userId, $supplierId);
        $this->repo->replaceItems($id, $items);
        $this->calc->recompute($id);
        // Apply rounding po recompute (createDraft ignoruje, recompute zachovává).
        $rounding = (float) ($payload['rounding'] ?? 0);
        if (abs($rounding) > 0.001) {
            $this->repo->setRounding($id, $supplierId, $rounding);
        }
        // Pro non-CZK currency: auto-apply ČNB kurz k tax_date (nebo issue_date).
        $this->applyCnbRate($id, $supplierId, $data);
        return $id;
    }

    /**
     * Auto-apply ČNB kurz pro non-CZK přijatou fakturu.
     *
     * Použije tax_date (DUZP) jako primary; fallback issue_date. CnbExchangeRateClient
     * má built-in fallback na předchozí pracovní den (víkend/svátek), takže vždy
     * najde platný kurz.
     */
    private function applyCnbRate(int $id, int $supplierId, array $data): void
    {
        $currency = strtoupper((string) ($data['currency'] ?? 'CZK'));
        if ($currency === 'CZK' || $currency === '') return;
        $dateStr = (string) ($data['tax_date'] ?? $data['issue_date'] ?? '');
        if ($dateStr === '') return;
        try {
            $issueDate = new \DateTimeImmutable($dateStr);
        } catch (\Throwable) {
            return;
        }
        try {
            $result = $this->cnb->getRate($currency, $issueDate);
        } catch (\Throwable) {
            return; // ČNB timeout / network — silent
        }
        if ($result === null || !isset($result['rate'])) return;
        try {
            $this->repo->setExchangeRate(
                $id,
                (float) $result['rate'],
                (string) ($result['rate_date'] ?? $dateStr),
                'cnb',
                $supplierId,
            );
        } catch (\Throwable) {
            // Pokud setExchangeRate selže (race condition / schema mismatch), silent.
        }
    }

    private function fetchTenantIc(int $supplierId): ?string
    {
        $stmt = $this->db->pdo()->prepare('SELECT ic FROM supplier WHERE id = ?');
        $stmt->execute([$supplierId]);
        $ic = $stmt->fetchColumn();
        if ($ic === false || $ic === '' || $ic === null) return null;
        return $this->normalizeIc((string) $ic);
    }

    private function normalizeIc(string $ic): ?string
    {
        $clean = preg_replace('/\D/', '', $ic) ?? '';
        return $clean !== '' ? $clean : null;
    }

    private function resolveCurrencyId(string $code, int $supplierId): int
    {
        $code = strtoupper(trim($code)) ?: 'CZK';
        $pdo = $this->db->pdo();
        $stmt = $pdo->prepare(
            'SELECT id FROM currencies WHERE supplier_id = ? AND code = ? ORDER BY is_default DESC, id ASC LIMIT 1'
        );
        $stmt->execute([$supplierId, $code]);
        $id = $stmt->fetchColumn();
        if ($id !== false) return (int) $id;
        $pdo->prepare(
            'INSERT INTO currencies (supplier_id, code, label, symbol, name_cs, name_en, decimals, is_active, is_default)
             VALUES (?, ?, ?, ?, ?, ?, 2, 0, 0)'
        )->execute([$supplierId, $code, $code, $code, $code, $code]);
        return (int) $pdo->lastInsertId();
    }

    private function loadVatRateMap(): array
    {
        // vat_rates používá valid_from/valid_to (NULL = stále platné), ne is_active.
        // Pro AI mapování stačí aktuálně platné sazby (k dnešnímu datu).
        $today = date('Y-m-d');
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, rate_percent FROM vat_rates
              WHERE (valid_from IS NULL OR valid_from <= ?)
                AND (valid_to   IS NULL OR valid_to   >= ?)'
        );
        $stmt->execute([$today, $today]);
        $rows = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $map = [];
        foreach ($rows as $r) $map[(int) $r['id']] = (float) $r['rate_percent'];
        return $map;
    }

    private function matchVatRateId(array $vatRates, float $rate): ?int
    {
        foreach ($vatRates as $id => $r) if (abs($r - $rate) < 0.01) return $id;
        return null;
    }

    /**
     * Compute rounding rozdíl mezi AI-extracted total_with_vat (sum of items) a
     * total_with_vat_rounded (částka zobrazená na PDF, pokud byla zaokrouhlena).
     *
     * Příklad: items sum 228.69, PDF říká "K úhradě 229" → rounding = 0.31.
     * Pokud AI nedetekovala explicitní zaokrouhlení, vrátí 0.
     */
    private function computeRounding(array $data): float
    {
        $total = (float) ($data['total_with_vat'] ?? 0);
        $rounded = isset($data['total_with_vat_rounded']) && $data['total_with_vat_rounded'] !== null
            ? (float) $data['total_with_vat_rounded'] : null;
        if ($rounded === null || $total === 0.0) return 0.0;
        $diff = round($rounded - $total, 2);
        // Sanity check — pouze pokud rozdíl je < 1 Kč (typicky zaokrouhlení nahoru/dolů)
        return abs($diff) < 1.0 ? $diff : 0.0;
    }

    private function sanitizeVendorNumber(string $vn): string
    {
        $vn = trim($vn);
        if ($vn === '') $vn = 'AI-import';
        $vn = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $vn);
        return strlen($vn) > 50 ? substr($vn, 0, 50) : $vn;
    }

    /**
     * Attach originální PDF bytes k vytvořené přijaté faktuře (uloží do archive,
     * setne pdf_path/hash/size na faktuře). Silent fail — pokud archive není
     * dostupný, faktura zůstane bez PDF (lze nahrát ručně později).
     */
    private function attachPdf(int $invoiceId, int $supplierId, string $pdfBytes, ?string $originalFilename): void
    {
        try {
            $archiveRoot = (string) $this->config->get('purchase_invoice.archive_storage', '');
            if ($archiveRoot === '') {
                $archiveRoot = Bootstrap::rootDir() . '/storage/purchase-invoices';
            }
            $tenantDir = $archiveRoot . '/supplier-' . $supplierId;
            if (!is_dir($tenantDir)) {
                @mkdir($tenantDir, 0755, true);
            }
            $sha256 = hash('sha256', $pdfBytes);
            $diskName = substr($sha256, 0, 16) . '.pdf';
            $finalPath = $tenantDir . '/' . $diskName;
            if (!is_file($finalPath)) {
                @file_put_contents($finalPath, $pdfBytes);
            }
            $relativePath = 'supplier-' . $supplierId . '/' . $diskName;
            $size = (int) @filesize($finalPath);
            $name = $originalFilename ?: 'ai-imported.pdf';
            $this->repo->setPdfMetadata($invoiceId, $supplierId, $relativePath, $sha256, $size, $name);
        } catch (\Throwable) {
            // Silent — extract success je důležitější než PDF attach.
        }
    }
}
