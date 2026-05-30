-- MyInvoice.cz — Skutečné výdaje v daňovém profilu (alternativa k výdajovému paušálu)
--
-- Umožní v daňovém optimalizátoru zvolit místo % paušálu reálné roční výdaje
-- (pro OSVČ, kteří vedou daňovou evidenci). Engine pak ve standardním režimu
-- použije actual_expenses místo příjem × paušál %.
--
-- Idempotence: MariaDB-native IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE tax_profiles
  ADD COLUMN IF NOT EXISTS use_actual_expenses TINYINT(1) NOT NULL DEFAULT 0
    COMMENT 'Místo výdajového paušálu použít skutečné výdaje?' AFTER activity_rate,
  ADD COLUMN IF NOT EXISTS actual_expenses DECIMAL(14,2) NOT NULL DEFAULT 0
    COMMENT 'Skutečné roční výdaje (Kč), platí když use_actual_expenses=1.' AFTER use_actual_expenses;
