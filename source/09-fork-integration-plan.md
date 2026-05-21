# Plán integrace forku `milhaus123/myinvoiceDph` — fáze 1–5

> **Datum:** 2026-05-20
> **Zdrojový fork:** https://github.com/milhaus123/myinvoiceDph (autor Martin Říha)
> **Cíl:** přidat přijaté faktury, importy, párování plateb, opakované přijaté faktury a EPO výkazy (DPH/KH/DPFO/DPPO) — bez skladu, EET a cash registru
> **Status:** plán schválen, čeká na start fáze 1

---

## Strategický rámec

**Princip:** 5 phased PRs, jeden per oblast, každý uzavřený samostatně (migrace + backend + UI + i18n + manuál + openapi + tag). Žádný long-lived branch.

**Branch model:**
- `feature/purchase-invoices`
- `feature/import-idoklad-fakturoid`
- `feature/bank-matching`
- `feature/recurring-purchase`
- `feature/dph-reports`

Každý se mergne do `master` před začátkem dalšího.

**Vztah k forku:** fork je **referenční zdroj, ne cherry-pick zdroj**. Hodně commitů ve forku jsou AI-generated fix-after-fix sekvence (mnoho commitů typu „Fix INSERT placeholders count mismatch"). Pro každou oblast přepíšeme čistě podle naší konvence; fork použijeme jako **specifikaci a inspiraci pro schema/API tvary**.

**Verzování:** každá fáze = minor bump (v3.5.0 → v3.6.0 → … → v3.9.0). Po fázi 5 = **v4.0.0** (s release notes „MyInvoice 4.0: kompletní DPH evidence").

**Migrace renumbering:** fork použil čísla 0020–0039, my máme 0020–0025. Naše nové migrace začnou od **0026** podle naší sekvence (fork ignoruje).

**Globální naming fix:** ve forku `purchase_invoices.supplier_id` = vendor (protistrana). To **přejmenujeme na `vendor_id`** (FK do `clients`). Náš `supplier_id` zůstává = tenant (naše firma). Bez tohoto přejmenování si každý budoucí čtenář vyláme zuby.

---

## Cross-cutting prvky (řeší se průběžně všemi fázemi)

### 1. Levé menu (sidebar) — refactor `AppLayout.vue` (fáze 1)
Současný layout: horizontální topbar + admin dropdown. Po fázi 1 přejdeme na **left sidebar** dle vzoru forku, ale **ořezaný** o naše out-of-scope items.

**Cílová struktura sekcí:**
- (bez sekce): Dashboard
- **Prodej**: Faktury, Proformy, Dobropisy, Pravidelné fakturace
- **Nákup** *(přidá fáze 1, 4)*: Přijaté faktury, Opakované přijaté faktury
- **Finance** *(rozšíří fáze 3)*: Banka, Statistiky
- **Klienti**: Klienti, Zakázky
- **Výkazy** *(přidá fáze 5)*: DPH přiznání, Kontrolní hlášení, Daň z příjmů
- **Systém** (admin): Nastavení, Šablony e-mailů, Importy, Exporty, Uživatelé, API tokeny, Cron, Aktualizace, Aktivita

**Co nepřebíráme z forku:** `/quotes`, `/receipts`, `/items` (sklady), `/cash` — out of scope.

**Implementace:** přepis `web/src/components/layout/AppLayout.vue` — sidebar 14–16rem, sekční nadpisy, mobile drawer. **Ikony v našem stylu, ne z forku** — držet se konvence existujícího codebase (Heroicons-style outline, stroke 2, viewbox 24, monochromatický `currentColor`). Topbar zachovat pro logo + jméno + jazyk + odhlásit + supplier banner. Konkrétní vzhled (šířka, paleta, font weights) na implementátorovi — držet konzistenci se stávajícím designem.

### 2. i18n strategie
- **Všechny nové klíče** v `web/src/i18n/cs.json` + `en.json` od první commit (per MEMORY rule).
- Klíčové prefixy: `purchase_invoice.*`, `recurring_purchase.*`, `import_idoklad.*`, `import_fakturoid.*`, `bank_matching.*`, `report.dph.*`, `report.kh.*`, `report.dpfo.*`, `report.dppo.*`, `nav.section_*`.
- Pro pole používat `tm()` + `rt()` (per `feedback_i18n_arrays.md`).

### 3. Manuál (`manual/`)
Stávající strukturu 01–21 + 99 zachovám, vsuvky pojmenuji s podpísmenkem (per MEMORY `project_manual_renumber.md` — full renumber je odložen). Nové soubory:
- `10_Prijate_faktury.md` (fáze 1; přečíslováno z `09a_` v 4.0.0)
- `14a_Opakovane_prijate_faktury.md` (fáze 4)
- `16a_Import_iDoklad_Fakturoid.md` (fáze 2)
- `12a_Parovani_plateb.md` (fáze 3)
- `22_Vykazy_DPH.md` (fáze 5) + případně `23_Dan_z_prijmu.md`

Po fázi 5 plný renumber 01–24 v samostatném PR (per `project_manual_renumber.md` to-do).

`INDEX.md` a `manual/index.php` aktualizovat v každé fázi. `manual.pdf` regenerovat až ve finále v4.0.

**Landing site sync:** `rebuild-manual.ps1` v `c:\work\myinvoice.web` per `project_landing_site.md` — po fázi 5 manuálně zrcadlit.

### 4. OpenAPI (`api/openapi.yaml`)
Současně ~50 paths, 41 schemas (per memory). Každá fáze dodá:
- Fáze 1: ~10 paths (`/purchase-invoices/*`), 4 schémata
- Fáze 2: ~6 paths (`/admin/imports/idoklad/*`, `/admin/imports/fakturoid/*`), 3 schémata
- Fáze 3: ~4 paths (`/bank-transactions/*/match`), 2 schémata
- Fáze 4: ~6 paths (`/recurring-purchase-invoices/*`), 2 schémata
- Fáze 5: ~6 paths (`/reports/dphdp3`, `/reports/kontrolni-hlaseni`, `/reports/dpfo`, `/reports/dppo`), 4 schémata

Cílový stav v4.0: ~80 paths, ~55 schemas. Swagger UI + Redoc se generují automaticky.

### 5. Testy
Per existující pattern (`api/tests/*`): PHPUnit pro každý nový Action + Repository + Service. Cíl: každá fáze nesmí snížit coverage. **Žádné mocky DB** (per `feedback_migrations.md`).

**Test coverage požadavek per Action:**
- ✅ Happy path (správný request → správný response)
- ✅ Auth missing → 401
- ✅ Wrong supplier scope → 403/404 (klíčové pro tenant isolation)
- ✅ Validation errors → 422 s polem-specifickými klíči
- ✅ Edge cases (prázdný input, max length, zákazaný stav transition)
- ✅ SQL injection attempt v textových polích (prepared statements ověření)
- ✅ XSS payload v textových polích (uložit/načíst, ověřit že není executable)

### 5a. Security hardening — od první commit fáze
Žádné „bezpečnost dodáme později". Každé Action v každé fázi má:

**Authentication & authorization:**
- `AuthMiddleware` na všech `/api/*` kromě veřejných (approval token, public version)
- `SupplierScopeMiddleware` na všech endpointech operujících nad tenant daty
- Role-based check uvnitř Action (`$user['role'] === 'admin'` nebo `'accountant'`) kde relevantní
- Žádné implicitní povolení — explicit allow-list

**Input validation:**
- Vstupy přes typed DTO třídy s validation (existující pattern v `api/src/Http/`)
- `filter_var(FILTER_VALIDATE_*)` na všech URL/email polích
- Numeric bounds: částky max 999_999_999.99, qty max 99_999, INT max 2^31
- Date format strict (`DateTimeImmutable::createFromFormat('Y-m-d')`), reject invalid
- Whitelist enum values, never trust client

**SQL injection prevention:**
- **Zákaz** string concatenation v SQL (`"WHERE x = $value"`)
- Vždy prepared statements s `:named` placeholders (nebo `?`)
- Identifier whitelist pro dynamic columns (např. `ORDER BY` sloupec)
- Code review zaměřený na každý nový raw SQL

**XSS / output safety:**
- Frontend: Vue 3 default escape, **nikdy** `v-html` na user content (jen na sanitized markdown)
- mPDF / Twig: `{{ }}` (auto-escape), nikdy `{{ raw }}`
- API responses: JSON-only, `Content-Type: application/json; charset=utf-8`

**CSRF:**
- Existující session cookie `SameSite=Lax`, PAT bearer pro `/api/v1/*`
- POST/PUT/DELETE vyžadují Origin/Referer kontrolu pro session-based requests (existující middleware)

**File uploads (kritické pro AI PDF import + ISDOC import + bank statements):**
- MIME sniffing přes `finfo_file` (ne trust Content-Type)
- Magic bytes whitelist: `%PDF-` pro PDF, `<?xml` pro XML, `PK\x03\x04` pro ZIP
- Max file size enforced per endpoint (20 MiB PDF, 5 MiB XML, 50 MiB total upload)
- Generated filename (UUID + verified extension), **nikdy** uživatelův
- Storage mimo webroot (`/var/uploads/`) s `X-Accel-Redirect` nebo PHP streaming
- Anti zip-bomb (existující `MAX_DECOMPRESSED_BYTES = 10 MiB` v `PdfIsdocExtractor`)

**Secrets handling:**
- API klíče (Anthropic, iDoklad, Fakturoid) **šifrované at-rest** v `supplier_settings.*_enc` přes `app.pepper`
- Klíče se **nikdy nelogují** (Monolog processor maskuje `*_api_key*`, `*_secret*`, `*_token*` na `***REDACTED***`)
- Žádné klíče v `.env` commitovaném do git (per existující `.env.example` pattern)

**Rate limiting:**
- Existující per-endpoint limity zachovat, rozšířit na nové endpointy
- AI extrakce: max 10 PDF / hodinu / supplier (cost cap + brute force protection)
- Import jobs: max 1 běžící job per supplier per source

**Audit logging:**
- Existující `activity_log` rozšířit o nové akce (`purchase_invoice.created`, `purchase_invoice.transitioned`, `ai_extraction.used`, `import_job.started`, …)
- Log obsahuje: user_id, supplier_id, ip, ua, action, target_id, changes_diff JSON

**Security test patterns** (povinné pro každou novou Action):
```php
public function testRequiresAuth(): void { /* 401 bez tokenu */ }
public function testCrossTenantAccessForbidden(): void { /* user A nečte data B */ }
public function testSqlInjectionInTextField(): void { /* ' OR 1=1-- v poli notes */ }
public function testXssInTextField(): void { /* <script> uloženo escapnuté */ }
public function testOversizedInputRejected(): void { /* 10 MiB v notes → 422 */ }
```

### 6. Multi-supplier scope
**Klíčové ověření v každé fázi:** všechna nová data jsou izolovaná per `supplier_id` (= tenant), nikdy globální. Fork to v posledních commitech opravoval (`fix: filter purchase_invoices by client's supplier_id (tenant)`); my to uděláme správně od začátku.

Postup: každá nová tabulka má `supplier_id BIGINT UNSIGNED NOT NULL`, FK do `suppliers(id)`, plus index `(supplier_id, …)`. Middleware `SupplierScopeMiddleware` filtrovat všechny dotazy.

### 7. Coding agent skill
Fork má `PURCHASE-INVOICE-ANALYSIS.md` jako spec doc. **Nepřebíráme** — jejich design analýza je informativní, ne autoritativní pro naši implementaci.

### 8. Klienti vs. dodavatelé — oddělení rolí
**Problém:** v fázi 1 ukládáme dodavatele přijatých faktur do tabulky `clients`. Bez oddělení by se v `/clients` seznamu mísili odběratelé (komu fakturujeme) s dodavateli (od koho nakupujeme) — UI nepřehledné.

**Rozhodnutí:** zůstat u **jedné tabulky `clients`** (existující), ale přidat **role flagy**:

```sql
ALTER TABLE clients
  ADD COLUMN IF NOT EXISTS is_customer TINYINT(1) NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS is_vendor   TINYINT(1) NOT NULL DEFAULT 0;
```

**Důvody pro jednu tabulku:**
- Některé firmy jsou **současně** zákazník i dodavatel (např. partnerská IT firma, kterou fakturuju za development a od ní kupuju hosting). Jedna entita = jedna `clients` row = jedna ARES synchronizace, jedna VIES validace, jedna adresa, jedna historie.
- Snapshot logika (`client_snapshot` na vydaných, `vendor_snapshot` na přijatých) zůstává nezávislá — historický doklad neovlivní změna v `clients`.
- Zachová `idoklad_id` / `fakturoid_id` deduplication beze změny.

**UI dopad:**
- `/clients` — defaultně filtr `is_customer = 1` ("Zákazníci"). Toggle „Zobrazit i dodavatele" pro full view.
- `/vendors` — alias route na `/clients?role=vendor`, filtr `is_vendor = 1`. Stejná komponenta, jiný preset.
- Při vytvoření klienta z **vydané faktury** → auto-set `is_customer=1`.
- Při vytvoření dodavatele z **přijaté faktury** (manuálně nebo z PDF/AI/ISDOC import) → auto-set `is_vendor=1`.
- Pokud ARES IČ match existující row → nastav i druhou roli (true OR existing) — neztrácet první roli.

**Migrace** (součást fáze 1, migrace `0026_purchase_invoices.sql` + `0026a_clients_roles.sql`):
- Backfill: všechny existující řádky dostanou `is_customer=1, is_vendor=0` (zachová stávající chování `/clients`).
- Index `(supplier_id, is_customer)`, `(supplier_id, is_vendor)` pro filtraci.

**Levé menu:** v sekci „Klienti" zobrazit obě položky: **Klienti** (zákazníci) + **Dodavatelé** (vendoři). Symetrické s „Prodej" / „Nákup" v horních sekcích.

### 9. Multi-currency u přijatých faktur (USD invoice → CZK/EUR účet)

**Problém:** přijatá faktura může být v USD ($1000), uživatel ji platí z **CZK účtu** přes bankovní konverzi (24,500 CZK + ~2% kurzová ztráta). Nebo z **EUR účtu** ($1000 → ~€915 + EUR/USD spread). Stávající `purchase_invoices` schema má `currency_id` + `exchange_rate` (USD → CZK at issue) — ne dost.

**Doplnit do `0026_purchase_invoices.sql`:**

```sql
purchase_invoices (rozšíření):
├── currency_id          INT UNSIGNED NOT NULL          -- měna FAKTURY (USD)
├── exchange_rate        DECIMAL(12,6) NULL             -- USD → tenant base (CZK) at issue/tax_date
├── exchange_rate_date   DATE NULL                      -- datum kurzu (typicky tax_date)
├── exchange_rate_source ENUM('cnb','manual','idoklad','fakturoid') DEFAULT 'cnb'
│
├── -- Platba (může být v jiné měně než faktura):
├── payment_currency_id  INT UNSIGNED NULL              -- měna ÚČTU plátce (CZK / EUR)
├── payment_exchange_rate DECIMAL(12,6) NULL            -- payment_currency → invoice currency at payment
├── paid_amount_payment_ccy DECIMAL(14,4) NULL          -- co reálně odešlo z účtu (24500.00 CZK)
├── paid_amount_invoice_ccy DECIMAL(14,4) NULL          -- ekvivalent v měně faktury ($1000.00)
├── exchange_diff_base   DECIMAL(12,2) NULL             -- kurzový rozdíl v base ccy (CZK): -150 = ztráta
```

**Bank matching (fáze 3) musí:**
- Pokud `bank_tx.currency == invoice.currency` → trivial match, použít částky 1:1.
- Pokud `bank_tx.currency != invoice.currency` → uživatel zadá `payment_exchange_rate` ručně nebo se vypočítá z `bank_tx.amount / invoice.amount_to_pay`. Rozdíl proti `invoice.exchange_rate × invoice.amount_to_pay` = `exchange_diff_base` (kurzová ztráta/zisk).
- Frontend match modal ukáže oba čísla + kurz + kurzový rozdíl, uživatel potvrdí.

**Currency convertor service:** `Service/Currency/ExchangeRateService.php` — fetch denních kurzů ČNB (cached daily) na URL `https://www.cnb.cz/cs/financni-trhy/devizovy-trh/kurzy-devizoveho-trhu/kurzy-devizoveho-trhu/denni_kurz.txt?date=DD.MM.YYYY`. Default source `cnb` při vytvoření faktury, override `manual`.

**CRM dopad (fáze 5):** reporting v base currency (CZK) — všechny částky převedené přes `exchange_rate`. Multi-currency uživatelé mají `CurrencyPicker` pro per-currency pohled.

**Edge case — partial payment v jiné měně:** rozšířit `purchase_invoice_payments` table (NOVÁ v fázi 3 — payments mohou být víc):
```sql
CREATE TABLE purchase_invoice_payments (
  id, purchase_invoice_id, bank_transaction_id NULL,
  paid_at DATE, amount_payment_ccy, payment_currency_id,
  amount_invoice_ccy, payment_exchange_rate,
  exchange_diff_base, created_at
);
```
Sum `amount_invoice_ccy` per invoice → updates `advance_paid_amount` → `amount_to_pay` STORED generated column se přepočítá automaticky.

---

## Fáze 1 — Přijaté faktury (purchase invoices)

**Cíl:** plné CRUD přijatých faktur, izolace per tenant, levé menu redesign.
**Verze:** v3.5.0.
**Odhad:** 2–3 dny práce (1 osoba).

### 1.1 Migrace `0026_purchase_invoices.sql` (idempotentní)
```
purchase_invoices
├── id, supplier_id (=tenant!), vendor_id → clients(id)
├── varsymbol (PF-YYYYMM-NNNN), vendor_invoice_number (jejich číslo)
├── issue_date, tax_date (DUZP), due_date, received_at
├── currency_id → currencies(id), exchange_rate, exchange_rate_date
├── reverse_charge, language, document_kind ENUM('invoice','receipt','credit_note','advance')
├── vendor_snapshot JSON, own_snapshot JSON
├── total_without_vat, total_vat, total_with_vat, rounding (DECIMAL(12,2))
├── advance_paid_amount, amount_to_pay (STORED generated)
├── status ENUM('draft','received','booked','paid','cancelled')
├── booked_at, paid_at, cancelled_at
├── pdf_path (skenovaný originál), notes
├── vat_classification_code VARCHAR(10) NULL — připraveno pro fázi 5
├── created_by, created_at, updated_at
├── UNIQUE (supplier_id, vendor_id, vendor_invoice_number, issue_date)
├── UNIQUE (supplier_id, varsymbol)
└── INDEX (supplier_id, status, due_date), (supplier_id, tax_date)

purchase_invoice_items
├── id, purchase_invoice_id, order_index
├── description, quantity, unit, unit_price_without_vat
├── vat_rate_id, vat_rate_snapshot
├── total_without_vat, total_vat, total_with_vat
└── vat_classification_code VARCHAR(10) NULL — pro fázi 5

purchase_invoice_counters
├── supplier_id, year_month CHAR(6), last_number
└── PK (supplier_id, year_month)
```
Idempotence: `CREATE TABLE IF NOT EXISTS`, `ALTER TABLE … ADD COLUMN IF NOT EXISTS` (per `feedback_migrations_idempotent.md`).

### 1.2 Backend (`api/src/`)
**Repository:** `PurchaseInvoiceRepository.php` — `listForTenant(supplierId, filters)`, `find(id, supplierId)`, `create`, `update`, `delete` (draft only), `transition(status)`, `setItems`, `setExchangeRate`, `nextVarsymbol(supplierId, yearMonth)`. Vždy filtruje přes `supplier_id` (tenant).

**Service:** `Service/Invoice/PurchaseInvoiceCalculator.php` — přepočet součtů (per item → invoice totals, rounding rules dle CZ konvence).

**Actions** (`Action/PurchaseInvoice/*`):
- `ListPurchaseInvoicesAction` — pagination, filters (year, month, status, vendor_id, document_kind, unpaid_only)
- `GetPurchaseInvoiceAction` — detail s items, vat_breakdown
- `CreatePurchaseInvoiceAction` — draft + vygeneruj varsymbol
- `UpdatePurchaseInvoiceAction` — jen draft
- `DeletePurchaseInvoiceAction` — jen draft
- `SetPurchaseInvoiceItemsAction`
- `SetPurchaseInvoiceExchangeRateAction`
- `TransitionPurchaseInvoiceStatusAction` — draft→received→booked→paid + cancel

Routes v `api/src/routes.php`, všechny chráněné `AuthMiddleware` + `SupplierScopeMiddleware`.

### 1.3 Frontend (`web/src/`)
**API client:** `api/purchaseInvoices.ts` (paralela k `invoices.ts`).
**Pages:**
- `pages/purchase-invoices/InvoiceList.vue` — tabulka s filtry, badges status, formátování měn, tlačítko **„+ Nová přijatá faktura"** + samostatné **„📎 Importovat z PDF"**
- `pages/purchase-invoices/InvoiceEditor.vue` — header (vendor, čísla, data) + items grid + totals + **drag & drop zóna nahoře**
- `pages/purchase-invoices/InvoiceDetail.vue` — read-only view + transition tlačítka + upload PDF (jako attachment)

**Drag & drop přímo v editoru (klíčový UX požadavek):**
- Když uživatel klikne „+ Nová přijatá faktura" → otevře se `InvoiceEditor.vue` v `mode=create` s **velkou drag & drop zónou** nad formulářem ("Sem přetáhněte PDF faktury, nebo vyplňte ručně").
- Drop PDF → spustí stejný extraction pipeline jako import modal (ISDOC → UBL → AI):
  1. POST `/api/purchase-invoices/extract-pdf` (multipart, jen extraction, ne save)
  2. Response = `PurchaseInvoiceDraft` JSON
  3. Frontend prefilluje pole formuláře + zobrazí confidence badges
  4. Uživatel zkontroluje + uloží přes standardní `POST /api/purchase-invoices`
- Stejné UX i pro **přijaté faktury z emailu** — uživatel přetáhne PDF z download složky.
- ESC nebo „Vymazat extrakci" tlačítko → vrátí prázdný formulář.
- Drag & drop zóna **zmizí**, jakmile uživatel začne psát do polí (anti-confusion).
- AI cesta async (job polling), ISDOC sync.

**Router:** přidat 3 routes do `web/src/router/index.ts`.
**Komponenty:**
- `VendorPicker.vue` — vybírá z `clients WHERE is_vendor=1` přes ARES, sdílí logiku s `ClientPicker`. Při výběru nového IČ → ARES lookup + insert do `clients` s `is_vendor=1`.
- `PdfDropzone.vue` — reusable drag & drop komponenta (i pro Banka „Importovat výpis").
- `ExchangeRateInput.vue` — pole pro kurz s tlačítkem „Načíst z ČNB k datu DUZP".
- `PaymentCurrencyBlock.vue` — collapsible „Platba v jiné měně" sekce (zobrazí se jen když user zaškrtne checkbox).

### 1.4 Levé menu redesign
**Refaktor `AppLayout.vue`** podle vzoru forku (viz cross-cutting #1). Hlavní změna: nav je v aside, ne v topbaru. Topbar zachovat se supplier banner. Tag `feature/sidebar-redesign` může být **samostatný preliminary commit** ve stejném PR.

### 1.5 i18n
Klíče `purchase_invoice.*` (~80 klíčů: titles, statuses, document_kinds, form labels, tooltips, errors), `nav.section_purchase`, `nav.purchase_invoices`. Plně CS + EN.

### 1.6 Manuál
`manual/10_Prijate_faktury.md` — workflow draft→received→booked→paid, jak importovat scan, jak svázat s úhradou. Screenshoty (`manual/img/09a_*.webp`).
Update: `01_Uvod.md` (feature list), `INDEX.md`, `manual/index.php`.

### 1.7 OpenAPI
10 paths pod `/api/purchase-invoices/*`. Schemas: `PurchaseInvoice`, `PurchaseInvoiceItem`, `PurchaseInvoiceList`, `PurchaseInvoiceTransition`.

### 1.8 Testy
`api/tests/PurchaseInvoice/*Test.php` — CRUD, status transitions, tenant isolation (jiný supplier nevidí cizí faktury), VAT calculation roundtrip, counter generation, duplicate guard.

### 1.9 Release
- `VERSION` → 3.5.0
- `CHANGELOG.md` sekce
- Tag `v3.5.0`, GHCR + production bundle (per `feedback_release_workflow.md`)

---

## Fáze 2 — Unified import (vydané + přijaté × ISDOC/PDF/API)

**Cíl:** přepracovat `admin/Imports.vue` na **jednotný hub** pro libovolný typ importu — vydané i přijaté faktury, z ISDOC / PDF / Pohoda XML / iDoklad API / Fakturoid API / AI fallback.
**Verze:** v3.6.0.
**Odhad:** 6–7 dní (větší refactor existujícího importu + 2a + 2b + 2c).

Marketingová story: „Jeden import pro všechno — vydané i přijaté faktury, ISDOC, PDF, Pohoda XML, iDoklad, Fakturoid, AI extrakce."

### 2.0) Refactor existujícího `ImportAction` (pre-requisite pro 2a/2b/2c)

**Stávající stav (k 2026-05-20):** `Action/Admin/ImportAction.php` importuje **jen vydané faktury** z Pohoda XML / ISDOC / ZIP. Endpoint `POST /api/admin/import`, multipart `files[]`.

**Cíl refaktoru:**
- Přidat query param `?kind=issued|purchase` (default `issued` pro BC).
- Rozšířit existující services o purchase variantu.
- Sjednotit response tvar — `{ created, skipped, failed, by_file: [...] }` (už existuje, jen rozšířit o `kind`).
- Frontend `admin/Imports.vue` → záložky **„Vydané"** / **„Přijaté"** s vlastním drag & drop pro každou.

**Backend změny:**
```
api/src/Service/Import/
├── InvoiceImportService.php          — EXISTUJE, dispatch podle kind:
│                                       kind=issued → existující IssuedInvoice logic
│                                       kind=purchase → nový PurchaseInvoiceImportService
├── PurchaseInvoiceImportService.php  — NOVÝ orchestrátor pro přijaté
├── IsdocToPurchaseInvoiceMapper.php  — NOVÝ (z fáze 2c plánu)
├── PohodaXmlParser.php               — EXISTUJE, rozšířit detekci <invoice> vs <receivedInvoice>
└── ZipExtractor.php (nebo inline)    — EXISTUJE, beze změny
```

Buyer validation v `IsdocToPurchaseInvoiceMapper`: ISDOC's `AccountingCustomerParty` IČ **musí matchovat** `suppliers.ic` tenanta. Jinak `skipped` s důvodem „ISDOC patří jinému plátci (IČ XXX)". Brání cross-tenant data leakage při náhodném uploadu.

**Endpoint změny:**
- `POST /api/admin/import?kind=issued` — beze změny (BC)
- `POST /api/admin/import?kind=purchase` — nová cesta
- `POST /api/admin/import?kind=auto` (default v fázi 2) — **auto-detekce per soubor**:
  - Pokud ISDOC `<AccountingSupplierParty>` IČO == tenant supplier IČO → **vydaná** (my jsme dodavatel) → směr do `invoices`
  - Pokud ISDOC `<AccountingCustomerParty>` IČO == tenant supplier IČO → **přijatá** (my jsme zákazník) → směr do `purchase_invoices`
  - Pokud ani jeden nematchuje → skip s důvodem „ISDOC patří jinému plátci (vendor IČO: X, customer IČO: Y, tenant: Z)"
- `POST /api/admin/import` (bez param) — `kind=auto` (UX wins)
- Auto-detection v ZIP: per-soubor split, response report rozděluje counters do issued/purchase.

**Frontend:**
- `admin/Imports.vue` — top tabs „Vydané" / „Přijaté" + ikonky pro každý formát (ISDOC, Pohoda XML, PDF, iDoklad, Fakturoid). Drag & drop zóna na záložce stejná, ale `kind` se posílá v requestu.
- Drag & drop akceptuje: `.isdoc`, `.xml`, `.zip`, `.pdf` (pro 2c).
- ZIP s mixed obsahem → po importu warning „Importováno X vydaných, Y přijatých — důvody pro Z přeskočených: …".

**Testy refaktoru:**
- BC test: starý request bez `kind` parametru se chová identicky (regrese protect).
- Cross-kind ZIP: 5 ISDOC souborů (3 issued + 2 received) → správně rozdělit, žádný cross-tenant leak.

### 2a) iDoklad + 2b) Fakturoid API import

### 2a.1 Migrace (sdílené pro 2a + 2b + 2c)
- `0027_idoklad_credentials.sql` — `supplier_settings` přidá `idoklad_client_id`, `idoklad_client_secret` (encrypted).
- `0028_idoklad_ids.sql` — `clients.idoklad_id`, `invoices.idoklad_id`, `purchase_invoices.idoklad_id` — všechny `UNIQUE(supplier_id, idoklad_id) WHERE idoklad_id IS NOT NULL`.
- `0029_import_jobs.sql` — generická tabulka `import_jobs` (id, supplier_id, source ENUM('idoklad','fakturoid','pdf_isdoc','pdf_ubl','pdf_ai'), status ENUM('queued','running','completed','failed','cancelled'), started_at, finished_at, total_items, processed, failed, log_text TEXT, params JSON).
- `0030_fakturoid_credentials.sql` + `0031_fakturoid_ids.sql` — analogicky.

**Pozn.:** Místo dvou samostatných `import_jobs` tabulek (jak měl fork) **jedna sjednocená** — uklízíme jejich design. Source ENUM rozšířen rovnou o PDF varianty pro sub-fázi 2c.

### 2a.2 Backend
**Service:**
- `Service/Import/IdokladClient.php` — OAuth2 client_credentials, retry, rate limiting (iDoklad limit 60 req/min).
- `Service/Import/FakturoidClient.php` — OAuth2 Bearer, slug-based URLs.
- `Service/Import/ImportJobService.php` — společný runner, dedup logic, dry-run mode.

**Bin workers:**
- `api/bin/import-worker.php --job-id=N` — spouští se detached (Windows: `start /b`, Linux: `nohup`). Fork měl bugy s Windows disconnection (`fix: zlepšení procesu odpojení na Windows`); my použijeme `proc_open` s `DETACHED_PROCESS` flag.
- Spouští se přes existující cron infrastrukturu (`cron_runs` tabulka).

**Actions:**
- `Action/Admin/Import/StartImportAction` (POST `/api/admin/imports/{source}/start`)
- `Action/Admin/Import/ImportStatusAction` (GET `/api/admin/imports/{id}`)
- `Action/Admin/Import/CancelImportAction` (POST `/api/admin/imports/{id}/cancel`)
- `Action/Admin/Import/ListImportsAction` (GET `/api/admin/imports`)

### 2a.3 Frontend
- `pages/admin/Imports.vue` — settings (credentials per source) + tlačítko "Spustit import" + polling progress (každé 2s) + log viewer + cancel tlačítko.
- `pages/admin/Settings.vue` — sekce „Externí integrace" s formuláři pro iDoklad/Fakturoid credentials (heslové pole, test connectivity).
- `api/imports.ts` — typed klient.

### 2a.4 Klíčové bugy z forku, kterým se vyhneme
- INSERT placeholder count mismatch (fork měl 4× commit na tohle) — použít named parameters nebo PDO_FETCH validační wrapper.
- Date parsing fallback (`Fix ReceivedInvoices date parsing - use DateOfAccountingEvent when DateOfIssue is null`) — uděláme od začátku robustně.
- Credit notes endpoint 404 — iDoklad v3 nemá `IssuedCreditNotes`, je `InvoiceType=3` v `IssuedInvoices`. Známo, ošetříme.
- Duplicate INSERT crash — zachytit `1062 Duplicate entry` graceful.

---

### 2c) Import z PDF (ISDOC embedded už máme + UBL/Factur-X + AI vision fallback)

**Důvod:** API import (2a, 2b) řeší jen onboarding z konkurence. Pro **ongoing workflow** (papírová faktura nebo email PDF) potřebujeme dropzone import. Tři detection paths:

**Aktuální stav (2026-05-20):** ISDOC + PDF/A-3 ISDOC import **už existuje** pro vystavené faktury:
- `api/src/Service/Import/PdfIsdocExtractor.php` — extrahuje ISDOC XML z `/EmbeddedFile` (vlastní stream parser, ne `smalot/pdfparser`, XXE-safe, anti zip-bomb 10 MiB limit)
- `api/src/Service/Import/IsdocParser.php` — parser ISDOC 6.0.x → normalized array
- `api/src/Service/Import/InvoiceImportService.php` — orchestrátor (Pohoda XML + ISDOC + ZIP wrapper)
- `api/src/Action/Admin/ImportAction.php` — endpoint `POST /api/admin/import`, multipart, supports `.xml` / `.isdoc` / `.zip`
- UI: `web/src/pages/admin/Imports.vue`

**Co dodělat v 2c (reuse existing infrastructure):**

**2c.1 Pořadí detekce v novém `Service/Import/PurchaseInvoicePdfExtractor.php`:**

```
1. .isdoc nebo PDF s embedded ISDOC  → reuse PdfIsdocExtractor + IsdocParser
                                       + NOVÝ mapper IsdocToPurchaseInvoiceMapper
2. .xml Pohoda dataPack přijatých    → rozšířit PohodaXmlParser o `<receivedInvoice>` element
3. PDF/A-3 s factur-x.xml / UBL      → deferred do v3.6.1 (jen detekce + error UI)
4. PDF textový / skenovaný           → AnthropicExtractor (nový engine)
```

**2c.2 ISDOC mapper — co napsat nového:**
- `Service/Import/IsdocToPurchaseInvoiceMapper.php` — bere normalized array z existujícího `IsdocParser` a mapuje do `purchase_invoices` + `purchase_invoice_items` (místo `invoices`). Klíčový rozdíl: **vendor** (dodavatel ISDOC `AccountingSupplierParty`) = naše `clients` row jako vendor; **buyer** (`AccountingCustomerParty`) musí matchovat náš `supplier_id` (jinak ISDOC patří jinému tenantovi → odmítnout).
- Cca 80 LOC, plně testovatelné.

**2c.3 Admin import UI rozšíření:**
- `web/src/pages/admin/Imports.vue` přidá **toggle „Typ dokladů: Vystavené / Přijaté"** nad dropzone.
- Pokud „Přijaté" → endpoint `POST /api/admin/import?kind=purchase` (rozšíření existující `ImportAction`).
- Pokud „Vystavené" → beze změny.
- Stejný report formát (created/skipped/failed per soubor).

**2c.4 UBL / Factur-X / ZUGFeRD extraction:**
- Vlastní stream parser podle vzoru `PdfIsdocExtractor` (najít `/EmbeddedFile` s filename `factur-x.xml` nebo `ubl-invoice.xml`).
- UBL XSD jiný než ISDOC → samostatný `UblParser.php`. Cca 200 LOC.
- Důvod priority: od 2026 povinné B2G v EU → bude přibývat.
- Implementace **deferred do v3.6.1** pokud bude tlak; v3.6.0 jen detekce + chybové hlášení „UBL/Factur-X detected, parser coming in v3.6.1, použijte manuální zadání nebo AI extrakci".

**2c.5 AI extraction (Anthropic Claude):**
- Engine: **Haiku 4.5** default (~$0.001/faktura), **Sonnet 4.6** opt-in pro low-confidence retry (~$0.008/faktura).
- Tool use s strict JSON schema → vendor (IČ, DIČ, name, address, IBAN, account), vendor_invoice_number, variable_symbol, issue_date / tax_date / due_date, currency, reverse_charge, items[] (description, qty, unit, unit_price, vat_rate, classification_code), totals, plus per-field `confidence` 0–1.
- System prompt česky: CZ formáty data (DD.MM.YYYY), DPH sazby (12/21), VS = číslo dokladu, IČ 8 cifer, DIČ `CZ` + 8–10 cifer, bankovní účet validovat mod-11.
- Anthropic API umí PDF natively jako message attachment (do 32 MB / 100 stran) — **žádný vlastní OCR** (ne tesseract).

**Post-extraction pipeline (společné pro všechny engine):**
1. Validate: `items[i].total_with_vat ≈ qty × unit_price × (1+vat)` ± 0.01 → pokud nesedí, snížit confidence dané položky + ne uložit AI součty, ale přepočítat přes `PurchaseInvoiceCalculator`.
2. ARES lookup nad detekovaným IČ (existující `AresService`) → ověř + doplň/aktualizuj adresu vendora.
3. Match `clients` přes `ic` → existující klient nebo nabídka „založit nového vendora".
4. Validate bank account mod-11 (per `feedback_test_data.md`).
5. Create `purchase_invoices` status=`draft` + items + `attachment` (originální PDF).
6. Log do `ai_extraction_log` (tokens, cost, hash).

### 2c.6 Migrace `0029a_pdf_import_ai.sql`

```sql
ALTER TABLE supplier_settings
  ADD COLUMN IF NOT EXISTS ai_provider ENUM('disabled','anthropic') NOT NULL DEFAULT 'disabled',
  ADD COLUMN IF NOT EXISTS ai_api_key_enc VARBINARY(512) NULL,
  ADD COLUMN IF NOT EXISTS ai_model VARCHAR(64) NULL DEFAULT 'claude-haiku-4-5-20251001',
  ADD COLUMN IF NOT EXISTS ai_low_confidence_retry TINYINT(1) NOT NULL DEFAULT 0;
       -- 1 = on low confidence (<0.7) retry s Sonnet 4.6

CREATE TABLE IF NOT EXISTS ai_extraction_log (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id         BIGINT UNSIGNED NOT NULL,
  used_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  kind                ENUM('purchase_invoice_pdf') NOT NULL,
  engine              ENUM('isdoc','ubl','anthropic_text','anthropic_vision') NOT NULL,
  model               VARCHAR(64) NULL,
  input_tokens        INT UNSIGNED NULL,
  output_tokens       INT UNSIGNED NULL,
  cost_usd            DECIMAL(8,4) NULL,
  source_hash         CHAR(64) NULL,         -- SHA-256 PDF, dedup proti opakovaným uploadům
  success             TINYINT(1) NOT NULL,
  error               TEXT NULL,
  avg_confidence      DECIMAL(4,3) NULL,
  purchase_invoice_id BIGINT UNSIGNED NULL,
  KEY idx_ail_supplier (supplier_id, used_at),
  KEY idx_ail_hash (source_hash),
  CONSTRAINT fk_ail_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE,
  CONSTRAINT fk_ail_pi       FOREIGN KEY (purchase_invoice_id) REFERENCES purchase_invoices(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

Retention: cron job maže `ai_extraction_log` starší 90 dní (privacy default).

### 2c.7 Backend (jen nové soubory, ostatní reuse)

```
api/src/Service/Import/
├── PdfIsdocExtractor.php             — EXISTUJE, reuse
├── IsdocParser.php                   — EXISTUJE, reuse
├── PohodaXmlParser.php               — EXISTUJE, rozšířit o receivedInvoice
├── InvoiceImportService.php          — EXISTUJE, rozšířit o kind=purchase route
├── IsdocToPurchaseInvoiceMapper.php  — NOVÝ (~80 LOC)
├── PurchaseInvoicePdfExtractor.php   — NOVÝ orchestrátor pro purchase flow
├── UblPdfExtractor.php               — NOVÝ, placeholder pro 3.6.1
├── UblParser.php                     — NOVÝ, deferred do 3.6.1
└── AnthropicExtractor.php            — NOVÝ, Claude API client + tool use schema

api/src/Action/Admin/
└── ImportAction.php                  — EXISTUJE, rozšířit o ?kind=purchase
```

**Actions (rozšíření a nové):**
- `POST /api/admin/import?kind=purchase` (rozšíření existující `ImportAction`, multipart, pro ISDOC + Pohoda XML)
- `POST /api/purchase-invoices/import-pdf-ai` (NOVÝ — multipart upload PDF, returns import_job_id pro AI cestu)
- `GET /api/purchase-invoices/import-pdf-ai/{job_id}` (polling status + extracted draft)
- `POST /api/purchase-invoices/import-pdf-ai/{job_id}/accept` (uloží draft do `purchase_invoices`)

Background worker `api/bin/pdf-ai-import-worker.php --job-id=N` — stejný launcher pattern jako iDoklad worker. ISDOC cesta běží synchronně (rychlá, deterministická), AI cesta async (latence Anthropic API 2–10s).

**Anthropic SDK:** PHP klient přes Guzzle (REST API, není oficiální PHP SDK). Wrapper `AnthropicClient.php` s metodami `extractInvoicePdf(string $pdfBytes, string $model): ExtractionResult`.

### 2c.8 Frontend

**Dva vstupní body podle uživatelského kontextu:**

1. **`web/src/pages/admin/Imports.vue` — bulk batch** (existuje pro vystavené)
   - Přidat toggle „Typ dokladů: Vystavené / Přijaté".
   - Při „Přijaté" + upload `.isdoc` / `.xml` / `.zip` / `.pdf` s embedded ISDOC → běží synchronně přes `ImportAction?kind=purchase` (reuse).
   - AI cesta zde **není** — bulk AI = drahý a chybový.

2. **`web/src/pages/purchase-invoices/InvoiceList.vue` — single doc with AI**
   - Tlačítko **„📎 Importovat fakturu z PDF"** (vedle „Nová přijatá faktura").
   - `PdfImportModal.vue` — drag & drop, progress steps („Detekce formátu… ISDOC nenalezeno → AI extrakce… ARES lookup… Hotovo"), retry tlačítko, fallback „Pokračovat manuálně".
   - Po dokončení redirect na `InvoiceEditor.vue` s `?draft_id=…&from=pdf_import`.
   - Editor: per-field badge **„🤖 AI extrakce, confidence X%"** žlutě pokud <0.8, červeně pokud <0.5. Po editaci pole badge zmizí.

### 2c.9 Bezpečnost a opt-in

- AI **disabled by default**.
- Admin musí v **Nastavení → Externí integrace → AI extrakce** zapnout + zadat **vlastní Anthropic API klíč** (BYOK — bring your own key). Důvod: faktury opouští instalaci, self-hosted myšlení vyžaduje vědomý souhlas. Klíč v `supplier_settings.ai_api_key_enc` šifrovaný `app.pepper`.
- ISDOC + UBL detekce funguje **bez AI** (default-on, žádný external call).
- Privacy notice v UI před prvním AI použitím (modal s odsouhlasením + odkaz na Anthropic privacy policy + zip-bombing protection notice).

### 2c.9b Dokumentace — jak si zaregistrovat Anthropic API klíč

**Nový manuál `manual/16c_AI_API_klic.md`** (CS + EN) — step-by-step s screenshoty:

1. **Vytvoření Anthropic účtu** — `https://console.anthropic.com/`, registrace přes e-mail nebo Google.
2. **Workspace setup** — Anthropic Console → vytvořit Workspace (např. „MyInvoice produkce").
3. **Billing** — přidat platební kartu v Settings → Billing. Důležité upozornění: **bez kreditu/karty API odmítá requesty** s 402.
4. **Volitelné: nastavit Usage Limits** — Settings → Limits → měsíční cap (např. $5/měsíc) → ochrana proti runaway costs. Doporučená hodnota pro typickou OSVČ: $2/měsíc (= cca 2000 faktur Haiku 4.5).
5. **Vytvoření API klíče** — API Keys → "Create Key" → Workspace = produkce → Name = „myinvoice-pdf-extract" → Copy klíč (`sk-ant-api03-…`).
6. **Důležité: klíč zkopírovat hned** — Anthropic ho znovu neukáže (jen poslední 4 znaky). Pokud ztratíš, vytvoř nový a starý smaž.
7. **Vložení do MyInvoice** — Nastavení → Externí integrace → AI extrakce → vložit klíč → test connectivity (jednoduchý ping request) → uložit.
8. **První extrakce** — zkusit test PDF (přiložit ukázkovou fakturu v manuálu).

**Sekce o ceně:**
- Aktuální ceny Haiku 4.5 (input $1/M tokens, output $5/M tokens) + odhad na fakturu (~$0.001).
- Tabulka „odhad měsíčního nákladu podle objemu" (10/30/100/500 faktur).
- Doporučení: Sonnet 4.6 jen pokud Haiku nestačí (low confidence retry).
- Link na aktuální Anthropic pricing page (může se měnit).

**FAQ:**
- „Můžu klíč sdílet mezi víc instalacemi MyInvoice?" — Ano, ale doporučujeme separátní klíče pro audit trail.
- „Co když přestanu AI používat?" — Smazat klíč v Anthropic Console + v MyInvoice nastavit AI provider na 'disabled'. Žádné další náklady.
- „Jsou moje faktury ukládané u Anthropic?" — Per Anthropic API Terms, prompt data se mažou po 30 dnech, není fine-tuning. Plus EU residency endpoint dostupný.
- „Co GDPR?" — Anthropic má EU DPA dostupné v Console → Settings → Compliance. Stáhnout + podepsat → soulad s GDPR Article 28.
- „Můžu vyzkoušet bez kreditní karty?" — Anthropic dává free $5 credit při registraci. Stačí to na ~5000 Haiku extrakcí.
- „Jaký je rozdíl mezi Workspace a Account?" — Workspace = projekt/aplikace pod účtem, samostatné limity a klíče. Doporučujeme jeden Workspace per instalace MyInvoice.

**Landing site cross-link:** v `c:\work\myinvoice.web` přidat sekci „AI extrakce — jak začít" s odkazem na manuál.

### 2c.10 Náklady — reálný odhad

| Engine | Cena/faktura | Pro 30 fakt./měs |
|---|---|---|
| ISDOC embedded | $0 | $0 |
| UBL embedded | $0 | $0 |
| Anthropic Haiku 4.5 (text) | ~$0.0005 | ~$0.015 |
| Anthropic Haiku 4.5 (vision) | ~$0.001 | ~$0.03 |
| Anthropic Sonnet 4.6 (vision) | ~$0.008 | ~$0.24 |

Sonnet 4.6 default-off; zapnout jen pro retry low-confidence (ai_low_confidence_retry=1).

### 2c.11 Rizika

1. **Halucinace u částek** → vždy přepočet `PurchaseInvoiceCalculator`, nikdy nevěřit AI sum-of-items.
2. **Privacy / GDPR** — BYOK + opt-in + retention 90 dní + audit log.
3. **Vendor lock-in** — interface `AiExtractor` (jen Anthropic implementace v v3.6, OpenAI snad v6 pokud bude poptávka).
4. **PDF/A-3 corner cases** — některé scannery generují fake PDF/A-3 bez attachment streamů. Detekce čistě podle `/EmbeddedFiles` keyword, ne podle metadata.
5. **Encrypted PDFs** → bail out s clear error „PDF je zaheslované, odstraňte heslo a zkuste znovu".

### 2.5 i18n / manuál / openapi (společné pro 2a + 2b + 2c)
- Klíče `import_idoklad.*`, `import_fakturoid.*`, `import_pdf.*`, `ai_extraction.*`, `nav.imports`.
- Manuál: `16a_Import_iDoklad_Fakturoid.md` + `16b_Import_PDF_AI.md` (jak získat Anthropic API klíč, jak ISDOC, kdy AI selže, jak číst confidence).
- OpenAPI: 6 paths (2a+2b) + 3 paths (2c) + schemas `ImportJob`, `ImportProgress`, `ImportCredentials`, `PdfExtractionResult`, `AiSettings`.

### 2.6 Tag `v3.6.0`.

---

## Fáze 3 — Bankovní výpisy + párování plateb

**Cíl:** import GPC/CSV → matching proti vydaným i přijatým fakturám.
**Verze:** v3.7.0.
**Odhad:** 2 dny (parser už máme pro vydané).

### 3.1 Migrace `0032_bank_purchase_matching.sql`
```sql
ALTER TABLE bank_transactions
  ADD COLUMN matched_purchase_invoice_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_bt_pi FOREIGN KEY (matched_purchase_invoice_id)
    REFERENCES purchase_invoices(id) ON DELETE SET NULL;

CREATE TABLE bank_transaction_matches (
  id, bank_transaction_id, invoice_id NULL, purchase_invoice_id NULL,
  match_type ENUM('auto_exact','auto_partial','manual','unmatched','ignored'),
  match_amount, expected_amount, amount_diff,
  matched_by, created_at, is_active TINYINT(1),
  UNIQUE (bank_transaction_id, is_active),  -- max 1 active per tx
  …
);
```
Audit trail vzor přebrán z forku — kvalitní design (one active match + full history).

### 3.2 Backend
- `Service/Bank/PaymentMatcher.php` — auto match: var. symbol → exact amount; var. symbol → partial; částka + datum + protistrana fuzzy.
- `Action/Bank/MatchTransactionAction.php` (POST `/api/bank-transactions/{id}/match`, body: `invoice_id` nebo `purchase_invoice_id`).
- `Action/Bank/UnmatchAction`, `IgnoreTransactionAction`.
- Po match: pokud nová částka pokrývá `amount_to_pay` → transition status na `paid` automaticky.

### 3.3 Frontend
- Update `pages/bank/BankStatement.vue` — sloupec "Spárováno s", autocomplete (typeahead přes obě tabulky), tlačítka match/unmatch/ignore.
- Badge na faktuře: "Uhrazeno převodem" + odkaz na transakci.

### 3.4 i18n / manuál / openapi
- `bank_matching.*` klíče.
- Manuál: `12a_Parovani_plateb.md`.
- OpenAPI: 4 paths + schema `BankMatch`.

### 3.5 Tag `v3.7.0`.

---

## Fáze 4 — Opakované přijaté faktury — **VYŘAZENO**

**Rozhodnutí 2026-05-20:** vyřazeno z roadmapy. Design forku slouží jen jako inspirace.

**Důvody:**
- Většina opakovaných nákladů (hosting, SaaS) přichází jako PDF email — řeší fáze 2c (PDF + AI import) elegantněji než šablona.
- Manuální zadání 5–10 měsíčních nákladů ročně není friction worth automating.
- Recurring vydaných faktur dává smysl (klient platí pravidelně), recurring přijatých výrazně méně (dodavatel posílá svůj doklad, my ho jen evidujeme).

**Co si vzít z forku jako inspiraci** (do `purchase_invoices.notes` nebo `vendor.tags`): příště rozvážit "Označit fakturu jako pravidelnou" pro filtraci, ne pro generování. Není v scope teď.

---

## Fáze 5 — CRM: kompletní reporting tržeb, nákladů, zisků, predikcí

**Cíl:** předělat stávající `Stats.vue` na plnohodnotný BI/CRM modul. Tržby, náklady (z přijatých faktur fáze 1), zisk, cashflow, klientskou analytiku, projektovou rentabilitu a predikce.
**Verze:** v3.8.0.
**Odhad:** 6–8 dní (3 dny backend, 3 dny frontend, 1 den forecasting algoritmus, 1 den i18n+docs).

**Závislosti:** vyžaduje **fázi 1 (přijaté faktury)** pro náklady/zisk; bez ní by zůstaly jen revenue-side widgety. Cashflow a forecasting může mít plný funkční rozsah až s fází 3 (bank matching → reálný cash position).

### 5.1 Vize

Místo jedné stránky `/stats` s 10 chart komponentami → samostatný **CRM modul** s vlastní sekcí v levém menu a vlastní vnitřní strukturou. Cíl: do 10 sekund odpovědět na otázky:
- Kolik jsem letos vydělal a kolik mě to stálo?
- Komu fakturuju nejvíc a kdo je největší riziko (concentration)?
- Kolik mi pravděpodobně přijde na účet příští měsíc?
- Jaký projekt mi dává nejlepší marži?
- Kteří klienti se mi „ztrácí" (90+ dní bez objednávky)?

### 5.2 Struktura — 8 podsekcí pod `/crm/*`

```
/crm                  Přehled        — KPI dashboard + alerts (landing)
/crm/revenue          Tržby           — měsíční, kumulativní, breakdown
/crm/costs            Náklady         — měsíční, top vendoři, kategorie [záv. fáze 1]
/crm/profit           Zisk            — marže, profitability per klient/projekt [záv. fáze 1]
/crm/cashflow         Cashflow        — peněžní toky + 90-day forecast [záv. fáze 3]
/crm/clients          Klienti         — top, ABC, LTV, churn risk
/crm/projects         Zakázky         — ROI, hodinová efektivita, status
/crm/forecast         Predikce        — výnosy/náklady next quarter
```

Vnitřní navigace v `/crm/*`: horizontální tab bar nebo sub-sidebar v rámci CRM modulu. Mezi sekcemi sdílený **PeriodPicker** (1m / 3m / 12m / YTD / custom) a **CurrencyPicker** (pro multi-currency uživatele).

### 5.3 Migrace `0033_crm_support.sql`

```sql
-- 1) Kategorie nákladů — nutné pro Costs sekci
CREATE TABLE IF NOT EXISTS expense_categories (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  supplier_id     BIGINT UNSIGNED NOT NULL,
  code            VARCHAR(20) NOT NULL,             -- 'hosting', 'software', 'kancelar', …
  label           VARCHAR(100) NOT NULL,
  fixed_or_var    ENUM('fixed','variable') DEFAULT 'variable',
  display_order   INT DEFAULT 0,
  archived        TINYINT(1) DEFAULT 0,
  UNIQUE KEY uq_ec (supplier_id, code),
  CONSTRAINT fk_ec_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE CASCADE
);

-- 2) Přiřazení kategorie na přijatou fakturu (a item-level pro detailní rozpad)
ALTER TABLE purchase_invoices
  ADD COLUMN IF NOT EXISTS expense_category_id INT UNSIGNED NULL,
  ADD CONSTRAINT fk_pi_expense_cat FOREIGN KEY (expense_category_id)
    REFERENCES expense_categories(id) ON DELETE SET NULL;

ALTER TABLE purchase_invoice_items
  ADD COLUMN IF NOT EXISTS expense_category_id INT UNSIGNED NULL;

-- 3) Volitelně: tagging na vydaných fakturách (revenue categories pro byznys mix)
ALTER TABLE invoices
  ADD COLUMN IF NOT EXISTS revenue_category VARCHAR(40) NULL;

-- 4) Pre-aggregated monthly summary pro výkon (refresh nightly cron)
CREATE TABLE IF NOT EXISTS crm_monthly_summary (
  supplier_id      BIGINT UNSIGNED NOT NULL,
  year_month       CHAR(7) NOT NULL,                -- '2026-05'
  currency         CHAR(3) NOT NULL,
  revenue          DECIMAL(14,2) DEFAULT 0,         -- total_with_vat issued
  revenue_net      DECIMAL(14,2) DEFAULT 0,         -- total_without_vat issued
  costs            DECIMAL(14,2) DEFAULT 0,         -- total_with_vat received
  costs_net        DECIMAL(14,2) DEFAULT 0,
  vat_output       DECIMAL(14,2) DEFAULT 0,
  vat_input        DECIMAL(14,2) DEFAULT 0,
  invoice_count    INT DEFAULT 0,
  purchase_count   INT DEFAULT 0,
  computed_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (supplier_id, year_month, currency)
);

-- 5) Stored procedure pro recompute (per existující pattern z migrace 0007)
-- sp_recompute_crm_monthly_summary(IN p_supplier_id BIGINT)
-- volá se z cronu + ručně z admin/maintenance
```

Cron rozšíření: do existující `cron_runs` infrastruktury přidat job `crm_recompute_summary` (denně 03:00, refresh za posledních 13 měsíců — overlap 1 měsíc pro late-arriving invoice opravy).

### 5.4 Backend struktura

```
api/src/Service/Crm/
├── CrmRevenueService.php          — revenue queries, group_by month/client/project/category/currency
├── CrmCostsService.php            — costs queries (purchase_invoices side)
├── CrmProfitService.php           — revenue − costs, marže, profitability per dimension
├── CrmCashflowService.php         — in/out flows + forecast
├── CrmClientAnalyticsService.php  — top, ABC (Pareto), LTV, churn risk, concentration
├── CrmProjectAnalyticsService.php — ROI, hodinová efektivita
├── CrmForecastService.php         — Holt-Winters + pipeline (confirmed) + recurring
└── CrmKpiService.php              — overview cards aggregator

api/src/Repository/Crm/
├── RevenueQueryRepository.php     — sjednocené SQL queries (group by + filtery)
├── CostsQueryRepository.php
└── MonthlySummaryRepository.php   — čte/zapisuje crm_monthly_summary

api/src/Action/Crm/
├── OverviewAction.php             — GET /api/crm/overview
├── RevenueAction.php              — GET /api/crm/revenue
├── CostsAction.php                — GET /api/crm/costs
├── ProfitAction.php               — GET /api/crm/profit
├── CashflowAction.php             — GET /api/crm/cashflow
├── ClientsAnalyticsAction.php     — GET /api/crm/clients/{top,abc,ltv,churn}
├── ProjectsAnalyticsAction.php    — GET /api/crm/projects/{roi,efficiency}
└── ForecastAction.php             — GET /api/crm/forecast
```

**Cache strategy:** in-memory cache (per request) + 5 min file cache key=`crm:{supplier_id}:{endpoint}:{hash(params)}`. Invalidace při INSERT/UPDATE/DELETE na invoices / purchase_invoices přes event hook v Repositories.

### 5.5 Endpoint specs

Jednotný response tvar pro graf-friendly výstupy:
```json
{
  "period": { "from": "2025-05-01", "to": "2026-05-31", "preset": "12m" },
  "currency": "CZK",
  "group_by": "month",
  "series": [
    { "label": "2025-05", "value": 152300.00, "secondary": { "vat": 31983 } },
    ...
  ],
  "totals": { "sum": 1823400.00, "avg": 151950.00, "min": 95000, "max": 220000 },
  "comparison": { "prev_period": { "sum": 1640000.00, "delta_pct": 11.18 } },
  "meta": { "computed_at": "2026-05-20T03:01:14Z", "cached": true }
}
```

Konkrétní endpointy:
| Endpoint | Filters | Group by | Vrací |
|---|---|---|---|
| `GET /api/crm/overview` | period | — | KPI cards + alerts + sparklines |
| `GET /api/crm/revenue` | period, currency, vendor, project | month/quarter/client/project/category/currency | series + totals + comparison |
| `GET /api/crm/costs` | period, currency, vendor, category | month/vendor/category | series + totals + top vendors |
| `GET /api/crm/profit` | period | month/client/project | revenue, costs, profit, margin% |
| `GET /api/crm/cashflow` | period, forecast_days | day/week/month | inflow, outflow, net, balance, forecast |
| `GET /api/crm/clients/top` | period, limit | — | sorted by revenue 12m |
| `GET /api/crm/clients/abc` | period | — | Pareto bucketing (A=80%, B=95%, C=zbytek) |
| `GET /api/crm/clients/ltv` | — | — | per-client cumulative revenue + first/last invoice |
| `GET /api/crm/clients/churn` | days_threshold=90 | — | klienti s last_invoice > N dní |
| `GET /api/crm/projects/roi` | period | — | revenue − allocated costs per projekt |
| `GET /api/crm/projects/efficiency` | period | — | invoiced amount / logged hours × hourly_rate |
| `GET /api/crm/forecast` | horizon=quarter | month | confirmed + recurring + run_rate, confidence bands |

### 5.6 Forecasting algoritmus

Žádný TensorFlow / ML. Tři vrstvy sloučené:

**Layer 1 — Confirmed pipeline** (deterministické):
- Vydané proformy ve stavu `issued|sent` → očekávaný cash-in v `due_date` (pravděpodobnost 0.85)
- Faktury `paid` ale ještě nepřevedené (gap mezi `paid_at` a bank match) → 1.0
- Recurring invoices `next_run_at` v horizontu → 0.95

**Layer 2 — Run rate baseline:**
- `avg(3 měsíce)` revenue per měsíc
- Seasonality faktor = `monthly_revenue[m] / yearly_avg[y-1]` (per-month index z minulého roku)
- Forecast `month[n] = run_rate × seasonality[n]`

**Layer 3 — Holt-Winters exponential smoothing** (jen pokud >24 měsíců historie):
- Trojitá exponenciála: level + trend + season
- PHP implementace ~150 LOC v `CrmForecastService::holtWinters()`
- Smoothing factors fixní α=0.3, β=0.1, γ=0.3 (žádný auto-tuning v MVP)

**Output:**
```json
{
  "horizon_months": 3,
  "scenarios": {
    "confirmed":   { "month": [...], "total": 280000 },
    "run_rate":    { "month": [...], "total": 420000 },
    "holt_winters": { "month": [...], "total": 460000 },
    "combined":    { "month": [...], "total": 480000, "confidence_low": 380000, "confidence_high": 580000 }
  }
}
```

UI ukazuje **combined** s confidence band (±15%) a možnost zobrazit jednotlivé scénáře (toggle).

### 5.7 Frontend

```
web/src/pages/crm/
├── CrmLayout.vue            — wrapper s vnitřní tab navigací + PeriodPicker
├── CrmOverview.vue          — KPI dashboard (landing)
├── CrmRevenue.vue
├── CrmCosts.vue             — disabled pokud fáze 1 nedeployed (graceful empty state)
├── CrmProfit.vue
├── CrmCashflow.vue
├── CrmClients.vue           — tabs: Top / ABC / LTV / Churn
├── CrmProjects.vue          — tabs: ROI / Efficiency / Status
└── CrmForecast.vue

web/src/components/crm/
├── KpiCard.vue              — number + delta% + sparkline
├── PeriodPicker.vue         — 1m / 3m / 6m / 12m / YTD / Custom
├── CurrencyPicker.vue       — pokud user má víc měn
├── GroupBySelector.vue      — switch month/quarter/client/project/category
├── ComparisonBadge.vue      — "vs minulé období +11.2%"
└── AlertList.vue            — pro overview (overdue invoices, churn warnings, …)

web/src/components/charts/  (rozšířit existující sadu)
├── (existující 12)
├── CostsStackedChart.vue          — kategorie stacked area
├── ProfitMarginChart.vue          — combo: bars(profit) + line(margin%)
├── CashflowForecastChart.vue      — area s confidence band
├── ParetoChart.vue                — ABC analýza (bar + cumulative line)
├── LtvHistogram.vue
├── ChurnRiskHeatmap.vue           — klient × days_since_last_invoice
└── ForecastScenariosChart.vue     — 3 lines + combined area
```

Charting library: zůstaňme u **Chart.js 4** (už máme `web/src/components/charts/*` na něm). Žádný D3/Plotly — overkill.

### 5.8 Levé menu — CRM jako vlastní sekce

V cross-cutting #1 přidat sekci:
```
- Prodej
- Nákup
- Finance: Banka
- CRM:            ← NOVÁ SEKCE (jediná položka, vnitřní tabs)
- Klienti
- Výkazy         ← fáze 6
- Systém
```

Bývalá položka „Statistiky" se **odstraní z menu** a route `/stats` se přesměruje na `/crm`. `Stats.vue` deprecated → smazat.

### 5.9 Migrační strategie pro stávající uživatele

- `Stats.vue` zůstane funkční v code base do v3.8.1 (1 patch release), pak smazat.
- Banner v CRM landing: „Statistiky byly přesunuty do CRM modulu — všechny grafy najdete v sekcích Tržby a Klienti."
- Žádný data loss — všechna data se počítají z existujících tabulek (invoices, clients, projects, work_reports).

### 5.10 i18n / manuál / openapi

- Klíče `crm.overview.*`, `crm.revenue.*`, `crm.costs.*`, `crm.profit.*`, `crm.cashflow.*`, `crm.clients.*`, `crm.projects.*`, `crm.forecast.*`, `nav.crm` (~200 klíčů — bohatá sekce).
- Manuál nahradí stávající stats kapitolu novým **24_CRM.md** (8 podkapitol per sekce), screenshoty.
- OpenAPI: ~14 paths (`/api/crm/*`), 8 schemas (`CrmKpi`, `CrmSeries`, `CrmForecast`, `CrmAbc`, `CrmLtv`, `CrmChurn`, `CrmRoi`, `CrmCashflow`).

### 5.11 Testy

- Service unit testy s fixture daty (1 supplier, 100 vydaných + 50 přijatých faktur za 24 měsíců) → assertions na totals, ABC bucketing, churn detection.
- Forecasting test: deterministická vstupní data → očekávaný output ± toleranci.
- `crm_monthly_summary` recompute idempotency test.
- Multi-supplier isolation test (jiný tenant nevidí cizí čísla).

### 5.12 Tag `v3.8.0`.

---

## Fáze 6 — EPO výkazy DPH/KH/DPFO/DPPO

**Cíl:** generování XML pro EPO portál MFČR.
**Verze:** v3.9.0 → po stabilizaci **v4.0.0**.
**Odhad:** 5–7 dní (XSD compliance + testování + warning UX). **Nejrizikovější fáze.** Posunuto z fáze 5 (CRM má prioritu — okamžitá user-facing hodnota; DPH je regulatorně rizikové).

### 6.1 Disclaimer-first design
Per nový scope (memory): každá strana výkazu má **prominentní červený banner**:

> ⚠️ Vygenerovaný XML soubor je pouze pomůcka. Před odesláním na EPO portál MF ČR vždy ověřte správnost s vaší účetní nebo daňovým poradcem. Testováno na omezené sadě dat.

Vzor: `docs/AUDIT_MFCR.md` (převzít a adaptovat z forku, vlastní disclaimer textem). Banner i v PDF náhledu výkazu.

### 6.2 Migrace
- `0034_vat_classification.sql` — `vat_classifications` (kód, label, sales/purchase, řádek DPHDP3, řádek KH). Seed z forku (kódy 40-41, 42, 43, 23, 24, 26 podle MFin ČR).
- `0035_supplier_tax_settings.sql` — `suppliers` přidá: `taxpayer_type ENUM('fo','po')`, `is_vat_payer`, `vat_period ENUM('monthly','quarterly')`, `financial_office_code` (FÚ), `workplace_code` (ÚzP), `cz_nace_code`, `sest_*` pole (sestavitel přiznání), `data_box_type`, `c_pop` (číslo popisné).
- `0036_invoice_vat_classification.sql` — `invoices.vat_classification_code`, `invoice_items.vat_classification_code`. (`purchase_invoices.vat_classification_code` už máme z fáze 1.)

### 6.3 Backend — XML exportéry
**Service struktury:**
```
Service/Report/
├── DphPriznaniBuilder.php       — DPHDP3 verzePis="03.01"
├── KontrolniHlaseniBuilder.php  — DPHKH1 verzePis="03.01"
├── DpfoBuilder.php              — DPFDP5 (FO)
├── DppoBuilder.php              — DPPDP9 (PO)
├── Xsd/                         — XSD soubory z MFČR (pro validaci před exportem)
└── VatClassificationMapper.php  — mapping kódů na řádky výkazu
```
Každý builder: vstup = `supplier_id` + období → výstup `DOMDocument` + validace proti XSD před vrácením.

**Actions:**
- `Action/Report/DphPriznaniAction` (GET `/api/reports/dphdp3?year=…&month=…`, vrací XML download)
- `Action/Report/KontrolniHlaseniAction`
- `Action/Report/IncomeTaxReturnFoAction`, `IncomeTaxReturnPoAction`
- `Action/Report/DphReportAction` — PŘEHLEDOVÁ stránka (souhrny vydané/přijaté po VAT klasifikaci, před exportem)

**Validace:** PHP `DOMDocument::schemaValidate()` před vrácením XML. Pokud selže → 422 s chybou.

### 6.4 Frontend
- `pages/reports/DphReport.vue` — landing s přehledem (vydané/přijaté po VAT klasifikaci, klikatelné na detail faktur)
- `pages/reports/DphPriznani.vue` — výběr období + náhled vět + tlačítko Stáhnout XML
- `pages/reports/KontrolniHlaseni.vue` — analogicky
- `pages/reports/IncomeTaxReturn.vue` — FO/PO přepínač
- `pages/reports/DaneReports.vue` — rozcestník (sekce „Výkazy" v menu)
- **VAT klasifikace picker** v editoru faktur — combobox per item, default per VAT rate.

### 6.5 i18n / manuál / openapi
- `report.*` klíče (rozsáhlé — popisy řádků výkazů).
- Manuál: nové `25_Vykazy_DPH.md` + `26_Dan_z_prijmu.md` (číslování po `24_CRM.md` z fáze 5). **Velmi důležitá** sekce „Disclaimer" v každém z nich.
- OpenAPI: 6 paths, schemas `DphPriznaniData`, `KontrolniHlaseniData`, `IncomeTaxData`, `VatClassification`.

### 6.6 Testy
- Fixture XML přiznání pro test scénáře (1 plátce, OSVČ, měsíční, jeden vendor) — assertEquals proti referenčním souborům.
- XSD validation test (každý builder musí projít).
- Test multi-supplier isolation v reportech.

### 6.7 Release v4.0.0
Po fázi 5 odpočítat 1–2 týdny stabilizace v3.9.0 (community feedback). Pak:
- `VERSION` → 4.0.0
- `CHANGELOG.md` velký block „MyInvoice 4.0: kompletní DPH evidence"
- README hero update (převzít wording z forku — fork to napsal dobře)
- `manual.pdf` regenerace, landing site rebuild
- GitHub release notes + announcement
- Issue #2 (Pavel Třešňák) — reopen + closed s reference na v4.0

---

## Souhrn odhadů

| Fáze | Verze | Odhad | Kritičnost |
|---|---|---|---|
| 1 Přijaté faktury + sidebar | v3.5.0 | 2–3 dny | Foundation, blocking |
| 2 Import iDoklad/Fakturoid + PDF/AI | v3.6.0 | 5–6 dní | Onboarding leverage + ongoing |
| 3 Bank matching | v3.7.0 | 2 dny | Quick win |
| 4 Recurring purchase | — | **vyřazeno** | Design inspirace ve forku, neimplementovat |
| 5 **CRM (tržby/náklady/zisk/cashflow/forecast)** | v3.8.0 | 6–8 dní | **High user value**, replaces Stats |
| 6 DPH/KH/DPFO/DPPO | v3.9.0 → v4.0.0 | 5–7 dní + 1–2 týdny stab. | **Risk concentrate** |
| **Celkem** | | **~4 týdny work + 2 stab.** | |

## Rizika a mitigace

1. **EPO XML regulační riziko** — XSD se mění ročně. Mitigace: prominent disclaimer, AUDIT_MFCR.md, version-pin v UI ("Generuje verze DPHDP3 03.01 platnou pro 2026").
2. **Migrace renumber konflikt** s forkery — naše čísla 0026+ s vlastním obsahem. Mitigace: dokumentovat ve `docs/MIGRACE.md` že jsme od forku divergovali.
3. **Multi-supplier breakage** — všechny nové akce musí mít `SupplierScopeMiddleware`. Mitigace: integration test per fáze ověří isolation.
4. **Vendor naming collision** — důsledně `vendor_id` všude. Mitigace: code review focus.
5. **Sidebar redesign rozbije existující UI** — proběhne ve fázi 1, dostatečně rozsáhlé. Mitigace: manuální QA na všech existujících stránkách před mergem fáze 1.
6. **CRM výkon u velkých dat** — `crm_monthly_summary` pre-aggregation chrání před table scans. Mitigace: load test s 10k vydaných + 5k přijatých faktur před mergem fáze 5.
7. **Forecasting credibility** — naivní algoritmus může produkovat divné čísla pro malý vzorek dat. Mitigace: pokud <6 měsíců historie, schovat forecast section a ukázat „pro predikce potřebujeme alespoň 6 měsíců historie".

## Otevřené otázky před startem

1. **Confirm verze 4.0** jako cílový tag pro fázi 6 (vs třeba v5.0)?
2. **EPO testovací data** — máš sám sebe jako test case nebo budeme používat fork test data set?
3. **Pořadí fází** — souhlas s 1→2→3→5→6? Alternativa: 1→5→2→3→6 (CRM hned po základním foundation).
4. **Disclaimer text** pro fázi 6 — chceš sám nadiktovat finální wording nebo převzít z forku a upravit?
5. **CRM expense_categories — seed defaultů?** — Navrhuju standardní sadu CZ OSVČ: hosting, software, kancelář, doprava, telekomunikace, marketing, externí služby, vzdělávání, ostatní. Souhlas?
6. **CRM forecast horizon** — default 3 měsíce dopředu (vidím v mnoha SaaS), max 12 měsíců?
