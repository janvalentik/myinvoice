-- MyInvoice.cz — Poslední chyba automatického generování pravidelné faktury
--
-- Cron (cron-generate-recurring-invoices.php) generuje faktury z šablon per-šablonu
-- v try/catch. Když generování selže (typicky guard „sazba DPH vypršela", nebo
-- nekladná částka), chyba dosud končila jen na STDERR + jako počet v activity_log —
-- dodavatel se o tom proaktivně nedozvěděl a faktura tiše nevznikla.
--
-- last_error / last_error_at drží POSLEDNÍ chybu generování pro danou šablonu.
-- Cron je nastaví při selhání a vynuluje při úspěchu; UI je zobrazí jako banner
-- na detailu šablony + odznak v seznamu. Ruční úspěšné vystavení je rovněž vynuluje.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (MariaDB native).

SET NAMES utf8mb4;

ALTER TABLE recurring_invoice_templates
  ADD COLUMN IF NOT EXISTS last_error VARCHAR(500) NULL AFTER last_run_date,
  ADD COLUMN IF NOT EXISTS last_error_at TIMESTAMP NULL AFTER last_error;
