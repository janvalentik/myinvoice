-- MyInvoice.cz — Volitelná informace o zápisu společnosti v obchodním rejstříku.
--
-- Zobrazuje se vycentrovaná těsně nad patičkou každé faktury (PDF). Drží se
-- ve `supplier_snapshot` aby historické faktury zachovaly text platný v čase
-- vystavení (analogie tagline / address).

SET NAMES utf8mb4;

-- Idempotentní napříč MariaDB + MySQL 8 (INFORMATION_SCHEMA guard).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='supplier' AND COLUMN_NAME='commercial_register');
SET @sql := IF(@col=0,
  'ALTER TABLE supplier ADD COLUMN commercial_register VARCHAR(255) NULL DEFAULT NULL AFTER tagline',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
