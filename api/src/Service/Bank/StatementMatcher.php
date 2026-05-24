<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Invoice\FinalFromProformaCreator;
use PDO;

/**
 * Matchne bankovní transakci na fakturu podle VS + amount.
 *
 * Strategie:
 *   1. Příchozí (amount > 0) — hledá unpaid invoice se shodným varsymbol
 *      a) amount == amount_to_pay → 'auto_exact', faktura → paid
 *      b) |amount - amount_to_pay| <= 1 Kč → 'auto_partial' (jen log, faktura zůstane)
 *   2. Odchozí (amount < 0) — neshodujeme (může být refund / náš výdaj)
 *
 * Multi-supplier: VS je unique per (supplier_id, varsymbol). Matcher určuje
 * supplier_id z bank_statement.account_number → currencies.account_number → supplier_id.
 * Pokud žádná currency neodpovídá účtu (bank statement nepatří žádnému supplierovi),
 * vrátí 'unmatched/unknown_supplier'.
 */
final class StatementMatcher
{
    public function __construct(
        private readonly Connection $db,
        private readonly FinalFromProformaCreator $finalCreator,
    ) {}

    public function match(int $transactionId): array
    {
        $pdo = $this->db->pdo();
        $tx = $pdo->prepare(
            'SELECT bt.*, bs.account_number AS recipient_account, bs.bank_code AS recipient_bank,
                    bs.currency AS statement_currency
               FROM bank_transactions bt
               JOIN bank_statements   bs ON bs.id = bt.statement_id
              WHERE bt.id = ?'
        );
        $tx->execute([$transactionId]);
        $row = $tx->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['status' => 'unmatched', 'reason' => 'transaction_not_found'];
        }
        $vs = $row['variable_symbol'];
        $amount = (float) $row['amount'];
        if (!$vs) {
            return ['status' => 'unmatched', 'reason' => 'no_vs'];
        }
        // Currency guard: tx currency (preferováno) → statement currency
        // (fallback) → null (backward compat — staré výpisy bez měny matchují
        // jakoukoli fakturu, jak to dělaly před fixem v4.1.0).
        $txCurrency = is_string($row['currency'] ?? null) && $row['currency'] !== ''
            ? (string) $row['currency']
            : (is_string($row['statement_currency'] ?? null) && $row['statement_currency'] !== ''
                ? (string) $row['statement_currency']
                : null);
        // Outgoing (amount < 0) → match na purchase_invoice (přijatou) — fáze 3.
        // Incoming (amount > 0) → match na invoice (vydanou) — existing flow.
        $isOutgoing = $amount < 0;

        // Určení supplier_id z bank účtu (currencies.account_number + bank_code).
        // Normalizace přes AccountNumberNormalizer (řeší zero-padding a prefix).
        $supplierId = 0;
        if (!empty($row['recipient_account'])) {
            $sql = 'SELECT supplier_id, account_number FROM currencies WHERE account_number IS NOT NULL';
            $params = [];
            if (!empty($row['recipient_bank'])) {
                $sql .= ' AND bank_code = ?';
                $params[] = $row['recipient_bank'];
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $candidate) {
                if (AccountNumberNormalizer::equals((string) $candidate['account_number'], (string) $row['recipient_account'])) {
                    $supplierId = (int) $candidate['supplier_id'];
                    break;
                }
            }
        }
        if ($supplierId === 0) {
            return ['status' => 'unmatched', 'reason' => 'unknown_supplier_for_account'];
        }

        // ── Outgoing → purchase_invoice (přijaté faktury) ────────────────
        if ($isOutgoing) {
            return $this->matchPurchase($pdo, $supplierId, $vs, abs($amount), (string) $row['posted_at'], $transactionId, $txCurrency);
        }

        // ── Incoming → invoice (vystavené faktury) — existing flow ─────────
        // Najdi fakturu s VS = transakce.VS, supplier scope, status in (issued, sent, reminded, paid),
        // amount_to_pay sedí. 'paid' je v setu, aby se transakce navázala i na fakturu už označenou
        // za zaplacenou ručně (ať ve výpisu nevisí unmatched). Status/paid_at v tom případě
        // ponecháme — netouchujeme stav, který uživatel nastavil ručně.
        // Proformu povolujeme — zaplacená proforma se označí paid a navíc vytvoří DRAFT finální faktury.
        // Currency-aware match: pokud tx má currency, najdeme jen fakturu se shodnou
        // měnou. Bez tohohle guardu by EUR výpis na 1000 EUR napároval CZK fakturu
        // na 1000 Kč (stejný VS+amount, ale měnově totální nesmysl).
        $sql = "SELECT i.id, i.varsymbol, i.amount_to_pay, i.status, i.invoice_type, cur.code AS currency
                  FROM invoices i
                  JOIN currencies cur ON cur.id = i.currency_id
                 WHERE i.supplier_id = ?
                   AND i.varsymbol = ?
                   AND i.status IN ('issued', 'sent', 'reminded', 'paid')
                   AND i.invoice_type IN ('invoice', 'proforma')";
        $params = [$supplierId, $vs];
        if ($txCurrency !== null) {
            $sql .= ' AND cur.code = ?';
            $params[] = $txCurrency;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $inv = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$inv) {
            return [
                'status' => 'unmatched',
                'reason' => $txCurrency !== null ? 'no_invoice_with_vs_and_currency' : 'no_invoice_with_vs',
                'tx_currency' => $txCurrency,
            ];
        }

        $alreadyPaid = ($inv['status'] === 'paid');
        $diff = abs($amount - (float) $inv['amount_to_pay']);
        if ($diff < 0.01) {
            // Exact match — pokud faktura ještě není paid, označit ji a (u proformy) vyrobit final draft.
            // Pro již ručně paid fakturu jen navážeme transakci (status/paid_at netknuté).
            $pdo->beginTransaction();
            try {
                if (!$alreadyPaid) {
                    $pdo->prepare(
                        "UPDATE invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                    )->execute([$row['posted_at'], $inv['id']]);
                }
                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET matched_invoice_id = ?, match_status = 'auto_exact', matched_at = NOW()
                      WHERE id = ?"
                )->execute([$inv['id'], $transactionId]);

                $finalDraftId = null;
                if (!$alreadyPaid && $inv['invoice_type'] === 'proforma') {
                    $finalDraftId = $this->finalCreator->create((int) $inv['id'], 0);
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }

            $result = ['status' => 'auto_exact', 'invoice_id' => (int) $inv['id'], 'varsymbol' => $vs];
            if ($finalDraftId !== null) {
                $result['final_draft_id'] = $finalDraftId;
            }
            if ($alreadyPaid) {
                $result['already_paid'] = true;
            }
            return $result;
        }
        if ($diff <= 1.0) {
            // Partial match — flag, ale nepaint paid (uživatel rozhodne)
            $pdo->prepare(
                "UPDATE bank_transactions
                    SET matched_invoice_id = ?, match_status = 'auto_partial', matched_at = NOW()
                  WHERE id = ?"
            )->execute([$inv['id'], $transactionId]);
            return ['status' => 'auto_partial', 'invoice_id' => (int) $inv['id'], 'diff' => $diff];
        }

        return ['status' => 'unmatched', 'reason' => 'amount_mismatch', 'expected' => $inv['amount_to_pay'], 'got' => $amount];
    }

    /**
     * Match outgoing transakce na přijatou fakturu.
     * bank_transactions.matched_invoice_id slouží jen pro vystavené faktury,
     * pro přijaté používáme payment_matches table (N:N model).
     */
    private function matchPurchase(\PDO $pdo, int $supplierId, string $vs, float $absAmount, string $postedAt, int $transactionId, ?string $txCurrency = null): array
    {
        // 'paid' v setu: dovolíme navázat transakci i na ručně zaplacenou přijatou fakturu
        // (ať ve výpisu nevisí). Status/paid_at v tom případě nepřepisujeme.
        // Currency guard viz match() — bez něj by EUR výdaj napároval CZK přijatou.
        //
        // VS lookup: na rozdíl od vystavených faktur (kde klient platí naši `varsymbol`),
        // u přijatých platíme my dodavateli — do bank převodu typicky vepíšeme
        // **VS dodavatele** = `vendor_invoice_number`. Náš `purchase_invoices.varsymbol`
        // je interní PF-YYYYMM-NNNN, jen občas se s `vendor_invoice_number` shodují
        // (když user nepoužívá auto-counter). Hledáme proto OR na obojí — uživatel může
        // platit pod naším PF-... i pod původním číslem dodavatele.
        $sql = "SELECT pi.id, pi.varsymbol, pi.vendor_invoice_number,
                       COALESCE(pi.amount_to_pay, pi.total_with_vat, 0) AS amount_to_pay,
                       pi.status, cur.code AS currency
                  FROM purchase_invoices pi
             LEFT JOIN currencies cur ON cur.id = pi.currency_id
                 WHERE pi.supplier_id = ?
                   AND (pi.varsymbol = ? OR pi.vendor_invoice_number = ?)
                   AND pi.status IN ('received', 'booked', 'paid')";
        $params = [$supplierId, $vs, $vs];
        if ($txCurrency !== null) {
            $sql .= ' AND cur.code = ?';
            $params[] = $txCurrency;
        }
        $sql .= ' LIMIT 1';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $pi = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$pi) {
            return [
                'status' => 'unmatched',
                'reason' => $txCurrency !== null ? 'no_purchase_with_vs_and_currency' : 'no_purchase_with_vs',
                'tx_currency' => $txCurrency,
            ];
        }

        $alreadyPaid = ($pi['status'] === 'paid');
        $diff = abs($absAmount - (float) $pi['amount_to_pay']);
        if ($diff < 0.01) {
            $pdo->beginTransaction();
            try {
                if (!$alreadyPaid) {
                    $pdo->prepare(
                        "UPDATE purchase_invoices SET status = 'paid', paid_at = ? WHERE id = ?"
                    )->execute([$postedAt, $pi['id']]);
                }
                // payment_matches je N:N — INSERT bezpečný i pro paid invoice.
                // (Pokud by user spustil rematch znovu, transakce je už auto_exact a do
                // rematch setu nespadne — duplikace tedy nehrozí.)
                $pdo->prepare(
                    "INSERT INTO payment_matches
                        (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
                     VALUES (?, ?, ?, ?, 'auto', 95)"
                )->execute([$supplierId, $transactionId, $pi['id'], $absAmount]);
                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET match_status = 'auto_exact', matched_at = NOW()
                      WHERE id = ?"
                )->execute([$transactionId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            $result = ['status' => 'auto_exact', 'purchase_invoice_id' => (int) $pi['id'], 'varsymbol' => $vs];
            if ($alreadyPaid) {
                $result['already_paid'] = true;
            }
            return $result;
        }
        if ($diff <= 1.0) {
            // Partial: zaznam do payment_matches + status na tx, ať UI vidí link
            // (předtím tady byl jen `return` bez zápisu — transakce zůstávaly
            // unmatched, partial match se v UI nikdy nezobrazil).
            $pdo->beginTransaction();
            try {
                $pdo->prepare(
                    "INSERT INTO payment_matches
                        (supplier_id, bank_transaction_id, purchase_invoice_id, amount, match_type, match_confidence)
                     VALUES (?, ?, ?, ?, 'auto', 70)"
                )->execute([$supplierId, $transactionId, $pi['id'], $absAmount]);
                $pdo->prepare(
                    "UPDATE bank_transactions
                        SET match_status = 'auto_partial', matched_at = NOW()
                      WHERE id = ?"
                )->execute([$transactionId]);
                $pdo->commit();
            } catch (\Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                throw $e;
            }
            return [
                'status' => 'auto_partial',
                'purchase_invoice_id' => (int) $pi['id'],
                'diff' => $diff,
                'expected' => (float) $pi['amount_to_pay'],
                'got' => $absAmount,
            ];
        }
        return ['status' => 'unmatched', 'reason' => 'amount_mismatch_purchase', 'expected' => $pi['amount_to_pay'], 'got' => $absAmount];
    }
}
