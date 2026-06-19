<?php

declare(strict_types=1);

namespace MyInvoice\Repository;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Úložiště platebních příkazů (dávek) a jejich položek — snapshot v čase exportu,
 * aby opětovné stažení bylo deterministické a nezávislé na pozdějších změnách faktur.
 *
 * Tenant scope: vždy filtrovat WHERE supplier_id = ?.
 */
final class PaymentOrderRepository
{
    public function __construct(private readonly Connection $db) {}

    /**
     * Bankovní účty plátce (z `currencies`) pro výběr „z jakého účtu platit".
     * Default = `is_default` účet dané měny.
     *
     * @return list<array<string,mixed>>
     */
    public function payerAccounts(int $supplierId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, label, symbol, account_number, bank_code, bank_name, iban, bic,
                    is_default, is_active
               FROM currencies
              WHERE supplier_id = ?
           ORDER BY code, is_default DESC, label'
        );
        $stmt->execute([$supplierId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']         = (int) $r['id'];
            $r['is_default'] = (bool) $r['is_default'];
            $r['is_active']  = (bool) $r['is_active'];
        }
        return $rows;
    }

    /** Jeden účet plátce (currencies) v rámci tenanta, nebo null. */
    public function payerAccount(int $currencyId, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT id, code, label, symbol, account_number, bank_code, bank_name, iban, bic, is_default
               FROM currencies WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$currencyId, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row['id'] = (int) $row['id'];
        return $row;
    }

    /**
     * Uloží dávku + položky v transakci. Vrací ID nové dávky.
     *
     * @param array<string,mixed>        $order hlavička
     * @param list<array<string,mixed>>  $items položky
     */
    public function create(array $order, array $items): int
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO payment_orders
                   (supplier_id, currency, payer_currency_id, payer_account_number, payer_bank_code,
                    payer_iban, payer_bic, payer_account_label, payment_date, total_amount, item_count,
                    note, mark_paid, created_by_user_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int) $order['supplier_id'],
                (string) $order['currency'],
                $order['payer_currency_id'] !== null ? (int) $order['payer_currency_id'] : null,
                $order['payer_account_number'] ?? null,
                $order['payer_bank_code'] ?? null,
                $order['payer_iban'] ?? null,
                $order['payer_bic'] ?? null,
                $order['payer_account_label'] ?? null,
                (string) $order['payment_date'],
                (float) $order['total_amount'],
                count($items),
                $order['note'] ?? null,
                !empty($order['mark_paid']) ? 1 : 0,
                $order['created_by_user_id'] !== null ? (int) $order['created_by_user_id'] : null,
            ]);
            $orderId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO payment_order_items
                   (payment_order_id, purchase_invoice_id, payee_name, payee_account_number,
                    payee_bank_code, payee_iban, payee_bic, amount, currency,
                    variable_symbol, constant_symbol, specific_symbol, message, account_verified)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            foreach ($items as $it) {
                $itemStmt->execute([
                    $orderId,
                    (int) $it['purchase_invoice_id'],
                    $it['payee_name'] ?? null,
                    $it['payee_account_number'] ?? null,
                    $it['payee_bank_code'] ?? null,
                    $it['payee_iban'] ?? null,
                    $it['payee_bic'] ?? null,
                    (float) $it['amount'],
                    (string) $it['currency'],
                    $it['variable_symbol'] ?? null,
                    $it['constant_symbol'] ?? null,
                    $it['specific_symbol'] ?? null,
                    $it['message'] ?? null,
                    (string) ($it['account_verified'] ?? 'na'),
                ]);
            }

            $pdo->commit();
            return $orderId;
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /** Hlavička dávky v rámci tenanta, nebo null. */
    public function find(int $id, int $supplierId): ?array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payment_orders WHERE id = ? AND supplier_id = ?'
        );
        $stmt->execute([$id, $supplierId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }
        $row = $this->castOrder($row);
        $row['items'] = $this->itemsFor($id);
        return $row;
    }

    /**
     * Historie dávek tenanta (bez položek; položky jen v detailu/downloadu).
     *
     * @return list<array<string,mixed>>
     */
    public function history(int $supplierId, int $limit = 200): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payment_orders WHERE supplier_id = ? ORDER BY created_at DESC, id DESC LIMIT ?'
        );
        $stmt->bindValue(1, $supplierId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return array_map([$this, 'castOrder'], $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }

    /** @return list<array<string,mixed>> */
    private function itemsFor(int $orderId): array
    {
        $stmt = $this->db->pdo()->prepare(
            'SELECT * FROM payment_order_items WHERE payment_order_id = ? ORDER BY id'
        );
        $stmt->execute([$orderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        foreach ($rows as &$r) {
            $r['id']                  = (int) $r['id'];
            $r['payment_order_id']    = (int) $r['payment_order_id'];
            $r['purchase_invoice_id'] = (int) $r['purchase_invoice_id'];
            $r['amount']              = (float) $r['amount'];
        }
        return $rows;
    }

    /** @param array<string,mixed> $row */
    private function castOrder(array $row): array
    {
        $row['id']                = (int) $row['id'];
        $row['supplier_id']       = (int) $row['supplier_id'];
        $row['payer_currency_id'] = $row['payer_currency_id'] !== null ? (int) $row['payer_currency_id'] : null;
        $row['total_amount']      = (float) $row['total_amount'];
        $row['item_count']        = (int) $row['item_count'];
        $row['mark_paid']         = (bool) $row['mark_paid'];
        return $row;
    }
}
