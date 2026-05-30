-- MyInvoice.cz — Cache odpovědí z registru plátců DPH (CRPDPH / MFČR).
--
-- Webová služba `getStatusNespolehlivyPlatce` vrací pro DIČ zveřejněné bankovní
-- účty + příznak nespolehlivého plátce. Cachujeme stejně jako ares_cache/vies_cache
-- (24h TTL v aplikaci), ať při setupu / opakovaném načítání nebombardujeme MFČR.
--
-- Klíč = DIČ bez prefixu „CZ" (jen číslice, 8–10 znaků).

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS crpdph_cache (
  dic        VARCHAR(14) PRIMARY KEY,
  payload    JSON NOT NULL,
  fetched_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
