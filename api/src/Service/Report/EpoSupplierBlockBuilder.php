<?php

declare(strict_types=1);

namespace MyInvoice\Service\Report;

use DOMElement;

/**
 * Sdílený helper pro sestavení `<VetaP>` (identifikace daňového subjektu)
 * a normalizaci CZ-NACE / OKEČ kódu napříč EPO výkazy (DPHDP3, DPHKH1, DPHSHV).
 *
 * VetaP struktura je v DPH/KH/SHV identická per EPO XSD — sdílíme jeden
 * generátor, aby všechny výkazy odpovídaly konzistentně tomu, co posílá
 * skutečné EPO podání: opr_*, sest_*, c_orient, c_pop, c_telef atd.
 */
final class EpoSupplierBlockBuilder
{
    /**
     * Vyplní VetaP atributy z `supplier` row.
     *
     * @param array<string,mixed> $supplier Načteno z `supplier` tabulky včetně
     *                                       cz_nace_code, opr_*, sest_*, street_number_*.
     */
    public static function fillVetaP(DOMElement $vetaP, array $supplier): void
    {
        // c_ufo (kód FÚ) je required. Fallback "451" (Praha 1) pokud chybí.
        $vetaP->setAttribute('c_ufo', (string) ($supplier['financial_office_code'] ?: '451'));
        if (!empty($supplier['workplace_code'])) {
            $vetaP->setAttribute('c_pracufo', (string) $supplier['workplace_code']);
        }
        // DIČ — pattern [0-9]{1,10}, strip "CZ" prefix.
        $dic = (string) ($supplier['dic'] ?? '');
        $vetaP->setAttribute('dic', preg_replace('/^CZ/i', '', $dic) ?? $dic);
        $vetaP->setAttribute('typ_ds', $supplier['data_box_type'] ?: 'F');

        if (($supplier['taxpayer_type'] ?? null) === 'po') {
            $vetaP->setAttribute('zkrobchjm', (string) $supplier['company_name']);
        } else {
            $parts = explode(' ', trim((string) $supplier['company_name']), 2);
            $vetaP->setAttribute('jmeno', $parts[0] ?? '');
            $vetaP->setAttribute('prijmeni', $parts[1] ?? $parts[0] ?? '');
        }

        // Adresa: ulice samotná + čísla popisné/orientační zvlášť (per EPO konvence).
        //   1) Pokud má uživatel samostatné `street_number_pop` / `street_number_orient`,
        //      použijeme je a z `street` odstřihneme trailing čísla (aby se nezdvojovala).
        //   2) Jinak fallback parsing z `street` — typický český formát "Ulice 1104/36"
        //      rozdělí na ulice + č.p. + č.o.
        $rawStreet = (string) ($supplier['street'] ?? '');
        $cpop = trim((string) ($supplier['street_number_pop'] ?? ''));
        $corient = trim((string) ($supplier['street_number_orient'] ?? ''));
        $uliceText = $rawStreet;
        if ($cpop !== '' || $corient !== '') {
            // Manuálně vyplněná čísla → odřízni numerický suffix z ulice (i s "/")
            $uliceText = preg_replace('/\s+\d+[a-zA-Z]?(?:\s*\/\s*\d+[a-zA-Z]?)?\s*$/u', '', $rawStreet) ?? $rawStreet;
            $uliceText = trim($uliceText);
        } elseif ($rawStreet !== '') {
            // Fallback parsing:
            //   "Kardinála Berana 1104/36" → ulice="Kardinála Berana", pop=1104, orient=36
            //   "Hlavní 12"                → ulice="Hlavní", pop=12
            //   "Hlavní 12a"               → ulice="Hlavní", pop=12a (alfa suffix ok)
            if (preg_match('/^(.+?)\s+(\d+[a-zA-Z]?)(?:\s*\/\s*(\d+[a-zA-Z]?))?\s*$/u', $rawStreet, $m)) {
                $uliceText = trim($m[1]);
                $cpop = $m[2];
                if (!empty($m[3])) $corient = $m[3];
            }
        }
        $vetaP->setAttribute('ulice', $uliceText);
        if ($cpop !== '')    $vetaP->setAttribute('c_pop', $cpop);
        if ($corient !== '') $vetaP->setAttribute('c_orient', $corient);
        $vetaP->setAttribute('naz_obce', (string) ($supplier['city'] ?? ''));
        $vetaP->setAttribute('psc', preg_replace('/\s/', '', (string) ($supplier['zip'] ?? '')) ?? '');
        $vetaP->setAttribute('stat', (string) ($supplier['country_iso2'] ?? 'CZ'));

        if (!empty($supplier['email'])) $vetaP->setAttribute('email', (string) $supplier['email']);
        if (!empty($supplier['phone'])) $vetaP->setAttribute('c_telef', self::normalizePhone((string) $supplier['phone']));

        // Oprávněná osoba (POVINNÉ u PO — jednatel apod.)
        if (!empty($supplier['opr_jmeno']))     $vetaP->setAttribute('opr_jmeno', (string) $supplier['opr_jmeno']);
        if (!empty($supplier['opr_prijmeni']))  $vetaP->setAttribute('opr_prijmeni', (string) $supplier['opr_prijmeni']);
        if (!empty($supplier['opr_postaveni'])) $vetaP->setAttribute('opr_postaveni', (string) $supplier['opr_postaveni']);

        // Sestavitel přiznání (typicky účetní). Příjmení má vlastní sloupec
        // `sest_prijmeni` (sjednoceno s jednatelem opr_*). Když není vyplněno,
        // fallback: split `sest_jmeno` podle první mezery (BC pro stará data).
        if (!empty($supplier['sest_jmeno'])) {
            if (!empty($supplier['sest_prijmeni'])) {
                $vetaP->setAttribute('sest_jmeno', (string) $supplier['sest_jmeno']);
                $vetaP->setAttribute('sest_prijmeni', (string) $supplier['sest_prijmeni']);
            } else {
                $sestParts = explode(' ', trim((string) $supplier['sest_jmeno']), 2);
                $vetaP->setAttribute('sest_jmeno', $sestParts[0] ?? '');
                if (!empty($sestParts[1])) {
                    $vetaP->setAttribute('sest_prijmeni', $sestParts[1]);
                }
            }
        }
        if (!empty($supplier['sest_telefon'])) $vetaP->setAttribute('sest_telef', self::normalizePhone((string) $supplier['sest_telefon']));
        // Pozn.: sest_email a sest_funkce NEJSOU v EPO XSD (DPH/KH/SHV) — držíme je
        // jen v DB pro vnitřní použití (kontakt na účetní v UI).
    }

    /**
     * Normalizace telefonu pro EPO `c_telef` / `sest_telef`:
     *   - odstraní `+420` / `00420` prefix
     *   - odstraní mezery, pomlčky, závorky
     * Reálné EPO podání uvádí jen 9-místné číslo (např. "722944990"); naše DB
     * může mít formát "+420 722 944 990".
     */
    public static function normalizePhone(string $raw): string
    {
        $s = trim($raw);
        $s = preg_replace('/^(\+|00)420\s*/', '', $s) ?? $s;
        $s = preg_replace('/[\s\-()]+/', '', $s) ?? $s;
        return $s;
    }

    /**
     * Normalizace CZ-NACE / OKEČ hodnoty pro `c_okec`. Hodnoty z UI mohou být
     * "62.02", "62020", "620200" apod. → strip non-digit znaků, ořež na max 6.
     *
     * NEPADUJEME zprava nulami: CZ-NACE číselník EPO je proměnné šířky — většina
     * položek je 5-místná (`62020`), jen pár odvětví má 6-místné podtřídy
     * (`010111`). XSD `totalDigits=6` je MAXIMUM, ne fixní délka; skutečný výčet
     * hodnot žije v externím číselníku MFČR
     * (https://adisspr.mfcr.cz/pmd/dokumentace/ciselniky/). Pravostranné doplnění
     * 5-místného kódu nulou ("62020" → "620200") vyrobí hodnotu mimo číselník
     * a EPO podání odmítne ("hodnota musí být z číselníku"). Validitu proti
     * číselníku zde NEKONTROLUJEME — uživatel zná svou klasifikaci.
     */
    public static function normalizeOkec(string $raw): ?string
    {
        $digits = preg_replace('/\D/', '', $raw) ?? '';
        if ($digits === '') return null;
        if (strlen($digits) > 6) $digits = substr($digits, 0, 6);
        return $digits;
    }
}
