-- MyInvoice.cz — Výkaz materiálu vedle Výkazu práce → 2 souhrnné položky na faktuře
--
-- Faktura dosud měla jeden „Výkaz víceprací" (work_reports + work_report_items) s řádky
-- práce (description, work_date, hours, rate), který se do faktury přenášel jako JEDNA
-- souhrnná položka. Tato migrace přidává k téže work_reports řádce DRUHOU část — výkaz
-- materiálu (množství + jednotka místo hodin, bez data, cena v cenové konvenci dokladu),
-- který se do faktury přenese jako DRUHÁ souhrnná položka.
--
-- Jedna work_reports řádka na fakturu (UNIQUE invoice_id zůstává) nese OBĚ části:
--   • total_hours, total_amount  = jen práce (význam nezměněn)
--   • vat_rate_id                = sazba DPH práce (12/21); NULL = fallback default faktury
--   • material_title             = název souhrnné položky materiálu (default „Materiál")
--   • material_total             = součet work_report_materials.total_amount
--   • material_vat_rate_id       = sazba DPH materiálu (12/21)
--
-- Řádky materiálu žijí v samostatné tabulce work_report_materials (čistší než přetěžovat
-- sloupec hours). Daňové jádro (InvoiceMath) se nemění — oba výkazy produkují běžné
-- řádky faktury (invoice_items), z nichž se počítá DPH zdola/shora dle prices_include_vat.
--
-- Idempotentní: ADD COLUMN IF NOT EXISTS / CREATE TABLE IF NOT EXISTS (MariaDB 10.6+),
-- FK přes DROP FOREIGN KEY IF EXISTS + ADD (vzor migrace 0064).

SET NAMES utf8mb4;

ALTER TABLE work_reports
    ADD COLUMN IF NOT EXISTS vat_rate_id INT UNSIGNED NULL
        COMMENT 'Sazba DPH práce (12/21); NULL = fallback default faktury'
        AFTER total_amount,
    ADD COLUMN IF NOT EXISTS material_title VARCHAR(190) NULL
        COMMENT 'Název souhrnné položky materiálu na faktuře (default „Materiál")'
        AFTER vat_rate_id,
    ADD COLUMN IF NOT EXISTS material_total DECIMAL(12,2) NOT NULL DEFAULT 0
        COMMENT 'Součet work_report_materials.total_amount (v cenové konvenci dokladu)'
        AFTER material_title,
    ADD COLUMN IF NOT EXISTS material_vat_rate_id INT UNSIGNED NULL
        COMMENT 'Sazba DPH materiálu (12/21)'
        AFTER material_total;

-- FK na vat_rates (idempotentně přes DROP IF EXISTS + ADD).
ALTER TABLE work_reports
    DROP FOREIGN KEY IF EXISTS fk_wr_vat;
ALTER TABLE work_reports
    ADD CONSTRAINT fk_wr_vat
        FOREIGN KEY (vat_rate_id) REFERENCES vat_rates(id);

ALTER TABLE work_reports
    DROP FOREIGN KEY IF EXISTS fk_wr_material_vat;
ALTER TABLE work_reports
    ADD CONSTRAINT fk_wr_material_vat
        FOREIGN KEY (material_vat_rate_id) REFERENCES vat_rates(id);

CREATE TABLE IF NOT EXISTS work_report_materials (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  work_report_id  BIGINT UNSIGNED NOT NULL,
  description     TEXT NOT NULL,
  quantity        DECIMAL(10,3) NOT NULL DEFAULT 1.000,
  unit            VARCHAR(20) NOT NULL DEFAULT 'ks',
  unit_price      DECIMAL(12,2) NOT NULL DEFAULT 0,   -- v cenové konvenci dokladu (prices_include_vat)
  total_amount    DECIMAL(12,2) NOT NULL DEFAULT 0,   -- round(quantity * unit_price, 2)
  order_index     INT NOT NULL DEFAULT 0,
  KEY idx_wrm_wr (work_report_id, order_index),
  CONSTRAINT fk_wrm_wr FOREIGN KEY (work_report_id) REFERENCES work_reports(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
