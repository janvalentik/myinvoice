# 14 — Identifikované osoby (issue #94)

> Stav: plán 2026-06-05, implementace v téže větvi.
> Požadavek: github.com/radekhulan/myinvoice/issues/94 (@mikolashodan) — režim pro
> identifikovanou osobu (IO) bez přepínání plátce/neplátce + bug se stale supplier
> stavem ve frontendu (změna plátcovství se projeví až po hard refreshi).

## Právní rámec (§ 6g–6l ZDPH)

IO **je v tuzemsku neplátec** (faktury bez DPH, žádný odpočet), ale má DIČ a
povinnosti výhradně z přeshraničních plnění:

| Oblast | IO |
|---|---|
| Tuzemské faktury | bez DPH (jako neplátce) |
| Služby do EU (§ 9/1) | bez DPH, daňový doklad s DIČ + „daň odvede zákazník" (čl. 196 směrnice 2006/112/ES); podává **SHV** (kód 3) |
| Přijaté služby z EU/3. zemí, pořízení zboží z JČS | samovyměření 21/12 % **BEZ nároku na odpočet** (daň platí reálně) |
| DPH přiznání | jen za měsíce se vznikem povinnosti, `typ_platce='I'`, jen ř. 3–13 + rekapitulace; **žádná Veta2/3/4** (ř. 20–26 ani odpočty ř. 40+) |
| Kontrolní hlášení | nepodává nikdy |
| Dovoz zboží ze 3. země (ř. 7/8) | neřeší v přiznání (DPH vybírá celní úřad) — řádky pro IO filtrovat |

## Návrh: `is_identified` jako doplněk k `is_vat_payer=0`

Žádný třetí stav. IO uživatel se nastaví jako **neplátce** (všech ~84 BE + ~124 FE
míst beze změny — tuzemsko, editor, KH gating) a `supplier.is_identified` (0/1)
jen **přidává** přeshraniční chování. `is_vat_payer=1 && is_identified=1` je
nevalidní kombinace (422).

### Zmapovaná fakta z kódu (2026-06-05)

- `MeAction` → `SupplierBrief` (FE supplier store) — **zdroj refresh bugu**:
  po `PUT /settings/supplier` se `availableSuppliers` neobnoví, editor čte stale
  `currentSupplier.is_vat_payer`.
- `invoice.twig`: neplátce větve dle `supplier.is_vat_payer`; meta řádek „Není
  plátce DPH" (ř. 103); RC klauzule existuje (`invoice.reverse_charge`, ř. 220),
  ale cituje § 92a (tuzemský RC) i pro zahraničí; DIČ dodavatele se tiskne, je-li
  vyplněné. `SnapshotBuilder::supplierSnapshot` nese `is_vat_payer` → doplnit
  `is_identified` (issued faktury renderují ze snapshotu).
- Editor vystavené faktury: `showReverseChargeUI = supplierIsVatPayer` (skryto
  neplátci), `loadClient` RC default force-false pro neplátce; **klasifikace
  (`vat_classification_code`) je viditelná všem** — cesta pro kód 22 je otevřená.
- `SouhrnneHlaseniBuilder` — IO **explicitně podporuje** (docblock validateSupplier),
  bere ledger rows s kódem 20/22/31; pro IO dává smysl jen 22 (služby § 9/1).
- `DphPriznaniBuilder` — `typ_platce` hardcoded `'P'` (komentář zná `I`);
  ř. 43 mirror vzniká z `dphdp3_line_secondary` ve `VatClassificationMapper::projectDphLines`.
- `KontrolniHlaseniBuilder` — pro IO jen doplnit warning (IO KH nepodává).
- Purchase editor: `is_vat_payer` reference jsou o VENDOROVI, supplier negating —
  IO může zahraniční RC doklad (klasifikace 23/24/25) evidovat už dnes.
- FE reports stránky nejsou plátcovstvím blokované (settings jen pro period picker).
- DPH menu v AppLayout není gated — IO uvidí Daně → vše; KH mu vrátí warning.

## Implementační kroky

**A. Refresh bug (nezávislý fix)**
1. supplier store: `patchSupplier(id, partial)`.
2. `Settings.vue` po `saveSupplier`/`saveBranding` propíše brief pole
   (`is_vat_payer`, `is_identified`, `taxpayer_type`, `default_payment_due_*`,
   `default_prices_include_vat`, `auto_send_reminders`, `payment_thanks_*`).

**B. Základ `is_identified`**
3. Migrace `0103_supplier_is_identified.sql` — TINYINT(1) NOT NULL DEFAULT 0
   AFTER is_vat_payer.
4. `SettingsAction`: allowed + bool cast + respond; validace exclusivity (výsledný
   stav obou flagů, 422); `createSupplier` — heuristiku „má DIČ → plátce" potlačit
   při `is_identified`.
5. `MeAction` → brief; `SnapshotBuilder` → supplier snapshot; OpenAPI `SupplierFull`.
6. FE: typy (`auth.ts`, `settings.ts`), checkbox v Nastavení (viditelný jen u
   neplátce) + hint, i18n cs/en.

**C. Vystavené faktury**
7. `invoice.twig`:
   - meta „Není plátce DPH" potlačit, když `supplier.is_identified && invoice.reverse_charge`
     (na RC dokladu mate — klauzule + DIČ ho nahrazují); tuzemské faktury IO řádek mají.
   - RC klauzule dle země odběratele: CZ → § 92a (stávající), jinak
     „…čl. 196 směrnice 2006/112/ES" (zpřesní i plátcovské EU faktury).
8. Editor: `showReverseChargeUI = payer || identified`; `loadClient` force-false
   jen pro čistého neplátce; **IO + zahraniční klient s DIČ → auto
   `reverse_charge=true` + default klasifikace `'22'`** (uživatel může změnit) + hint.

**D. Výkazy**
9. `DphPriznaniBuilder`: IO → `typ_platce='I'`, whitelist řádků
   `3,4,5,6,12,13` (ostatní vč. mirror ř. 43, ř. 1/2, Veta2/3 zahodit
   s warningem per řádek), IO-specifické info místo „není plátce" warningu,
   `summary['typ_platce']`.
10. `KontrolniHlaseniBuilder`: warning „IO kontrolní hlášení nepodává".
11. SHV: beze změny (funguje); DIČ warning už řeší.

**E. Testy + dokumentace**
12. Testy: DphPriznaniBuilder IO režim (typ_platce I, bez Veta4, whitelist),
    SettingsAction exclusivity.
13. Manuál (§ 24 výkazy + § 18 nastavení dodavatele), CHANGELOG, OpenAPI.
14. `npm run build`, phpunit.

## Vědomě odloženo (fáze 2)

- Promítnutí samovyměřené DPH bez odpočtu jako daňového nákladu do
  `IncomeTaxBuilder`/optimalizátoru (dnes si uživatel zaeviduje daň ručně).
- Hlídání registračních limitů (326k pořízení zboží) / upozornění „stáváte se IO".
- ř. 9 (nový dopravní prostředek § 19) — mapper kód neexistuje ani pro plátce.
- CRM dismiss/restore pro accountant (nesouvisí, latentní nález z 2026-06-05).
