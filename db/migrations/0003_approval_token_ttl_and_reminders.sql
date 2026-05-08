-- MyInvoice.cz — token expiration + reminder cron pro schvalování výkazu
-- Spec: feature/work-report-approval (rozšíření 0002)
--
-- Doplňuje:
--   1. invoices.approval_token_expires_at — token expiruje za N dní (cfg.approval.token_ttl_days)
--      Aktuálně token nikdy neexpiruje (jen invalidate při decize). Po této migraci
--      vyprší automaticky → menší attack surface pro nikdy neotevřené emaily.
--   2. invoices.approval_reminder_at + approval_reminder_count — sledování upomínek
--      pro cron-send-approval-reminders.php (denně, X dní bez reakce zákazníka).
--
-- Idempotentní napříč MariaDB i MySQL 8 (INFORMATION_SCHEMA guard).

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='approval_token_expires_at');
SET @sql := IF(@col=0,
  'ALTER TABLE invoices ADD COLUMN approval_token_expires_at TIMESTAMP NULL DEFAULT NULL AFTER approval_token',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='approval_reminder_at');
SET @sql := IF(@col=0,
  'ALTER TABLE invoices ADD COLUMN approval_reminder_at TIMESTAMP NULL DEFAULT NULL AFTER approval_decided_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='approval_reminder_count');
SET @sql := IF(@col=0,
  'ALTER TABLE invoices ADD COLUMN approval_reminder_count TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER approval_reminder_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Index pro cron query: najdi requested + posledni reminder/request starsi nez X dni
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_inv_approval_reminder');
SET @sql := IF(@idx=0,
  'ALTER TABLE invoices ADD KEY idx_inv_approval_reminder (approval_status, approval_reminder_at, approval_requested_at)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
