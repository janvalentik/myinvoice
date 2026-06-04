-- MyInvoice.cz - #86 účely použití u fakturačních e-mailů zakázky
--
-- Symetrie s client_email_contacts: e-mail zakázky může být omezen na typ
-- zprávy (documents/reminders/approvals). NULL nebo prázdné pole = VŠECHNY
-- typy (default) — existující řádky se chovají beze změny.
-- Formát: JSON pole stringů, např. ["documents","reminders"].

SET NAMES utf8mb4;

ALTER TABLE project_billing_emails
  ADD COLUMN IF NOT EXISTS usages JSON NULL AFTER label;
