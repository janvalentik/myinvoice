-- MyInvoice.cz — povolení přeposlaných (FW) bankovních e-mailových avíz per IMAP účet
--
-- Přeposlané avízo má `From` na adrese uživatele, ne banky, takže ho systémové
-- parsery (match na doménu odesílatele) odmítnou. Schránky, do kterých avíza
-- chodí přeposlaná, si tuto detekci z těla e-mailu zapnou tímto příznakem.
-- Opt-in, defaultně vypnuto (zachová striktní anti-spoof routing u ostatních).

ALTER TABLE bank_email_imap_settings
  ADD COLUMN IF NOT EXISTS allow_forwarded TINYINT(1) NOT NULL DEFAULT 0 AFTER require_email_auth;
