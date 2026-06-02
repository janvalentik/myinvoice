-- MyInvoice.cz - FR-58 ověření autenticity bankovních e-mailových avíz
--
-- Možnost A: důvěřujeme verdiktu přijímacího serveru z hlavičky
-- `Authentication-Results` (DKIM/DMARC). Per IMAP účet volitelné (fail-open default),
-- s možností připnout důvěryhodný authserv-id proti podvržení hlavičky.

ALTER TABLE bank_email_imap_settings
  ADD COLUMN IF NOT EXISTS require_email_auth TINYINT(1) NOT NULL DEFAULT 0 AFTER validate_cert;

ALTER TABLE bank_email_imap_settings
  ADD COLUMN IF NOT EXISTS email_auth_serv_id VARCHAR(190) NULL AFTER require_email_auth;
