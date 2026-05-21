<?php

declare(strict_types=1);

/**
 * Auto-scan inbox přijatých faktur (PDF / ISDOC) — periodic cron.
 *
 * Konfigurace v cfg.php:
 *   'purchase_invoice' => [
 *     'inbox_dir' => 'C:/Users/.../Faktury/Inbox',  // adresář kam dodavatelé posílají PDF
 *     'allowed_exts' => ['pdf', 'isdoc', 'xml'],
 *   ]
 *
 * Skenuje rekurzivně inbox_dir, hledá podporované soubory.
 * Pro každý:
 *   - SHA-256 dedup vůči purchase_invoices.pdf_hash
 *   - Pokud má embedded ISDOC → parse + vytvoř draft
 *   - Pokud PDF bez ISDOC a tenant má Anthropic AI nakonfigurovanou →
 *     volá AI extract + vytvoří draft
 *   - Jinak skipne s důvodem
 *
 * **Multi-tenant**: scan běží **per supplier_id** (každý tenant má vlastní inbox_dir
 * konfiguraci v cfg per environment, nebo sdílený root s subadresáři).
 * Default supplier_id z cfg.app.default_supplier_id (typically 1 pro single-tenant).
 *
 * Idempotent: SHA-256 dedup zaručuje, že stejný soubor se nezpracuje 2×.
 * Cron může běžet libovolně často (typicky každých 5-15 min).
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;
use MyInvoice\Service\Import\PurchaseInvoiceInboxScanner;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$conn    = new Connection($config);
$run     = CronRun::start($conn->pdo(), 'cron-scan-purchase-inbox');

$inboxDir = (string) $config->get('purchase_invoice.inbox_dir', '');
if ($inboxDir === '' || !is_dir($inboxDir)) {
    fwrite(STDERR, "[scan-purchase-inbox] cfg.purchase_invoice.inbox_dir neexistuje: " . ($inboxDir ?: '(prázdné)') . "\n");
    $run->finish('ok', ['skipped' => 'inbox_dir not configured', 'inbox_dir' => $inboxDir]);
    exit(0);
}

// Multi-supplier scan: pokud cfg.app.scan_all_suppliers=true, projeď všechny
// active suppliers. Default: jen default_supplier_id (single-tenant scenario).
$scanAll = (bool) $config->get('app.scan_all_suppliers', false);
$pdo = $conn->pdo();
if ($scanAll) {
    $supplierIds = array_map('intval', $pdo->query("SELECT id FROM supplier")->fetchAll(\PDO::FETCH_COLUMN));
} else {
    $defaultSupplierId = (int) $config->get('app.default_supplier_id', 1);
    $supplierIds = [$defaultSupplierId];
}

if (empty($supplierIds)) {
    fwrite(STDERR, "[scan-purchase-inbox] Žádní suppliers ke skenování.\n");
    $run->finish('ok', ['skipped' => 'no suppliers']);
    exit(0);
}

// System user (id=0 pro cron) — actions ho použijí pro created_by audit.
$cronUserId = (int) $config->get('app.cron_user_id', 0);

$container = Bootstrap::buildApp()->getContainer();
$scanner   = $container->get(PurchaseInvoiceInboxScanner::class);

$started      = microtime(true);
$totalSummary = ['suppliers' => 0, 'created' => 0, 'skipped' => 0, 'failed' => 0, 'details' => []];

foreach ($supplierIds as $sid) {
    try {
        $result = $scanner->scan($sid, $cronUserId, false);
    } catch (\Throwable $e) {
        fwrite(STDERR, "[scan-purchase-inbox] supplier {$sid}: " . $e->getMessage() . "\n");
        continue;
    }
    $totalSummary['suppliers']++;
    $totalSummary['created'] += (int) ($result['created'] ?? 0);
    $totalSummary['skipped'] += (int) ($result['skipped'] ?? 0);
    $totalSummary['failed']  += (int) ($result['failed']  ?? 0);
    // Jen failed/skipped do logu (created jsou očekávaný šum)
    foreach ($result['details'] ?? [] as $d) {
        if (($d['status'] ?? '') !== 'imported') {
            $totalSummary['details'][] = ['supplier_id' => $sid] + $d;
        }
    }
}

$ms = (int) ((microtime(true) - $started) * 1000);
echo "[" . date('Y-m-d H:i:s') . "] scan-purchase-inbox ({$ms} ms): "
    . json_encode([
        'suppliers' => $totalSummary['suppliers'],
        'created'   => $totalSummary['created'],
        'skipped'   => $totalSummary['skipped'],
        'failed'    => $totalSummary['failed'],
    ], JSON_UNESCAPED_UNICODE) . "\n";

// Vypsat issue items (jen pokud existují) pro debug v log souboru
if (!empty($totalSummary['details'])) {
    echo "  Non-imported items (" . count($totalSummary['details']) . "):\n";
    foreach ($totalSummary['details'] as $d) {
        $file = isset($d['file']) ? basename((string) $d['file']) : '?';
        echo "    [{$d['status']}] {$file} — " . ($d['reason'] ?? '') . "\n";
    }
}

$run->finish('ok', [
    'suppliers'           => $totalSummary['suppliers'],
    'created'             => $totalSummary['created'],
    'skipped'             => $totalSummary['skipped'],
    'failed'              => $totalSummary['failed'],
    'duration_ms'         => $ms,
    'non_imported_count'  => count($totalSummary['details']),
]);
