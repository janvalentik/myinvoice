-- MyInvoice.cz — Per-supplier šablona interního čísla PŘIJATÉ faktury (#103).
--
-- Doteď bylo interní číslo přijatých faktur generováno napevno formátem
-- {PP}{YYMM}{CCC} (např. PF2605001), kde {PP} = daňový prefix dle uplatnění
-- (PF/PN/KU/KN/NU/NN). Nově si uživatel může v Nastavení → Číslování faktur
-- zvolit vlastní šablonu (např. legacy 'PF-{YYYY}{MM}-{CCCC}' → PF-202605-0001).
--
-- NULL = výchozí vestavěná šablona '{PP}{YY}{MM}{CCC}' (= dosavadní chování,
-- žádná změna pro existující instalace).
--
-- Placeholdery: {PP} daňový prefix (volitelný), {YYYY}/{YY}/{MM} datum, {C+} čítač.
--
-- Idempotent přes MariaDB native IF NOT EXISTS / IF EXISTS.

SET NAMES utf8mb4;

ALTER TABLE supplier
  ADD COLUMN IF NOT EXISTS purchase_invoice_number_format VARCHAR(60) NULL DEFAULT NULL
    COMMENT 'Per-supplier šablona interního čísla přijaté faktury. NULL = vestavěný default {PP}{YY}{MM}{CCC}.';

-- Rozšiř period column z CHAR(6) na VARCHAR(10) pro year ('YYYY') / none ('ALL') scope
-- (šablona bez {MM} → roční řada). Default {PP}{YY}{MM}{CCC} má {MM} → měsíční 'YYYYMM' beze změny.
ALTER TABLE purchase_invoice_counters
  MODIFY COLUMN IF EXISTS period VARCHAR(10) NOT NULL;
