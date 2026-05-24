<?php

declare(strict_types=1);

/**
 * Denní cleanup — login_attempts (>24h), expirované sessions, použité password_resets,
 * login_otps + trusted_devices (e-mailové 2FA), log files >90 dní.
 *
 * POZN: PDF se NEMAŽE. Aktivní cache může pominout (renderer ji znovu vytvoří),
 * ale archivovaná historie (storage/invoices/sup-N/_archive) obsahuje verze
 * skutečně odeslané klientovi a je důkazem fakturace.
 *
 * Použití (Windows Task Scheduler):
 *   php api/bin/cron-cleanup.php
 */

if (PHP_SAPI !== 'cli') exit("CLI only.\n");
require __DIR__ . '/../vendor/autoload.php';

use MyInvoice\Bootstrap;
use MyInvoice\Infrastructure\Config\Config;
use MyInvoice\Infrastructure\Database\Connection;
use MyInvoice\Service\Cron\CronRun;

$rootDir = Bootstrap::rootDir();
$config  = Config::load($rootDir);
$pdo     = (new Connection($config))->pdo();

$run = CronRun::start($pdo, 'cron-cleanup');
$startedAt = microtime(true);
$report = [];

// 1) login_attempts — drop záznamy starší 24 hodin
$n = $pdo->exec("DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 24 HOUR");
$report['login_attempts'] = (int) $n;

// 2) sessions — expirované
$n = $pdo->exec("DELETE FROM sessions WHERE expires_at < NOW()");
$report['expired_sessions'] = (int) $n;

// 3) password_resets — použité nebo expirované >7 dní
$n = $pdo->exec("DELETE FROM password_resets WHERE used_at IS NOT NULL OR expires_at < NOW() - INTERVAL 7 DAY");
$report['password_resets'] = (int) $n;

// 3b) login_otps (e-mailové 2FA kódy) — použité/expirované >1 den
$n = $pdo->exec("DELETE FROM login_otps WHERE used_at < NOW() - INTERVAL 1 DAY OR expires_at < NOW() - INTERVAL 1 DAY");
$report['login_otps'] = (int) $n;

// 3c) trusted_devices — expirovaná „zapamatovaná zařízení"
$n = $pdo->exec("DELETE FROM trusted_devices WHERE expires_at < NOW()");
$report['trusted_devices'] = (int) $n;

// 4) ARES/VIES cache — starší 30 dní
$n = $pdo->exec("DELETE FROM ares_cache WHERE fetched_at < NOW() - INTERVAL 30 DAY");
$report['ares_cache'] = (int) $n;
$n = $pdo->exec("DELETE FROM vies_cache WHERE fetched_at < NOW() - INTERVAL 30 DAY");
$report['vies_cache'] = (int) $n;

// 5) Log files — Monolog rotuje, ale když je config zapnutý max_files, držíme se ho
$logDir = (string) $config->get('logging.path', $rootDir . '/log/app.log');
$logDir = dirname($logDir);
$maxFiles = (int) $config->get('logging.max_files', 90);
$logDeleted = 0;
if (is_dir($logDir)) {
    $files = glob($logDir . '/*.log') ?: [];
    if (count($files) > $maxFiles) {
        usort($files, fn ($a, $b) => filemtime($a) - filemtime($b));
        $toDel = array_slice($files, 0, count($files) - $maxFiles);
        foreach ($toDel as $f) if (@unlink($f)) $logDeleted++;
    }
}
$report['log_files'] = $logDeleted;

// Pročisti cron_runs — drž max 500 posledních záznamů na skript.
$report['cron_runs_purged'] = CronRun::purgeOld($pdo, 500);

$ms = (int) ((microtime(true) - $startedAt) * 1000);
echo "[" . date('Y-m-d H:i:s') . "] cron-cleanup ({$ms} ms): " . json_encode($report, JSON_UNESCAPED_UNICODE) . "\n";

// Audit do activity_log
$pdo->prepare(
    "INSERT INTO activity_log (action, payload) VALUES ('cron.cleanup', ?)"
)->execute([json_encode($report, JSON_UNESCAPED_UNICODE)]);

$run->finish('ok', $report);
