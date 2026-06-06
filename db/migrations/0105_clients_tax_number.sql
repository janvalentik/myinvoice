-- MyInvoice.cz — národní daňové číslo protistrany (issue #120).
--
-- Některé země vedou vedle VAT ID (naše `dic`, s country prefixem) ještě národní
-- daňové číslo: SK DIČ (bez prefixu, má ho i neplátce), DE/AT Steuernummer,
-- PL NIP, HU Adószám (formát 8-1-2, liší se od HU VAT). Směrnice 2006/112/ES
-- (čl. 226) na přeshraniční faktuře vyžaduje jen VAT ID, ale lokální praxe
-- (hlavně SK: IČO + DIČ + IČ DPH) tato čísla na dokladu očekává.
--
-- `dic` u SK klienta nese IČ DPH (SK+číslo — formát pro VIES/RC),
-- `tax_number` nese národní daňové číslo bez prefixu. Frontend pole zobrazuje
-- jen pro země SK/DE/AT/PL/HU s nativním labelem.

SET NAMES utf8mb4;

ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS tax_number VARCHAR(30) NULL DEFAULT NULL AFTER dic;
