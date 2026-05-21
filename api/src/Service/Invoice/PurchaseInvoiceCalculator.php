<?php

declare(strict_types=1);

namespace MyInvoice\Service\Invoice;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Přepočítá sumy přijaté faktury (totals + per-item) a vat breakdown.
 *
 * Paralel k `InvoiceCalculator`, ale nad tabulkami purchase_invoices / purchase_invoice_items.
 * Vlastní výpočty delegovány na `InvoiceMath` (pure function, sdílená logika).
 *
 *  - Per item: total_without_vat = round(qty * unit_price, 2)
 *              total_vat         = round(base * rate/100, 2)
 *              total_with_vat    = base + vat
 *  - Reverse charge: rate = 0 pro všechny položky (input VAT self-assessed)
 *  - amount_to_pay je generated STORED column (total_with_vat - advance_paid_amount)
 */
final class PurchaseInvoiceCalculator
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Přepočítá fakturu — per-item totals + invoice totals. Volat po každé změně items.
     *
     * @return array{totals: array{without_vat: float, vat: float, with_vat: float}, vat_breakdown: list<array<string,mixed>>}
     */
    public function recompute(int $purchaseInvoiceId): array
    {
        $pdo = $this->db->pdo();

        $stmt = $pdo->prepare('SELECT reverse_charge FROM purchase_invoices WHERE id = ?');
        $stmt->execute([$purchaseInvoiceId]);
        $header = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($header === false) {
            throw new \RuntimeException("Purchase invoice {$purchaseInvoiceId} not found");
        }
        $reverseCharge = (bool) $header['reverse_charge'];

        $stmt = $pdo->prepare(
            'SELECT id, quantity, unit_price_without_vat, vat_rate_snapshot
               FROM purchase_invoice_items
              WHERE purchase_invoice_id = ?
              ORDER BY order_index, id'
        );
        $stmt->execute([$purchaseInvoiceId]);
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $computed = InvoiceMath::compute($items, $reverseCharge);

        // Persist per-item totals
        $updateItem = $pdo->prepare(
            'UPDATE purchase_invoice_items
                SET total_without_vat = ?, total_vat = ?, total_with_vat = ?
              WHERE id = ?'
        );
        foreach ($items as $i => $item) {
            $r = $computed['items'][$i];
            $updateItem->execute([$r['base'], $r['vat'], $r['with'], (int) $item['id']]);
        }

        // Persist invoice totals (amount_to_pay je generated column).
        // POZOR: rounding NEpřepisujeme — uchová value z DB (typicky AI import extract
        // 'total_with_vat_rounded' rozdíl, nebo manuální user edit).
        $stmt = $pdo->prepare(
            'UPDATE purchase_invoices
                SET total_without_vat = ?, total_vat = ?, total_with_vat = ?
              WHERE id = ?'
        );
        $stmt->execute([
            $computed['totals']['without_vat'],
            $computed['totals']['vat'],
            $computed['totals']['with_vat'],
            $purchaseInvoiceId,
        ]);

        return [
            'totals'        => $computed['totals'],
            'vat_breakdown' => $computed['vat_breakdown'],
        ];
    }
}
