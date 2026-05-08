-- MyInvoice.cz — uchová přesný den kurzu pro audit + zobrazení.
--
-- Při fallbacku (víkend/svátek/feed nedostupný) může být použit kurz ze
-- dříve dostupného dne — např. pátek pro nedělní fakturu, nebo last-known
-- z minulého týdne. Bez tohoto pole bychom v UI/PDF nemohli říct "kurz ze
-- dne X" — jen předpokládat issue_date, což pro fallback klame.

SET NAMES utf8mb4;

-- Idempotentní napříč MariaDB + MySQL 8 (INFORMATION_SCHEMA guard).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='exchange_rate_date');
SET @sql := IF(@col=0,
  'ALTER TABLE invoices ADD COLUMN exchange_rate_date DATE NULL DEFAULT NULL AFTER exchange_rate',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
