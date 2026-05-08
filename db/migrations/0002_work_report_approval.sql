-- MyInvoice.cz — schvalování výkazu práce zákazníkem
-- Spec: feature/work-report-approval
-- Doplňuje: projects.requires_work_report_approval
--           invoices.approval_status / token / timestamps / decided_by / rejection_reason
--           email_templates seed pro 'invoice_approval' (cs, en)
--
-- Idempotentní napříč MariaDB i MySQL 8: ADD COLUMN / ADD KEY se pouští
-- jen pokud sloupec/index nejsou v INFORMATION_SCHEMA. (`ADD COLUMN IF NOT
-- EXISTS` je MariaDB-only a na MySQL 8 padá s 1060 Duplicate column.)
-- Lze spouštět opakovaně bez chyby.

SET NAMES utf8mb4;

-- ==========================================================================
-- 1. Projects — flag, že tento projekt vyžaduje schválení výkazu zákazníkem
-- ==========================================================================

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='projects' AND COLUMN_NAME='requires_work_report_approval');
SET @sql := IF(@col=0,
  'ALTER TABLE projects ADD COLUMN requires_work_report_approval TINYINT(1) NOT NULL DEFAULT 0 AFTER status',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ==========================================================================
-- 2. Invoices — stav schvalovacího procesu
-- ==========================================================================

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='approval_status');
SET @sql := IF(@col=0,
  "ALTER TABLE invoices ADD COLUMN approval_status ENUM('none','requested','approved','rejected') NOT NULL DEFAULT 'none' AFTER status",
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='approval_token');
SET @sql := IF(@col=0,
  'ALTER TABLE invoices ADD COLUMN approval_token VARCHAR(64) NULL DEFAULT NULL AFTER approval_status',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='approval_requested_at');
SET @sql := IF(@col=0,
  'ALTER TABLE invoices ADD COLUMN approval_requested_at TIMESTAMP NULL DEFAULT NULL AFTER approval_token',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='approval_decided_at');
SET @sql := IF(@col=0,
  'ALTER TABLE invoices ADD COLUMN approval_decided_at TIMESTAMP NULL DEFAULT NULL AFTER approval_requested_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='approval_decided_by_email');
SET @sql := IF(@col=0,
  'ALTER TABLE invoices ADD COLUMN approval_decided_by_email VARCHAR(190) NULL DEFAULT NULL AFTER approval_decided_at',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='approval_rejection_reason');
SET @sql := IF(@col=0,
  'ALTER TABLE invoices ADD COLUMN approval_rejection_reason TEXT NULL DEFAULT NULL AFTER approval_decided_by_email',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='uq_inv_approval_token');
SET @sql := IF(@idx=0,
  'ALTER TABLE invoices ADD UNIQUE KEY uq_inv_approval_token (approval_token)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND INDEX_NAME='idx_inv_approval_status');
SET @sql := IF(@idx=0,
  'ALTER TABLE invoices ADD KEY idx_inv_approval_status (approval_status)',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Šablona invoice_approval (cs/en) je file-based v api/templates/email/.
-- Admin si může vytvořit override v UI Email Templates → vytvoří se řádek v email_templates.
