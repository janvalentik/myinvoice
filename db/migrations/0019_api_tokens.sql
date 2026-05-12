-- MyInvoice.cz — Public REST API v1: bearer (PAT) tokeny
--
-- Ukládáme jen SHA-256 hash plaintextu; plaintext se uživateli zobrazí
-- pouze jednou při vytvoření a v DB ho nikdy nedržíme.
--
-- Token je volitelně bound na konkrétní supplier (NULL = všichni supplier-i
-- daného usera). Scope rozliší read-only vs read-write integrace.
--
-- Indexes:
--   uq_apitok_hash — primární lookup při ověřování
--   idx_apitok_user — list/admin výpis
--   idx_apitok_supplier — cleanup při smazání supplier-a (FK CASCADE)

SET NAMES utf8mb4;

CREATE TABLE api_tokens (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NOT NULL,
  supplier_id     TINYINT UNSIGNED NULL,
  name            VARCHAR(100) NOT NULL,
  token_hash      CHAR(64) NOT NULL,
  prefix          VARCHAR(16) NOT NULL,
  scope           ENUM('read','read_write') NOT NULL DEFAULT 'read_write',
  last_used_at    DATETIME NULL,
  last_used_ip    VARBINARY(16) NULL,
  expires_at      DATETIME NULL,
  revoked_at      DATETIME NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_apitok_hash (token_hash),
  KEY idx_apitok_user (user_id),
  KEY idx_apitok_supplier (supplier_id),
  CONSTRAINT fk_apitok_user     FOREIGN KEY (user_id)     REFERENCES users(id)    ON DELETE CASCADE,
  CONSTRAINT fk_apitok_supplier FOREIGN KEY (supplier_id) REFERENCES supplier(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
