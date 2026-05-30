-- MyInvoice.cz — Plátcovství DPH u klientů/dodavatelů
--
-- Motivace: u DODAVATELE potřebujeme vědět, zda je plátce DPH. Od neplátce nelze
-- uplatnit nárok na odpočet (na dokladu žádná DPH není) → přijaté faktury musí mít
-- `vat_deduction='none'`. Příznak plníme z ARES (CZ dle IČO) / VIES (zahraniční dle DIČ).
--
-- `supplier.is_vat_payer` (vlastní firma) existuje od 0001; tohle je analogický příznak
-- na straně protistrany v `clients`. Default 1 (BC — dosavadní chování = plátce).
--
-- Idempotence: MariaDB-native ADD COLUMN IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE clients
    ADD COLUMN IF NOT EXISTS is_vat_payer TINYINT(1) NOT NULL DEFAULT 1
        COMMENT 'Protistrana je plátce DPH (z ARES/VIES). U dodavatele řídí nárok na odpočet.';
