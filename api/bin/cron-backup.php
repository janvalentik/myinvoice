<?php

declare(strict_types=1);

/**
 * Denní DB backup — mariadb-dump → gzip do storage/backup/.
 * Retention: 30 denních + 12 měsíčních (1. v měsíci se zachová déle).
 *
 * Vyžaduje v PATH: mariadb-dump (případně mysqldump).
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);

$dbHost = (string) $config->get('db.host');
$dbName = (string) $config->get('db.name');
$dbUser = (string) $config->get('db.user');
$dbPass = (string) $config->get('db.pass');
$dbPort = (int)    $config->get('db.port', 3306);

$backupDir = $rootDir . '/storage/backup';
if (!is_dir($backupDir)) @mkdir($backupDir, 0755, true);

$date = date('Y-m-d');
$file = "$backupDir/$dbName-$date.sql.gz";

// Test dostupnosti dump nástroje:
//   1) explicitní cesta z configu (db.dump_tool)
//   2) PATH (mariadb-dump → mysqldump)
//   3) běžné instalační lokace na Windows
$tool = (string) $config->get('db.dump_tool', '');
if ($tool === '' || !@is_executable($tool)) {
    $tool = shell_exec('mariadb-dump --version 2>&1') ? 'mariadb-dump' :
            (shell_exec('mysqldump --version 2>&1')   ? 'mysqldump'   : '');
}
if ($tool === '' && stripos(PHP_OS, 'WIN') === 0) {
    $candidates = array_merge(
        glob('C:\\Program Files\\MariaDB*\\bin\\mariadb-dump.exe') ?: [],
        glob('C:\\Program Files\\MariaDB*\\bin\\mysqldump.exe')    ?: [],
        glob('C:\\Program Files\\MySQL\\*\\bin\\mysqldump.exe')    ?: [],
        glob('C:\\inetpub\\MariaDB\\bin\\mariadb-dump.exe')        ?: [],
        glob('C:\\inetpub\\MariaDB\\bin\\mysqldump.exe')           ?: [],
        glob('C:\\xampp\\mysql\\bin\\mysqldump.exe')               ?: [],
        glob('C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe')     ?: []
    );
    $tool = $candidates[0] ?? '';
}
if ($tool === '') {
    fwrite(STDERR, "mariadb-dump ani mysqldump není v PATH (ani v běžných instalačních cestách). Nastav db.dump_tool v cfg.php.\n");
    exit(1);
}

// Heslo přes env (ne v command line, kde by ho viděl ps)
$env = ['MYSQL_PWD' => $dbPass];
$cmd = sprintf(
    '%s -h%s -P%d -u%s --single-transaction --quick --routines --triggers %s 2>storage/backup/.last-error',
    escapeshellcmd($tool),
    escapeshellarg($dbHost),
    $dbPort,
    escapeshellarg($dbUser),
    escapeshellarg($dbName)
);

$proc = proc_open(
    "$cmd | gzip > " . escapeshellarg($file),
    [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
    $pipes,
    $rootDir,
    $env
);
if (!is_resource($proc)) {
    fwrite(STDERR, "Cannot start backup process.\n");
    exit(1);
}
foreach ($pipes as $p) fclose($p);
$rc = proc_close($proc);

if ($rc !== 0 || !is_file($file) || filesize($file) < 100) {
    fwrite(STDERR, "Backup selhal (rc=$rc).\n");
    @unlink($file);
    exit(1);
}

$size = round(filesize($file) / 1024, 1);
echo "[" . date('Y-m-d H:i:s') . "] backup: " . basename($file) . " ({$size} KB)\n";

// Retention: smaž denní starší 30 dní (kromě 1. v měsíci, ty drž 365 dní)
$files = glob($backupDir . '/*.sql.gz') ?: [];
$now = time();
foreach ($files as $f) {
    if (!preg_match('/-(\d{4}-\d{2}-\d{2})\.sql\.gz$/', $f, $m)) continue;
    $age = $now - strtotime($m[1]);
    $isMonthly = str_ends_with($m[1], '-01');
    $maxAge = $isMonthly ? 365 * 86400 : 30 * 86400;
    if ($age > $maxAge) {
        @unlink($f);
        echo "  - retention: smazáno " . basename($f) . "\n";
    }
}
