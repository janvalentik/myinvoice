-- MyInvoice.cz — Pořízení dlouhodobého majetku + RC mirror odpočet
--
-- Issue #29 (Pavel Třešňák, květen 2026): dvě nedoplněné regulační oblasti
-- v DPHDP3 / KH:
--
-- (A) **Pořízení dlouhodobého majetku — ř. 47 DPHDP3**
--     Když je doklad za majetek vymezený v § 4 odst. 4 písm. c) (typicky vozidlo,
--     stroj), je nutné jeho hodnotu (základ) uvést samostatně na ř. 47 jako
--     **doplňující údaj** k ř. 40-45. Příznak `is_fixed_asset` na hlavičce
--     i na řádku (mixed-asset doklady) — řádek vyhrává nad hlavičkou.
--
-- (B) **RC mirror odpočet — ř. 43 DPHDP3**
--     U reverse charge přijatých plnění (samovyměření) se daň objevuje 2×:
--       • výstup (samovyměřená daň): ř. 3 (EU zboží) / ř. 10 (tuzemský RC) /
--         ř. 5/6/12/13 (služby) — primary `dphdp3_line`
--       • vstup (nárok na odpočet):  ř. 43 — secondary mirror
--     Migrace 0042 už zavedla `dphdp3_line_secondary` pro dovoz služby
--     (kód 24: ř. 12 → ř. 43). Zde doplníme stejný mirror i pro kódy 5
--     (tuzemský RC purchase) a 23 (EU pořízení zboží), které dosud měly
--     pouze primary line — výsledkem byl chybějící odpočet na ř. 43.
--
-- Idempotent: ADD COLUMN IF NOT EXISTS + UPDATE jen pro známé kódy bez secondary.

SET NAMES utf8mb4;

-- ─────────────────────────────────────────────────────────────────────────
-- (A) is_fixed_asset příznak — header + per-item
-- ─────────────────────────────────────────────────────────────────────────

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS is_fixed_asset TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Doklad za dlouhodobý majetek (§ 4 odst. 4 písm. c)) — započíst do ř. 47 DPHDP3'
        AFTER vat_classification_code;

ALTER TABLE purchase_invoice_items
    ADD COLUMN IF NOT EXISTS is_fixed_asset TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Řádek je za dlouhodobý majetek — override header pro mixed doklady'
        AFTER vat_classification_code;

-- ─────────────────────────────────────────────────────────────────────────
-- (B) RC mirror odpočet — ř. 43 secondary pro kódy 5 a 23
-- ─────────────────────────────────────────────────────────────────────────

UPDATE vat_classifications
   SET dphdp3_line_secondary = '43'
 WHERE code IN ('5', '23')
   AND supplier_id IS NULL
   AND (dphdp3_line_secondary IS NULL OR dphdp3_line_secondary = '');
