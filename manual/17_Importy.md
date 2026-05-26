# 17. Importy (Pohoda XML, ISDOC, PDF/A-3, iDoklad API, Fakturoid API)

Pokud máš historické vystavené faktury v jiném systému (Pohoda, iDoklad,
Fakturoid, Superfaktura nebo jiný fakturační software podporující ISDOC),
můžeš je do MyInvoice **naimportovat** — nemusíš je opisovat ručně.

Existují dvě cesty:

1. **Soubor upload** (Pohoda XML, ISDOC, PDF/A-3 s embedded ISDOC) — sekce 17.1–17.7
2. **Přímý API import z iDoklad / Fakturoid** (OAuth2 credentials + background job) — sekce 17.8–17.9

> **Importují se jen tvoje vystavené faktury** (ne přijaté, ne nákupní doklady
> jiné firmy). Dodavatel ve vstupním souboru se musí shodovat s aktuálně
> zvoleným dodavatelem v aplikaci.

## 17.1 Obrazovka importů

V hlavním menu **Systém → Importy**.

Formulář:

- **Soubory** — přetáhni nebo klikni pro výběr. Akceptuje:
  - `.xml` (Pohoda dataPack)
  - `.isdoc` (ISDOC 6.0.x)
  - `.pdf` (PDF/A-3 s embedded ISDOC přílohou — viz § 17.6)
  - `.zip` s libovolným počtem těchto souborů uvnitř
- **Importovat** — odešle a vrátí report (kolik vytvořeno / přeskočeno / chyba).

## 17.2 Co se založí

Pro každou fakturu v souboru:

| Entita | Logika |
|---|---|
| **Klient** | Lookup po IČO. Pokud neexistuje, načteme adresu z **ARES** (preferenčně), fallback na adresu z XML. Vznikne nový klient. |
| **Zakázka** | Když má faktura `číslo zakázky` (ISDOC `OrderReference/ID`, Pohoda `numberOrder`), přiřadí se k zakázce s tím číslem (vytvoří se, pokud chybí). Pokud nemá číslo zakázky, ale klient má v importovaném balíku **více různých e-mailů**, vytvoří se per-email zakázka s názvem `{Firma} – {email}`. Jinak `bez zakázky`. |
| **Faktura** | Přepíše se do `invoices` se zachovaným původním varsymbolem. Položky, sazby DPH, kurz, měna se převezmou. Snapshoty (klient/dodavatel/banka) se zafixují z aktuálních dat. |

## 17.3 Stav (paid vs issued) — pravidlo 30 dní

Aby ses nemusel po importu zabývat starými fakturami:

- **Datum splatnosti starší než 30 dní** → faktura se uloží jako **Zaplacená**
  (`paid_at` = DUZP nebo datum vystavení). Předpoklad: starý doklad už dávno
  zaplacený.
- **Datum splatnosti v posledních 30 dnech (nebo v budoucnu)** → faktura se
  uloží jako **Vystavená**. Můžeš platbu spárovat standardním flow přes
  bankovní výpis nebo ručně označit jako zaplacenou.

## 17.4 Co se přeskočí

- **Cizí dodavatel** — celý soubor se přeskočí, pokud IČO dodavatele v souboru
  neodpovídá aktuálnímu dodavateli v aplikaci. (Hláška v reportu.)
- **Duplicita** — pokud faktura s daným varsymbolem u tohoto dodavatele už
  existuje, přeskočí se. V reportu se zobrazí důvod a id existující faktury.

## 17.5 Report

Po importu vidíš tabulku:

| Sloupec | Význam |
|---|---|
| Soubor | Cesta v balíku (název ZIPu / interní cesta) |
| Stav | `vytvořeno` / `přeskočeno` / `chyba` |
| Var. symbol | Z faktury |
| Detail | Link na vytvořenou fakturu, badge `paid`/`issued`, štítky `+ klient` / `+ zakázka` (pokud něco vzniklo). U přeskočených/chybných: důvod. |

## 17.6 PDF/A-3 import (embedded ISDOC)

Většina českých fakturačních systémů (**iDoklad**, **Fakturoid**, **Superfaktura**,
**Pohoda**, **MyInvoice**) dnes vkládá ISDOC XML přímo do PDF dokumentu jako
přílohu — viz standard **PDF/A-3** + ISDOC spec. Pokud máš v ruce jen PDF
faktury (typicky to, co ti přišlo emailem od dodavatele), můžeš ho importovat
přímo — MyInvoice z něj vytáhne embedded `*.isdoc` přílohu a importuje stejně,
jako kdybys nahrál samostatný `.isdoc` soubor.

**Jak to poznáš, jestli PDF má embedded ISDOC?**

- Otevři PDF v jakémkoli prohlížeči, klikni na ikonu **přílohy / sponky**.
  Pokud uvidíš soubor typu `*.isdoc` (často `invoice.isdoc`, ale třeba iDoklad
  ho pojmenuje `Vydaná faktura - 20230005-invoice.isdoc`), je to ono.
- V `Adobe Reader` najdeš přílohu v levém panelu pod ikonou kancelářské sponky.
- Můžeš to taky zjistit příkazem `pdfdetach -list <soubor>.pdf` (z balíku
  `poppler-utils`), nebo jakýmkoli PDF prohlížečem podporujícím přílohy.

**Co když PDF přílohu nemá?**

Pak ho **nelze automaticky importovat** — pure PDF nemá strukturovaná data
faktury, jen vizuální layout. Import vyhodí čitelnou chybu „PDF neobsahuje
ISDOC přílohu". V tom případě:

- Buď v původním systému (iDoklad, Pohoda …) **stáhni XML/ISDOC samostatně**
  a importuj ten soubor.
- Nebo fakturu zadej ručně.

**Co se podporuje:**

- ✅ PDF/A-3 s `/Type /EmbeddedFile` + filename končící `.isdoc` (oficiální
  ISDOC PDF spec).
- ✅ PDF s embedded ISDOC pod jiným jménem (content sniff podle ISDOC
  namespace `http://isdoc.cz/namespace/2013`).
- ✅ PDF s *compressed object streams* (`/Type /ObjStm`, PDF 1.5+).
  Spec sice ObjStm zavedlo, ale **stream objekty (a tím i `EmbeddedFile`)
  v ObjStm být nesmí** — vždy zůstávají na top-level, takže náš scanner
  je najde i v takových PDF.

**Limity:**

- ❌ **Šifrované PDF** (heslem nebo certifikátem). Stream byty jsou
  zašifrované, extractor je neumí dekódovat. Otevři PDF v Adobe Readeru,
  zadej heslo, ulož znovu bez šifrování, a pak nahraj.
- ❌ **Non-FlateDecode stream filtr** (LZW, RunLengthDecode, ASCII85
  bez následného Flate). Extractor zvládá jen FlateDecode (drtivá
  většina dnešních PDF). U starších/legacy producentů můžeš narazit.
- ❌ **Vícestupňový filter chain** (`/Filter [/ASCII85Decode /FlateDecode]`).
  Vzácné, ale existuje. Workaround: stáhni si ISDOC samostatně v původním
  systému.

## 17.7 Tipy

- **Před importem nahraj klienty z ARES** — ne nutné, ale pokud máš čas, můžeš
  je založit ručně se správnou výchozí měnou a paušálem; import pak jen použije
  existující ID a nebude tahat ARES.
- **Pohoda → MyInvoice** — exportuj v Pohodě data balíček (XML), nahraj sem.
  Pohoda neukládá `číslo zakázky` per fakturu, takže se importují bez zakázky
  (pokud klient nemá více emailů — viz § 17.2).
- **Multi-supplier** — přepni v aplikaci na cílového dodavatele předtím, než
  spustíš import. IČO z XML se ověří proti tomuto kontextu.
- **Co dělat, když import vyhodí chybu** — soubor zkontroluj v textovém
  editoru, jestli má validní XML a očekávaný root element (`<dat:dataPack>`
  pro Pohodu, `<Invoice>` v ISDOC namespace pro ISDOC). Pro PDF zkontroluj,
  jestli má `.isdoc` přílohu (viz § 17.6).

## 17.8 API import z iDoklad

Alternativa k file uploadu: přímé volání iDoklad API v3 (OAuth2 Client Credentials).
Vhodné pro většinu dat — táhne **kontakty + vystavené faktury + dobropisy + přijaté
faktury** najednou, po sekcích a rocích, s dry-run preview a background jobem.

### 17.8.1 Získání API credentials

1. Přihlas se do [iDokladu](https://app.idoklad.cz/).
2. **Nastavení → API přístup** (nebo **Uživatelský účet → API**).
3. **Vytvořit nový API klíč** → typ **Client Credentials**.
4. Zkopíruj:
   - **Client ID** — identifikátor aplikace
   - **Client Secret** — tajný klíč (zobrazí se **jen jednou**; uschovej si ho)

### 17.8.2 Nastavení v MyInvoice

`Systém → Externí integrace → iDoklad` (admin only):

| Pole | Popis |
|---|---|
| **Client ID** | Vložit z iDokladu |
| **Client Secret** | Vložit z iDokladu (uloží se šifrovaně AES-256-GCM per supplier) |

Klikni **Uložit** → MyInvoice si **otestuje connection** (token endpoint + ping
na první sekci). Pokud OAuth2 selže (401), zkontroluj copy-paste (typicky se
přidá whitespace).

### 17.8.3 Spuštění importu

Na téže stránce, sekce **Spustit import**:

| Pole | Popis |
|---|---|
| **Roky** | Range (např. `2020-2025`); můžeš zvolit i jen aktuální + minulý rok |
| **Sekce** | Zaškrtnout: `contacts` / `invoices` / `credit-notes` / `purchases` |
| **Dry-run (jen náhled)** | Default ON pro první běh — nepíše nic do DB, jen vypíše co BY udělal |

Klikni **Spustit import**.

### 17.8.4 Co se importuje

| Sekce | Co se vytvoří |
|---|---|
| **contacts** | `clients` rows (IČ, name, address, DIČ, email, phone). ARES NEvolá — důvěřuje datům z iDokladu. |
| **invoices** | `invoices` + `invoice_items` + VAT classification. Status: `paid` nebo `issued` per pravidlo 30 dní (viz § 17.3). |
| **credit-notes** | `invoices` se `invoice_type='credit_note'` + parent link na původní fakturu (přes `parent_invoice_id`). |
| **purchases** | `purchase_invoices` + `purchase_invoice_items` (od v3.6.0). Klient → `clients` s `is_vendor=true`. |

**Sleva** *(od v4.3.0)* — sleva z iDokladu se přenáší: sleva na úrovni dokladu
(`DiscountType=OnDocument`) se u vydaných faktur uloží jako procentuální sleva
(viz § 11.4.1), u přijatých jako záporná položka „Sleva X %" po sazbách DPH;
položková sleva se zapečetí do jednotkové ceny. Importovaná částka tak odpovídá
iDokladu (dřív se sleva ignorovala a faktura se importovala za plnou cenu).

**Idempotence:** každý záznam má v DB sloupec `idoklad_id`, který se uloží při
prvním importu. Druhý import téhož období záznamy **přeskočí** (žádné duplicity,
žádný update existujících — import je čistě additivní).

## 17.9 API import z Fakturoid

Stejný flow jako iDoklad, jen jiný provider. **Od v4.1.0 podporujeme dvě auth
metody** — legacy email + API token i nový OAuth2 Client Credentials (issue #31).

### 17.9.1 Získání API credentials

**Nově založené účty (po 2024) — OAuth2:**

1. Přihlas se do [Fakturoidu](https://app.fakturoid.cz/).
2. **Nastavení → API v3 přístupové údaje**.
3. **Přidat aplikaci** → zkopíruj **Client ID** + **Client Secret**.
4. Zjisti **slug účtu** — část URL: `https://app.fakturoid.cz/{slug}/...`,
   např. `jannovak`.

**Starší účty (před 2024) — legacy:**

1. **Nastavení → API přístup → Osobní API token**.
2. Zkopíruj **email** + **API token**.
3. Zjisti **slug** (stejný postup).

### 17.9.2 Nastavení v MyInvoice

`Systém → Externí integrace → Fakturoid`:

Přepínač **Typ autentizace**:

| Typ | Pole |
|---|---|
| **OAuth2 (Client Credentials)** — pro nové účty | Slug + Client ID + Client Secret |
| **Email + API token (legacy)** — pro starší účty | Slug + Email + API token |

Oba způsoby koexistují per-supplier. Pokud má supplier vyplněné oba bloky,
**OAuth2 má prioritu** (Bearer token).

OAuth2 token MyInvoice cachuje šifrovaně (AES-256-GCM v
`supplier.fakturoid_access_token_enc`) s TTL ~2h. Při HTTP 401 se token vyhodí
a obnoví automaticky — uživatel to nemusí řešit.

### 17.9.3 Spuštění importu

Identické s iDoklad (viz § 17.8.3) — vyber roky, sekce, dry-run.

### 17.9.4 Co se importuje

| Sekce | Co se vytvoří |
|---|---|
| **contacts** (Fakturoid `subjects`) | `clients` |
| **invoices** | `invoices` + `invoice_items` + DPH klasifikace |
| **credit-notes** | `invoices` s `invoice_type='credit_note'` |
| **purchases** (Fakturoid `expenses`) | `purchase_invoices` |

Fakturoid stránkuje po 40 záznamech — MyInvoice automaticky tahá všechny stránky
za vybrané roky.

**Idempotence přes `fakturoid_id`** stejně jako u iDokladu.

## 17.10 Dry-run mód

Společný pro iDoklad i Fakturoid. Po zaškrtnutí **Jen náhled (dry-run)** se import
provede **synchronně** (vrátí výsledek najednou) a **nezapisuje do DB**. Slouží
k validaci credentials + náhledu dat.

**Příklad výstupu:**

```
[contacts]    Nalezeno 45 kontaktů — 40 by se vytvořilo, 5 přeskočeno (duplicita)
[invoices]    Nalezeno 120 faktur — 115 nových, 5 přeskočeno (varsymbol existuje)
[purchases]   Nalezeno 30 přijatých faktur — 30 nových
```

Pokud výstup vypadá rozumně, odzaškrtni dry-run a spusť ostrý import.

## 17.11 Background job (ostrý import)

Ostrý import (bez dry-run) běží jako **background worker** přes PHP CLI proces
(`api/bin/import-worker.php`). Aplikace vrátí `job_id` okamžitě a UI sleduje
průběh:

1. **Progress bar** se aktualizuje pollingem `GET /api/admin/import-jobs/{id}`
   (každé 2 sekundy, viz `import_jobs` migrace 0029).
2. **Detailní log** každého záznamu (sekce, akce, ID v DB / důvod přeskočení).
3. **Tlačítko Zrušit import** — worker bezpečně dokončí aktuální batch a zastaví
   se. Status v DB se nastaví na `cancelled`.

**Prevence duplicitních jobů:** stejné parametry (provider + sekce + roky)
nelze spustit znovu, dokud běží — UI vrátí 409 Conflict s odkazem na běžící job.

## 17.12 Časté problémy API importu

**„Neplatné credentials" / 401 Unauthorized**
→ Whitespace v copy-pastu Client Secret / API tokenu. Vygeneruj credentials znovu
a vlož pečlivě (bez okolních mezer / newlines). Test connection v Nastavení by
měl projít zelený.

**„Slug Fakturoid — kde ho najdu?"**
→ Z URL po přihlášení: `https://app.fakturoid.cz/jannovak/invoices` → slug je
`jannovak`. Slug je tvoje subdoména, **ne** company name.

**Import se zasekl / „neodpovídá"**
→ V UI klikni **Zrušit import**. Pokud nepomůže, restartuj backend kontejner
(`docker compose restart app`) a spusť znovu. Workers nejsou supervised — po
restartu spadnou tichá.

**Faktury se importují, ale chybí DPH klasifikace**
→ V iDoklad/Fakturoid musí mít položky vyplněné členění DPH. Pokud chybí,
MyInvoice použije auto-default podle sazby (`VatClassificationDefaulter`):
21 % → `1` (sales) / `40` (purchase), 12 % → `2`/`41`, 0 % → `3`/`42`.

**Kontakty z iDoklad/Fakturoid nemají emaily**
→ Originální systém je nemá vyplněné. Doplň ručně v `Klienti` po importu —
jinak nebudou fungovat upomínky.
