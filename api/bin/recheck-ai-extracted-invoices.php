<?php

declare(strict_types=1);

/**
 * Recheck existujících přijatých faktur proti AI extrakci.
 *
 * Projde přijaté faktury, které mají PDF přílohu a zatím nemají `extraction_warning`,
 * znovu zavolá AI (Anthropic) pro každé PDF a porovná AI-vrácený `total_without_vat`
 * s aktuálním DB totalem. Pokud se liší o víc než 2 %, zapíše varování do
 * `extraction_warning` — UI ho pak vyflagne jako "vyžaduje kontrolu".
 *
 * Pozor: API volání stojí peníze (Haiku 4.5 ~ 0.001 USD / PDF). Default je dry-run,
 * který jen porovná a vypíše. Pro skutečný zápis použij `--apply`.
 *
 * Použití:
 *   php api/bin/recheck-ai-extracted-invoices.php                       # dry-run, všichni supplieři
 *   php api/bin/recheck-ai-extracted-invoices.php --apply               # zápis warning_textu
 *   php api/bin/recheck-ai-extracted-invoices.php --supplier-id=1       # jen supplier 1
 *   php api/bin/recheck-ai-extracted-invoices.php --limit=10            # max 10 faktur (pro test)
 *   php api/bin/recheck-ai-extracted-invoices.php --include-flagged     # i ty co už mají warning (refresh)
 *   php api/bin/recheck-ai-extracted-invoices.php --threshold=0.05      # custom práh (default 0.02 = 2%)
 *
 * Note: faktury BEZ pdf_path se ignorují (nelze re-extract). Faktury, kde extrakce
 * selže (timeout / nesprávné API klíče / corrupted PDF) zaloguje warning a pokračuje.
 */

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Import\AnthropicClient;
use MyInvoice\Repository\PurchaseInvoiceRepository;

// ── Argumenty ─────────────────────────────────────────────────────────
$dryRun         = !in_array('--apply', $argv, true);
$includeFlagged = in_array('--include-flagged', $argv, true);
$supplierId     = null;
$limit          = 0;
$threshold      = 0.02;
foreach ($argv as $a) {
    if (preg_match('/^--supplier-id=(\d+)$/', $a, $m))  $supplierId = (int) $m[1];
    if (preg_match('/^--limit=(\d+)$/', $a, $m))        $limit      = (int) $m[1];
    if (preg_match('/^--threshold=([\d.]+)$/', $a, $m)) $threshold  = (float) $m[1];
}

// ── Bootstrap ─────────────────────────────────────────────────────────
$app       = Bootstrap::buildApp();
$container = $app->getContainer();
$pdo       = $container->get(Connection::class)->pdo();
$anthropic = $container->get(AnthropicClient::class);
$repo      = $container->get(PurchaseInvoiceRepository::class);
$rootDir   = Bootstrap::rootDir();

// ── Konfigurace archivu PDF ────────────────────────────────────────────
$archiveRoot = (string) $container->get(\MyInvoice\Infrastructure\Config\Config::class)
    ->get('purchase_invoice.archive_storage', '');
if ($archiveRoot === '') {
    $archiveRoot = $rootDir . '/storage/purchase-invoices';
}

// ── Najít kandidáty ───────────────────────────────────────────────────
$where = ['pdf_path IS NOT NULL', "pdf_path != ''"];
$params = [];
if (!$includeFlagged) $where[] = 'extraction_warning IS NULL';
if ($supplierId !== null) {
    $where[] = 'supplier_id = ?';
    $params[] = $supplierId;
}
$sql = 'SELECT id, supplier_id, vendor_invoice_number, total_without_vat, total_with_vat,
               pdf_path, status
          FROM purchase_invoices
         WHERE ' . implode(' AND ', $where) . '
         ORDER BY id DESC';
if ($limit > 0) $sql .= " LIMIT $limit";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$candidates = $stmt->fetchAll(PDO::FETCH_ASSOC);

$count = count($candidates);
printf("Nalezeno %d kandidátů (PDF + bez warning%s%s).\n",
    $count,
    $supplierId !== null ? ", supplier=$supplierId" : '',
    $includeFlagged ? ', včetně už flagovaných' : '',
);
printf("Mode: %s, threshold: %.1f %%\n\n", $dryRun ? 'DRY-RUN' : 'APPLY', $threshold * 100);

if ($count === 0) {
    echo "Nic k recheck. Hotovo.\n";
    exit(0);
}

// ── Iterace ───────────────────────────────────────────────────────────
$stats = ['ok' => 0, 'flagged' => 0, 'skipped_no_pdf' => 0, 'skipped_no_total' => 0, 'errors' => 0];

foreach ($candidates as $i => $row) {
    $id       = (int) $row['id'];
    $supId    = (int) $row['supplier_id'];
    $vNum     = (string) $row['vendor_invoice_number'];
    // abs() — dobropisy mají v DB záporný total_without_vat, AI vrací kladné
    $dbTotal  = abs((float) $row['total_without_vat']);
    $pdfRel   = (string) $row['pdf_path'];

    printf("[%d/%d] #%d (%s) ... ", $i + 1, $count, $id, $vNum);

    $pdfPath = $archiveRoot . '/' . $pdfRel;
    if (!is_file($pdfPath)) {
        printf("PDF chybí na disku (%s), skip\n", $pdfPath);
        $stats['skipped_no_pdf']++;
        continue;
    }

    $pdfBytes = @file_get_contents($pdfPath);
    if ($pdfBytes === false || $pdfBytes === '') {
        printf("PDF nelze přečíst, skip\n");
        $stats['skipped_no_pdf']++;
        continue;
    }

    $result = $anthropic->extractInvoice($supId, $pdfBytes);
    if (!$result['ok']) {
        printf("AI volání selhalo: %s\n", $result['error'] ?? 'neznámá chyba');
        $stats['errors']++;
        continue;
    }

    $aiData = $result['data'] ?? [];
    // Porovnáváme VÝHRADNĚ bez DPH proti bez DPH. Žádný přepočet `total_with_vat / 1.21`
    // — u multi-rate faktur (mix 21/12/0 %) by dělal false positive. Pokud AI nevrátí
    // total_without_vat, recheck pro tuto fakturu přeskočíme.
    $aiTotal = isset($aiData['total_without_vat']) ? abs((float) $aiData['total_without_vat']) : null;
    if ($aiTotal === null || $aiTotal <= 0.0) {
        printf("AI nevrátila total_without_vat, skip\n");
        $stats['skipped_no_total']++;
        continue;
    }

    $diff = abs($dbTotal - $aiTotal);
    $relativeDiff = $dbTotal > 0.0 ? $diff / $dbTotal : ($aiTotal > 0.0 ? 1.0 : 0.0);

    if ($relativeDiff <= $threshold) {
        printf("OK (DB=%.2f, AI=%.2f, rozdíl %.1f %%)\n",
            $dbTotal, $aiTotal, $relativeDiff * 100);
        $stats['ok']++;
        continue;
    }

    $direction = $dbTotal > $aiTotal ? 'nafouknutý' : 'podhodnocený';
    $warning = sprintf(
        'Při zpětné kontrole AI extrakce: aktuální součet řádků v DB (%s) se liší od nově extrahovaného AI totalu bez DPH (%s) o %.1f %% (%s součet). '
            . 'Typická příčina: AI při původní extrakci započítala mezisoučtové řádky ("Celkem", "Subtotal") jako další položky. '
            . 'Zkontroluj prosím řádky proti PDF před zaúčtováním.',
        number_format($dbTotal, 2, ',', ' '),
        number_format($aiTotal, 2, ',', ' '),
        $relativeDiff * 100.0,
        $direction,
    );

    printf("FLAG (DB=%.2f, AI=%.2f, %.1f %% %s)%s\n",
        $dbTotal, $aiTotal, $relativeDiff * 100, $direction,
        $dryRun ? ' [dry-run]' : '');
    $stats['flagged']++;

    if (!$dryRun) {
        try {
            $repo->setExtractionWarning($id, $supId, $warning);
        } catch (\Throwable $e) {
            printf("    ! Zápis warningu selhal: %s\n", $e->getMessage());
            $stats['errors']++;
        }
    }
}

// ── Shrnutí ───────────────────────────────────────────────────────────
echo "\n";
echo "Hotovo. Statistika:\n";
printf("  OK (rozdíl pod %.1f %%)     : %d\n", $threshold * 100, $stats['ok']);
printf("  FLAG (nad práh)            : %d%s\n", $stats['flagged'], $dryRun ? ' (DRY-RUN, nezapsáno)' : '');
printf("  Skip — chybí PDF           : %d\n", $stats['skipped_no_pdf']);
printf("  Skip — AI nevrátila total  : %d\n", $stats['skipped_no_total']);
printf("  Chyby                      : %d\n", $stats['errors']);

if ($dryRun && $stats['flagged'] > 0) {
    echo "\nPro skutečný zápis spusť znovu s --apply.\n";
}
