# 16 — Platební příkazy (payment orders) pro přijaté faktury

> Plán nové funkce pro hromadné generování příkazů k úhradě z nezaplacených přijatých faktur.
> Stav: **navrženo, neimplementováno**.

## Kontext

U přijatých faktur dnes umíme jen zobrazit QR k jednotlivé platbě (`PaymentQrAction` +
`payment_*` sloupce z migrace `0107`). Chybí způsob, jak **hromadně** vzít nezaplacené přijaté
faktury, zkontrolovat/doplnit platební údaje a vygenerovat **dávkový příkaz k úhradě** pro nahrání
do banky.

Cíl — nová pod-stránka v sekci *Přijaté faktury* → „Platební příkazy", která:

- zobrazí nezaplacené přijaté faktury + platební údaje (účet, VS) a **jak byly načteny**
  (`payment_account_source`: isdoc / ai / qr_image / manual),
- umožní inline editaci/doplnění účtu příjemce,
- **ověří účet dodavatele proti registru** (CRPDPH — zveřejněné účty + nespolehlivý plátce),
- umožní vybrat faktury a vygenerovat příkaz do **CSV, PDF a ABO/KPC** (formát České spořitelny),
- vybere **účet plátce** (default dle měny, lze změnit),
- export si **zapamatuje** (historie) s možností opětovného stažení,
- označí faktury příznakem **„Zařazeno k úhradě"** (`payment_ordered_at`), volitelně rovnou „Zaplaceno".

### Rozhodnutí (potvrzeno zadavatelem)

1. **Stav po exportu:** nový příznak `payment_ordered_at` + odvozený badge „Zařazeno k úhradě".
   Status se **NEpřeklápí na `paid`** (to dělá až párování bankovního výpisu). Při exportu
   volitelný checkbox „rovnou označit jako zaplacené" (jde přes existující `InvoicePaymentService`).
2. **Cizí měny:** ABO/KPC jen **CZK** (tuzemský platební styk). EUR/cizí faktury lze zahrnout
   do **CSV + PDF**, ne do ABO. SEPA pain.001 = budoucí rozšíření (mimo scope).
3. **Umístění:** pod-stránka v sekci Přijaté faktury + tlačítko „Do příkazu k úhradě" v hromadných
   akcích seznamu (`InvoiceList.vue`).

## ABO/KPC formát

Ověřeno z `private/KPC/ABO_format.pdf` + reálného `private/KPC/abo-payment-96.kpc`.
Použijeme **hromadný příkaz** (debetní účet v hlavičce skupiny, položky bez debetního účtu).
Kódování UTF-8 (zprávy transliterujeme na ASCII), řádky ukončené CRLF.

```text
UHL1<ddmmrr><20× název klienta><10× číslo klienta><000><999><000000><000000>CRLF
1 1501 <sssppp> <směr.kód banky plátce 4>CRLF        # hlavička účetního souboru (1501 = úhrady)
2 <prefix-číslo plátce> <celk.částka haléře> <ddmmrr datum splatnosti>CRLF   # hlavička skupiny
<prefix-číslo příjemce> <částka haléře> <VS> <KS8> <SS10> AV:<zpráva>CRLF      # položka (bez deb. účtu)
... další položky ...
3 +CRLF                                               # konec skupiny
5 +CRLF                                               # konec účetního souboru
```

Klíčové detaily (z ověřeného `.kpc`):

- **Částka v haléřích** (×100, bez desetinné tečky).
- **KS pole = 8 číslic:** levé 4 = směrový kód banky příjemce, pravé 4 = konstantní symbol
  (např. `01000308` = banka 0100 + KS 0308; default KS `0000`). Bere se z `payment_bank_code` příjemce.
- **Účet příjemce** = `prefix-číslo` BEZ kódu banky (kód je v KS poli). Parsuje `BankAccountParser`.
- **Datum splatnosti** skupiny = jedno datum pro celou dávku (uživatel zvolí; default dnes;
  nesmí být menší než dnešní datum → u faktur po splatnosti = dnes).
- **Číslo klienta** (UHL1, 10 znaků) = číslo účtu plátce bez předčíslí, doplněné nulami zleva
  (v `.kpc` jsou nuly, fungují). **Název klienta** (20 znaků) = `supplier.company_name` transliterace.
  → Žádná nová povinná supplier pole nejsou nutná; odvodíme z dodavatele + zvoleného účtu.
  Volitelný override (`abo_client_number`) jen pokud banka přidělí jiné číslo.

## Datový model — migrace `0113_payment_orders.sql`

Idempotentní (MariaDB `IF NOT EXISTS`, vzor dle `0107`). Aktuální poslední migrace = `0112`.

**Nové tabulky** (batch + položky; snapshot údajů v čase exportu → deterministické re-stažení):

```sql
payment_orders
  id BIGINT UNSIGNED PK, supplier_id TINYINT UNSIGNED,
  currency CHAR(3),                 -- měna dávky (CZK pro ABO)
  payer_currency_id INT UNSIGNED,   -- FK currencies (zvolený účet plátce)
  payer_account_number / payer_bank_code / payer_iban / payer_bic VARCHAR,  -- snapshot
  payer_account_label VARCHAR,
  payment_date DATE,                -- datum splatnosti skupiny
  total_amount DECIMAL(14,2), item_count INT,
  note VARCHAR(255) NULL,
  mark_paid TINYINT(1) DEFAULT 0,   -- byl při exportu zvolen i flip na paid
  created_at TIMESTAMP, created_by_user_id BIGINT NULL

payment_order_items
  id BIGINT PK, payment_order_id FK, purchase_invoice_id FK,
  payee_name VARCHAR, payee_account_number / payee_bank_code / payee_iban / payee_bic,
  amount DECIMAL(14,2), currency CHAR(3),
  variable_symbol VARCHAR(10), constant_symbol VARCHAR(4) NULL, specific_symbol VARCHAR(10) NULL,
  message VARCHAR(140) NULL,
  account_verified ENUM('verified','not_listed','unreliable','na') DEFAULT 'na'  -- výsledek CRPDPH kontroly
```

**Rozšíření `purchase_invoices`** (idempotentně):

```sql
ADD COLUMN IF NOT EXISTS payment_ordered_at TIMESTAMP NULL       -- „Zařazeno k úhradě" (odvozený badge)
ADD COLUMN IF NOT EXISTS payment_constant_symbol VARCHAR(4) NULL -- volitelný KS k platbě
```

**Volitelné supplier pole** (jen pokud banka vyžaduje override; jinak odvodíme):

```sql
ALTER TABLE supplier ADD COLUMN IF NOT EXISTS abo_client_number VARCHAR(10) NULL
```

(`abo_client_name` neřešíme — bereme `company_name`.)

## Backend

### Nové služby (`api/src/Service/`)

- **`Payment/AboPaymentOrderWriter.php`** — staví ABO/KPC text z `payment_orders` snapshotu.
  Logika: částka → haléře, KS pole `sprintf('%04d%04d', bankCode, ks)`, account split přes
  `BankAccountParser`, transliterace zprávy přes `Export\ExportFilename::transliterate()`,
  CRLF, číslo klienta = číslo účtu plátce bez prefixu (10, padding nulami).
  **Jen CZK + příjemce s `account_number` + `bank_code`** (IBAN-only se do ABO nezahrne).
- **`Payment/PaymentOrderCsvWriter.php`** — `fputcsv` + UTF-8 BOM + `;` + CSV-injection guard
  (vzor `Action/Invoice/ExportCsvAction.php:52-86`). Sloupce: příjemce, účet, částka, měna, VS, KS, SS,
  splatnost, zpráva.
- **`Pdf/PaymentOrderPdfRenderer.php`** — mPDF + Twig, reuse `MpdfFontConfig::options()`
  (vzor `Pdf/DphBookPdfRenderer.php`). Šablona `api/templates/payment-order/payment-order.twig`
  (hlavička: účet plátce, datum; tabulka plateb; součet). Landscape A4.
- **`Payment/PaymentOrderService.php`** — orchestrace: z vybraných `purchase_invoice_id` + payer
  `currency_id` + datum sestaví snapshot, ověří účty (`CrpDphClient::lookup(vendor.dic)` → match
  proti `accounts`), uloží `payment_orders` + items, nastaví `payment_ordered_at`, volitelně
  `InvoicePaymentService` flip na paid. VS přes `VariableSymbolNormalizer::forPayment()`.

### Ověření účtu (reuse)

`Service/Ares/CrpDphClient::lookup($dic)` vrací `accounts[]` + `unreliable`. Pro každou položku:
porovnat payee `prefix-číslo/kód` proti zveřejněným účtům → `verified` / `not_listed`;
nespolehlivý plátce → `unreliable`; bez DIČ / ne-CZ → `na`. Výsledek do `account_verified` + UI badge.

### Akce (`api/src/Action/PurchaseInvoice/`) + routy (`api/src/Routes.php`, skupina kolem ř. 315–344)

| Metoda + cesta | Popis | RBAC |
|---|---|---|
| `GET /api/purchase-invoices/payment-orders/candidates` | nezaplacené faktury (`status IN ('received','booked') AND amount_to_pay > 0`) + `payment_*` + source + návrh ověření + seznam účtů plátce (z `currencies`, default dle měny) | read |
| `POST /api/purchase-invoices/payment-orders` | vytvoř dávku `{invoice_ids, payer_currency_id, payment_date, constant_symbol?, note?, mark_paid?}` | write |
| `GET /api/purchase-invoices/payment-orders` | historie dávek | read |
| `GET /api/purchase-invoices/payment-orders/{id}/download?format=abo\|csv\|pdf` | re-generace ze snapshotu | read |
| `PUT /api/purchase-invoices/{id}/payment-account` (reuse) | inline editace účtu příjemce | write |

Vzor batch akce: `Action/Invoice/BulkReissueAction.php` (per-item try/catch, ownership guard
`SupplierGuard::owns()`, limit 200, response `{created:[], errors:[]}`). RBAC write přes method
gating (vzor stávajících purchase routes). Audit přes `Service\ActivityLogger`
(`payment_order.created` / `payment_order.exported`).

### OpenAPI

Přijaté faktury **nejsou** ve veřejném `/api/v1/*` subsetu → `api/openapi.yaml` se **nemění**
(interní plumbing; dle pravidla v `CLAUDE.md`).

## Frontend

- **Nová stránka** `web/src/pages/purchase-invoices/PaymentOrders.vue` + router entry + záložka
  v sekci přijatých faktur. Multi-select tabulka (vzor `InvoiceList.vue:56-325`, `selectedIds`/toggle
  all), inline editace účtu, badge zdroje (`PaymentAccountSource`), badge ověření, selektor účtu plátce
  (default dle měny), date-picker splatnosti, KS volitelně, tlačítka **Export CSV / PDF / ABO (KPC)**,
  checkbox „označit jako zaplacené". i18n přes `t()` (žádný hardcoded text), dark-mode sémantické
  tokeny (dle `feedback_ui_standards`).
- **Historie** dávek (seznam + re-download v každém formátu).
- Tlačítko **„Do příkazu k úhradě"** v hromadných akcích `InvoiceList.vue` (předvyplní výběr).
- **API klient** `web/src/api/purchaseInvoices.ts` (nebo nový `paymentOrders.ts`): typy
  `PaymentOrderCandidate`, `PaymentOrder`, metody `listCandidates`, `createPaymentOrder`,
  `listPaymentOrders`, `downloadPaymentOrder`. `PaymentAccountSource` už existuje.

## Klíčové soubory (modifikace / nové)

- `db/migrations/0113_payment_orders.sql` *(nové)*
- `api/src/Service/Payment/AboPaymentOrderWriter.php`, `PaymentOrderCsvWriter.php`,
  `PaymentOrderService.php` *(nové)*
- `api/src/Service/Pdf/PaymentOrderPdfRenderer.php` + `api/templates/payment-order/payment-order.twig` *(nové)*
- `api/src/Action/PurchaseInvoice/PaymentOrderAction.php` *(nové)*; `api/src/Routes.php` *(úprava)*
- `api/src/Repository/PurchaseInvoiceRepository.php` *(rozšířit list dotaz o `payment_ordered_at`;
  candidates dotaz)*
- reuse: `Service/Payment/BankAccountParser.php`, `Service/Bank/VariableSymbolNormalizer.php`,
  `Service/Ares/CrpDphClient.php`, `Service/Pdf/MpdfFontConfig.php`, `Service/Export/ExportFilename.php`,
  `Service/Invoice/InvoicePaymentService.php`, `Service/ActivityLogger.php`
- `web/src/pages/purchase-invoices/PaymentOrders.vue` *(nové)*, router, `web/src/api/*` *(úprava/nové)*,
  `web/src/pages/purchase-invoices/InvoiceList.vue` *(tlačítko)*, i18n soubory.

## Verifikace

1. **Migrace:** `& c:\inetpub\php\php.exe api\bin\migrate.php` → 2× spustit (idempotence),
   ověřit nové tabulky/sloupce.
2. **Unit testy** (`cd api; & c:\inetpub\php\php.exe vendor/bin/phpunit --testsuite Unit`):
   - `AboPaymentOrderWriterTest` — golden output vs struktura `abo-payment-96.kpc` (haléře,
     KS = banka + KS, account split, číslo klienta padding, CRLF, transliterace). Syntetická data
     (ne reálné účty).
   - CSV writer (BOM, injection guard), CRPDPH match mapping.
3. **Build FE:** `cd web; pnpm build` (testuje se přes `dist/`, ne dev server).
4. **E2E v prohlížeči** (Edge s Claude ext., `dev.myinvoice.cz`): otevřít Platební příkazy → vybrat
   nezaplacené faktury → doplnit účet → ověřit CRPDPH badge → zvolit účet plátce + datum → Export
   ABO/CSV/PDF → zkontrolovat soubor → ověřit badge „Zařazeno k úhradě" → historie + re-download.
5. **DPH/daně:** beze změny (export jen čte; flip na paid jde přes `InvoicePaymentService`).
