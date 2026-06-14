-- 0110_logbook_fuel_liters_attempted.sql
-- Kniha jízd / tankování: marker, že u faktury už proběhl dávkový pokus o doplnění
-- litrů z položek faktury (str. 1) jako fallback k detailu (str. 2). Když se litry
-- nepodaří doplnit (faktura je nemá ani v položkách), „Vytěžit historii" to už
-- nezkouší znovu donekonečna — invariant: každou fakturu doplnit nejvýše jednou.
-- Ruční „Rozpoznat znovu" tímto markerem omezené není.

ALTER TABLE logbook_fuel_scans
    ADD COLUMN IF NOT EXISTS liters_attempted TINYINT(1) NOT NULL DEFAULT 0 AFTER status;
