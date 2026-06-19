-- 0113: Platební příkazy (payment orders) pro přijaté faktury.
--
-- Hromadné generování příkazu k úhradě z nezaplacených přijatých faktur:
-- uživatel vybere faktury + účet plátce + datum splatnosti a vyexportuje
-- dávku do CSV / PDF / ABO (KPC, Česká spořitelna). Export se uloží jako
-- SNAPSHOT (payment_orders + payment_order_items) → opětovné stažení je
-- deterministické a nezávislé na pozdějších změnách faktur.
--
-- Tabulky:
--   payment_orders       — hlavička dávky (účet plátce, datum, součet, formát flagy)
--   payment_order_items  — položky (příjemce, účet, částka, VS/KS/SS, ověření CRPDPH)
--
-- purchase_invoices:
--   payment_ordered_at      — „Zařazeno k úhradě" (odvozený badge; status se NEpřeklápí
--                             na paid — to dělá až párování bankovního výpisu)
--   payment_constant_symbol — volitelný konstantní symbol k platbě
--
-- supplier:
--   abo_client_number — volitelné „číslo klienta" do hlavičky ABO UHL1 (override;
--                       jinak se odvodí z čísla účtu plátce). Banka ho někdy přiděluje.
--
-- Idempotentní: CREATE TABLE IF NOT EXISTS + ADD COLUMN IF NOT EXISTS (MariaDB 10.6+).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS payment_orders (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id          TINYINT UNSIGNED NOT NULL,
  currency             CHAR(3) NOT NULL DEFAULT 'CZK',
  payer_currency_id    INT UNSIGNED NULL,
  payer_account_number VARCHAR(34) NULL,
  payer_bank_code      VARCHAR(10) NULL,
  payer_iban           VARCHAR(34) NULL,
  payer_bic            VARCHAR(11) NULL,
  payer_account_label  VARCHAR(120) NULL,
  payment_date         DATE NOT NULL,
  total_amount         DECIMAL(14,2) NOT NULL DEFAULT 0,
  item_count           INT UNSIGNED NOT NULL DEFAULT 0,
  note                 VARCHAR(255) NULL,
  mark_paid            TINYINT(1) NOT NULL DEFAULT 0,
  created_by_user_id   BIGINT UNSIGNED NULL,
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_po_supplier (supplier_id, created_at),
  KEY idx_po_payer_currency (payer_currency_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS payment_order_items (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_order_id     BIGINT UNSIGNED NOT NULL,
  purchase_invoice_id  BIGINT UNSIGNED NOT NULL,
  payee_name           VARCHAR(255) NULL,
  payee_account_number VARCHAR(34) NULL,
  payee_bank_code      VARCHAR(10) NULL,
  payee_iban           VARCHAR(34) NULL,
  payee_bic            VARCHAR(11) NULL,
  amount               DECIMAL(14,2) NOT NULL DEFAULT 0,
  currency             CHAR(3) NOT NULL DEFAULT 'CZK',
  variable_symbol      VARCHAR(10) NULL,
  constant_symbol      VARCHAR(4) NULL,
  specific_symbol      VARCHAR(10) NULL,
  message              VARCHAR(140) NULL,
  account_verified     ENUM('verified','not_listed','unreliable','na') NOT NULL DEFAULT 'na',
  created_at           TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_poi_order (payment_order_id),
  KEY idx_poi_invoice (purchase_invoice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE purchase_invoices
    ADD COLUMN IF NOT EXISTS payment_ordered_at TIMESTAMP NULL DEFAULT NULL
        COMMENT 'Kdy byla faktura zařazena do (vyexportovaného) platebního příkazu'
        AFTER payment_account_checked_at,
    ADD COLUMN IF NOT EXISTS payment_constant_symbol VARCHAR(4) NULL
        COMMENT 'Volitelný konstantní symbol pro platbu / ABO export'
        AFTER payment_variable_symbol;

ALTER TABLE supplier
    ADD COLUMN IF NOT EXISTS abo_client_number VARCHAR(10) NULL
        COMMENT 'Číslo klienta do hlavičky ABO/KPC (override; jinak odvozeno z účtu plátce)';
