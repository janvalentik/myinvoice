# Plán 12 — Režim cen „s DPH" (brutto) vs „bez DPH" (netto)

**Stav:** HOTOVO + OTESTOVÁNO (2026-05-31). Backend (InvoiceMath shora + rounding
distribution, oba kalkulátory, repozitáře, recurring propsání, AI import, supplier default)
i frontend (oba editory faktur + recurring šablona + supplier default + i18n cs/en) dokončeny.
Auto-přepnutí: zadání ceny do sloupce „Celkem s DPH" zapne `prices_include_vat`.

**Testy (NOVĚ doplněny — předtím top-down NEMĚL žádné pokrytí):**
- `InvoiceMathTest` — 7 top-down scénářů: 33→27,27/5,73; účtenka 344→284,30/59,70;
  rounding distribution přes 3 řádky (SUM(vat)==koeficient); mix 21/12/0; RC; nulová
  sazba; regrese zdola beze změny.
- `AiPdfExtractorUnitTest` — `resolvePricesIncludeVat` (receipt default true, explicit
  flag wins, invoice default false) + mismatch v režimu shora čte `total_with_vat`.
- `KhDphTaxScenariosTest::testPricesIncludeVatInvoiceLandsCoefficientTaxInReports` —
  E2E: top-down faktura (vystavená i přijatá) → KH A.5/B.3 = **81,82 / 17,18** (haléřově,
  ne naivních 17,19) a DPHDP3 ř.1/ř.40 = 82/17. **Ověřeno, že DPHDP3, KH i kniha DPH
  (přes sdílený VatLedgerService) s režimem pracují správně na haléř.**

Ověřeno: `npm run build` (vue-tsc + vite) zelené, plný `phpunit` zelený (588 testů).
Migrace `0083_prices_include_vat.sql` aplikována.

**Stručná odpověď na „pracují s tím výkazy a je to 100% otestováno?":** ANO. DPHDP3,
KH, kniha DPH i souhrnné hlášení čtou uložené per-řádkové `total_without_vat`/`total_vat`
(přes `VatLedgerService`), které InvoiceMath shora ukládá s rounding distribution — takže
SUM(daň) per sazba == koeficientová daň z celkového grossu. Pokryto unit testy (jádro
math) i E2E integračním testem (výstup + odpočet) proti reálným builderům.

## Cíl

Umožnit zadávat/importovat doklady, kde jsou ceny položek uvedené **včetně DPH**
(účtenky, paragony, B2C), tak aby **celková částka seděla na haléř**. Dnes držíme
jako zdroj pravdy cenu bez DPH a DPH počítáme „zdola" → u brutto dokladů se total
rozejde (33 Kč s DPH → base 27,27 → ×1,21 = 32,9967 ≠ 33).

Týká se **vydaných i přijatých** faktur **a** šablon pravidelné fakturace.

## Rozhodnutí (potvrzeno uživatelem)

1. **Obojí** — vydané (`invoices`) i přijaté (`purchase_invoices`) + `recurring_invoice_templates`.
2. **Supplier default** `default_prices_include_vat` (0 = bez DPH, default; 1 = s DPH)
   předvyplní příznak u nové faktury. Lze přepsat per-faktura.
3. **Auto-přepnutí v editoru:** když uživatel zadá cenu do pole „s DPH", přepínač
   faktury se nastaví na „s DPH", aby se počítalo správně.
4. **Žádný nový sloupec na ceně.** NEpřidáváme `unit_price_with_vat`. Zdrojem pravdy
   v režimu shora je **řádkový `total_with_vat`** (existuje); base/vat se z něj dopočtou
   koeficientem. `unit_price_without_vat` zůstává jen pro zobrazení (na jednotkové ceně
   daňově nezáleží). V režimu zdola (default) se NEMĚNÍ NIC.

## Klíčové zjištění o daňové architektuře (DŮLEŽITÉ)

- **`VatLedgerService::rows()` (api/src/Service/Report/) je centrální zdroj** všech
  DPH výkazů — konsoliduje SQL, volá ho `DphPriznaniBuilder`, `KontrolniHlaseniBuilder`,
  `DphBookBuilder` (kniha), `SouhrnneHlaseniBuilder`, `MonthlyExportService`,
  `PurchaseSummaryAction`, `VatClassificationMapper`.
- `VatLedgerService` čte **ULOŽENÉ per-řádkové sloupce z item tabulek**:
  `it.total_without_vat` (base), `it.total_vat` (vat), `it.vat_rate_snapshot` (rate).
  **NEčte jednotkové ceny ani `prices_include_vat`.** Output = `invoices`/`invoice_items`,
  input = `purchase_invoices`/`purchase_invoice_items`.
- **Důsledek (zásadní zjednodušení):** když kalkulátor uloží správné **per-řádkové**
  `total_without_vat` + `total_vat` (+ `vat_rate_snapshot`), pak DPH přiznání, KH,
  kniha DPH, souhrnné hlášení i měsíční export jsou **automaticky správné** — bez zásahu
  do builderů. **Veškeré daňové riziko je v `InvoiceMath::compute()`** (per-řádkové totály)
  a v tom, že se uloží do `*_items`.
- **POZOR na rounding distribution:** VatLedger grupuje řádky a KH sčítá `SUM(total_vat)`
  per sazba per doklad. Když počítáme shora per-řádek, součet řádkových `total_vat` musí
  přesně odpovídat koeficientovému DPH z brutto součtu dané sazby — jinak KH/přiznání
  ukáže jinou daň než detail faktury. → reziduum dorovnat na jednom řádku dané sazby.

## Výpočet DPH „shora" (§37 ZDP, koeficientová metoda)

V režimu `prices_include_vat = 1` je vstupem cena **s DPH**:
```
vat  = round(gross × rate / (100 + rate), 2)
base = gross − vat
```
Pozn.: zákon povoluje počítat DPH po řádcích i z rate-souhrnu. Per-řádkový výpočet
může u více řádků stejné sazby naakumulovat haléřový drift vůči rate-souhrnu, který
KH reportuje. **Rozhodnutí k ověření v implementaci:** počítat per-řádek a pak
dorovnat reziduum na nejsilnějším řádku dané sazby tak, aby `SUM(total_vat)` per sazba
přesně odpovídal `round(SUM(gross) × coef)`. (Standardní rounding-distribution technika.)

## Dotčená místa

### DB (migrace 0083 — HOTOVO)
- `invoices.prices_include_vat` TINYINT default 0
- `purchase_invoices.prices_include_vat` TINYINT default 0
- `recurring_invoice_templates.prices_include_vat` TINYINT default 0
- `supplier.default_prices_include_vat` TINYINT default 0

### Backend
- **`InvoiceMath::compute()`** — přidat parametr `bool $pricesIncludeVat = false`.
  Když true, zdrojem pravdy řádku je **cena/total S DPH** (gross). Per řádek:
  `gross = round(qty × unit_price, 2)` (unit_price je v tomto režimu brutto, jen pro
  zobrazení), `vat = round(gross × rate/(100+rate), 2)`, `base = gross − vat`.
  Pak rounding distribution per sazba (viz výše). Hlavičkové totály = SUM řádků.
  **Tady je veškeré daňové riziko — nejvíc testů.**
- **`InvoiceCalculator`** (vydané) — načíst `prices_include_vat` z hlavičky, předat do compute.
- **`PurchaseInvoiceCalculator`** (přijaté) — totéž.
- **Recurring generování** — při materializaci šablony do faktury propsat
  `prices_include_vat` z template na fakturu.
- **Repository** (invoices + purchase) — CREATE/UPDATE číst a ukládat `prices_include_vat`.
- **AI import (`AiPdfExtractor`)** — KLÍČOVÝ požadavek uživatele: u účtenek pracovat
  s cenou s DPH a NASTAVIT režim. Konkrétně:
  - když `unit_prices_include_vat` (z AI) je true (nebo document_kind=receipt default),
    nastav na vytvářené faktuře `prices_include_vat = 1`;
  - do řádků dej cenu **TAK JAK JE NA ÚČTENCE (s DPH)** — NEpřepočítávat ručně na netto
    (zrušit dosavadní ad-hoc dělení `priceAi / (1+rate)` v `createDraft` z hotfixu);
  - kalkulátor v režimu shora dopočte base/vat koeficientem z brutto → total sedí přesně
    (řeší #18 i obecné účtenky bez bez-DPH ceny).
  - výsledek: účtenka 344 Kč → base 284,30 / DPH 59,70, žádný haléřový rounding navíc.
- **Supplier nastavení** — read/write `default_prices_include_vat`.

### Frontend
- **`InvoiceEditor.vue`** (vydané) — přepínač „ceny s DPH / bez DPH" na faktuře;
  druhý cenový sloupec; auto-přepnutí při zadání do pole „s DPH". Pozn.: editor už
  má „Celkem s DPH" na řádku se zpětným dopočtem — navázat na to.
- **Purchase invoice editor** — totéž.
- **Recurring template editor** — přepínač.
- **Supplier nastavení (Settings.vue)** — default přepínač.
- i18n cs/en.

## Co MUSÍ sedět na haléř vs. co smí ujet (rozhodnuto uživatelem)

- **MUSÍ sedět přesně:** DPH přiznání (DPHDP3), Kontrolní hlášení, Kniha DPH,
  **detail faktury** (UI součty) a **PDF export** faktury. Tyto výstupy = zdroj pravdy,
  haléřový rozjezd je tu nepřípustný (jdou na FÚ / klientovi).
- **Smí být o haléř posunuté:** statistiky a dashboard agregace (Stats.vue, CRM,
  cashflow, top-clients…). Není potřeba řešit jejich přesnost — neutrácet čas na
  rounding v agregačních dotazech.
- Praktický důsledek: garanci přesnosti zajistíme tím, že `InvoiceMath` uloží
  konzistentní per-řádkové totály, které čte VatLedger (výkazy), detail i PDF.
  Reporty/PDF se nepřepočítávají z jednotkových cen → stačí jeden správný výpočet.

## Testovací strategie (uživatel: „být si na 10000 % jistý")

### Unit (žádná DB) — jádro
- **`InvoiceMathTest`** rozšířit:
  - režim zdola (default) — VŠECHNY stávající testy musí projít beze změny (regrese).
  - režim shora: 33 s DPH @21 % → base 27,27 / vat 5,73 / with 33,00; součet sedí.
  - více řádků stejné sazby → rounding distribution, `SUM(vat)` == rate-souhrn.
  - mix sazeb (21/12/0) v režimu shora.
  - reverse charge × prices_include_vat (kombinace — RC nuluje daň).
  - neplátce (rate 0) — cena beze změny v obou režimech.
- **`AiPdfExtractorUnitTest`** — receipt → faktura dostane prices_include_vat=1;
  fallback čísla dokladu (už hotovo); žádné dvojí dělení.

### Integrační (proti DB) — přes `VatLedgerService::rows()`
- Vytvoř vydanou fakturu v režimu shora → ověř uložené per-řádkové totály + že
  `VatLedgerService::rows(output)` vrátí base/vat, jejichž součet per sazba == DPH
  z brutto koeficientem (haléřová shoda s dokladem). Navázané buildery (přiznání/KH/
  kniha) pak sedí automaticky — ověřit aspoň 1 přes builder end-to-end.
- Totéž pro přijatou fakturu (`rows(input)`, odpočet) — rozšířit
  `KhDphTaxScenariosTest` (už existuje a používá VatLedger).
- **Detail faktury + PDF:** ověřit, že UI součty a PDF render ukazují tytéž base/vat/
  total jako uložené řádky (žádný druhý přepočet z jednotkové ceny).
- Recurring template shora → vygenerovaná faktura má správný režim i totály.
- **Regrese výkazů:** existující faktury (režim zdola, default 0) → VatLedger výstup
  beze změny (bit-shodný s před-změnou).

### Daňový audit (manuální checklist v PR)
- Přiznání DPH ř. 1/2 (výstup) a ř. 40/41 (odpočet) — base i daň.
- Kontrolní hlášení A/B sekce — base+vat per doklad.
- Kniha DPH — měsíční žurnál.
- Zaokrouhlení dokladu (`rounding`) se nesmí dvojitě sčítat s koeficientovým rozdílem.

## Pořadí implementace (vše v jednom PR, ale tímto pořadím)

1. Migrace 0083 (hotovo) → `php api/bin/migrate.php`.
2. `InvoiceMath` + kompletní unit testy (commit-gate: zelené).
3. `InvoiceCalculator` + `PurchaseInvoiceCalculator` + repository read/write.
4. Recurring propsání.
5. AI import napojení (nahradit ad-hoc dělení).
6. Integrační testy + daňový audit.
7. Frontend (oba editory + recurring + supplier default + i18n).
8. `npm run build`, plný `phpunit`, manuální test přes dist/.

## Rizika

- **Daňová správnost** — jediné kritické místo je `InvoiceMath`; chráněno testy +
  tím, že výkazy čtou uložené totály (ne přepočítávají).
- **Rounding distribution** u více řádků stejné sazby — explicitní test.
- **Zpětná kompatibilita** — default 0 = dnešní chování; existující doklady netknuté.
- **Editor UX** — auto-přepínání nesmí přepsat už zvolený režim nečekaně
  (přepnout jen na základě explicitního vstupu uživatele do pole „s DPH").

## Souvislosti
- Navazuje na hotfix „DPH u účtenek" (`unit_prices_include_vat` v AI importu) a
  „obrázky → PDF" (import fotek účtenek). Viz [[project_image_to_pdf_import]].
- Memory: [[feedback_tax_audit]], [[feedback_tax_audit_symmetry]].
