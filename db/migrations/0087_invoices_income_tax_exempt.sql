-- MyInvoice.cz — Příznak „osvobozeno od daně z příjmů" na vydané faktuře
--
-- Motivace: některé vydané doklady NEjsou základem daně z příjmů — typicky prodej
-- movité věci osvobozený dle § 4 odst. 1 písm. c) ZDP (vozidlo > 1 rok od nabytí,
-- majetek mimo obchodní majetek u paušalisty) nebo přefakturace / průběžné položky
-- (§ 23 odst. 4 ZDP). Takový doklad je pro DPH zdanitelné plnění (zůstává v přiznání
-- DPH / kontrolním hlášení / tržbách), ale do základu daně z příjmů (§ 7) nepatří.
--
-- A protože vyměřovací základ SP/ZP u OSVČ se ODVOZUJE z dílčího základu § 7
-- (zákon 589/1992 Sb. a 592/1992 Sb. — z daňového základu, v rámci min./max.
-- vyměřovacího základu; konkrétní procenta i minima drží roční daňové konstanty
-- aplikace), vyloučením z § 7 částka zmizí i z vyměřovacího základu pojistného
-- (nad rámec minimálního základu) — jeden příznak řeší daň z příjmů i SP/ZP.
--
-- Příznak řídí JEN výpočet daně z příjmů (IncomeTaxBuilder + daňový optimalizátor);
-- NEovlivňuje DPH, kontrolní hlášení ani tržby/obrat.
--
-- Idempotence: MariaDB-native ADD COLUMN IF NOT EXISTS. Re-run safe.

SET NAMES utf8mb4;

ALTER TABLE invoices
    ADD COLUMN IF NOT EXISTS income_tax_exempt TINYINT(1) NOT NULL DEFAULT 0
        COMMENT 'Vydaná částka není základem daně z příjmů (§4 osvobození / přefakturace) → vyloučeno z DPFO/DPPO i SP/ZP. DPH/KH/tržby nedotčeny.',
    ADD COLUMN IF NOT EXISTS income_tax_exempt_reason VARCHAR(190) NULL
        COMMENT 'Volitelný důvod (např. prodej vozidla § 4 odst. 1 písm. c, přefakturace nákladů).';
