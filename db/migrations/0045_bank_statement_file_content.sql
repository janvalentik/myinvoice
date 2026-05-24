-- MyInvoice.cz — uložení originálního obsahu GPC výpisu pro download
--
-- Bug report (květen 2026): uživatel chtěl moct stáhnout zpět původní GPC
-- soubor (audit, re-import do účetnictví). Dosud jsme měli jen file_hash
-- (pro dedup) a file_name (zobrazení) — originální bajty se zahazovaly.
--
-- MEDIUMBLOB až 16 MB — GPC výpisy jsou typicky <100 KB (text fixed-width),
-- takže to s rezervou pokryje i banky s velmi dlouhými statementy. NULL je
-- povolené pro zpětnou kompatibilitu — statementy importované před touto
-- migrací nemají file_content a download endpoint pro ně vrátí 404.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS (MariaDB 10.6+ native).

SET NAMES utf8mb4;

ALTER TABLE bank_statements
    ADD COLUMN IF NOT EXISTS file_content MEDIUMBLOB NULL
        COMMENT 'Originální obsah GPC souboru — slouží pro re-download a re-import'
        AFTER file_hash;
