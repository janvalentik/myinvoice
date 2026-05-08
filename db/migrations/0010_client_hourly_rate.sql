-- MyInvoice.cz — Default hodinová sazba na klientovi.
--
-- Použije se v editoru faktury jako fallback pro položky, pokud klient
-- nemá vybranou žádnou zakázku (zakázka má svou vlastní sazbu, která má
-- přednost). 0 = nenastaveno (žádný fallback, item zůstane s 0).

SET NAMES utf8mb4;

-- Idempotentní napříč MariaDB + MySQL 8 (INFORMATION_SCHEMA guard).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='clients' AND COLUMN_NAME='hourly_rate');
SET @sql := IF(@col=0,
  'ALTER TABLE clients ADD COLUMN hourly_rate DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER payment_due_default',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
