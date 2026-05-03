-- MyInvoice.cz — schvalování výkazu práce zákazníkem
-- Spec: feature/work-report-approval
-- Doplňuje: projects.requires_work_report_approval
--           invoices.approval_status / token / timestamps / decided_by / rejection_reason
--           email_templates seed pro 'invoice_approval' (cs, en)
--
-- Bezpečné spuštění: každý ALTER používá ADD COLUMN — runtime ignoruje pokud už existuje
-- by selhal s 1060; v produkci spuštět single-shot.

SET NAMES utf8mb4;

-- ==========================================================================
-- 1. Projects — flag, že tento projekt vyžaduje schválení výkazu zákazníkem
-- ==========================================================================

ALTER TABLE projects
  ADD COLUMN requires_work_report_approval TINYINT(1) NOT NULL DEFAULT 0
    AFTER status;

-- ==========================================================================
-- 2. Invoices — stav schvalovacího procesu
-- ==========================================================================

ALTER TABLE invoices
  ADD COLUMN approval_status ENUM('none','requested','approved','rejected') NOT NULL DEFAULT 'none'
    AFTER status,
  ADD COLUMN approval_token VARCHAR(64) NULL DEFAULT NULL
    AFTER approval_status,
  ADD COLUMN approval_requested_at TIMESTAMP NULL DEFAULT NULL
    AFTER approval_token,
  ADD COLUMN approval_decided_at TIMESTAMP NULL DEFAULT NULL
    AFTER approval_requested_at,
  ADD COLUMN approval_decided_by_email VARCHAR(190) NULL DEFAULT NULL
    AFTER approval_decided_at,
  ADD COLUMN approval_rejection_reason TEXT NULL DEFAULT NULL
    AFTER approval_decided_by_email,
  ADD UNIQUE KEY uq_inv_approval_token (approval_token),
  ADD KEY idx_inv_approval_status (approval_status);

-- Šablona invoice_approval (cs/en) je file-based v api/templates/email/.
-- Admin si může vytvořit override v UI Email Templates → vytvoří se řádek v email_templates.
