-- MyInvoice.cz — Režim zadávání cen "s DPH" (brutto) vs "bez DPH" (netto)
--
-- Motivace: účtenky/paragony (a B2C doklady) uvádějí ceny VČETNĚ DPH. Když držíme
-- jako zdroj pravdy cenu bez DPH a DPH dopočítáváme "zdola", celková částka se
-- u brutto dokladů rozejde o haléře (33 Kč s DPH → base 27,27 → ×1,21 = 32,9967).
-- Řešení: per-doklad příznak `prices_include_vat`. Když je 1, kalkulátor počítá DPH
-- "shora" koeficientem rate/(100+rate) z ceny S DPH → celek sedí přesně.
--
-- DATOVÝ MODEL (rozhodnuto s uživatelem): NEpřidáváme `unit_price_with_vat`. Zdrojem
-- pravdy v režimu shora je řádkový `total_with_vat` (už existuje); base/vat se z něj
-- dopočítají koeficientem a `unit_price_without_vat` zůstává jen pro zobrazení
-- (na jednotkové ceně daňově nezáleží). V režimu zdola (default) se nemění nic.
--
-- Supplier default (`default_prices_include_vat`) předvyplní příznak u nové faktury.
--
-- Idempotence: MariaDB-native ADD COLUMN IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

-- Per-doklad příznak — vydané i přijaté faktury
ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS prices_include_vat TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Ceny položek zadané včetně DPH (brutto) — DPH se počítá shora koeficientem';

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS prices_include_vat TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Ceny položek zadané včetně DPH (brutto) — DPH se počítá shora koeficientem';

-- Šablony pravidelné fakturace — režim se propíše do vygenerované faktury
ALTER TABLE recurring_invoice_templates
    ADD COLUMN IF NOT EXISTS prices_include_vat TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Ceny položek zadané včetně DPH (brutto) — propíše se do generovaných faktur';

-- Supplier default pro nové faktury (0 = bez DPH, 1 = s DPH)
ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS default_prices_include_vat TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Výchozí režim cen u nových faktur (0 = bez DPH, 1 = s DPH)';
