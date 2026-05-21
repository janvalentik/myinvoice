<?php

declare(strict_types=1);

/**
 * Backfill chybějících `vat_classification_code` na invoice_items
 * (VYSTAVENÉ faktury) podle vat_rate_snapshot.
 *
 * Use case: vystavené faktury vytvořené před existencí auto-klasifikace
 * v InvoiceRepository::replaceItems() nemají `vat_classification_code` →
 * VatClassificationMapper SKIPNE → faktury nedorazí do DPH přiznání ani KH.
 *
 * Mapování (sale, tuzemsko):
 *   21%  → '1' (Dodání zboží/služby tuzemsko — základní)
 *   12%  → '2' (Dodání zboží/služby tuzemsko — snížená)
 *   0%   → '3' (Dodání tuzemsko osvobozeno)
 *
 * Pro dodávky do EU (kódy 20, 22) / vývoz (26) si uživatel musí kód změnit
 * ručně v UI — defaultem je tuzemsko.
 *
 * Použití:
 *   php api/bin/backfill-vat-classification-invoices.php           # dry-run
 *   php api/bin/backfill-vat-classification-invoices.php --apply   # zápis
 */

require __DIR__ . '/../vendor/autoload.php';

$dryRun = !in_array('--apply', $argv, true);

$app = \MyInvoice\Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(\MyInvoice\Infrastructure\Database\Connection::class)->pdo();

$stmt = $pdo->query(
    "SELECT ii.id, ii.invoice_id, ii.vat_rate_snapshot,
            i.supplier_id, i.varsymbol, i.status, i.invoice_type
       FROM invoice_items ii
       JOIN invoices i ON i.id = ii.invoice_id
      WHERE ii.vat_classification_code IS NULL
        AND i.status NOT IN ('draft', 'cancelled')
        AND i.invoice_type != 'proforma'
      ORDER BY i.supplier_id, i.id, ii.id"
);
$items = $stmt->fetchAll(\PDO::FETCH_ASSOC);

if (empty($items)) {
    echo "Žádné invoice_items bez vat_classification_code — nic k doplnění.\n";
    exit(0);
}

$mode = $dryRun ? '[DRY-RUN] ' : '';
echo "{$mode}Nalezeno " . count($items) . " invoice_items bez vat_classification_code:\n\n";

$counts = ['1' => 0, '2' => 0, '3' => 0];
$updateStmt = $pdo->prepare(
    "UPDATE invoice_items SET vat_classification_code = ? WHERE id = ?"
);

foreach ($items as $it) {
    $rate = (float) $it['vat_rate_snapshot'];
    $r = (int) round($rate);
    $code = $r >= 21 ? '1' : ($r >= 5 && $r <= 15 ? '2' : '3');

    $line = sprintf(
        "  item#%-6d inv#%-6d tenant=%-2d  %-9s  rate=%5.2f%%  vs=%s  →  %s",
        $it['id'],
        $it['invoice_id'],
        $it['supplier_id'],
        $it['status'],
        $rate,
        $it['varsymbol'] ?: '(none)',
        $code
    );

    $counts[$code]++;
    if (!$dryRun) {
        $updateStmt->execute([$code, $it['id']]);
    }
    echo $line . "\n";
}

echo "\nSouhrn: kód 1 → {$counts['1']}, kód 2 → {$counts['2']}, kód 3 → {$counts['3']}\n";

if ($dryRun) {
    echo "\nSpusť znovu s --apply pro skutečný zápis.\n";
} else {
    echo "\nHotovo. Po backfill spusť 'Přepočítat' v /crm dashboardu, aby se DPH přiznání aktualizovala.\n";
}
