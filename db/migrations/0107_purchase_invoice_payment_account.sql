-- MyInvoice.cz — Platební účet dodavatele na přijaté faktuře (pro „Zaplatit pomocí QR")
--
-- purchase_invoices dosud nemělo žádné sloupce pro platební účet dodavatele.
-- AI extrakce sice vrací vendor.bank_account, ale buildVendorSnapshot() ho zahazuje
-- a ISDOC parser z <PaymentMeans> četl jen datum splatnosti. Pro funkci „Zaplatit
-- pomocí QR" potřebujeme účet uložit editovatelně přímo u dokladu (zdroj pravdy
-- na úrovni faktury, ne na clients — účet se může lišit doklad od dokladu).
--
--   • payment_account_number / payment_bank_code — český formát ([prefix-]číslo/kód)
--   • payment_iban / payment_bic — pro SEPA (zahraniční dodavatelé)
--   • payment_variable_symbol — VS pro QR (často číslo faktury dodavatele)
--   • payment_account_source — provenience údaje (isdoc/ai/ai_reextract/qr_image/manual)
--   • payment_account_checked_at — gate proti opakovanému (placenému) AI dotazu:
--     jakmile jednou proběhne pokus o doplnění účtu, NULL → NOW() a lazy re-extrakce
--     se už nespouští (uživatel může účet kdykoli doplnit ručně).
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS (MariaDB 10.6+).

SET NAMES utf8mb4;

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS payment_account_number VARCHAR(34) NULL
        COMMENT 'Číslo účtu dodavatele (CZ formát [prefix-]číslo) pro QR platbu'
        AFTER exchange_diff_base,
    ADD COLUMN IF NOT EXISTS payment_bank_code VARCHAR(10) NULL
        COMMENT 'Kód banky (CZ, 4 číslice) pro QR platbu'
        AFTER payment_account_number,
    ADD COLUMN IF NOT EXISTS payment_iban VARCHAR(34) NULL
        COMMENT 'IBAN dodavatele (zahraniční/SEPA QR)'
        AFTER payment_bank_code,
    ADD COLUMN IF NOT EXISTS payment_bic VARCHAR(11) NULL
        COMMENT 'BIC/SWIFT dodavatele (SEPA QR, volitelné)'
        AFTER payment_iban,
    ADD COLUMN IF NOT EXISTS payment_variable_symbol VARCHAR(20) NULL
        COMMENT 'Variabilní symbol pro QR platbu'
        AFTER payment_bic,
    ADD COLUMN IF NOT EXISTS payment_account_source
        ENUM('isdoc','ai','ai_reextract','qr_image','manual') NULL
        COMMENT 'Zdroj platebního účtu (provenience)'
        AFTER payment_variable_symbol,
    ADD COLUMN IF NOT EXISTS payment_account_checked_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Kdy proběhl (jednorázový) pokus o automatické doplnění účtu; gate pro lazy AI re-extrakci'
        AFTER payment_account_source;
