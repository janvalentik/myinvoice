<?php

declare(strict_types=1);

/**
 * RESET — vymaže všechna uživatelská data ze systému (ponechá schéma + globální číselníky).
 *
 *   php api/bin/reset.php             # interaktivní potvrzení
 *   php api/bin/reset.php --yes       # bez ptaní
 *   php api/bin/reset.php --yes --keep-cache   # ponechá ARES/VIES cache
 *
 * Ponechává (globální číselníky + schema): countries, vat_rates, units,
 *       exchange_rates (cache ČNB kurzů — drahé refetchnout), migrations
 * Maže: users, sessions, password_resets, login_attempts, api_tokens,
 *       supplier, clients, projects, invoices, work_reports, activity_log,
 *       bank_statements, invoice_counters, invoice_pdfs (PDF historie),
 *       invoice_attachments, app_meta (version cache), ares_cache, vies_cache
 *       (volitelně), email_templates, project/client revenue cache,
 *       currencies (per-supplier!)
 *
 * Pozn.: currencies jsou per-supplier (multi-tenant), takže s ním padají.
 * Po resetu setup.php založí novému supplier defaultní CZK + EUR.
 *
 * Po resetu spusť znovu:
 *   php api/bin/setup.php       # admin + supplier + currencies
 *   php api/bin/sample.php      # (volitelné) testovací data
 *
 * Pro úplný restart včetně schema: DROP DATABASE + CREATE DATABASE + migrate.php
 * (reset.php schema záměrně neshazuje).
 */

// === CLI guard — odmítni HTTP přístup ===
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    exit("Tento skript lze spustit pouze z příkazové řádky (CLI).\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Config\CfgLocalWriter;

$args = array_flip(array_slice($argv, 1));
$autoYes   = isset($args['--yes']) || isset($args['-y']);
$keepCache = isset($args['--keep-cache']);

$rootDir = Bootstrap::rootDir();

try {
    $config = Config::load($rootDir);
    $pdo    = (new Connection($config))->pdo();
} catch (\Throwable $e) {
    fwrite(STDERR, "[reset] Chyba: " . $e->getMessage() . "\n");
    fwrite(STDERR, "[reset] Pravděpodobně chybí cfg.php nebo DB. Spusť `php api/bin/setup.php`.\n");
    exit(1);
}

echo "================================================\n";
echo "  MyInvoice.cz — RESET DATA\n";
echo "================================================\n";
echo "  DB:   " . $config->get('db.name') . " @ " . $config->get('db.host') . "\n";
echo "  Root: $rootDir\n";
echo "================================================\n\n";

// Stats před resetem
$counts = [];
foreach (['users', 'invoices', 'clients', 'projects', 'bank_statements', 'activity_log'] as $t) {
    try {
        $counts[$t] = (int) $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    } catch (\Throwable) {
        $counts[$t] = '?';
    }
}
echo "Aktuální stav:\n";
foreach ($counts as $t => $c) printf("  %-20s %s\n", $t, $c);
echo "\n";

if (!$autoYes) {
    echo "POZOR: smaže veškerá data v systému. Pokračovat? (napiš 'ANO'): ";
    $answer = trim((string) fgets(STDIN));
    if ($answer !== 'ANO') {
        echo "Zrušeno.\n";
        exit(0);
    }
}

// Tabulky ke smazání (v pořadí kvůli FK; FOREIGN_KEY_CHECKS=0 je dole, ale držíme topologii)
$wipe = [
    'bank_transactions',
    'bank_statements',
    'work_report_items',
    'work_reports',
    'invoice_items',
    'invoice_pdfs',                  // PDF historie (cascade by ji vzal s invoices, ale TRUNCATE FK ignoruje)
    'invoice_attachments',           // uživatelské přílohy faktur
    'invoices',
    'invoice_counters',
    'project_revenue_cache',
    'client_revenue_cache',
    'project_billing_emails',
    'projects',
    'clients',
    'currencies',                    // per-supplier (multi-tenant) — setup.php založí znovu
    'supplier',
    'sessions',
    'password_resets',
    'login_attempts',
    'api_tokens',                    // Personal Access Tokens (FK→users, ale TRUNCATE FK ignoruje)
    'activity_log',
    'email_templates',
    'app_meta',                      // version-check cache + jiné globální K/V; reset = fresh fetch
    'users',
];
if (!$keepCache) {
    $wipe[] = 'ares_cache';
    $wipe[] = 'vies_cache';
}

echo "\n[reset] Mažu tabulky…\n";
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
$total = 0;
foreach ($wipe as $t) {
    try {
        $pdo->exec("TRUNCATE TABLE `$t`");
        echo "  ✓ $t\n";
        $total++;
    } catch (\PDOException $e) {
        // tabulka nemusí existovat (např. settings/email_templates jsou volitelné)
        echo "  - $t (skipped: " . $e->getMessage() . ")\n";
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');

// PDF cache + storage cleanup
$dirs = [
    $rootDir . '/storage/invoices',
    $rootDir . '/storage/cache/mpdf',
    $rootDir . '/storage/cache/twig',
];
echo "\n[reset] Čistím cache adresáře…\n";
foreach ($dirs as $d) {
    if (is_dir($d)) {
        $count = wipeDir($d);
        echo "  ✓ $d ($count souborů)\n";
    }
}

// Zruš setup-time přepínače v cfg.local.php (jinak by stará hodnota přežila nový setup).
try {
    CfgLocalWriter::setKeys($rootDir, ['auth.require_totp' => false]);
    echo "\n[reset] cfg.local.php: auth.require_totp = false\n";
} catch (\Throwable $e) {
    echo "\n[reset] cfg.local.php: nelze zapsat (" . $e->getMessage() . ") — uprav ručně, pokud potřebuješ.\n";
}

echo "\n================================================\n";
echo "  HOTOVO. Vymazáno $total tabulek.\n";
echo "  Spusť `php api/bin/setup.php` pro nové úvodní nastavení.\n";
echo "================================================\n";

function wipeDir(string $dir): int
{
    $count = 0;
    $iter = new \RecursiveIteratorIterator(
        new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
        \RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($iter as $f) {
        if ($f->isDir()) {
            @rmdir($f->getPathname());
        } else {
            if (@unlink($f->getPathname())) $count++;
        }
    }
    return $count;
}
