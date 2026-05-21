# 18. Více dodavatelů z jedné instalace

MyInvoice umožňuje fakturovat za **libovolný počet dodavatelů (firem / IČO)**
z jedné instalace. Typické scénáře:

- **OSVČ + s.r.o.** — Jan Novák, OSVČ + Novák s.r.o. = 2 dodavatelé
- **Holding** — mateřská firma + 3 dceřiné = 4 dodavatelé
- **Účetní kancelář** — fakturuje za sebe + spravuje fakturaci pro 20 klientů
- **Sdílený workspace pro tým** — každý kolega má vlastní firmu, ale všichni
  vidí svého

Data jsou **plně izolovaná** — klienti jednoho dodavatele nejsou viditelní
pro druhého, faktury mají vlastní řadu varsymbolů, číselné cykly,
e-mailové šablony atd.

## 18.1 Jak to vidět v UI

Po přihlášení se v horní liště zobrazí **přepínač dodavatele**:

![Přepínač dodavatele](img/14_supplier_switcher.webp)

- Pokud je dodavatel jediný, ukazuje se text „Pracuješ jako: **Název firmy**".
- Pokud je víc, ukazuje se dropdown s aktuálním + ostatními ke přepnutí.

Při přepnutí:

- Aplikace se reloadne (router-link na `/`)
- Pokud jsi byl na detailu / editoru entity, přesměruje na seznam (entita
  patří jinému dodavateli, neviděl bys ji)

## 18.2 Přidání nového dodavatele

V hlavním menu **Systém → Dodavatelé**.

![Seznam dodavatelů](img/14_dodavatele_list.webp)

Tabulka:

| Sloupec | Význam |
|---|---|
| Název | Název firmy / OSVČ |
| IČO | České IČO |
| DIČ | Daňové ID |
| Měn | Počet aktivních měn (= počet bankovních účtů) |
| Klientů | Počet klientů pod tímto dodavatelem |
| Faktur | Počet vystavených faktur |
| Vytvořen | Datum |

Tlačítko **+ Nový dodavatel** vpravo nahoře.

### 18.2.1 Modal nového dodavatele

![Nový dodavatel — ARES](img/14_dodavatel_novy.webp)

| Pole | Význam |
|---|---|
| IČO | Zadej a klikni **Načíst z ARES** — předvyplní zbytek |
| Firma | Název |
| DIČ | (volitelné, OSVČ neplátce nech prázdné) |
| Adresa | Ulice / Město / PSČ / Stát |
| E-mail / telefon | Kontakt |
| První bankovní účet | CZK účet (číslo + bank kód) — automaticky se založí v měně CZK |

Po **Vytvořit** je dodavatel okamžitě v dropdownu, můžeš na něj přepnout.

## 18.3 Co je per-dodavatel (izolované)

Každý dodavatel má vlastní:

- **Klienty** + jejich zakázky + faktury
- **Měny** + bankovní účty (CZK + EUR + …)
- **Číselnou řadu varsymbolů** (každý dodavatel má samostatné `2605001`,
  `2605002`, …)
- **Šablonu čísla faktury** — vlastní formát per typ dokladu (`{YY}{MM}{CCC}`,
  `JD{YYYY}-{CC}`, …) + reset cyklu (rok / měsíc / nikdy) — viz § 18.5.4
- **Výchozí nastavení** — splatnost, hodinová sazba, DPH
- **E-mailové šablony** (faktura nová / upomínka / reset hesla)
- **Pohoda kódy** pro export
- **From: jméno + Reply-To** v odchozích e-mailech
- **Statistiky** (dashboard ukazuje data jen aktuálního dodavatele)

## 18.4 Co je sdílené (cross-supplier)

- **Uživatelé + role** — uživatel vidí všechny dodavatele
- **Číselníky** (DPH sazby, země) — společné systémové
- **Activity log** — všechny mutace logované, ale filtrovatelné per dodavatel
- **IP allowlist + bezpečnostní nastavení** — globální
- **SMTP konfigurace** — globální (`From:` jméno se ale řídí per-dodavatel)
- **Cron skripty** — projedou všechny dodavatele

## 18.5 Editace dodavatele

**Systém → Dodavatelé → klik na řádek → Editovat**.

Záložky:

### 18.5.1 Základní údaje

Stejné jako při založení (IČO, název, adresa, kontakt). Změna se projeví na
NOVÝCH fakturách. Vystavené mají vlastní snapshot.

### 18.5.2 E-mail branding

**From / Reply-To** se odvozuje automaticky:

| Pole | Význam |
|---|---|
| From: jméno | `display_name` dodavatele (fallback `company_name`) — místo „myinvoice@server" |
| Reply-To | `email` dodavatele — odpovědi klientů jdou rovnou na firemní mail |

**Vlastní branding emailů + PDF (od v2.1.0)** — **Systém → Dodavatelé →
detail dodavatele → sekce „Branding emailů"**. Nahraď default „M" logo
MyInvoice vlastním logem firmy a navol akcent barvu napříč emailem. Když
je branding **zapnutý**, použije se stejné logo i v hlavičce **PDF faktur**
(místo textového jména firmy). Když je **vypnutý**, e-mail vrátí default
MyInvoice „M" branding a PDF zobrazí jméno firmy textem — toggle gatuje
obojí konzistentně.

![Branding emailů — toggle, logo, akcent barva, live preview](img/14_branding.webp)

| Pole | Co dělá |
|---|---|
| **Použít vlastní branding** | Toggle vpravo nahoře (default vypnuto = MyInvoice branding). Pokud zapnuté, hlavička emailů i PDF se sestaví z polí níže. |
| **Logo** | Upload PNG / JPG / SVG (max 1 MiB, ideálně do 200 KiB). Pro raster ideální výška 240 px (zobrazí se v emailu jako 48 px na 5× retině). SVG: originál se uloží pro PDF (vektor = crisp v libovolném zoomu), pro email se serverstrana převede na transparentní PNG (Outlook a Gmail SVG strippují) — primárně přes PHP `Imagick` extension (cross-platform — Windows i Linux), fallback na `rsvg-convert` CLI (`librsvg2-bin`). Logo se v emailu připojí jako CID inline image, takže se zobrazí bez „Display images" promptu v Gmailu/Outlooku. Tlačítka **Nahradit logo** / **Odebrat**. |
| **Akcent barva** | Hex `#RRGGBB` — barva náhradního „M" boxu (pokud nemáš logo) a všech odkazů v emailu. Default `#3B2D83` (fialová MyInvoice). Color picker + textový input pro přesné zadání + odkaz **↺ default** pro reset. |

> 🛈 **Auto-save** — toggle a barva se ukládají **automaticky** (color picker
> má 0,5 s debounce, ať se neukládá při každém pixelu pohybu). Logo se ukládá
> okamžitě při uploadu. Tlačítko **Uložit branding** je explicitní fallback
> pro jistotu — typicky ho nepotřebuješ.

V hlavičce se pak vykreslí:

- **Logo** vlevo (místo fialového „M" boxu) — `<img>` s `max-height: 48px`
- **Brand name** = `display_name` dodavatele (fallback `company_name`)
- **Subtitle** = `tagline` dodavatele (pokud vyplněno)

**Live preview** — pod nastavením iframe se zkušebním emailem (faktura
`2026005`, Kč částka, sample odstavec — viz screenshot výše). Tlačítka
**CS / EN** přepínají jazyk preview. Po každé změně toggle / barvy /
loga se preview obnoví automaticky; tlačítko **↻** vpravo nahoře v hlavičce
preview je manuální refresh, kdyby si cache hrála.

**Patička emailu** vždy obsahuje malý šedý text „Používá fakturační systém
[MyInvoice.cz](https://myinvoice.cz/)" jako attribution — nezakrývá tvoji
firemní identitu, jen drobně označuje použitou platformu.

> 🛈 **Snapshot vs live branding** — fakturační údaje (název firmy, adresa,
> kontakt) se v emailu berou ze **snapshotu** zachyceného při vystavení faktury
> (immutable, kvůli auditu). Naopak **branding** (logo, barva, toggle) se vždy
> bere **live** z aktuálního stavu dodavatele — pokud změníš logo, projeví se
> okamžitě i v emailech ke starým fakturám.

> ⚠️ **SVG na hostu bez Imagick i `rsvg-convert`** — SVG upload selže
> s hláškou „SVG konverze není dostupná". Buď nainstaluj jedno z toho:
> - **PHP `imagick` extension** (cross-platform — Windows: `pecl install imagick`,
>   Linux: `apt install php-imagick`, macOS: `pecl install imagick`) — preferované
> - **`librsvg2-bin`** (Linux: `apt install librsvg2-bin`, macOS: `brew install librsvg`)
>
> Docker image `ghcr.io/radekhulan/myinvoice` má `librsvg2-bin` zabalené, takže
> SVG funguje out-of-the-box. PNG / JPG funguje vždy přes GD (built-in).

### 18.5.3 Číslování faktur

V detailu dodavatele najdeš sekci **Číslování faktur** se šablonami pro každý
typ dokladu a volbou cyklu, kdy se pořadové číslo resetuje.

**Šablony (per typ dokladu):**

| Pole | Co zadat |
|---|---|
| Šablona pro fakturu | např. `{YY}{MM}{CCC}` → `2605001` (default) nebo `JD{YYYY}-{CCC}` → `JD2026-001` |
| Šablona pro zálohovou | např. `9{YY}{MM}{CCC}` → `92605001` (prefix 9 = záloha) |
| Šablona pro dobropis | např. `7{YY}{MM}{CCC}` → `72605001` (prefix 7 = dobropis) |

Placeholdery:

| Token | Význam | Příklad pro 2026-04, counter=42 |
|---|---|---|
| `{YYYY}` | 4-ciferný rok | `2026` |
| `{YY}` | 2-ciferný rok | `26` |
| `{MM}` | číslo měsíce (01..12) | `04` |
| `{C}`, `{CC}`, `{CCC}`… | counter, padding podle počtu C | `42`, `42`, `042` |

> 🛈 Pole nech **prázdné** a systém použije fallback z `cfg.varsymbol.templates`
> (default `{YY}{MM}{CCC}` pro fakturu, `9{YY}{MM}{CCC}` pro proformu,
> `7{YY}{MM}{CCC}` pro dobropis). Vyplň, jen když chceš vlastní řadu.

Pod každým polem **live preview** ukazuje, jak by vypadalo příští číslo
(např. „Náhled: `JD2026-001`"). Když chybí counter (`{C+}`), pole je červené
s chybou „Chybí counter".

**Reset číselné řady:**

| Hodnota | Kdy se counter vrací na 1 |
|---|---|
| **Roční** (`year`) | 1. ledna |
| **Měsíční** (`month`) | 1. dne v měsíci (default — backwards compat s legacy chováním) |
| **Bez resetu** (`none`) | Nikdy — souvislá číselná řada napříč roky |

> ⚠️ **Změna cyklu uprostřed roku** může vyrobit duplicitní čísla. Pokud
> přepneš z `month` na `year` a šablona obsahuje `{MM}`, dostaneš v dalším
> měsíci stejné `{YY}{MM}001` jako už máš v evidenci. Backend chytne přes
> 409 chybu při Vystavení, ale doporučujeme spolu s změnou cyklu **upravit
> i šablonu** (pro `year` vyhoď `{MM}`, pro `none` vyhoď `{YY}` i `{MM}`).

**Kde se to projeví:**

- V editoru konceptu vidíš **placeholder** s předpokládaným číslem (preview).
- Při Vystavení (Issue) se atomicky vezme další counter z DB a uloží jako
  immutable `varsymbol`.
- V editoru konceptu můžeš číslo přepsat ručně — viz [§ 11.2.5](11_Faktura_editor.md#1125-číslo-dokladu--ruční-override-volitelné).

### 18.5.4 Pohoda kódy

| Pole | Význam | Příklad |
|---|---|---|
| Číselná řada | `pohoda_account_code` | `FV` |
| Středisko | `pohoda_centre_code` | `01` |
| Činnost | `pohoda_activity_code` | `100` |
| Předkontace | `pohoda_classification_code` | `300` |

Viz [16. Exporty → § 13.4.2](16_Exporty.md).

## 18.6 Smazání dodavatele

Zatím **není v UI** — vyžaduje SQL zásah z důvodu integrity (faktury,
klienti, zakázky). Pokud potřebuješ, kontaktuj IT — `php api/bin/reset.php
--supplier=N` (TODO).

## 18.7 X-Supplier-Id v API

Aktuální dodavatel se posílá v každém API requestu jako header
`X-Supplier-Id: N`. UI ho posílá z localStorage (`myinvoice.current_supplier_id`).

Pokud header chybí, server fallbackuje na `MIN(supplier.id)` — typicky první
dodavatel = ten z setup wizardu.

Pro programátory: viz `source/04-api.md` v repu.

## 18.8 Tipy

- **Při založení dodavatele použij ARES** — ušetří 5 minut opisování.
- **Nevynechej Pohoda kódy** pokud plánuješ používat Pohoda XML export.
- **Per-dodavatel `From:` jméno** je důležitý pro deliverabilitu — klient
  vidí v inboxu „Faktury MyWebdesign" místo „myinvoice@server-3.hosting.cz".
- **Sample data se vygenerují jen pro jednoho dodavatele** — pokud máš víc
  a chceš testovací sadu pro každého, musíš spustit `php api/bin/sample.php`
  vícekrát s parametrem (TODO).
