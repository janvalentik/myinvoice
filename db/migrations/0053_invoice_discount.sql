-- MyInvoice.cz — Procentuální sleva na úrovni vydané faktury
--
-- Issue #50 (feature) + #48 (iDoklad import bug).
--
-- Model: `invoices.discount_percent` je ZDROJ PRAVDY (0–100 %), který zadá uživatel.
-- Při uložení (InvoiceRepository::replaceItems) se z bází jednotlivých sazeb DPH
-- dopočítá ZÁPORNÁ položka „Sleva X %" (jedna na každou kombinaci sazba+klasifikace)
-- s `item_kind='discount'`. Díky tomu se sleva automaticky promítne do totals,
-- vat_breakdown i do DPH výkazů (Kniha DPH / EPO DPHDP3 / KH), protože všechny
-- sumují `invoice_items` — žádné speciální větvení v reportech není potřeba.
--
-- `item_kind` rozlišuje uživatelské řádky od systémově generovaných slevových:
--   editor je při načtení vyfiltruje (needitují se, nezdvojí se při uložení).
--
-- Přijaté faktury (purchase_invoices) ZÁMĚRNĚ nemají discount_percent — sleva
-- z iDokladu se u nich řeší jen v import skriptu přidáním záporné položky.
--
-- Pravidelné fakturace (recurring_invoice_templates) slevu drží také — generátor
-- ji zkopíruje na vystavenou fakturu a slevová položka se materializuje stejně.
--
-- Idempotence: ADD COLUMN IF NOT EXISTS (MariaDB native).

SET NAMES utf8mb4;

ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0
    AFTER advance_paid_amount;

ALTER TABLE invoice_items
  ADD COLUMN IF NOT EXISTS item_kind ENUM('standard','discount') NOT NULL DEFAULT 'standard'
    AFTER order_index;

ALTER TABLE recurring_invoice_templates
  ADD COLUMN IF NOT EXISTS discount_percent DECIMAL(5,2) NOT NULL DEFAULT 0
    AFTER reverse_charge;
