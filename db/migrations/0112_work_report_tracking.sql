-- 0112: Veřejný náhled na výkaz práce — sledovací odkaz s tajným hashem + e-mailová autorizace.
--
-- Funkce „Náhled na výkaz práce": klient (nebo zakázka) dostane TRVALÝ odkaz s tajným
-- tokenem, který ŽIVĚ ukazuje aktuálně otevřené (draft) výkazy práce:
--   • scope='client'  → všechny draft faktury klienta, které mají výkaz práce,
--   • scope='project' → jen draft faktury dané zakázky s výkazem práce.
--
-- První přístup z prohlížeče vyžaduje ověření jednorázovým kódem zaslaným na e-mail
-- klienta/zakázky; po ověření se přístup uloží do dlouhodobé cookie
-- (work_report_link_sessions) a periodicky se znovu neověřuje.
--
-- 3 tabulky:
--   work_report_links          — trvalý odkaz na klienta/zakázku (token, scope, revoke)
--   work_report_link_codes     — jednorázové e-mailové kódy (sha256 hash, TTL, attempts)
--   work_report_link_sessions  — ověřená zařízení (sha256 hash session tokenu z cookie)
--
-- Idempotentní: CREATE TABLE IF NOT EXISTS. Bez seedu dat.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS work_report_links (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id        TINYINT UNSIGNED NOT NULL,
  scope              ENUM('client','project') NOT NULL,
  client_id          BIGINT UNSIGNED NOT NULL,
  project_id         BIGINT UNSIGNED NULL,
  token              CHAR(48) NOT NULL,
  created_by_user_id BIGINT UNSIGNED NULL,
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_sent_at       DATETIME NULL,
  last_viewed_at     DATETIME NULL,
  revoked_at         DATETIME NULL,
  UNIQUE KEY uq_wrl_token (token),
  KEY idx_wrl_entity (supplier_id, scope, client_id, project_id),
  KEY idx_wrl_client (client_id),
  KEY idx_wrl_project (project_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_report_link_codes (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  link_id     BIGINT UNSIGNED NOT NULL,
  email       VARCHAR(190) NOT NULL,
  code_hash   CHAR(64) NOT NULL,
  expires_at  DATETIME NOT NULL,
  attempts    INT UNSIGNED NOT NULL DEFAULT 0,
  used_at     DATETIME NULL,
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ip          VARBINARY(16) NULL,
  KEY idx_wrlc_active (link_id, email, used_at),
  KEY idx_wrlc_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS work_report_link_sessions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  link_id       BIGINT UNSIGNED NOT NULL,
  email         VARCHAR(190) NOT NULL,
  session_hash  CHAR(64) NOT NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_seen_at  DATETIME NULL,
  revoked_at    DATETIME NULL,
  ip            VARBINARY(16) NULL,
  UNIQUE KEY uq_wrls_session (session_hash),
  KEY idx_wrls_link (link_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
