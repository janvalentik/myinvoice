-- MyInvoice.cz — Per-supplier email branding (issue #5 follow-up)
--
-- Přidává sloupce pro custom branding hlavičky odchozích emailů:
--   - email_branding_enabled  TINYINT(1) — opt-in toggle (default 0 = MyInvoice branding)
--   - email_accent_color      VARCHAR(7) — hex barva napříč emailem (default fialová MyInvoice)
--
-- Reuse existujících polí: display_name (brand name), tagline (subtitle), logo_path (logo file).
--
-- Idempotentní: kontrola information_schema.COLUMNS, ALTER se pustí jen pokud sloupec chybí.

SET NAMES utf8mb4;

-- email_branding_enabled
SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'supplier'
     AND COLUMN_NAME  = 'email_branding_enabled'
);

SET @sql := IF(@col_exists = 0,
  'ALTER TABLE supplier ADD COLUMN email_branding_enabled TINYINT(1) NOT NULL DEFAULT 0 AFTER tagline',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- email_accent_color
SET @col_exists := (
  SELECT COUNT(*)
    FROM information_schema.COLUMNS
   WHERE TABLE_SCHEMA = DATABASE()
     AND TABLE_NAME   = 'supplier'
     AND COLUMN_NAME  = 'email_accent_color'
);

SET @sql := IF(@col_exists = 0,
  "ALTER TABLE supplier ADD COLUMN email_accent_color VARCHAR(7) NOT NULL DEFAULT '#3B2D83' AFTER email_branding_enabled",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
