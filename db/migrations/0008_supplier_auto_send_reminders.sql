-- MyInvoice.cz — Per-supplier přepínač automatického posílání upomínek.
--
-- Když je 0, cron `bin/cron-send-reminders.php` přeskočí faktury daného
-- dodavatele. Ruční upomínky (jednotlivé i hromadné z UI) fungují dál.
-- Default 1 = upomínky se posílají (zachovává stávající chování).

SET NAMES utf8mb4;

-- Idempotentní napříč MariaDB + MySQL 8 (INFORMATION_SCHEMA guard).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='supplier' AND COLUMN_NAME='auto_send_reminders');
SET @sql := IF(@col=0,
  'ALTER TABLE supplier ADD COLUMN auto_send_reminders TINYINT(1) NOT NULL DEFAULT 1 AFTER default_hourly_rate',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
