# MyInvoice.cz — Funkční a technická specifikace

> Verze: 1.0 · Datum: 2026-05-02 · Autor: MyWebdesign.cz s.r.o.

## 1. Účel a rozsah

MyInvoice.cz je **multi-supplier fakturační systém** určený pro vystavování faktur českým a EU klientům. Z jedné instalace lze fakturovat za libovolný počet dodavatelů (firem / IČ) s plně izolovanými daty. Klade důraz na rychlou tvorbu opakovaných faktur (z předchozí faktury, ze zakázky), automatické generování QR plateb (CZK SPAYD, EUR SEPA EPC) a doprovodný **výkaz víceprací** jako přílohu PDF.

### Cílové prostředí
- Produkce: **https://myinvoice.cz**
- Vývoj: **https://dev.myinvoice.cz**
- Hosting: **IIS i Apache** (oba musí fungovat — repo obsahuje `web.config` i `.htaccess`)

### Tech stack
| Vrstva | Volba |
|---|---|
| Backend | **PHP 8.5** (Slim 4.13 + ADR pattern, PSR-7/PSR-15, PHP-DI 7) |
| Databáze | **MariaDB 10.6+** (instance `c:/inetpub/MariaDB`, db `myinvoice`, user `root`, heslo v `cfg.local.php`) |
| Cache / brute-force | **Redis** přes **predis 3** pokud je dostupný; fallback **MariaDB MEMORY engine** |
| PDF | **mPDF 8.2** |
| QR platby | **rikudou/czqrpayment 5** (CZK SPAYD), **smhg/sepa-qr-data 3** (EUR SEPA EPC), **chillerlan/php-qrcode 6** renderer |
| Frontend | **Vue 3.5** (Composition API, `<script setup>`) + **Vite 8** + **Pinia 3** + **vue-router 5** + **Tailwind CSS 4** + **VueUse 14** + **axios 1.16** + **TypeScript 5.7** |
| Grafy | **Chart.js 4** + **vue-chartjs 5** |
| i18n | **vue-i18n 11** (CZ/EN), backend Symfony Translation pro PDF/email |
| Email | **Symfony Mailer 8** + **Symfony Mime 8** přes SMTP (s OAuth2 / DKIM podporou), šablony **Twig 3.10** |
| Validace / sanitizace | **respect/validation 3**, **enshrined/svg-sanitize 0.22**, **guzzlehttp/guzzle 7.9** (ARES/VIES) |
| Auth | session-based (HTTP-only cookie, SameSite=Lax) + CSRF token; **bez JWT** (server-side trust, ne SPA na různých doménách) |
| Logging | **Monolog 3.7** → rotující soubor + DB tabulka `activity_log` |
| Testy / kvalita | **PHPUnit 13**, **PHPStan 2**, **php-cs-fixer 3**, **vue-tsc 2** |
| Build | **Composer 2** (PHP), **pnpm 10** + **Node.js 22+** (JS), GitHub Actions CI |

### Vizuální styl
- Paleta: **emerald-600 / zinc-900** (finančně-důvěryhodný), accent blue-500
- Font: **Inter** (UI), **Geist Mono** (čísla, varsymbol)
- Radius 6px, jemné stíny, světlé pozadí
- Logo: SVG, monogram **M** se zeleným checkmarkem

---

## 2. Uživatelé a autentizace

### 2.0 First-run setup (prvotní spuštění)
Po čerstvé instalaci (prázdná tabulka `users`) je celá aplikace zamčena na **setup wizard**. Žádný jiný endpoint kromě `/api/health` a setup endpointů neodpovídá (vrací 423 *Locked* s instrukcí na `/setup`).

#### Frontend
Pokud `GET /api/auth/setup-status` vrátí `{ "needs_setup": true }`, router přesměruje na `/setup` (přebíjí všechny ostatní routes).

#### Setup wizard (3 kroky)
1. **Krok 1 — Administrátor:**
   - Jméno (povinné)
   - Email (povinné, validace formátu)
   - Heslo (min. 12 znaků, sila indikátor)
   - Heslo znovu
2. **Krok 2 — Dodavatel (volitelné, lze přeskočit a doplnit později):**
   - Firma / jméno OSVČ
   - IČ → tlačítko *Načíst z ARES* předvyplní zbytek
   - Adresa, DIČ, email, telefon
   - První bankovní účet (CZK): číslo + kód banky
3. **Krok 3 — Hotovo:** výzva k přihlášení s nově vytvořenými údaji.

#### Backend
- `GET /api/auth/setup-status` — jediný always-available endpoint. Vrací `{ "needs_setup": <bool>, "version": "..." }`.
- `POST /api/auth/setup` — proběhne jen pokud `users` tabulka je prázdná (idempotence + ochrana). Body:
  ```json
  {
    "admin": { "name": "...", "email": "...", "password": "..." },
    "supplier": { ... }   // volitelné
  }
  ```
  V transakci: vytvoří admin usera (`role='admin'`), případně vyplní `supplier` (id=1). Loguje `setup.completed`.
- Po setupu jsou ostatní endpointy povolené.
- Setup je **jednorázový** — opakovaný `POST /api/auth/setup` po vytvoření prvního usera vrátí 409.

> Bezpečnost: setup má rate-limit 5/hodina/IP a logguje IP, aby se zabránilo race-condition zneužití při deploy okně. V produkci doporučeno spustit setup ihned po deploy z důvěryhodné IP.

### 2.1 Model uživatele
Multi-supplier: N dodavatelů (firem / IČ) v jedné instalaci, plně izolovaná data. M uživatelů má přístup ke všem dodavatelům přes přepínač v UI (X-Supplier-Id header). Typický scénář: 1 hlavní uživatel + 1 účetní s read-only přístupem, 1–5 dodavatelů.

| Pole | Typ | Pozn. |
|---|---|---|
| email | VARCHAR(190) UNIQUE | login |
| password_hash | CHAR(60) | bcrypt cost 12 |
| name | VARCHAR(120) | |
| role | ENUM('admin','accountant','readonly') | RBAC |
| locale | ENUM('cs','en') | DEFAULT 'cs' |
| created_at, last_login_at | TIMESTAMP | |

### 2.2 Přihlášení
- POST `/api/auth/login` přijme `{email, password}`, vrátí session cookie + CSRF token v `X-CSRF-Token` headeru.
- Hesla bcrypt, cost 12, peppered (pepper z env `APP_PEPPER`).
- Session uložena v Redis (klíč `sess:<id>`, TTL 30 dní), fallback do `sessions` tabulky.

### 2.3 Brute-force ochrana
**Klíč: `bf:<sha1(email)>:<ip_class_c>`**, sliding window, fail2ban algoritmus:

| Pokusy během | Stav |
|---|---|
| 5 selhání / 5 min | CAPTCHA (Cloudflare Turnstile) |
| 10 selhání / 15 min | Lockout 15 min |
| 30 selhání / hod | Lockout 24 h + email upozornění userovi |

- Implementace: pokud `REDIS_URL` v env, použij Redis (`INCR` + `EXPIRE`).
- Fallback: tabulka `login_attempts` typu `ENGINE=MEMORY` s indexem na (`bucket_key`, `created_at`), čištění cron každých 5 min.
- IP třída /24 zabraňuje obcházení přes IPv4 sousedy; pro IPv6 použij /64.
- **Konstantní časové porovnání** (`hash_equals`) a `password_verify` se vždy spustí (i pro neexistující email — přidá dummy hash) → ochrana proti user enumeration.

### 2.4 Změna hesla
- `POST /api/auth/change-password` `{old, new, new_confirm}`
- Vyžaduje aktuální heslo, invaliduje všechny ostatní session (kromě té současné).
- Min. 12 znaků, kontrola HIBP top-1000 wordlist (offline).

### 2.5 Obnova zapomenutého hesla
1. `POST /api/auth/forgot` `{email}` — vždy vrátí 200 (nehlásí, zda email existuje).
2. Pokud email existuje, vygeneruje token (32B random), uloží `password_resets(token_hash, user_id, expires_at)` s TTL 60 min.
3. Pošle email s linkem `https://myinvoice.cz/reset?token=...`.
4. `POST /api/auth/reset` `{token, password}` — ověří token (`hash_equals` s `sha256` z DB), nastaví heslo, smaže token, invaliduje všechny session.
5. Rate-limit: max 3 forgot/email/hodina.

---

## 3. Doménový model

```
supplier (1)
 ├── supplier_bank_accounts (N) — CZK: účet/banka, EUR: IBAN
 │
 └── clients (N)
      ├── client_billing_emails (1..3) + main email (povinný)
      └── projects (N) — "zakázky"
           └── invoices (N)
                ├── invoice_items (N)
                └── work_report (0..1)
                     └── work_report_items (N)
```

### 3.1 Dodavatel (Supplier)
Jediný řádek. Pole: firma, jméno (pro OSVČ), ulice, město, PSČ, stát, IČ, DIČ, web, telefon, email, default měna (CZK/EUR), default sazba DPH, default splatnost (7 dní), default hodinová sazba (1500 Kč/h bez DPH).

**Bankovní účty 1:N**, každý účet má:
- `currency` ENUM('CZK','EUR','USD',...)
- `is_default_for_currency` (jeden default per měna)
- CZK: `account_number` (např. `1000000005`, volitelně s prefixem `19-1000000005`), `bank_code` (4 číslice), `bank_name`
- EUR/jiné: `iban`, `bic`, `bank_name`

### 3.2 Klient
| Pole | Typ | Povinné |
|---|---|---|
| firma | VARCHAR(190) | Y (nebo jméno+příjmení pro fyzické osoby) |
| ic | VARCHAR(10) | N |
| dic | VARCHAR(20) | N |
| ulice, mesto, psc, stat | … | Y (Y) Y Y |
| main_email | VARCHAR(190) | **Y** |
| language | ENUM('cs','en') | DEFAULT 'cs' |
| currency_default | CHAR(3) | DEFAULT 'CZK' |
| reverse_charge | TINYINT(1) | N — pokud TRUE, faktury default DPH 0% |
| payment_due_default | INT | DEFAULT 7 (přepíše default ze supplieru) |
| note | TEXT | |

**ARES lookup** (`GET /api/aresvies/ares?ic=...`) předvyplní firma/ulice/město/PSČ/DIČ.
**VIES lookup** (`GET /api/aresvies/vies?vat=CZ...`) ověří DIČ pro EU.

### 3.3 Zakázka (Project)
1:N s klientem. Pole:
| Pole | Typ | Povinné | Default |
|---|---|---|---|
| name | VARCHAR(190) | **Y** | |
| payment_due_days | INT | Y | **7** |
| project_number | VARCHAR(50) | N | |
| contract_number | VARCHAR(50) | N | |
| budget_total | DECIMAL(12,2) | N | |
| budget_yearly | DECIMAL(12,2) | N | |
| budget_monthly | DECIMAL(12,2) | N | |
| hourly_rate | DECIMAL(10,2) | N | **1500.00** (CZK bez DPH) |
| currency | CHAR(3) | Y | dědí z klienta |
| status | ENUM('active','paused','closed') | Y | 'active' |

**Fakturační emaily zakázky** — 0 až 3 emaily uložené v `project_billing_emails` (`position` 1/2/3, volitelný `label` např. „účetní"). Každá zakázka může mít vlastní emaily (různé kontakty per zakázka u stejného klienta).

UI varuje, pokud při tvorbě faktury měsíční/roční fakturace přesáhne `budget_*`.

### 3.4 Faktura (Invoice)

#### 3.4.0 Typy dokladů
Systém rozeznává 4 typy faktur (sloupec `invoice_type`):

| Typ | Název | DUZP | Účel | Vazba |
|---|---|---|---|---|
| `proforma` | Zálohová faktura | **NE** | Výzva k úhradě zálohy/celku před plněním | žádná |
| `invoice` | Faktura — daňový doklad | ANO | Standardní vyúčtování | volitelně `parent_invoice_id` na proformu |
| `credit_note` | Dobropis (opravný daňový doklad) | ANO | Snížení/storno původní faktury | povinně `parent_invoice_id` na `invoice` |
| `cancellation` | Storno (interní zrušení) | — | Pouze flag, bez účetního dopadu | povinně `parent_invoice_id` |

**Storno vs. dobropis:**
- **Storno** (`cancellation`) — měkké zrušení faktury, která ještě nešla do účetnictví / nebyla nikdy odeslána klientovi (v praxi typicky chybně vystavená). Nevytváří doklad pro klienta, jen interně. Setuje na původní faktuře `cancelled_at` a vyřadí ji ze sumací.
- **Dobropis** (`credit_note`) — opravný daňový doklad. Vytvoří se nová faktura se zápornými hodnotami a `parent_invoice_id` na původní. Klient ji dostane. Původní faktura zůstává v platnosti, sumace se počítají včetně dobropisu (záporně).

UI nabídne při zrušení vystavené faktury volbu: *„Pouze storno (interní)"* nebo *„Vystavit dobropis"*.

#### 3.4.1 Pole faktury
| Pole | Typ | Pozn. |
|---|---|---|
| invoice_type | ENUM('proforma','invoice','credit_note','cancellation') | DEFAULT 'invoice' |
| varsymbol | VARCHAR(20) UNIQUE | viz 3.4.2 |
| parent_invoice_id | FK NULL | proforma → invoice; invoice → credit_note/cancellation |
| client_id | FK | |
| project_id | FK NULL | |
| issue_date | DATE | dnes |
| tax_date (DUZP) | DATE NULL | **NULL pro proformu**, jinak dnes |
| due_date | DATE | issue_date + project.payment_due_days |
| currency | CHAR(3) | CZK / EUR |
| bank_account_id | FK | |
| reverse_charge | TINYINT(1) | |
| language | ENUM('cs','en') | |
| note_above_items, note_below_items | TEXT | |
| total_without_vat, total_vat, total_with_vat | DECIMAL(12,2) | computed |
| **advance_paid_amount** | DECIMAL(12,2) | suma odečtených záloh (jen u finální faktury vystavené z proformy); 0 jinak |
| **amount_to_pay** | DECIMAL(12,2) | computed = `total_with_vat - advance_paid_amount` (nesmí být záporné) |
| paid_at | DATE NULL | |
| sent_at | TIMESTAMP NULL | |
| status | ENUM('draft','issued','sent','paid','cancelled') | |
| pdf_path | VARCHAR(255) NULL | |
| created_by, created_at, updated_at | | |

#### 3.4.2 Číselná řada (varsymbol)
Generuje se při přechodu draft → issued. Per měsíc samostatný čítač, **odlišený podle typu**.

**Konfigurovatelné přes `cfg.varsymbol.templates`** s placeholdery:
- `{YYYY}` — rok 4 cifry (`2026`)
- `{YY}` — rok 2 cifry (`26`)
- `{MM}` — měsíc 2 cifry (`04`)
- `{C}` až `{CCCCCC}` — counter padded podle počtu znaků (`{CCC}` = 3 cifry → `001`)

**Default templates:**

| Typ | Template | Příklad |
|---|---|---|
| `invoice` | `{YY}{MM}{CCC}` | `2604001` |
| `proforma` | `9{YY}{MM}{CCC}` | `92604001` (prefix 9 = záloha) |
| `credit_note` | `7{YY}{MM}{CCC}` | `72604001` (prefix 7 = dobropis) |
| `cancellation` | — (nedostává varsymbol, jen interní) |

Counter atomicky inkrementován v tabulce `invoice_counters (invoice_type, period, last_number)` přes `INSERT ... ON DUPLICATE KEY UPDATE last_number = last_number + 1`. Reset na `1` každý měsíc, samostatně per typ.

`VarsymbolGenerator::preview(type, date)` vrátí náhled bez inkrementu (pro UI).

> Důvod prefixů: účetní programy (Pohoda apod.) snadno rozlišují typ dokladu už podle prefixu var. symbolu.

#### 3.4.1 Položky faktury
| Pole | Pozn. |
|---|---|
| description | text |
| quantity | DECIMAL(10,3) |
| unit | VARCHAR(20) (ks, h, …) |
| unit_price_without_vat | DECIMAL(12,2) |
| vat_rate_id | FK do `vat_rates` (default 21%) |
| reverse_charge_override | TINYINT(1) NULL |
| order_index | INT |
| linked_work_report_id | FK NULL — pokud položka reprezentuje sumu z výkazu |

#### 3.4.3 Číselník DPH
Tabulka `vat_rates`: `(id, code, rate_percent, country, valid_from, valid_to, is_default)`. Předvyplněno:
- `CZ-21` (21%, default), `CZ-15` (15%, snížená), `CZ-12` (12%, druhá snížená — od 2024), `CZ-0` (0%), `CZ-RC` (0% reverse charge).

#### 3.4.4 Číselník měn
Aktuálně podporované: **CZK** (default) a **EUR**. Tabulka `currencies` je rozšiřitelná, ale UI zatím nabídne jen tyto dvě. Přidání další měny v budoucnu = jeden INSERT + případně doplnit kurz, žádný kód neměnit.

### 3.5 Výkaz víceprací (Work Report)
0..1 vázáno na fakturu. Pole:
- `title` — editovatelný, default `Vícepráce za měsíc M/YYYY`
- `project_id` (FK) — výběr zakázky (zděděno z faktury, lze přepsat)
- `items[]` — `description, hours DECIMAL(6,2), rate (default project.hourly_rate)`, `total = hours * rate` (computed v PHP, v DB jen základ)

Sumace výkazu (`SUM(hours * rate)`) se přidá jako jedna položka faktury s názvem `Vícepráce za měsíc M/YYYY` a flagem `linked_work_report_id`. Výkaz se v PDF renderuje **na další stránce** za fakturou.

---

## 4. Klíčové use-cases

### 4.1 Vytvoření faktury ze zakázky
1. User klikne *Nová faktura* → modal: vybrat klienta (autocomplete) → vybrat zakázku (nebo *+ Nová zakázka*).
2. Předvyplní se: `client_id`, `project_id`, `currency`, `due_date = today + project.payment_due_days`, `bank_account_id` (default pro currency), `reverse_charge` z klienta, `language` z klienta.
3. Žádné položky — user přidá ručně, případně přidá výkaz.

### 4.2 Vytvoření faktury z předchozí faktury (klonování)
1. *Klonovat* → vybere se zdroj.
2. Zkopíruje se vše kromě `id`, `varsymbol`, `paid_at`, `sent_at`, `status`, `parent_invoice_id`, snapshots. Nový status = `draft`. Datumy se přepočítají od dneška.
3. **Auto-increment měsíce v popiscích položek**: pokud `description` matchuje regex `\b(\d{1,2})/(\d{4})\b` (např. `3/2026`), zvedne se měsíc o 1 (s přechodem do dalšího roku). Toto se aplikuje na všechny položky **a** na `work_report.title` + položky výkazu.
4. Logika: `'Konzultace 3/2026'` → `'Konzultace 4/2026'`; `'Vícepráce 12/2025'` → `'Vícepráce 1/2026'`.

### 4.3 Hromadné „Vystavit znovu" (re-issue)
Účel: každý měsíc opakuji ~10 stejných faktur s posunutým měsícem v popisku. Toto je shortcut pro ně všechny najednou.

1. V listu faktur user vyfiltruje předchozí měsíc (`filter[year]=2026&filter[month]=4`) a označí checkboxem N faktur.
2. Toolbar nabídne tlačítko **„Vystavit znovu pro další měsíc"**.
3. Pro každou označenou fakturu:
   - vytvoří se klon (logika z 4.2 — auto-increment měsíce v popiscích, datumy = dnes)
   - **status zůstává `draft`** (nikdy se neodešle automaticky)
   - **email se neposílá**
4. Po dokončení toast: *„Vytvořeno N konceptů. Zkontrolujte je v listu faktur (filtr: koncepty)."*
5. User pak ručně otevírá každý koncept, ověří částky/popis a klikne **Vystavit** + případně **Poslat**.

> Bezpečnost: hromadná akce nikdy negeneruje varsymbol ani neodesílá. Vždy je vyžadován manuální krok per faktura.

### 4.4 Zálohová faktura → finální faktura

#### 4.4.1 Vystavení zálohové faktury (proforma)
1. *Nová faktura* → user zvolí typ **Zálohová faktura**.
2. Editor je stejný jako standardní, ale:
   - `tax_date` (DUZP) je **skryté** (proforma nemá DUZP)
   - V hlavičce je výrazně označeno *„ZÁLOHOVÁ FAKTURA — není daňový doklad"*
   - V PDF chybí kolonka DUZP, místo „Faktura č." se vypíše „Zálohová faktura č."
3. Po vystavení získá `varsymbol` s prefixem `9` (např. `9260400001`).
4. Klient zaplatí — user označí proformu jako `paid` (`paid_at`).

#### 4.4.2 Vystavení daňového dokladu z proformy
1. Z detailu proformy (status `paid`) tlačítko **„Vystavit daňový doklad k záloze"**.
2. Vytvoří se nový draft typu `invoice` s:
   - `parent_invoice_id` = id proformy
   - **kopie všech položek** z proformy
   - `tax_date` = dnes (DUZP)
   - `due_date` = dnes (lze přepsat)
   - `advance_paid_amount` = celková částka zaplacené proformy (z `paid_amount` proformy nebo `total_with_vat`)
3. UI v editoru zobrazí infobox:
   ```
   Tato faktura je vystavená k zálohové faktuře 9260400001
   ze dne 1.4.2026 (zaplaceno: 100 000 Kč).

   Celkem fakturováno:        100 000 Kč
   Odečet zaplacené zálohy:  -100 000 Kč
   ─────────────────────────────────────
   K úhradě:                        0 Kč
   ```
4. Pole `advance_paid_amount` je editovatelné — typicky se nechá rovno celé záloze, ale lze odečíst i jen část (částečná záloha).
5. PDF této faktury obsahuje samostatný řádek pod sumací: *„Odečet zálohy (faktura 9260400001 ze dne 1.4.2026): -100 000 Kč"* a finální *„K úhradě: 0 Kč"*.
6. Pokud `amount_to_pay = 0`, neukáže se QR kód k platbě (zaplaceno).

### 4.5 Storno a dobropis
Z detailu vystavené faktury (status ≠ `draft`) je dostupné tlačítko **„Zrušit fakturu"** s volbou:

**Varianta A — Pouze storno (interní):**
- Pro chyby zachycené dřív, než se faktura dostala do účetnictví / k zákazníkovi.
- Vytvoří záznam typu `cancellation` s `parent_invoice_id`.
- Původní faktura dostane `cancelled_at`, status = `cancelled`.
- Žádný PDF doklad pro klienta.
- V sumacích se původní faktura nezapočítává.

**Varianta B — Vystavit dobropis (opravný daňový doklad):**
- Pro faktury, které už klient přijal a evidoval.
- Vytvoří se nový draft typu `credit_note` s `parent_invoice_id`.
- Položky se zkopírují **se zápornými hodnotami** (UI je editovatelné — lze dobropisovat jen část).
- DUZP = dnes, splatnost = dnes (typicky bezprostřední vrácení peněz).
- PDF má hlavičku *„Opravný daňový doklad č. 7260400001"* a viditelnou referenci na původní fakturu.
- Po vystavení a odeslání: status původní = `cancelled`, dobropis = `issued`.
- V sumacích se započítávají oba (kladná původní + záporný dobropis).

### 4.6 Reverse charge
- Pokud klient má `reverse_charge = TRUE`, default vat_rate = `CZ-RC` (0%).
- V PDF se zobrazí poznámka: `„Daň odvede zákazník" / "Reverse charge — VAT to be accounted by the customer"`.

### 4.7 Operace s fakturou
- **Uložit / upravit** — povolené dokud `status='draft'`.
- **Smazat** — jen `draft`. Po `issued` lze pouze storno nebo dobropis (viz 4.5).
- **Zobrazit** — HTML render (server-side z Twig šablony, sdílí CSS s PDF).
- **Stáhnout PDF** — `GET /api/invoices/{id}/pdf` → mPDF, cache do `storage/invoices/YYYY-MM/Faktura-YY-MM-NNN.pdf`.
- **Poslat emailem** — `POST /api/invoices/{id}/send` → použije šablonu, přiloží PDF, pošle na `client.main_email + project.billing_emails[]` (fakturační emaily zakázky). Pokud faktura nemá `project_id`, jen `client.main_email`. Loguje do `activity_log` a nastaví `sent_at`.
- **Test odeslání** — `POST /api/invoices/{id}/send-test` → pošle **pouze na `cfg.smtp.from_email`** s prefixem `[TEST]` v subjectu. Neovlivní `sent_at` ani status faktury, funguje i pro draft. Pro náhled emailu (DKIM, formátování, příloha) před ostrým odesláním klientovi.

### 4.8 Import bankovních výpisů (GPC)

Automatické párování plateb na faktury podle var. symbolu.

#### Formát
**ABO/GPC** — český standard pro bank. výpisy (Komerční banka, ČSOB, ČS, Air Bank, Fio, Raiffeisenbank — všichni umí export do GPC). Pevná šířka řádků v Windows-1250 nebo UTF-8.

```
074 ... záhlaví výpisu (číslo účtu, jméno, počáteční/koncový stav, datum)
075 ... řádek transakce (částka, typ, VS/KS/SS, popis, datum)
078 ... avizo (textové popisy)
079 ... patička
```

Klíčová pole z řádku **075**:
| Offset | Délka | Pole | Příklad |
|---|---|---|---|
| 4 | 16 | účet protistrany | `0000001000000005` |
| 20 | 13 | částka v haléřích | `0000000254100` (= 2 541 Kč) |
| 33 | 1 | typ transakce | `1` = příchozí, `2` = odchozí |
| 34 | 14 | variabilní symbol | `0000202604001` |
| 48 | 10 | konstantní symbol | `0000000308` |
| 58 | 14 | specifický symbol | (často 0) |
| 73 | 6 | datum valuty | `260415` (15.4.2026) |
| 79 | 1 | identifikace měny | (rozšířený formát) |

#### DB schéma — nová tabulka

```sql
CREATE TABLE bank_statements (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filename        VARCHAR(255) NOT NULL,
  file_hash       CHAR(64) NOT NULL,                  -- sha256 obsahu, idempotence
  account_number  VARCHAR(30) NOT NULL,
  bank_code       CHAR(4) NULL,
  currency        CHAR(3) NOT NULL,
  statement_date  DATE NOT NULL,
  opening_balance DECIMAL(12,2) NULL,
  closing_balance DECIMAL(12,2) NULL,
  transactions_count INT UNSIGNED NOT NULL DEFAULT 0,
  matched_count   INT UNSIGNED NOT NULL DEFAULT 0,
  source          ENUM('upload','scan') NOT NULL,
  source_path     VARCHAR(500) NULL,                  -- pro scan: relativní cesta k souboru
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_bs_hash (file_hash)
) ENGINE=InnoDB;

CREATE TABLE bank_transactions (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  statement_id      BIGINT UNSIGNED NOT NULL,
  direction         ENUM('in','out') NOT NULL,
  amount            DECIMAL(12,2) NOT NULL,
  currency          CHAR(3) NOT NULL,
  counterparty_account VARCHAR(40) NULL,
  variable_symbol   VARCHAR(20) NULL,
  constant_symbol   VARCHAR(10) NULL,
  specific_symbol   VARCHAR(20) NULL,
  description       VARCHAR(255) NULL,
  value_date        DATE NOT NULL,
  matched_invoice_id BIGINT UNSIGNED NULL,            -- pokud spárováno
  match_confidence  ENUM('exact','partial','none') NOT NULL DEFAULT 'none',
  matched_at        TIMESTAMP NULL,
  matched_by        BIGINT UNSIGNED NULL,             -- user kdo spárování potvrdil
  KEY idx_bt_statement (statement_id),
  KEY idx_bt_vs (variable_symbol),
  KEY idx_bt_invoice (matched_invoice_id),
  CONSTRAINT fk_bt_statement FOREIGN KEY (statement_id) REFERENCES bank_statements(id) ON DELETE CASCADE,
  CONSTRAINT fk_bt_invoice   FOREIGN KEY (matched_invoice_id) REFERENCES invoices(id) ON DELETE SET NULL,
  CONSTRAINT fk_bt_user      FOREIGN KEY (matched_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
```

#### Použití

**A) Upload manuální** (Settings → Bankovní výpisy → Upload GPC):
- `POST /api/bank-statements/upload` — multipart soubor
- Validace formátu, parse, dedupe podle `file_hash`
- Auto-match transakcí na faktury podle VS

**B) Scan adresáře** (Settings → Bankovní výpisy → Konfigurace scan):
- `cfg.bank_import.scan_root` — kořenový adresář (např. `c:/work/active/bank/`)
- Konvence: `<scan_root>/<YYYY-MM>/*.gpc` nebo `*.abo`
- `POST /api/bank-statements/scan` — manual trigger
- Cron `bin/cron-bank-scan.php` — denně

#### Auto-match logika

Pro každou příchozí transakci s `variable_symbol`:

1. **Exact match:** `amount = invoice.amount_to_pay` AND `variable_symbol = invoice.varsymbol` AND `currency = invoice.currency` → `match_confidence = 'exact'`
2. **Partial match:** VS sedí, ale částka se liší (např. částečná platba) → `match_confidence = 'partial'` + flagovat k manuální kontrole
3. **None:** žádná shoda → ručně přiřadit z UI

Pokud `match_confidence = 'exact'`:
- Faktura se automaticky označí jako `paid`, `paid_at = transaction.value_date`
- Activity log: `invoice.paid_via_bank` s reference na transaction

#### Frontend

**Nová stránka `/bank`:**
- Seznam výpisů (datum, soubor, počet trans / spárováno)
- Klik na výpis → detail s tabulkou transakcí
- Filtry: nespárované / odchozí / podezřelé
- Tlačítko *Upload GPC* + *Spustit scan*
- U každé transakce: pokud nespárované, autocomplete pole pro výběr faktury → manual link

**Konfigurace v Settings:**
- Cesta k scan rootu
- Auto-scan zapnutý? (cron)
- Notifikace na email při unmatchovaných transakcích nad X CZK

#### Konfigurace `cfg.bank_import`

```php
'bank_import' => [
    'enabled'         => true,
    'scan_root'       => 'c:/work/active/bank',     // kořenový adresář, podadresáře YYYY-MM
    'scan_pattern'    => '*.gpc,*.abo',
    'scan_recursive'  => true,
    'auto_mark_paid'  => true,                      // u exact match nastavit invoice.paid
    'unmatched_alert' => 1000,                      // CZK — email upozornění při příchozí > X bez matche
    'cron_interval'   => '0 8 * * *',               // 08:00 každý den
],
```

#### Knihovny / implementace

- Vlastní GPC parser (formát je jednoduchý, ~200 řádků PHP) v `Service/Bank/GpcParser.php`
- Encoding detection (Windows-1250 vs UTF-8) přes `mb_detect_encoding`
- Hash souboru = `hash_file('sha256', $path)` pro dedupe

> Implementace plánovaná v **M5** (po M4 = PDF + email + odeslání faktur).

### 4.10 Aktivity log
Tabulka `activity_log`: `(id, user_id, entity_type, entity_id, action, payload JSON, ip, user_agent, created_at)`. Akce mj.: `auth.login`, `auth.login_failed`, `auth.password_changed`, `invoice.created`, `invoice.issued`, `invoice.sent`, `invoice.paid`, `invoice.cancelled`, `invoice.credit_note_issued`, `invoice.reissued_bulk`, `proforma.final_issued`, `client.created`, `client.updated`, `project.created`, `email.sent`, `pdf.generated`.

---

## 5. PDF a QR platba

### 5.1 mPDF setup
- Page A4, margin 15mm.
- Font: **DejaVu Sans** (mPDF default, plná diakritika).
- Šablona: `templates/invoice/invoice.twig` → vyrendrované HTML → mPDF.
- Outputname: `Faktura-YY-MM-NNN.pdf` (ne plný rok, kvůli inspiraci `Faktura-26-04-001.pdf`).

### 5.2 QR platba
Re-use logiky z `c:/work/prazdroj.cz/api/Web/Payment.php::qrCode()`:

```php
// CZK → SPAYD
new \Rikudou\CzQrPayment\QrPayment(
    new \Rikudou\Iban\Iban\CzechIbanAdapter($account, $bankCode)
);

// EUR / ostatní → SEPA EPC
(new \SepaQr\SepaQrData())
    ->setName($supplierName)
    ->setIban($iban)
    ->setRemittanceText('Invoice-'.$varsymbol)
    ->setAmount($amount);

// Render
new \chillerlan\QRCode\QRCode($qrOptions)->render($payload);
```

Vrací data URI, vloží se jako `<img src>` do PDF i HTML viewu.

### 5.3 Renderování faktury
Inspirace: `c:/work/prazdroj.cz/api/Core/Faktura.php::renderIt()`.

Layout (jedna A4 strana standardně):
1. Hlavička: logo vlevo, **„Faktura — DAŇOVÝ DOKLAD" + číslo** vpravo
2. Tabulka 50/50: dodavatel | odběratel
3. Metadata: datum vystavení, DUZP, splatnost, var. symbol, způsob platby, měna, číslo zakázky
4. Tabulka položek: popis, množství, j.cena, DPH%, bez DPH, s DPH
5. Sumace: bez DPH, DPH (rozpis sazeb), **Celkem**
6. QR platba + bankovní spojení vpravo dole
7. Reverse charge poznámka (pokud aplikovatelná)
8. Volitelná poznámka pod položkami

Pokud existuje `work_report`: druhá strana = **Výkaz víceprací**, tabulka (popis, hodiny, sazba, celkem), součet musí odpovídat položce z faktury.

---

## 6. Email

### 6.1 SMTP

Plně konfigurovatelné přes `cfg.php` sekci `smtp`. Implementace v `Service/Mail/Mailer.php` postavena nad **Symfony Mailer**.

#### Implementační poznámky (Symfony Mailer)

```php
// Service/Mail/MailerFactory.php
public function create(array $cfg): MailerInterface {
    $dsn = $this->buildDsn($cfg);              // smtp://user:pass@host:port?encryption=ssl&...
    $transport = Transport::fromDsn($dsn, $logger);
    return new Mailer($transport);
}
```

DSN se sestaví z `cfg.smtp.*`:
- `smtp://user:pass@smtp.seznam.cz:465?encryption=ssl` (SSL/465)
- `smtp://user:pass@smtp.gmail.com:587?encryption=tls&auth_mode=login` (STARTTLS/587)
- `smtp://127.0.0.1:1025` (lokální MailHog, bez auth)
- `gmail+oauth://USER:REFRESH_TOKEN@default?client_id=...&client_secret=...` (OAuth2 přes `symfony/google-mailer`)

Příklad odeslání faktury:
```php
$email = (new Email())
    ->from(new Address($cfg['smtp']['from_email'], $cfg['smtp']['from_name']))
    ->to(...$recipients)
    ->subject($subject)
    ->html($twig->render("email/invoice_send.{$lang}.twig", $vars))
    ->text($twig->render("email/invoice_send.{$lang}.txt.twig", $vars))
    ->attachFromPath($pdfPath, "Faktura-{$invoice->varsymbol}.pdf", 'application/pdf');

if ($cfg['smtp']['dkim']['enabled']) {
    $signer = new DkimSigner($cfg['smtp']['dkim']['private_key'],
                             $cfg['smtp']['dkim']['domain'],
                             $cfg['smtp']['dkim']['selector']);
    $email = $signer->sign($email);
}

$mailer->send($email);  // Symfony Mailer řeší TLS, retry, transport errors
```

Závislosti (Composer):
- `symfony/mailer` ^7.x
- `symfony/mime` ^7.x (auto)
- `symfony/google-mailer` ^7.x (volitelné, pro Gmail OAuth2)
- `symfony/microsoft-mailer` ^7.x (volitelné, pro M365 OAuth2)

#### Connection
| Klíč | Hodnoty | Default | Popis |
|---|---|---|---|
| `host` | string | `''` | SMTP server hostname (např. `smtp.seznam.cz`, `smtp.gmail.com`) |
| `port` | int | `465` | `25` plain (NEDOPORUČENO), `465` SMTPS, `587` STARTTLS, `2525` alt |
| `encryption` | `'ssl' \| 'tls' \| ''` | `'ssl'` | TLS varianta. SSL = implicit TLS na portu 465. TLS = STARTTLS na 587. Prázdné = plain (jen pro lokální dev relay). |
| `timeout` | int | `30` | Socket timeout v sekundách |

#### Authentication
| Klíč | Hodnoty | Popis |
|---|---|---|
| `auth_enabled` | bool | `false` = anonymous SMTP (např. lokální Postfix relay), `true` = vyžaduje credentials |
| `auth_type` | `'LOGIN' \| 'PLAIN' \| 'CRAM-MD5' \| 'XOAUTH2'` | Mechanismus. Většina poskytovatelů akceptuje `LOGIN`. Pro Gmail/M365 doporučeno `XOAUTH2`. |
| `user` | string | SMTP username (typicky email) |
| `pass` | string | SMTP password / app password |
| `oauth.provider` | `'google' \| 'microsoft' \| null` | Pokud `XOAUTH2` |
| `oauth.client_id`, `oauth.client_secret`, `oauth.refresh_token` | string | OAuth2 credentials |

#### Sender identity
| Klíč | Default | Popis |
|---|---|---|
| `from_email` | `faktury@myinvoice.cz` | Odesílatel (musí matchovat SMTP účet, jinak hrozí spam folder) |
| `from_name` | `MyInvoice` | Display name |
| `reply_to_email` | `''` | Reply-To header (pokud prázdné, použije se `from_email`) |
| `reply_to_name` | `''` | |

#### TLS validation
| Klíč | Default | Popis |
|---|---|---|
| `verify_peer` | `true` | Ověřit cert. **Vypnout JEN pro self-signed dev SMTP!** |
| `verify_peer_name` | `true` | Ověřit, že CN/SAN matchne hostname |
| `allow_self_signed` | `false` | Pro lokální MailHog/Mailpit |

#### Anti-spam / deliverability (DKIM)
| Klíč | Popis |
|---|---|
| `dkim.enabled` | `true` zapne podpis odchozí pošty |
| `dkim.domain` | `myinvoice.cz` |
| `dkim.selector` | např. `mail` (DNS: `mail._domainkey.myinvoice.cz` musí mít TXT s public key) |
| `dkim.private_key` | PEM private key (string nebo path k souboru) |
| `dkim.passphrase` | Pokud key je šifrovaný |

DKIM/SPF/DMARC infra se nastavuje na DNS úrovni (mimo aplikaci) — viz 9.12.

#### Behavior & retry
| Klíč | Default | Popis |
|---|---|---|
| `keepalive` | `false` | `true` = jeden persistent connection pro batch (rychlejší při hromadném sendu) |
| `charset` | `UTF-8` | |
| `encoding` | `8bit` | `7bit \| 8bit \| base64 \| quoted-printable` |
| `wordwrap` | `78` | Wrap po N znacích (RFC 5322) |
| `max_retries` | `3` | Počet retry pokud SMTP vrátí 4xx |
| `retry_delay_s` | `60` | Pauza mezi retry |

#### Debug
| Klíč | Hodnoty | Popis |
|---|---|---|
| `debug_level` | `0`–`4` | `0`=off, `1`=client, `2`=client+server, `3`=+connection, `4`=low-level. **Jen pro lokální debug!** V prod vždy `0`. |
| `debug_log_file` | string | Pokud nastaveno, debug do souboru místo stderr |

#### Příklady konfigurace

**Seznam.cz:**
```php
'host' => 'smtp.seznam.cz', 'port' => 465, 'encryption' => 'ssl',
'auth_enabled' => true, 'auth_type' => 'LOGIN',
'user' => 'faktury@myinvoice.cz', 'pass' => 'app-password',
```

**Gmail s App Password:**
```php
'host' => 'smtp.gmail.com', 'port' => 465, 'encryption' => 'ssl',
'auth_enabled' => true, 'auth_type' => 'LOGIN',
'user' => 'me@gmail.com', 'pass' => 'xxxx-xxxx-xxxx-xxxx',
```

**Gmail s OAuth2 (preferováno):**
```php
'auth_type' => 'XOAUTH2',
'user' => 'me@gmail.com',
'oauth' => [
    'provider' => 'google',
    'client_id' => '...',
    'client_secret' => '...',
    'refresh_token' => '...',
],
```

**Lokální dev (MailHog/Mailpit):**
```php
'host' => '127.0.0.1', 'port' => 1025, 'encryption' => '',
'auth_enabled' => false, 'verify_peer' => false,
```

**Self-hosted Postfix relay (no auth):**
```php
'host' => 'mail.internal', 'port' => 25, 'encryption' => 'tls',
'auth_enabled' => false,
```

#### Test endpoint
`POST /api/admin/smtp/test` (jen `admin`):
```json
{ "to": "test@example.com" }
```
Pošle test email s aktuální konfigurací, vrací plný debug log (pokud `debug_level > 0`) a status code z SMTP serveru. Užitečné pro ověření před prvním ostrým odesláním.

### 6.2 Šablony
Twig, soubory v `templates/email/`:
- `invoice_send.cs.twig` / `invoice_send.en.twig` — odeslání faktury
- `password_reset.cs.twig` / `password_reset.en.twig`
- `lockout_warning.cs.twig` — upozornění na 30 selhání

Render: server-side, vždy s plain-text alternativou.

### 6.3 Send invoice
```
POST /api/invoices/{id}/send
{
  "to": ["..."],          // override, jinak main + billing emails
  "cc": ["..."],
  "subject_override": "...",
  "body_override": "..."
}
```
Příloha: PDF, název `Faktura-YY-MM-NNN.pdf`. Logováno.

---

## 7. ARES & VIES

### 7.1 ARES
Endpoint: `https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/{ic}` (REST JSON, nový od 2024).
Parsování: `obchodniJmeno`, `sidlo.{nazevObce, psc, ulice, cisloDomovni}`, `dic`.
Cache: 24 h v Redis / DB (klíč `ares:<ic>`).

### 7.2 VIES
SOAP: `http://ec.europa.eu/taxation_customs/vies/services/checkVatService.wsdl`
Parametry: `countryCode` (první 2 znaky DIČ), `vatNumber` (zbytek).
Response: `valid` (bool), `name`, `address`. Cache 24 h.
Fallback při downtimu: zobraz warning, neblokuj uložení.

---

## 8. Nefunkční požadavky

| Atribut | Cíl |
|---|---|
| Doba odezvy API | p95 < 200ms (lokální DB) |
| PDF render | < 1.5s pro fakturu s 20 položkami + výkaz |
| Backup | denní mariadb-dump → `backup/myinvoice-YYYY-MM-DD.sql.gz`, retence 30 dní |
| HTTPS | vynucené, HSTS 1 rok, Let's Encrypt na obou doménách |
| Logy | rotace 90 dní (denně), `log/app.log` přes Monolog `RotatingFileHandler` |
| Účetní záloha | 1× měsíčně export všech `issued+paid` faktur jako ZIP PDF + CSV soupis (cron) |
| Browser support | Chrome/Edge/Firefox/Safari poslední 2 verze; ne IE11 |
| Accessibility | WCAG 2.1 AA pro hlavní UI |

---

## 9. Bezpečnost — komplexní specifikace

Aplikace pracuje s finančními daty a fakturami klientů. Bezpečnost je **prioritou** — implementujeme defense-in-depth: každá vrstva (transport, session, request, vstup, výstup, audit) má samostatnou ochranu.

### 9.1 Transport security (HTTPS / TLS)

- **HTTPS-only**, žádný HTTP fallback. HTTP requesty se 301-redirectem přesměrují na HTTPS (na úrovni IIS/Apache config).
- **TLS 1.2+** (TLS 1.0/1.1 zakázané). Preferovat TLS 1.3.
- **HSTS:** `Strict-Transport-Security: max-age=31536000; includeSubDomains; preload` (po stabilizaci submit do HSTS preload listu).
- **Certifikát:** Let's Encrypt (auto-renewal přes win-acme na IIS / certbot na Apache).
- **Forward secrecy:** ECDHE cipher suites (jen je povolit).
- **OCSP stapling** zapnuté.
- **HTTP/2** povolené pro performance.

### 9.2 Security HTTP headers

Všechny response headers (set v `web.config`/`.htaccess` + middleware pro API):

| Header | Hodnota | Účel |
|---|---|---|
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains; preload` | Force HTTPS |
| `Content-Security-Policy` | `default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' https://challenges.cloudflare.com; font-src 'self'; connect-src 'self'; frame-src https://challenges.cloudflare.com; frame-ancestors 'none'; base-uri 'self'; form-action 'self'` | XSS mitigation, povolení Turnstile widgetu |
| `X-Content-Type-Options` | `nosniff` | MIME sniffing block |
| `X-Frame-Options` | `DENY` | Clickjacking |
| `Referrer-Policy` | `same-origin` | Privacy |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), payment=()` | Disable unused APIs |
| `Cross-Origin-Opener-Policy` | `same-origin` | Spectre/Meltdown mitigation |
| `Cross-Origin-Resource-Policy` | `same-origin` | Cross-origin resource isolation |
| `Cross-Origin-Embedder-Policy` | `require-corp` | (po test, může bránit fontům) |
| `Cache-Control` | `no-store` pro `/api/*`, `public, max-age=31536000, immutable` pro `/styles/*`, `/assets/*` | Citlivá data nesmí cache |

Server identification:
- IIS: skrýt `Server` header (`removeServerHeader` v `web.config`).
- Apache: `ServerTokens Prod`, `ServerSignature Off`.
- PHP: `expose_php = Off` v `php.ini` (nezveřejňovat `X-Powered-By`).

### 9.3 Authentication

#### 9.3.1 Hesla
- **Hash:** `password_hash($pass.$pepper, PASSWORD_BCRYPT, ['cost' => 12])`. Pepper z `cfg.app.pepper` (32B base64, **NIKDY nesmí být v repu**).
- **Validace:** `password_verify($pass.$pepper, $hash)` + `password_needs_rehash()` — pokud algoritmus/cost zastaral, rehash při příštím přihlášení.
- **Politika hesel:** min. 12 znaků, max. 128 (DoS proti bcrypt s extrémně dlouhým inputem). Doporučujeme passphrase, ne komplexní pravidla.
- **HIBP check:** offline kontrola proti top-1000 wordlist (zapouzdřeno v `PasswordPolicyService`). Pro top-10000 (volitelně) = hash-prefix online API.
- **Žádné `pepper` v aktivním logu** — nikdy nelogovat hash, plain hash, ani pokus o login s heslem.

#### 9.3.2 Session
- **Cookie:** `myinvoice_session=<128-bit random>; HttpOnly; Secure; SameSite=Lax; Path=/; Max-Age=2592000`
- `SameSite=Lax` (nejen Strict) — Strict by rozbil link z emailů (reset hesla). Pro mutating requesty máme CSRF token jako další vrstvu.
- **Server-side store:** Redis (primární) nebo MariaDB `sessions` tabulka. Klient nemá žádná data v cookie, jen opaque ID.
- **Session ID rotace:** `regenerateId()` při:
  - úspěšném login
  - elevation privilegií (změna hesla, přechod readonly → admin)
  - každých 24h aktivity
- **Idle timeout:** 4 hodiny bez aktivity → vyžadovat re-login pro citlivé akce (vystavení faktury, smazání klienta, změna nastavení dodavatele). Běžné GET requesty fungují dokud platí session.
- **Absolute timeout:** 30 dní od prvního přihlášení → force re-login.
- **Concurrent sessions:** povolené (uživatel může být na desktopu i mobilu), v UI seznam aktivních sessions s tlačítkem „odhlásit zde".
- **Logout invaliduje session na serveru** (smaže z Redis/DB), ne jen cookie.

#### 9.3.3 First-run setup
Viz kapitola 2.0. Setup endpoint pracuje pod IP allowlist (pokud aktivní s `apply_to=all`) a má rate-limit 5/hod/IP. Po vytvoření prvního admina je trvale uzamčen.

### 9.4 CSRF (Cross-Site Request Forgery)

Vue 3 SPA + cookie session = klasický cíl CSRF. Použijeme **dvojitou ochranu**:

#### 9.4.1 SameSite cookie (1. vrstva)
`SameSite=Lax` blokuje většinu cross-site POST/PUT/DELETE z jiných domén. Nestačí to jako jediná ochrana (Lax povoluje top-level navigace), ale eliminuje 90 % útoků.

#### 9.4.2 Synchronizer Token Pattern (2. vrstva)
- Při login se generuje CSRF token (32B random, base64) a uloží do session na serveru.
- Klient ho dostane v response těla `/api/auth/login` jako `csrf_token`.
- Pro **každý** mutating request (POST/PUT/PATCH/DELETE) musí klient poslat header `X-CSRF-Token: <token>`.
- Backend (`CsrfMiddleware`) ho porovná s hodnotou v session — pokud nesedí, **403 + log**.
- Token se rotuje při každém přihlášení a změně hesla. **Nerotuje** se per-request (jinak bychom rozbili paralelní requesty z UI).

#### 9.4.3 Origin / Referer check (3. vrstva)
`CsrfMiddleware` navíc ověří `Origin` header (a fallback na `Referer`):
- Pokud chybí oba → 403.
- Pokud `Origin` neodpovídá `cfg.app.url` → 403.
- Pro GET requesty se kontrola **neaplikuje** (jsou považované za safe — každý mutující GET = bug).

### 9.5 Rate limiting & brute force

#### 9.5.1 Rate limity (per route)
Viz tabulka v `04-api.md` kapitola 10. Implementace přes `RateLimitMiddleware` s per-key window counter (Redis nebo MariaDB MEMORY). Headers v response:
```
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 47
X-RateLimit-Reset: 1714587600
```
Při překročení: 429 + `Retry-After`.

#### 9.5.2 Brute-force lockout (login + reset)
Viz kapitola 2.3. Sliding window (5 / 15 / 60 min) per `<sha1(email)>:<ip_class>`:
- 5 selhání / 5 min → CAPTCHA
- 10 selhání / 15 min → lockout 15 min
- 30 selhání / 60 min → lockout 24 h + email upozornění userovi

**User enumeration prevention:**
- `password_verify` vždy zavolat (i pro neexistující email — proti dummy hash, aby timing byl konzistentní).
- `/auth/forgot` vždy 204 bez ohledu na existenci emailu.
- Login error message: vždy `"Neplatné přihlašovací údaje"` (ne `"Email neexistuje"` ani `"Špatné heslo"`).

#### 9.5.3 CAPTCHA — Cloudflare Turnstile

Aktivuje se po 5 selháních login pokusů (per email+IP) v okně 5 minut. Použijeme **Cloudflare Turnstile** (privacy-friendly, žádné tracking, většinou neviditelné pro usera — automatický browser challenge).

**Frontend:**
- Skript: `<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" defer></script>` (URL z `cfg.captcha.script_url`).
- Widget: `<div class="cf-turnstile" data-sitekey="<cfg.captcha.site_key>" data-action="login"></div>` se vyrendrová v Login formuláři **podmíněně** (jen pokud server vrátil `423 captcha_required`).
- Po vyřešení challenge dostane klient token, pošle ho v body při dalším loginu jako `cf_turnstile_response`.
- Site key se z backendu poskytne přes `GET /api/auth/setup-status` (vrací mj. `captcha: { provider, site_key }`) — site key je veřejný a smí být v HTML, ale necentralizujeme ho na frontend hardcoded.

**Backend (`TurnstileVerifier` service):**
1. Klient pošle `POST /api/auth/login` s polem `cf_turnstile_response`.
2. `Authenticator` zavolá `TurnstileVerifier::verify($token, $clientIp)`:
   - POST na `cfg.captcha.verify_url` s `secret`, `response`, `remoteip`
   - Timeout `cfg.captcha.timeout` (default 5s)
   - Response JSON: `{ "success": bool, "challenge_ts": "...", "hostname": "...", "action": "login", "error-codes": [...] }`
3. Pokud `success=false` → 423 `captcha_failed` + log s error-codes.
4. Pokud Turnstile API timeoutuje / 5xx → fallback dle `cfg.captcha.fail_open`:
   - `true` (default) — povolit (neblokovat legitimního usera kvůli výpadku CF)
   - `false` — odmítnout (přísnější, ale rizikové při výpadku)
5. Validace `action` v response = `login` (chrání proti replay tokenu z jiné stránky).
6. Token je **single-use** — Cloudflare ho zneplatní po prvním verify; klient musí pro další pokus vyřešit nový challenge.

**Konfigurace v `cfg.php`:**
```php
'captcha' => [
    'provider'    => 'turnstile',           // 'turnstile' | 'none'
    'site_key'    => '...',                 // public, do <div data-sitekey>
    'secret_key'  => '...',                 // server-side verify, NIKDY do frontend bundle
    'verify_url'  => 'https://challenges.cloudflare.com/turnstile/v0/siteverify',
    'script_url'  => 'https://challenges.cloudflare.com/turnstile/v0/api.js',
    'timeout'     => 5,
    'fail_open'   => true,
    'action'      => 'login',
],
```

> Skutečné `site_key` a `secret_key` jsou v `cfg.php` (gitignored). V `source/*.md` je nezveřejňujeme.

**Activity log:** `auth.captcha_required`, `auth.captcha_passed`, `auth.captcha_failed` (s error-codes).

**CSP doplnění:** kvůli Turnstile widgetu doplnit `script-src 'self' https://challenges.cloudflare.com` a `frame-src https://challenges.cloudflare.com`.

### 9.6 Authorization (RBAC)

3 role:
| Role | Práva |
|---|---|
| `admin` | Vše: settings, users, ip-allowlist, email šablony, faktury (CRUD + issue/send/cancel), dashboard |
| `accountant` | Faktury (CRUD + issue/send/cancel), klienti, zakázky, dashboard. **Bez** users management, settings, IP allowlist. |
| `readonly` | Pouze GET endpointy. Žádné mutace. |

Implementováno přes attribute na Action: `#[RequireRole('admin')]`. `AuthMiddleware` načte usera, RBAC check dělá samostatný `RoleMiddleware`. Selhání = 403.

### 9.7 Input validation

- **Whitelist approach** — `Respect/Validation` schémata per request body. Neznámé pole odmítnout (strict mode).
- Validace **vždy server-side**, frontend jen pro UX. Klient může poslat cokoliv.
- Numeric fields s limity (max částka 999 999 999, max quantity 999 999.999, atd.).
- Strings s max-length (TEXT typy v DB, ale na vstupu omezit na 64 KB pro jednu položku).
- Date validace: ISO 8601, rozumný rozsah (1990-2099).
- Email validace: `filter_var($email, FILTER_VALIDATE_EMAIL)` + max 190 znaků.
- IČ: 8 číslic + modulo-11 checksum.
- DIČ: regex per země (CZ: `CZ\d{8,10}`, EU varianty).
- Bank account number: regex per měna (CZ: `(prefix-)?account/code`).
- IBAN: ISO 13616 mod-97 check.

### 9.8 Output encoding

- **JSON output** — `json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)`. Content-Type vždy `application/json; charset=utf-8`.
- **HTML output (PDF preview, email)** — Twig auto-escape ON. Žádné `|raw` filtry kromě interních konstant.
- **PDF output** — mPDF přijímá HTML; veškeré user inputy projdou Twig escape. Žádné `<script>` v PDF (ostatně se nespustí, ale pro jistotu).
- **CSV exporty** — escaping `,`, `"`, `\n`, prefix `'` u buněk začínajících `=+-@\t\r` (proti CSV injection / formula attacks v Excelu).

### 9.9 SQL Injection prevention

- **Výhradně prepared statements** přes PDO. Žádný `sql_query("... ".$var." ...")` (jak je v reference kódu z prazdroj.cz — to je legacy, neportujeme).
- `PDO::ATTR_EMULATE_PREPARES = false` (skutečné prepared statements na úrovni MariaDB protocolu).
- `PDO::ATTR_ERRMODE = PDO::ERRMODE_EXCEPTION`.
- Charset v DSN: `mysql:host=...;charset=utf8mb4`.
- Dynamic ORDER BY / column names → whitelist, ne user input přímo.

### 9.10 File uploads

Jediný upload v aplikaci: **logo dodavatele** a **podpis** (PNG / SVG, max 1 MB).

- MIME type detekce na **obsahu** (`finfo_file()`), ne `$_FILES['type']` (klient si vymyslí).
- Whitelist: `image/png`, `image/svg+xml`. Vše ostatní odmítnout.
- SVG sanitace přes `enshrined/svg-sanitize` — odstraní `<script>`, `<foreignObject>`, eventy `on*`, externí URL.
- Max velikost: 1 MB (kontrola server-side, ne jen klient).
- Filename: vygenerovat `<sha256-content>.png`, **nikdy** nepoužít user-supplied název (path traversal, injection).
- Storage: `storage/uploads/` mimo public root (servíruje API endpoint s auth checkem).

### 9.11 Sensitive data handling

- **Hesla v paměti:** vyčistit `unset($_POST['password'])` co nejdřív.
- **Logy:** `LoggerSanitizer` middleware redaktuje pole `password`, `password_confirm`, `current_password`, `new_password`, `token`, `csrf_token` z payload před uložením do logu.
- **Activity log payload:** ukládá *před/po* snapshoty mutací, ale s redakcí citlivých polí.
- **Error responses:** v produkci nikdy nevrátit stack trace, SQL query, file path. Generic `"Internal server error"`. Detailní info jen do `log/app.log`.
- **Backup:** mariadb-dump je gzipovaný. Pro skutečnou produkci doporučeno encrypt-at-rest (BitLocker na disk, nebo `gpg --symmetric` na backup soubor).

### 9.12 Email security

- **Symfony Mailer** s povinným TLS (`encryption=ssl` na 465 nebo `encryption=tls` STARTTLS na 587).
- **DKIM signing** na odchozí poštu (klíč z DNS, key v `cfg.smtp.dkim_*`).
- **SPF + DMARC** record na DNS pro `myinvoice.cz` doménu (`v=spf1 mx -all`, `v=DMARC1; p=quarantine; rua=mailto:dmarc@myinvoice.cz`).
- **Header injection prevention:** validace recipientů (`filter_var FILTER_VALIDATE_EMAIL`), nepovolené `\r`, `\n` v subject/body.
- **Open redirects** v password reset linku — token validace, ne open `?redirect=URL` parametr.

### 9.13 Dependency security

- `composer audit` v CI po každém PR.
- `pnpm audit` v CI.
- Renovate / Dependabot pro auto-PR security updatů.
- Pinné verze v `composer.lock` a `pnpm-lock.yaml`.
- Žádné `dev-master` či volné constraints — vždy SemVer.
- Pravidelný měsíční manuální review velkých dependencies (Slim, Vue, mPDF).

### 9.14 Audit & monitoring

- **Activity log** všech mutací (kapitola 4.8) + přístupů k citlivým endpointům (faktury, dodavatel, settings, users).
- **Security log** v `log/security-YYYY-MM-DD.log` (samostatný file, ne mixovaný s app.log) — auth events, IP block, CSRF fail, rate limit hit, captcha challenge.
- **Alerting:** při >100 failed login / hod globálně → email adminovi.
- **Monitoring:** uptime check `/api/health`, error rate (5xx) z logů.
- **Backup verification:** týdně automaticky restore-test na samostatnou DB instanci.

### 9.15 Deployment & infra

- `cfg.php` nikdy v gitu (gitignored).
- Pepper, SMTP heslo, DB heslo: rotovat pravidelně (alespoň ročně + při incidentu).
- File permissions na produkci:
  - `cfg.php`: `0600`, vlastník = web user
  - `storage/`: `0700`
  - `log/`: `0700`
  - PHP soubory: `0644`
- Web user **nemá** shell access.
- DB user `myinvoice` (NE `root` v produkci!) má jen `SELECT, INSERT, UPDATE, DELETE` na `myinvoice.*` — žádné `DROP`, `CREATE`, `GRANT`. Migrace pouští samostatný admin user manuálně.
- Backup do off-site lokace (S3 / Backblaze) s odlišnými credentials.

### 9.16 Privacy & GDPR

- **Data minimization:** ukládáme jen co potřebujeme pro fakturaci (jméno, adresa, IČ, email klienta). Žádné tracking cookies, žádné analytics třetích stran.
- **Right to erasure:** klient může požádat o smazání → archivace faktur (legislativa CZ vyžaduje 10 let), ale anonymizace osobních údajů (`anonymize_client(client_id)` přepíše jméno/email/telefon na placeholder).
- **Right to access:** export všech faktur klienta jako ZIP (PDF + JSON).
- **Cookie banner:** nepotřebujeme — používáme jen nutnou session cookie (no consent required dle ePrivacy).
- **Privacy policy** na `/privacy` (statická stránka).

### 9.17 IP allowlist
(Detail viz dříve — předtím očíslováno 9.1, teď přesunuto sem.)

Volitelné omezení přístupu na IP rozsahy — kompletní spec v dřívější verzi tohoto dokumentu zůstává validní:
- `cfg.ip_allowlist.enabled = true|false`
- `mode: 'block' | 'log_only'`
- Podpora IPv4 + IPv6 + CIDR (`/24`, `/56`, `/64`)
- `apply_to: 'all' | 'admin_only' | 'mutations_only'`
- Trusted proxies s configurable header
- UI pro správu pravidel (admin only)
- Activity log: `security.ip_blocked`, `security.ip_allowlist_changed`

### 9.18 Threat model summary

| Hrozba | Mitigace |
|---|---|
| Stolen session cookie (XSS) | HttpOnly + CSP zabraňuje XSS, Secure proti odposlechu |
| CSRF | SameSite=Lax + CSRF token + Origin check (3 vrstvy) |
| Brute-force login | Rate limit + lockout + CAPTCHA + email warning |
| User enumeration | Konstantní timing + generic error message + always-204 forgot |
| Password reset hijack | Token = sha256, 60 min TTL, single-use, log IP |
| SQL injection | Prepared statements, no string concat |
| XSS v PDF/HTML | Twig auto-escape, CSP, SVG sanitize |
| File upload abuse | MIME content-check, whitelist, sanitize, content-hash filename |
| Privilege escalation | RBAC middleware, attribute-based, default-deny |
| Information disclosure (errors) | Generic 500 v prod, full info jen v logu |
| Insider abuse | Audit log + admin actions vždy logované s IP |
| Compromised dependency | composer/pnpm audit v CI, pinné verze, monthly review |
| MITM | HTTPS-only, HSTS, TLS 1.2+, OCSP stapling |
| Stolen backup | Encrypt-at-rest, off-site copy, separated credentials |
| DoS | Rate limit per IP, max body size, max items per invoice (1000), nginx/IIS connection limits |

### 9.1 IP allowlist
Volitelné omezení přístupu k aplikaci na seznam povolených IP rozsahů. Pokud je konfigurace prázdná (`ip_allowlist.enabled = false`), allowlist se neaplikuje a aplikace je dostupná odkudkoli (s ostatní vrstvou ochrany — auth, brute-force).

**Konfigurace v `cfg.php`:**
```php
'ip_allowlist' => [
    'enabled' => true,
    'mode'    => 'block',          // 'block' = 403 mimo allowlist, 'log_only' = jen warning v logu
    'allow'   => [
        '127.0.0.1',               // IPv4 jediná
        '192.168.1.0/24',          // IPv4 podsíť
        '88.146.123.45/32',        // statická IPv4 (ekvivalent jediné)
        '::1',                     // IPv6 loopback
        '2a01:430:1c:0:0:0:0:0/64',// IPv6 prefix /64
        '2001:db8::/56',           // IPv6 prefix /56
    ],
    'apply_to' => 'all',           // 'all' | 'admin_only' | 'mutations_only'
    'trusted_proxies' => [         // pokud aplikace stojí za reverse proxy / CDN
        '10.0.0.0/8',
    ],
    'header' => 'X-Forwarded-For', // odkud číst skutečnou client IP (pokud trusted proxy matchne)
],
```

**Implementace** (`IpAllowlistMiddleware`, runs **před** AuthMiddleware):
1. Získá client IP — buď `REMOTE_ADDR`, nebo (pokud `REMOTE_ADDR` matchne `trusted_proxies`) první IP z `header`.
2. Normalizuje (IPv6 expanduje na plný tvar, IPv4 na 32-bit integer).
3. Pro každý záznam v `allow` zkontroluje:
   - Plain IP (`192.168.1.5`) → exact match
   - CIDR (`192.168.1.0/24`, `2001:db8::/56`) → bitwise AND s maskou
4. Pokud **mode=block** a žádný match → response 403 `{ "error": { "code": "ip_not_allowed", "message": "..." } }` + log.
5. Pokud **mode=log_only** a žádný match → projde, ale do `log/app.log` se zapíše warning s IP.

**Granularita přes `apply_to`:**
- `all` (default) — IP check pro všechny endpointy včetně `/auth/login`
- `admin_only` — IP check jen pokud `auth.user.role = 'admin'` (běžný user může přistupovat odkudkoli, admin jen z firmy)
- `mutations_only` — IP check jen pro POST/PUT/DELETE; čtení (GET) povolené odkudkoli

**Edge cases:**
- IPv6: aplikace musí umět rozeznat IPv4-mapped IPv6 (`::ffff:192.168.1.5`) a normalizovat na IPv4 pro porovnání s IPv4 pravidly.
- IP allowlist se kontroluje **i pro `/auth/setup`** (kromě případu, kdy je `apply_to='admin_only'` — bezpečnostní výjimka, aby setup mohl proběhnout).
- Při blokaci se neloguje email/heslo z requestu (jen IP a path).

**UI:**
- Admin sekce *Settings → Bezpečnost* zobrazí stav, počet aktivních pravidel, posledních 50 zablokovaných pokusů (z `activity_log`).
- Lze přidávat/odebírat pravidla přes UI (uloží se do `settings` tabulky a překryje hodnoty z `cfg.php` — DB má přednost).

**Activity log akce:**
- `security.ip_blocked` — zablokovaný request s IP a path
- `security.ip_allowlist_changed` — změna pravidel přes UI

---

## 10. Rozhodnutí a otevřené body

Všechny původní otevřené otázky jsou rozhodnuté:

| # | Otázka | Rozhodnutí |
|---|---|---|
| 1 | RBAC | **3 role** (admin / accountant / readonly), bez per-klient ACL |
| 2 | Storno faktury | **Obojí**: storno (interní flag) i dobropis (opravný daňový doklad) — viz 4.5 |
| 3 | Měny | **CZK + EUR** (zatím), číselník připraven na rozšíření |
| 4 | Pohoda/MoneyS3 export | **Ne** |
| 5 | Periodické faktury | **Ne** automatické. Místo toho **„Vystavit znovu" hromadná akce** — viz 4.3 |
| 6 | Zálohové faktury (proforma) | **Ano**, bez DUZP, varsymbol s prefixem `9` — viz 4.4 |
| 7 | Vystavení daňového dokladu z proformy | **Ano**, s automatickým odečtem zaplacené zálohy — viz 4.4.2 |
