<?php

declare(strict_types=1);

namespace MyInvoice\Service\Export;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Repository\PurchaseInvoiceRepository;

/**
 * Export přijaté faktury do ISDOC / Pohoda XML.
 *
 * Strategie: **role inversion adapter**. Existující IsdocExporter / PohodaXmlExporter
 * pracují s vystavenými fakturami (náš tenant = dodavatel, klient = customer). Pro
 * přijatou fakturu se role obrátí (vendor = supplier, náš tenant = customer).
 *
 * Adapter transformuje `purchase_invoice` array na `invoice`-compatible array
 * (s prohozenými party snapshots) a deleguje na buildXml() existing exportérů.
 */
final class PurchaseInvoiceExportService
{
    public function __construct(
        private readonly Connection $db,
        private readonly PurchaseInvoiceRepository $repo,
        private readonly IsdocExporter $isdoc,
        private readonly PohodaXmlExporter $pohoda,
    ) {}

    public function toIsdocXml(int $purchaseInvoiceId, int $supplierId): string
    {
        $invoice = $this->buildInvoiceShape($purchaseInvoiceId, $supplierId);
        return $this->isdoc->buildXml($invoice);
    }

    /**
     * Pohoda XML wrapper kolem `<dataPack>` s jednou položkou `<dataPackItem>`.
     */
    public function toPohodaXml(int $purchaseInvoiceId, int $supplierId): string
    {
        $invoice = $this->buildInvoiceShape($purchaseInvoiceId, $supplierId);
        $cfg = $this->loadPohodaCfg($supplierId);
        // Pohoda XML pro **přijatou** fakturu používá <pur:purchase> místo <inv:invoice>.
        // Pragmatic přístup: existující exporter generuje vystavené ve formátu Pohoda;
        // pro přijaté ho voláme s flagem `direction='purchase'` v cfg.
        $cfg['direction'] = 'purchase';
        return $this->pohoda->buildXml([$invoice], $cfg);
    }

    /**
     * Sestaví invoice-shaped array z purchase_invoice. Klíčové: prohození party snapshots.
     */
    private function buildInvoiceShape(int $purchaseInvoiceId, int $supplierId): array
    {
        $pi = $this->repo->find($purchaseInvoiceId, $supplierId);
        if ($pi === null) {
            throw new \RuntimeException("Přijatá faktura #{$purchaseInvoiceId} nenalezena.");
        }

        // Náš tenant je v této transakci CUSTOMER (kupující). Vendor je SUPPLIER.
        $ourSnapshot = $this->loadSupplierSnapshot($supplierId);
        $vendorSnapshot = $pi['vendor_snapshot'] ?? $this->loadVendorSnapshot((int) $pi['vendor_id']);

        // Items mapping — preserve structure (description, quantity, unit_price, vat_rate)
        $items = array_map(function ($it) {
            return [
                'description'            => $it['description'] ?? '',
                'quantity'               => (float) ($it['quantity'] ?? 1),
                'unit'                   => $it['unit'] ?? 'ks',
                'unit_price_without_vat' => (float) ($it['unit_price_without_vat'] ?? 0),
                'vat_rate'               => (float) ($it['vat_rate_snapshot'] ?? $it['vat_rate'] ?? 0),
                'total_without_vat'      => (float) ($it['total_without_vat'] ?? 0),
                'total_vat'              => (float) ($it['total_vat'] ?? 0),
                'total_with_vat'         => (float) ($it['total_with_vat'] ?? 0),
            ];
        }, $pi['items'] ?? []);

        return [
            'id'              => $pi['id'],
            'invoice_type'    => match ($pi['document_kind'] ?? 'invoice') {
                'credit_note' => 'credit_note',
                'advance'     => 'proforma',
                default       => 'invoice',
            },
            'varsymbol'       => $pi['varsymbol'] ?? $pi['vendor_invoice_number'] ?? ('P-' . $pi['id']),
            'issue_date'      => $pi['issue_date'],
            'tax_date'        => $pi['tax_date'] ?? $pi['issue_date'],
            'due_date'        => $pi['due_date'],
            'currency'        => $pi['currency'] ?? 'CZK',
            'exchange_rate'   => $pi['exchange_rate'] ?? null,
            'reverse_charge'  => $pi['reverse_charge'] ?? false,
            // Aby exporter dopočítal netto jednotkovou cenu z řádkového základu (v tomto
            // režimu nese unit_price_without_vat brutto).
            'prices_include_vat' => !empty($pi['prices_include_vat']),
            'language'        => $pi['language'] ?? 'cs',
            // **Inverted roles**:
            //   - Vystavená: supplier=náš tenant, client=zákazník
            //   - Přijatá:   supplier=dodavatel, client=náš tenant
            'supplier_snapshot' => $vendorSnapshot,
            'client_snapshot'   => $ourSnapshot,
            // Plus pro legacy/fallback code paths v existing exporteru
            'supplier'          => $vendorSnapshot,
            'client'            => $ourSnapshot,
            'items'             => $items,
            'total_without_vat' => (float) ($pi['totals']['without_vat'] ?? $pi['total_without_vat'] ?? 0),
            'total_vat'         => (float) ($pi['totals']['vat'] ?? $pi['total_vat'] ?? 0),
            'total_with_vat'    => (float) ($pi['totals']['with_vat'] ?? $pi['total_with_vat'] ?? 0),
            'note_above_items'  => $pi['note_above_items'] ?? null,
            'note_below_items'  => $pi['note_below_items'] ?? null,
            // Marker pro export — pomáhá exportérovi vědět, že jde o přijatou
            '_direction'        => 'purchase',
        ];
    }

    private function loadSupplierSnapshot(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT s.company_name, s.street, s.city, s.zip, s.ic, s.dic,
                    s.email, s.phone, COALESCE(c.iso2, 'CZ') AS country_iso2
               FROM supplier s
          LEFT JOIN countries c ON c.id = s.country_id
              WHERE s.id = ?"
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    private function loadVendorSnapshot(int $vendorId): array
    {
        $stmt = $this->db->pdo()->prepare(
            "SELECT c.company_name, c.street, c.city, c.zip, c.ic, c.dic,
                    c.main_email AS email, c.phone, COALESCE(cnt.iso2, 'CZ') AS country_iso2
               FROM clients c
          LEFT JOIN countries cnt ON cnt.id = c.country_id
              WHERE c.id = ?"
        );
        $stmt->execute([$vendorId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    private function loadPohodaCfg(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT ic, dic, pohoda_account_code, pohoda_centre_code,
                    pohoda_activity_code, pohoda_contract_code
               FROM supplier WHERE id = ?'
        );
        $stmt->execute([$supplierId]);
        return $stmt->fetch(\PDO::FETCH_ASSOC) ?: [];
    }
}
