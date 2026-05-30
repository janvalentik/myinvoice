# Plán 13 — Dodavatel neplátce DPH → žádný nárok na odpočet

**Stav: IMPLEMENTOVÁNO + OTESTOVÁNO (2026-05-31).** Plná phpunit suite (592 testů) i
`npm run build` zelené. Backfill dry-run ověřen proti DB. Migrace 0084 aplikována.
Odchylky od plánu: (1) přidána migrace `0084_clients_is_vat_payer.sql` — `clients.is_vat_payer`
NEEXISTOVALO; (2) backfill u neplátce navíc nastaví sazby na 0 % a `unit_price_without_vat`
:= cena s DPH (základ = zaúčtovaná částka, DPH = 0, total beze změny).

## Context

Přijatá faktura `VF_260069mwd.pdf` (dodavatel **Ing. Věra Hlavatá, IČO 67759661, „DIČ:
Neplátce DPH"**, položka 2 500 Kč bez DPH) se přes AI import uložila s `vat_deduction='full'`
→ nárok na odpočet DPH. To je **daňová chyba**: od neplátce není co odpočítat (na dokladu
žádná DPH není). Tahle chyba může do DPH přiznání ř.40 dostat neoprávněný odpočet.

Kořen: `ClientResolver` si při importu z ARES tahá data, ale `is_vat_payer` (které ARES
vrací) **zahazuje** a `AiPdfExtractor` `vat_deduction` nikdy nenastaví → repo default `'full'`.

Cíl: sledovat u dodavatele plátcovství DPH (CZ: autoritativně z ARES dle IČO; **zahraniční
EU: z VIES dle DIČ**; online při nové/editované faktuře, cache 24 h), a u neplátce automaticky
vynutit `vat_deduction='none'` + vynulovat DPH sazby + varování. Plus zpětný fix přes
všechny existující dodavatele.

**Zdroj plátcovství podle typu dodavatele (precedence):**
1. **CZ (IČO 8 číslic)** → ARES `is_vat_payer` (`stavZdrojeDph === 'AKTIVNI'`).
2. **Zahraniční EU (DIČ, bez CZ IČO)** → VIES `valid` (registrované DIČ = plátce).
3. **Fallback** (ARES/VIES nedostupné / mimo EU) → signál z dokladu (AI: „Neplátce DPH" /
   žádné DIČ + žádná DPH na řádcích), jinak ponech dosavadní default (plátce).

**Potvrzená rozhodnutí uživatele:**
1. U neplátce **tvrdě vynutit** `vat_deduction='none'` + vynulovat sazby + viditelné
   varování (uživatel může vědomě přepsat v editoru).
2. Backfill skript: **dry-run default + `--apply`** (plný rozsah: flip `is_vat_payer`,
   `vat_deduction='none'` na dotčených fakturách, reprefix varsymbol, recompute).
3. Volbu plátcovství zobrazit v editoru přijaté faktury **pod „Reverse charge"** + online
   ARES načtení při výběru dodavatele / editaci, příznak nastavit automaticky.

## Existující infrastruktura (znovupoužít, NEpsat znovu)

- `AresClient::lookup($ic)` → normalizovaná data vč. `is_vat_payer`
  (`stavZdrojeDph === 'AKTIVNI'`, `api/src/Service/Ares/AresClient.php:117`). Cache
  `ares_cache` 24 h (`ares.cache_ttl`, ř. 233).
- Endpoint `POST /clients/lookup-ares` → `AresLookupAction` vrací celý ARES výsledek
  (vč. `is_vat_payer`); frontend `clientsApi.lookupAres(ic)` (`web/src/api/clients.ts:199`).
- `clients.is_vat_payer` — POZOR, NEEXISTOVAL (sloupec na ř. 138 v 0001 je u `supplier`,
  ne `clients`). Přidána migrace **`0084_clients_is_vat_payer.sql`** (DEFAULT 1).
- `VatLedgerService` už vylučuje `vat_deduction='none'` z odpočtu
  (`api/src/Service/Report/VatLedgerService.php:188` → `AND pi.vat_deduction <> 'none'`).
  Tím pádem stačí správně nastavit `vat_deduction` a DPHDP3 ř.40/KH B se opraví samy.
- `PurchaseInvoiceRepository`: reprefix varsymbolu po změně daň. uplatnění
  (`reprefixVarsymbolForTaxTreatment`, ř. 919-946) + recompute přes `PurchaseInvoiceCalculator`.
- `extraction_warning` (purchase) — existující mechanismus žlutého varování v detailu/editoru.

## Implementace

### 1. Backend — persistovat `is_vat_payer` u dodavatele
- **`ClientResolver::resolveAny`** (`api/src/Service/Import/ClientResolver.php`): do `$data`
  pro `clients.create` doplnit `'is_vat_payer' => $aresData['is_vat_payer'] ?? true`
  (ARES je autoritativní; když ARES nezná, ponech default true). U existujícího klienta
  (created=false) volitelně refresh, viz bod 3.
- **`ClientRepository::create`** (ř. 220): přidat `is_vat_payer` do INSERT (akceptovat z `$data`,
  default 1).
- Malý helper pro centralizaci pravidla (sdílí AI import, manuální create, backfill):
  `private static function vendorRequiresNoDeduction(bool $isVatPayer): bool` — neplátce ⇒ true.

### 2. Backend — AI import vynucení (`AiPdfExtractor`)
- V `createInvoiceFromData` (kolem ř. 356-385, kde se staví `$payload` + `$items`):
  zjistit plátcovství dodavatele: primárně z resolvnutého klienta (`clients.is_vat_payer`,
  doplněné ARESem v bodě 1); fallback signál z dokladu — AI rozšířit o `vendor.is_vat_payer`
  (prompt v `AnthropicClient`: „DIČ: Neplátce DPH" / žádné DIČ + žádná DPH na řádcích ⇒ false).
- Když **neplátce**: na `$items` nastavit `vat_rate_id` = 0% sazba (matchVatRateId(0)),
  `$payload['vat_deduction'] = 'none'`, a po vytvoření zavolat `setExtractionWarning(...)`
  s textem „Dodavatel je neplátce DPH — odpočet daně byl automaticky zakázán (žádná DPH
  na dokladu)." (i18n přes existující warning kanál).
- Nový testovatelný helper `resolveVendorIsVatPayer($data, $clientId)` (analogicky k
  `resolvePricesIncludeVat` z plánu 12) — pure logic, unit test.

### 3. Backend — manuální create/edit + online refresh
- **`CreatePurchaseInvoiceAction`** + **`UpdatePurchaseInvoiceAction`**: po načtení vendora
  (`$this->clients->find($vendorId)`, ř. 52) když `is_vat_payer=0` a `$body['vat_deduction']`
  není explicitně `'none'` → vynutit `vat_deduction='none'` + přidat `_warning`.
- **Refresh při vytváření faktury (1× denně dle cache):** lehký endpoint
  `GET /clients/{id}/vat-status` (nový `ClientVatStatusAction`): když má klient IČO, zavolá
  `AresClient::lookup` (cache 24 h → „1× denně"), **uloží** `clients.is_vat_payer` a vrátí
  `{ is_vat_payer, source, ic, dic }`. Registrace v `api/src/Routes.php`.

### 4. Frontend — purchase editor (`web/src/pages/purchase-invoices/InvoiceEditor.vue`)
- Do flags řádku **pod „Reverse charge"** přidat volbu „Dodavatel je plátce DPH"
  (checkbox vázaný na `form.vendor_is_vat_payer`) — viditelné zobrazení dnes skrytého
  `clients.is_vat_payer`.
- `onVendorSelected` + edit hydratace: zavolat `clientsApi.getVatStatus(vendorId)` (nový
  wrapper nad `GET /clients/{id}/vat-status`), nastavit `vendor_is_vat_payer`.
- Když je dodavatel **neplátce**: auto-nastavit `form.vat_deduction='none'`, vynulovat
  sazby řádků na 0 % a zobrazit nenápadnou poznámku „neplátce → bez nároku na odpočet".
  Uživatel může checkbox/vat_deduction přepsat (override).
- `clients.ts`: rozšířit `AresLookupResult` o `is_vat_payer`; přidat `getVatStatus(id)`.
- i18n cs/en: `purchase_invoice.fields.vendor_is_vat_payer` + hint + warning text.

### 4b. Frontend — seznam klientů/dodavatelů (`web/src/pages/clients/ClientList.vue`)
- Přidat sloupec **„Plátce DPH"** (Ano/Ne badge) — viditelné hlavně v `?role=vendors`.
- Backend list serializer (`ListClientsAction` / `ClientRepository` list dotaz) musí
  vracet `is_vat_payer` v položkách seznamu; `clients.ts` list typ doplnit o `is_vat_payer`.

### 5. api/bin backfill (`api/bin/backfill-vendor-vat-payer.php`)
- Vzor: ostatní `api/bin/backfill-*.php`. Dry-run default, `--apply` provede zápis.
- Pro každého dodavatele (`clients.is_vendor=1`): `VendorVatPayerResolver` (ARES CZ / VIES zahr.)
  → `clients.is_vat_payer`.
  - Pro **neplátce**: přijatým fakturám (mimo cancelled) s `vat_deduction <> 'none'` NEBO
    s nějakou sazbou > 0:
    - `vat_deduction='none'`,
    - položky: `unit_price_without_vat := cena s DPH` (gross), sazba → 0 % → recompute dá
      základ = zaúčtovaná částka, DPH = 0, **total beze změny**,
    - reprefix varsymbolu (`reprefixVarsymbol`).
- Výpis: kolik dodavatelů = neplátci, kolik faktur dotčeno; v dry-run jen náhled.

## Testy

- **Unit** (`AiPdfExtractorUnitTest`): `resolveVendorIsVatPayer` — ARES neplátce ⇒ none;
  doklad „Neplátce DPH" bez ARES ⇒ none; plátce ⇒ full. + že neplátce vynuluje sazby.
- **Integrace** (rozšířit `KhDphTaxScenariosTest`): přijatá faktura od neplátce s
  `vat_deduction='none'` → **nesmí** být v DPHDP3 ř.40 ani v KH B (ověřit přes builder).
- Manuální E2E na `VF_260069mwd.pdf`: re-import → `vat_deduction='none'`, sazby 0 %, warning.

## Verifikace

- `cd api && /mnt/c/inetpub/php/php.exe vendor/bin/phpunit` (plná suite zelená).
- `npm run build` (vue-tsc + vite) — viz [[reference_toolchain_paths]].
- Dry-run `php api/bin/backfill-vendor-vat-payer.php` → zkontrolovat náhled, pak `--apply`.
- ARES lookup IČO 67759661 musí vrátit `is_vat_payer=false`.

## Otevřená interpretace k potvrzení
„u zákazníka to dej pod Reverse charge jako volbu" chápu jako: v **editoru přijaté faktury**
přidat volbu plátcovství dodavatele do flags řádku hned pod checkbox „Reverse charge".
