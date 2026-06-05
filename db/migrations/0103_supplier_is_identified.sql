-- MyInvoice.cz — Identifikovaná osoba (§ 6g–6l ZDPH, issue #94).
--
-- IO je v tuzemsku NEPLÁTCE (is_vat_payer = 0), ale má DIČ a povinnosti
-- z přeshraničních plnění: RC faktury do EU (čl. 196 směrnice), souhrnné
-- hlášení, samovyměření z přijatých zahraničních plnění BEZ nároku na odpočet,
-- DPHDP3 s typ_platce='I' jen za měsíce se vznikem povinnosti.
--
-- Záměrně NE třetí stav v is_vat_payer — is_identified jen PŘIDÁVÁ přeshraniční
-- chování k neplátci, tuzemské větve (editor, PDF, KH gating) zůstávají netknuté.
-- Kombinace is_vat_payer=1 && is_identified=1 je nevalidní (hlídá SettingsAction).

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS is_identified TINYINT(1) NOT NULL DEFAULT 0 AFTER is_vat_payer;
