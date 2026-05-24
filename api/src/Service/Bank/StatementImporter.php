<?php

declare(strict_types=1);

namespace MyInvoice\Service\Bank;

use MyInvoice\Infrastructure\Database\Connection;
use PDO;

/**
 * Persist parsed GPC do DB. Dedupe podle file_hash.
 */
final class StatementImporter
{
    public function __construct(
        private readonly Connection $db,
        private readonly GpcParser $parser,
        private readonly StatementMatcher $matcher,
    ) {}

    /**
     * @return array{statement_id:int, transactions:int, matched:int, duplicate:bool}
     */
    public function import(string $content, string $fileName, ?int $userId): array
    {
        $hash = hash('sha256', $content);
        $pdo = $this->db->pdo();

        // Dedupe
        $exists = $pdo->prepare('SELECT id FROM bank_statements WHERE file_hash = ?');
        $exists->execute([$hash]);
        $existingId = $exists->fetchColumn();
        if ($existingId !== false) {
            return ['statement_id' => (int) $existingId, 'transactions' => 0, 'matched' => 0, 'duplicate' => true];
        }

        $parsed = $this->parser->parse($content);
        $h = $parsed['header'];

        // GPC header (074) NEMÁ pole pro měnu — máme to jen v 075 transakcích
        // (pozice 118-122, ISO 4217 numeric). Odvodíme měnu výpisu v pořadí:
        //   1) Per transakce má parser currency vyplněnou (CREDITAS, Fio, KB ji
        //      v 075 plní) → vezmeme dominantní non-null currency.
        //   2) Fallback: lookup do currencies podle account_number — pokud má
        //      supplier účet s naším account_number vedený v EUR, použijeme EUR.
        //   3) Bez 1 ani 2: NULL (UI fallback CZK).
        // Per bug report: GPC EUR výpis (Creditas, 00978) se zobrazoval jako
        // CZK protože bank_statements.currency zůstával NULL.
        $statementCurrency = $this->detectStatementCurrency($parsed['transactions'])
            ?? $this->lookupAccountCurrency($h['account_number']);

        $pdo->prepare(
            'INSERT INTO bank_statements
                 (file_name, file_hash, file_content, account_number, currency,
                  statement_number, statement_date,
                  prev_balance, curr_balance, credit_total, debit_total, transaction_count, imported_by)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $fileName, $hash, $content, $h['account_number'], $statementCurrency,
            $h['statement_number'], $h['statement_date'],
            $h['prev_balance'], $h['curr_balance'], $h['credit_total'], $h['debit_total'],
            count($parsed['transactions']), $userId,
        ]);
        $statementId = (int) $pdo->lastInsertId();

        $insertTx = $pdo->prepare(
            'INSERT INTO bank_transactions
                 (statement_id, posted_at, amount, currency, variable_symbol, constant_symbol, specific_symbol,
                  counterparty_account, counterparty_bank, counterparty_name, description, bank_ref)
             VALUES (?,?,?,?,?,?,?,?,?,?,?,?)'
        );

        $matched = 0;
        foreach ($parsed['transactions'] as $tx) {
            // Per-tx currency má prioritu (multi-currency výpisy by stejně tak
            // měly v 075 mít odlišný kód) — fallback na statement currency, aby
            // se EUR transakce z banky, která 075.currency nevyplňuje, neztratila.
            $txCurrency = $tx['currency'] ?? $statementCurrency;
            $insertTx->execute([
                $statementId, $tx['posted_at'], $tx['amount'], $txCurrency,
                $tx['variable_symbol'], $tx['constant_symbol'], $tx['specific_symbol'],
                $tx['counterparty_account'], $tx['counterparty_bank'], $tx['counterparty_name'],
                $tx['description'], $tx['bank_ref'],
            ]);
            $txId = (int) $pdo->lastInsertId();
            $r = $this->matcher->match($txId);
            if (in_array($r['status'], ['auto_exact', 'auto_partial'], true)) {
                $matched++;
            }
        }

        $pdo->prepare('UPDATE bank_statements SET matched_count = ? WHERE id = ?')
            ->execute([$matched, $statementId]);

        return [
            'statement_id' => $statementId,
            'transactions' => count($parsed['transactions']),
            'matched'      => $matched,
            'duplicate'    => false,
        ];
    }

    /**
     * Dominantní currency z transakcí — vrátí ten kód, který se vyskytuje
     * nejčastěji (po vyřazení NULL). NULL pokud ani jedna transakce currency
     * nemá. Multi-currency výpisy jsou v praxi vzácné; když je víc kódů,
     * statement.currency dostane majoritní.
     *
     * @param list<array{currency?:?string}> $transactions
     */
    private function detectStatementCurrency(array $transactions): ?string
    {
        $counts = [];
        foreach ($transactions as $tx) {
            $c = $tx['currency'] ?? null;
            if (is_string($c) && $c !== '') {
                $counts[$c] = ($counts[$c] ?? 0) + 1;
            }
        }
        if ($counts === []) return null;
        arsort($counts);
        return (string) array_key_first($counts);
    }

    /**
     * Lookup currency podle account_number v `currencies` tabulce. Pro případy,
     * kdy banka nevyplňuje 075.currency (= per-tx detection selže) — vezmeme
     * měnu prvního nalezeného currencies řádku se stejným číslem účtu (napříč
     * tenanty; multi-supplier separace je doménou caller — StatementImporter
     * pracuje bez tenant kontextu, ale account_number je defakto unikátní).
     *
     * AccountNumberNormalizer::equals normalizuje leading zeros / dashes pro
     * porovnání (např. `0000000112866714` z GPC vs `112866714` z UI inputu).
     */
    private function lookupAccountCurrency(string $accountNumber): ?string
    {
        if ($accountNumber === '') return null;
        $stmt = $this->db->pdo()->query(
            'SELECT account_number, code FROM currencies WHERE account_number IS NOT NULL'
        );
        if ($stmt === false) return null;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (AccountNumberNormalizer::equals((string) $row['account_number'], $accountNumber)) {
                return (string) $row['code'];
            }
        }
        return null;
    }
}
