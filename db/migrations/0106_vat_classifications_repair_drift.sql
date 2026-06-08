-- 0106: Oprava driftnutého globálního číselníku DPH klasifikací (supplier_id IS NULL).
--
-- Migrace 0044/0048/0063 (RC příznaky + samovyměření + cleanup kódů 3/22/26/42) a 0072
-- (vložení 25s) jsou jednorázové a idempotentní — orazítkované jako proběhlé se znovu
-- nespustí. Na DB, kde globální seed mezitím driftnul (re-seed ze staršího 0037, kopie
-- starší produkční DB, ruční zásah do číselníku apod.), proto můžou zůstat staré chybné
-- hodnoty. Pozorovaný drift např.: kód 3/42 s `dphdp3_line` = číslo kódu místo NULL,
-- chybějící kód 25s. Důsledky ve výkazech:
--   • kód 3 (osvobozený tuzemský prodej) korumpoval ř.3 DPHDP3 (pořízení zboží z JČS),
--   • kód 42 (přijaté bez nároku na odpočet) padal do KH B.2/B.3,
--   • bez 25s se tuzemský RC dodavatel (§ 92a) vykázal na ř.20 místo ř.25,
--   • bez RC příznaku / mirror ř.43 by se dovoz/RC nesamovyměřil.
--
-- Tato migrace RE-ASERTUJE kanonický stav RC/cleanup polí pro systémové kódy. Sahá JEN
-- na systémové řádky (supplier_id IS NULL) — ty edituje pouze migrace, nikdy uživatel
-- (admin UI pracuje výhradně s per-tenant řádky `WHERE supplier_id = <tenant>`), takže
-- nemůže přepsat žádná uživatelská data. Na čisté/aktuální DB je no-op (idempotentní).

SET NAMES utf8mb4;

-- ── Reverse charge příznak (samovyměření na vstupu) — kódy 5, 23, 24, 25 (0048 + 0063 A)
UPDATE vat_classifications
   SET is_reverse_charge = 1
 WHERE supplier_id IS NULL
   AND direction = 'purchase'
   AND code IN ('5', '23', '24', '25')
   AND is_reverse_charge <> 1;

-- ── Mirror odpočet ř.43 u samovyměřených RC plnění — kódy 5, 23, 24, 25 (0044 + 0063 A)
UPDATE vat_classifications
   SET dphdp3_line_secondary = '43'
 WHERE supplier_id IS NULL
   AND direction = 'purchase'
   AND code IN ('5', '23', '24', '25')
   AND (dphdp3_line_secondary IS NULL OR dphdp3_line_secondary <> '43');

-- ── EU dodání zboží (20) do KH nepatří (vykazuje se v souhrnném hlášení) (0063 B)
UPDATE vat_classifications
   SET kh_section = NULL
 WHERE supplier_id IS NULL
   AND direction = 'sale'
   AND code = '20'
   AND kh_section IS NOT NULL;

-- ── Oddíl C: poskytnutí služby do JČS (22 → ř.21) a vývoz (26 → ř.22) (0048)
UPDATE vat_classifications
   SET dphdp3_line = '21'
 WHERE supplier_id IS NULL
   AND direction = 'sale'
   AND code = '22'
   AND (dphdp3_line IS NULL OR dphdp3_line <> '21');

UPDATE vat_classifications
   SET dphdp3_line = '22'
 WHERE supplier_id IS NULL
   AND direction = 'sale'
   AND code = '26'
   AND (dphdp3_line IS NULL OR dphdp3_line <> '22');

-- ── Kód 3 "tuzemsko osvobozeno" — patří NULL (ř.3 = pořízení z JČS, ne osvob. prodej) (0063 D)
UPDATE vat_classifications
   SET dphdp3_line = NULL
 WHERE supplier_id IS NULL
   AND direction = 'sale'
   AND code = '3'
   AND dphdp3_line IS NOT NULL;

-- ── Kód 42 "tuzemsko bez nároku na odpočet" — patří NULL (do DPHDP3/KH se nevykazuje) (0063 C)
UPDATE vat_classifications
   SET dphdp3_line = NULL
 WHERE supplier_id IS NULL
   AND direction = 'purchase'
   AND code = '42'
   AND dphdp3_line IS NOT NULL;

-- ── Tuzemský RC dodavatel (§ 92a–92e) → ř.25 (pln_rez_pren). Vloží jen pokud chybí. (0072)
INSERT INTO vat_classifications
    (supplier_id, code, label, direction, dphdp3_line, kh_section, vat_rate, is_reverse_charge, display_order)
SELECT NULL, '25s', 'Tuzemský režim přenesení daňové povinnosti – dodavatel (§ 92a–92e)',
       'sale', '25', 'A.1', NULL, 1, 23
FROM DUAL
WHERE NOT EXISTS (
    SELECT 1 FROM vat_classifications WHERE code = '25s' AND supplier_id IS NULL
);
