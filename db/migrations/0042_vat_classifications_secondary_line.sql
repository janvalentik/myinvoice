-- MyInvoice.cz — Kniha DPH: secondary DPHDP3 line
--
-- Some VAT classification codes map to TWO lines in DPHDP3 (e.g. dovoz služby
-- ze 3.země / EU — base in ř.12, deduction in ř.43). Pro Kniha DPH report
-- musíme být schopni zaúčtovat řádek faktury do obou sekcí najednou.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS + UPDATE jen pro známé kódy.

SET NAMES utf8mb4;

ALTER TABLE vat_classifications
    ADD COLUMN IF NOT EXISTS dphdp3_line_secondary VARCHAR(10) NULL
        COMMENT 'Druhý řádek DPHDP3 pro téhož doklad (např. dovoz služby ř.12 + ř.43 odpočet)'
        AFTER dphdp3_line;

-- Backfill: "Dovoz / přijetí služby ze zahraničí" — DPH se na výstupu zaúčtuje
-- jako ř.12 (z dovozu služby) a SOUČASNĚ na vstupu jako ř.43 (nárok na odpočet).
--
-- Seed kód 24 "Přijetí služby z jiného členského státu EU" je v 0037 mapován
-- na dphdp3_line='5' (původní fork mapping). Pro Knihu DPH potřebujeme 12/43
-- pár (oficiální DPHDP3 řádky pro dovoz služby). Přemapujeme + nastavíme
-- secondary.
--
-- Kód 25 "Dovoz zboží ze 3. země" zůstává na ř.7 (zboží, ne služba). Pokud
-- by uživatel měl vlastní custom kódy pro dovoz služby, může je doplnit
-- ručně přes Codebooks UI.
UPDATE vat_classifications
   SET dphdp3_line = '12',
       dphdp3_line_secondary = '43'
 WHERE code IN ('24')
   AND supplier_id IS NULL
   AND (dphdp3_line_secondary IS NULL OR dphdp3_line_secondary = '');

-- Vyhledá libovolné kódy, které byly už uživatelem ručně přemapovány na ř.12
-- (dovoz služby) — doplní jim secondary='43', pokud chybí.
UPDATE vat_classifications
   SET dphdp3_line_secondary = '43'
 WHERE dphdp3_line = '12'
   AND (dphdp3_line_secondary IS NULL OR dphdp3_line_secondary = '');
