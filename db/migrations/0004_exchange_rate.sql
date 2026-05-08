-- MyInvoice.cz — denní kurzy ČNB pro přepočet faktur v cizí měně do CZK
--
-- 1. exchange_rates — cache denních kurzů z https://www.cnb.cz/.../denni_kurz.txt
--    Klíč (rate_date, currency_code) = jeden řádek z feedu, normalizovaný na
--    kurz za 1 jednotku měny (CNB feed má sloupec "množství", typicky 1 nebo 100).
--    fetched_at slouží jen pro audit / debug — primary lookup je přes klíč.
--
-- 2. invoices.exchange_rate — kurz CZK / 1 jednotka faktur. měny zafixovaný
--    při uložení faktury. NULL pro CZK faktury i pro případ kdy ČNB nevrátila
--    žádný kurz (poslední známý ani 7 dní zpět). Při změně issue_date / currency
--    se přepočítá v UpdateInvoiceAction.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS exchange_rates (
  rate_date     DATE NOT NULL,
  currency_code CHAR(3) NOT NULL,
  rate          DECIMAL(14,6) NOT NULL,        -- CZK za 1 jednotku měny (po normalizaci)
  fetched_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (rate_date, currency_code),
  KEY idx_rates_currency (currency_code, rate_date DESC)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Idempotentní napříč MariaDB + MySQL 8 (INFORMATION_SCHEMA guard).
SET @col := (SELECT COUNT(*) FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoices' AND COLUMN_NAME='exchange_rate');
SET @sql := IF(@col=0,
  'ALTER TABLE invoices ADD COLUMN exchange_rate DECIMAL(14,6) NULL DEFAULT NULL AFTER currency_id',
  'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
