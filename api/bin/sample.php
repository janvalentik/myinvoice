<?php

declare(strict_types=1);

/**
 * SAMPLE DATA — generuje testovací data pro vývoj.
 *
 *   php api/bin/sample.php           # interaktivní potvrzení
 *   php api/bin/sample.php --yes     # bez ptaní
 *
 * Vytvoří (pro prvního supplier):
 *   - 5 klientů (firmy s IČO + DIČ)
 *   - 8 zakázek (1-3 na klienta)
 *   - 20 vystavených faktur za poslední 2 měsíce
 *   - 4 dobropisy (k 4 z těch 20 faktur)
 *
 * Vyžaduje již proběhlý `setup.php` (admin user + supplier v DB).
 *
 * Doporučené pořadí spouštění (fresh dev install):
 *   1. cp cfg.sample.php cfg.php  +  vyplň db/smtp/pepper
 *   2. php api/bin/setup.php       # interaktivně: migrace + supplier + admin
 *   3. php api/bin/sample.php      # tento skript — testovací data
 *   ── později ──
 *      php api/bin/reset.php       # wipe všeho, pak znovu setup + sample
 *
 * Logika je sdílená s HTTP setup wizardem (POST /api/auth/setup-sample) —
 * implementace v `Service\Sample\SampleDataGenerator`.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403); exit("CLI only.\n");
}

require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Sample\SampleDataGenerator;

$autoYes = in_array('--yes', $argv, true) || in_array('-y', $argv, true);

$app = Bootstrap::buildApp();
$container = $app->getContainer();
$pdo = $container->get(Connection::class)->pdo();

$adminId = (int) $pdo->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1")->fetchColumn();
$supplierId = (int) $pdo->query('SELECT MIN(id) FROM supplier')->fetchColumn();
if ($adminId === 0 || $supplierId === 0) {
    fwrite(STDERR, "[sample] Chybí předpoklady (admin: $adminId, supplier: $supplierId).\n");
    fwrite(STDERR, "[sample] Spusť nejdřív interaktivní setup:\n         php api/bin/setup.php\n");
    exit(1);
}

echo "================================================\n";
echo "  MyInvoice.cz — SAMPLE TEST DATA\n";
echo "================================================\n";
echo "  Supplier:   #$supplierId\n";
echo "  Admin:      #$adminId\n";
echo "  Vygeneruje: 5 klientů, 8 zakázek, 20 faktur, 4 dobropisy, 2 pravidelné fakturace\n";
echo "  Období:     poslední 2 měsíce\n";
echo "================================================\n\n";

if (!$autoYes) {
    echo "Pokračovat? (ANO): ";
    if (trim((string) fgets(STDIN)) !== 'ANO') { echo "Zrušeno.\n"; exit(0); }
}

$generator = $container->get(SampleDataGenerator::class);
try {
    $r = $generator->generate($supplierId, $adminId);
} catch (\Throwable $e) {
    fwrite(STDERR, "[sample] Generování selhalo: " . $e->getMessage() . "\n");
    exit(1);
}

echo "================================================\n";
printf("  HOTOVO. %d klientů, %d zakázek, %d faktur, %d dobropisů, %d pravidelných fakturací.\n", $r['clients'], $r['projects'], $r['invoices'], $r['credit_notes'], $r['recurring']);
echo "================================================\n";
