-- MyInvoice.cz — e-mailové OTP jako 2. faktor pro uživatele BEZ TOTP
--
-- Kdo nemá `users.totp_enabled=1`, dostane po ověření hesla 6místný kód
-- na e-mail (povinné, řízeno `cfg.auth.email_otp.enabled`). Kód má krátkou
-- platnost a omezený počet pokusů. Volitelně si uživatel může zapamatovat
-- zařízení („důvěryhodné zařízení") a druhý faktor se mu po danou dobu
-- nebude vyžadovat — k tomu slouží `trusted_devices`.
--
-- Žádný sloupec v `users` nepotřebujeme: email OTP je implicitní fallback
-- pro každého bez TOTP, globálně (de)aktivovaný configem.

SET NAMES utf8mb4;

-- Jednorázové e-mailové kódy. Ukládáme jen sha256 hash kódu (nikdy plaintext).
-- attempts = počet neúspěšných ověření daného kódu (anti-brute-force per kód).
CREATE TABLE IF NOT EXISTS login_otps (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id     BIGINT UNSIGNED NOT NULL,
  code_hash   CHAR(64) NOT NULL,                       -- sha256 hex 6místného kódu
  expires_at  TIMESTAMP NOT NULL,
  used_at     TIMESTAMP NULL,
  attempts    SMALLINT UNSIGNED NOT NULL DEFAULT 0,    -- neúspěšné pokusy o ověření tohoto kódu
  ip          VARBINARY(16) NOT NULL,                  -- packed IPv4 (4B) / IPv6 (16B)
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_otp_user (user_id, used_at, expires_at),
  CONSTRAINT fk_otp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Důvěryhodná zařízení („zapamatovat na 30 dní"). Klient drží jen opaque token
-- v cookie, v DB je sha256 hash. Platnost = cfg.auth.email_otp.trusted_device_days.
CREATE TABLE IF NOT EXISTS trusted_devices (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id      BIGINT UNSIGNED NOT NULL,
  token_hash   CHAR(64) NOT NULL,                      -- sha256 hex opaque tokenu z cookie
  expires_at   TIMESTAMP NOT NULL,
  user_agent   VARCHAR(255) NOT NULL DEFAULT '',       -- audit: UA při vytvoření
  ip           VARBINARY(16) NOT NULL,                 -- audit: IP při vytvoření
  created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  last_used_at TIMESTAMP NULL,
  UNIQUE KEY uq_td_token (token_hash),
  KEY idx_td_user (user_id, expires_at),
  CONSTRAINT fk_td_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
