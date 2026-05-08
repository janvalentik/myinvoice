-- MyInvoice.cz — Per-supplier konfigurace formátu čísla faktury (varsymbol).
--
-- Inspirováno NstyInvoice forkem — uživatel si v Nastavení → Číslování faktur
-- definuje vlastní šablonu (např. 'JD{YYYY}-{CC}' → 'JD2026-01') a kdy se
-- counter resetuje (year/month/none). Pokud per-supplier sloupec je NULL,
-- generator padá na fallback z cfg.varsymbol.templates.{type} (zachová zpětnou
-- kompatibilitu pro stávající instalaci, kde supplier zatím nic nemá nastaveno).
--
-- Druhá novinka: ruční override čísla faktury per-doklad — pole 'varsymbol'
-- v editoru. Sloupec `invoices.varsymbol` už existuje (VARCHAR(20)), takže
-- migrace tady nic nemění; jen rozšiřujeme `invoice_counters.period`
-- z CHAR(6) na VARCHAR(10), aby pojal 'YYYY' (year), 'YYYYMM' (month, legacy)
-- a 'ALL' (none) period keys.
--
-- Idempotentní napříč MariaDB i MySQL 8 (INFORMATION_SCHEMA guard).

SET NAMES utf8mb4;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='supplier' AND COLUMN_NAME='invoice_number_format');
SET @sql := IF(@col=0,
  "ALTER TABLE supplier ADD COLUMN invoice_number_format VARCHAR(60) NULL DEFAULT NULL COMMENT 'Per-supplier template pro varsymbol (typ invoice). NULL = fallback na cfg.varsymbol.templates.invoice.'",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='supplier' AND COLUMN_NAME='proforma_number_format');
SET @sql := IF(@col=0,
  "ALTER TABLE supplier ADD COLUMN proforma_number_format VARCHAR(60) NULL DEFAULT NULL COMMENT 'Per-supplier template pro varsymbol (typ proforma). NULL = fallback na cfg.'",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='supplier' AND COLUMN_NAME='credit_note_number_format');
SET @sql := IF(@col=0,
  "ALTER TABLE supplier ADD COLUMN credit_note_number_format VARCHAR(60) NULL DEFAULT NULL COMMENT 'Per-supplier template pro varsymbol (typ credit_note). NULL = fallback na cfg.'",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='supplier' AND COLUMN_NAME='invoice_number_period');
SET @sql := IF(@col=0,
  "ALTER TABLE supplier ADD COLUMN invoice_number_period ENUM('year','month','none') NOT NULL DEFAULT 'month' COMMENT 'Reset countru: year = 1.1., month = 1. dne v měsíci, none = nikdy.'",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Rozšiř period column (CHAR(6) -> VARCHAR(10)) pro podporu year/none scope.
-- ALTER nemá IF NOT EXISTS pro modify, ošetříme přes information_schema lookup.
SET @col_type := (
  SELECT COLUMN_TYPE FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME = 'invoice_counters'
     AND COLUMN_NAME = 'period'
);
SET @sql := IF(@col_type = 'char(6)',
  'ALTER TABLE invoice_counters MODIFY COLUMN period VARCHAR(10) NOT NULL',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
