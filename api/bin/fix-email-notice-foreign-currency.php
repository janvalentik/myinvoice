<?php

declare(strict_types=1);

/**
 * Oprava chybné MĚNY u e-mailových bankovních avíz na CIZOMĚNOVÝCH účtech (issue #160).
 *
 * PROBLÉM
 * -------
 * Fio avíza „Příjem/Výdaj na kontě" měnu v těle nenesou, takže
 * FioBankEmailNoticeParser defaultoval na CZK. Tato defaultovaná měna se pak
 * zapsala do bank_statements i bank_transactions, i když je účet vedený v EUR.
 * Důsledek: cizoměnové platby evidované jako CZK + StatementMatcher je porovnává
 * jako CZK (nespustí FX párovací větev → uniklé shody).
 *
 * Kódová oprava (BankEmailNoticeRepository::createTransactionFromNotice +
 * BankEmailNoticeScanner) je už nasazená — měna účtu je nově autoritativní.
 * Tenhle skript dorovná data naimportovaná PŘED opravou.
 *
 * CO SKRIPT DĚLÁ
 * --------------
 * Pro každý bank_statements se source='email_notice':
 *   1) dohledá dodavatele přes bank_email_processed_messages (statement → supplier),
 *   2) najde v currencies účet odpovídající číslu účtu výpisu — STEJNĚ jako runtime
 *      (BankEmailNoticeRepository::mappingForRecipientAccount): AccountNumberNormalizer
 *      ::equals(account_number) + guard na bank_code; jako fallback porovná i IBAN,
 *   3) je-li měna účtu jiná než uložená, opraví bank_statements.currency a všechny
 *      bank_transactions.currency pod ním na správný ISO kód.
 *
 * Měsíční výpis je klíčovaný file_hashem z (účet|banka|MĚNA|YYYY-MM). Při změně měny
 * se proto PŘEPOČÍTÁ file_hash i source_ref, aby budoucí avíza padala do opraveného
 * výpisu místo zakládání duplicitního. Pokud cílový file_hash už existuje (správný
 * výpis vznikl jinde), transakce se přepojí do něj, počty se sečtou, vazby v
 * processed_messages se přesměrují a starý prázdný výpis se smaže (MERGE).
 *
 * Bezpečné a idempotentní: částky se nemění, jen měna/hash. Opakované spuštění už
 * nic nezmění. Doklady, kde se účet v currencies nepodaří jednoznačně určit
 * (žádná/víc různých měn), se NEopravují — jen vypíšou k ruční kontrole.
 *
 * Použití:
 *   php api/bin/fix-email-notice-foreign-currency.php                 # dry-run, vše
 *   php api/bin/fix-email-notice-foreign-currency.php --apply         # zápis
 *   php api/bin/fix-email-notice-foreign-currency.php --supplier=1    # jen jeden tenant
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Service\Bank\AccountNumberNormalizer;

$dryRun     = !in_array('--apply', $argv, true);
$supplierId = null;
foreach ($argv as $a) {
    if (str_starts_with($a, '--supplier=')) {
        $supplierId = (int) substr($a, 11);
    }
}

$app = \MyInvoice\Bootstrap::buildApp();
$pdo = $app->getContainer()->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

/**
 * Dodavatel pro daný email-notice výpis (přes processed_messages). Jeden účet =
 * jeden tenant, takže stačí libovolná navázaná zpráva.
 */
$supplierStmt = $pdo->prepare(
    'SELECT supplier_id FROM bank_email_processed_messages
      WHERE bank_statement_id = ? AND supplier_id IS NOT NULL
      ORDER BY id LIMIT 1'
);

// Měny (účty) dodavatele – cache per supplier.
$currenciesStmt = $pdo->prepare(
    'SELECT code, account_number, bank_code, iban FROM currencies WHERE supplier_id = ?'
);
/** @var array<int,list<array<string,mixed>>> $currencyCache */
$currencyCache = [];
$currenciesFor = static function (int $sid) use (&$currencyCache, $currenciesStmt): array {
    if (!isset($currencyCache[$sid])) {
        $currenciesStmt->execute([$sid]);
        $currencyCache[$sid] = $currenciesStmt->fetchAll(\PDO::FETCH_ASSOC);
    }
    return $currencyCache[$sid];
};

/**
 * ISO kódy měn, jejichž účet odpovídá číslu účtu výpisu. Replikuje runtime
 * mappingForRecipientAccount: equals(account_number) + guard na bank_code; navíc
 * IBAN fallback (EUR účty bývají vedené jen IBANem, #109).
 *
 * @param list<array<string,mixed>> $currencies
 * @return list<string> distinct ISO kódy
 */
$matchCodes = static function (array $currencies, string $account, ?string $bankCode): array {
    $codes = [];
    foreach ($currencies as $c) {
        $cBank = trim((string) ($c['bank_code'] ?? ''));
        if ($bankCode !== null && $bankCode !== '' && $cBank !== '' && $cBank !== $bankCode) {
            continue; // guard: jiná banka = jiný účet
        }
        $accNo = (string) ($c['account_number'] ?? '');
        $iban  = (string) ($c['iban'] ?? '');
        if (AccountNumberNormalizer::matchesAny($account, $accNo !== '' ? $accNo : null, $iban !== '' ? $iban : null)) {
            $codes[strtoupper((string) $c['code'])] = true;
        }
    }
    return array_keys($codes);
};

$selectSql = "
    SELECT id, account_number, bank_code, currency, statement_date, source_ref, file_hash,
           transaction_count, matched_count
      FROM bank_statements
     WHERE source = 'email_notice'
     ORDER BY id
";
$statements = $pdo->query($selectSql)->fetchAll(\PDO::FETCH_ASSOC);

if (!$statements) {
    echo "Žádné e-mailové výpisy (source=email_notice).\n";
    exit(0);
}

$txMismatchStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM bank_transactions
      WHERE statement_id = ? AND (currency IS NULL OR currency <> ?)'
);
$hashOwnerStmt  = $pdo->prepare('SELECT id FROM bank_statements WHERE file_hash = ? LIMIT 1');

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}E-mailové výpisy ke kontrole: " . count($statements) . "\n";
echo str_repeat('-', 110) . "\n";

$fixedStatements = 0;
$fixedTx         = 0;
$merged          = 0;
$skippedOk       = 0;
$warned          = 0;

foreach ($statements as $s) {
    $stId    = (int) $s['id'];
    $account = (string) $s['account_number'];
    $bankRaw = $s['bank_code'];
    $bankCode = $bankRaw !== null && $bankRaw !== '' ? (string) $bankRaw : null;
    $current = $s['currency'] !== null ? strtoupper((string) $s['currency']) : null;

    $supplierStmt->execute([$stId]);
    $sid = $supplierStmt->fetchColumn();
    if ($sid === false) {
        printf("  ⚠ bs#%-6d %-20s — nelze určit dodavatele (žádná navázaná zpráva), přeskočeno.\n", $stId, $account);
        $warned++;
        continue;
    }
    $sid = (int) $sid;
    if ($supplierId !== null && $sid !== $supplierId) {
        continue;
    }

    $codes = $matchCodes($currenciesFor($sid), $account, $bankCode);
    if (count($codes) === 0) {
        printf("  ⚠ t%-2d bs#%-6d %-20s — účet nenalezen v currencies, přeskočeno (měna %s).\n",
            $sid, $stId, $account, $current ?? '∅');
        $warned++;
        continue;
    }
    if (count($codes) > 1) {
        printf("  ⚠ t%-2d bs#%-6d %-20s — víc měn pro stejný účet (%s), nejednoznačné, přeskočeno.\n",
            $sid, $stId, $account, implode('/', $codes));
        $warned++;
        continue;
    }
    $target = $codes[0];

    $txMismatchStmt->execute([$stId, $target]);
    $txBad = (int) $txMismatchStmt->fetchColumn();
    $stBad = $current !== $target;

    if (!$stBad && $txBad === 0) {
        $skippedOk++;
        continue; // idempotent / už správně
    }

    $ym = substr((string) $s['statement_date'], 0, 7);

    if ($stBad) {
        // Měna výpisu se mění → přepočítej file_hash a source_ref.
        $monthKey = $account . '|' . ($bankCode ?? '') . '|' . $target . '|' . $ym;
        $newHash  = hash('sha256', 'email-notice-monthly:' . $monthKey);
        $newRef   = 'imap-monthly:' . $monthKey;

        $hashOwnerStmt->execute([$newHash]);
        $owner = $hashOwnerStmt->fetchColumn();
        $mergeInto = ($owner !== false && (int) $owner !== $stId) ? (int) $owner : null;

        if ($mergeInto !== null) {
            printf("  ↳ t%-2d bs#%-6d %-20s %s→%s  MERGE do bs#%d (přepojení %d tx)\n",
                $sid, $stId, $account, $current ?? '∅', $target, $mergeInto, (int) $s['transaction_count']);
        } else {
            printf("  • t%-2d bs#%-6d %-20s %s→%s  (výpis + %d tx, hash recompute)\n",
                $sid, $stId, $account, $current ?? '∅', $target, max($txBad, (int) $s['transaction_count']));
        }

        if (!$dryRun) {
            $pdo->beginTransaction();
            try {
                if ($mergeInto !== null) {
                    // Přepoj transakce a opravy měny, sečti počty, přesměruj zprávy, smaž starý výpis.
                    $pdo->prepare('UPDATE bank_transactions SET statement_id = ?, currency = ? WHERE statement_id = ?')
                        ->execute([$mergeInto, $target, $stId]);
                    $pdo->prepare(
                        'UPDATE bank_statements t
                            JOIN bank_statements s ON s.id = ?
                            SET t.transaction_count = t.transaction_count + s.transaction_count,
                                t.matched_count     = t.matched_count + s.matched_count
                          WHERE t.id = ?'
                    )->execute([$stId, $mergeInto]);
                    $pdo->prepare('UPDATE bank_email_processed_messages SET bank_statement_id = ? WHERE bank_statement_id = ?')
                        ->execute([$mergeInto, $stId]);
                    $pdo->prepare('DELETE FROM bank_statements WHERE id = ?')->execute([$stId]);
                    $merged++;
                } else {
                    $pdo->prepare('UPDATE bank_statements SET currency = ?, file_hash = ?, source_ref = ? WHERE id = ?')
                        ->execute([$target, $newHash, $newRef, $stId]);
                    $pdo->prepare('UPDATE bank_transactions SET currency = ? WHERE statement_id = ? AND (currency IS NULL OR currency <> ?)')
                        ->execute([$target, $stId, $target]);
                    $fixedStatements++;
                }
                $pdo->commit();
            } catch (\Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } else {
            $mergeInto !== null ? $merged++ : $fixedStatements++;
        }
        $fixedTx += max($txBad, (int) $s['transaction_count']);
    } else {
        // Výpis má měnu správně, jen některé transakce ne (defenzivní dorovnání).
        printf("  • t%-2d bs#%-6d %-20s  jen tx: %d×%s→%s\n", $sid, $stId, $account, $txBad, $current ?? '∅', $target);
        if (!$dryRun) {
            $pdo->prepare('UPDATE bank_transactions SET currency = ? WHERE statement_id = ? AND (currency IS NULL OR currency <> ?)')
                ->execute([$target, $stId, $target]);
        }
        $fixedTx += $txBad;
    }
}

echo str_repeat('-', 110) . "\n";
echo "Opraveno výpisů: {$fixedStatements}";
if ($merged) {
    echo " (+ {$merged} sloučeno)";
}
echo ", transakcí: {$fixedTx}.\n";
if ($skippedOk) {
    echo "Beze změny (už správně): {$skippedOk}.\n";
}
if ($warned) {
    echo "K ruční kontrole (účet/měna neurčeny): {$warned}.\n";
}
if ($dryRun) {
    echo "\nDRY-RUN — nic nezapsáno. Pro zápis přidej --apply.\n";
} else {
    echo "\nHotovo. DPH/párování se počítá živě — žádný recompute není potřeba.\n";
}
