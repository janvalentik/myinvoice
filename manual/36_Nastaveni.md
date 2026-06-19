# 36. Nastavení

V hlavním menu **Systém** je rozbalovací podmenu se sekcemi pro konfiguraci
aplikace:

- **Dodavatelé** — viz [35. Multi-supplier](35_Multi_supplier.md)
- **Bankovní účty** — měny, účty dodavatele, IMAP účty a bankovní e-mailová avíza
- **Číselníky** — DPH sazby, země, jednotky a další pomocné seznamy
- **Uživatelé** — správa lidí, kteří se přihlašují
- **E-mail šablony** — texty automatických e-mailů
- **Activity log** — kdo co změnil
- **Exporty** — viz [15. Exporty](15_Exporty.md)

## 36.1 Číselníky

**Systém → Číselníky**.

![Číselníky — Měny](img/15_ciselniky_meny.webp)

4 záložky:

### 36.1.1 Měny

Měny a bankovní účty aktuálního dodavatele jsou nově soustředěné na stránce
**Systém → Bankovní účty**. Každý řádek představuje jeden bankovní účet v dané
měně; pokud máš víc účtů pro stejnou měnu, založ více řádků se stejným kódem
měny.

| Pole | Význam |
|---|---|
| Kód | ISO 4217 — `CZK`, `EUR`, `USD`, `GBP` |
| Označení | „CZK — KB", „EUR — Fio" — pro UI rozlišení (víc účtů per měna) |
| Symbol | `Kč`, `€`, `$`, `£` |
| Název CS / EN | „Koruna" / „Crown" |
| Decimals | Počet desetinných míst (2 typicky) |
| Aktivní | Vypnutá měna nelze pro nové faktury |
| Default pro kód | Pokud máš víc účtů per měna (např. 2× CZK), který je default |
| **Účet** (CZK) | Číslo účtu (např. `1000000005`) + bank kód (`0100`) + název banky |
| **Účet** (EUR) | IBAN + BIC + název banky |

> ⚠️ Po **změně bankovního účtu** se **automaticky invaliduje PDF cache**
> všech faktur, které renderují bank info live (drafty + faktury bez
> snapshotu). Faktury v stavu `issued+` mají immutable `bank_snapshot`.

Na stejné stránce je i konfigurace **bankovních e-mailových avíz**: IMAP účty,
mapování bankovní účet → IMAP účet → parser, parser provideri a přehled
zpracovaných e-mailů. Detail je v [§ 24.7 Bankovní e-mailová avíza](24_Banka.md).

### 36.1.2 Sazby DPH

![Číselníky — DPH](img/15_ciselniky_dph.webp)

| Pole | Význam |
|---|---|
| Kód | `CZ-21`, `CZ-12`, `CZ-0`, `CZ-RC` |
| Sazba | `21`, `12`, `0`, `0` |
| Stát | `CZ` (zatím) |
| Popisek CS / EN | Pro UI / PDF |
| Default | Která sazba se předvyplní v editoru |
| Reverse charge | Zatrhneme pro `CZ-RC` |
| Platnost od | Pro historické faktury (15 % v roce 2023) |

### 36.1.3 Země

Statický číselník — nemělo by být potřeba editovat. Obsahuje 200+ zemí podle
ISO 3166-1.

### 36.1.4 Jednotky

Číselník měrných jednotek pro položky faktury. Globální (sdílený mezi
dodavateli), nahrazuje volný textový vstup za dropdown.

| Pole | Význam |
|---|---|
| Kód | Krátký identifikátor (`h`, `ks`, `den`, `měs.`) |
| Popisek CS / EN | Co se zobrazí v UI / PDF (`hodina` / `hour`) |
| Default | Která jednotka se předvyplní při přidání nové položky (typicky `h`) |
| Pořadí | Číslo pro řazení v dropdownu |

> 💡 **Default = `hodina`** dává smysl, protože nová položka přebírá
> hodinovou sazbu z projektu/klienta. Pro jednorázové položky (paušál,
> licence, materiál) jednotku ručně přepneš.

> 🛈 **Auto-clean prázdných položek** — při uložení faktury se řádky bez
> popisu i bez ceny tiše smažou. Můžeš tedy v editoru přidat víc řádků na
> zásobu a nepoužité se neuloží.

## 36.2 Uživatelé

**Systém → Uživatelé** (jen pro admina).

![Uživatelé](img/15_users.webp)

Tabulka uživatelů, kteří se mohou přihlásit. Tlačítko **+ Nový uživatel**.

### 36.2.1 Pole formuláře

| Pole | Význam |
|---|---|
| Jméno | Zobrazení v UI |
| E-mail | Login |
| Heslo | Min. 12 znaků |
| Role | `admin` / `accountant` / `readonly` |
| Jazyk | `cs` / `en` |
| Aktivní | Vypnutý uživatel nemůže se přihlásit |

### 36.2.2 Role

| Role | Co může |
|---|---|
| **admin** | Vše — vystavování, konfigurace, uživatelé, force editace, smazání |
| **accountant** | Vystavování faktur, klienti, banka, exporty, daňové výkazy. **Bez** konfigurace systému, **bez** force editace, **bez** správy uživatelů |
| **readonly** | Vidí **totéž co účetní** — vč. exportů a daňových výkazů (DPH/KH/…) — a smí **data exportovat**, ale **nic nemění** (nezakládá, neupravuje, nemaže). Vhodné pro auditora / klienta |

> 🛈 Rozdíl mezi **accountant** a **readonly** je jediný: zápis. Obě role vidí a
> exportují stejná data; `readonly` jen nemá žádná tlačítka pro úpravy. Úplná
> matice oprávnění je v [§ 39.5 RBAC](39_Bezpecnost.md).

> 🛈 Systém má **guard proti odebrání posledního aktivního admina** — pokud
> jsi sám admin a zkusíš si snížit roli, vrátí 409. Musí být minimálně 1
> admin v systému.

## 36.3 Můj profil

**Pravý horní roh → klik na jméno → Můj profil**. Stejná obrazovka jako
[§ 7.5 Můj profil](07_Prihlaseni.md) — viz screenshot tam.

Můžeš si změnit:

- **Jméno + jazyk**
- **Heslo** — vyžaduje původní heslo
- **2FA** — zapnout / vypnout (vyžaduje heslo + ověření TOTP)

Viz [39. Bezpečnost § 37.2](39_Bezpecnost.md) pro detail TOTP.

## 36.4 E-mailové šablony

**Systém → E-mail šablony**.

![E-mail šablony](img/15_emails_list.webp)

Seznam šablon:

| Kód | Použití |
|---|---|
| `invoice_send` | Odeslání faktury klientovi |
| `invoice_reminder` | Upomínka po splatnosti |
| `proforma_reminder` | Připomínka nezaplacené zálohové faktury |
| `invoice_payment_thanks` | Poděkování za úhradu (viz § 33.5.5) — má i variantu pro zálohu |
| `invoice_approval` | Žádost o schválení výkazu víceprací zákazníkem |
| `recurring_draft_reminder` | Připomínka otevřeného konceptu pravidelné fakturace |
| `password_reset` | Reset hesla (system) |
| `login_otp` | Ověřovací kód pro přihlášení (system) |
| `welcome` | Uvítací e-mail novému uživateli |
| `test` | Pro Test odeslání (debug) |

### 36.4.1 Editor šablony

Klik na řádek → editor.

Záložky podle jazyka × formátu:

- **CS HTML** — česká verze, plný HTML
- **CS Text** — plain text fallback
- **EN HTML** — anglická verze
- **EN Text** — anglický plain text

Editor je **CodeMirror** s syntaxí Twig.

### 36.4.2 Předmět

Pole nahoře, podporuje placeholders (`{{ varsymbol }}`, …).

### 36.4.3 Test odeslání

Tlačítko **Test e-mail** dole — pošle vyplněnou šablonu na **tvůj** e-mail
(přihlášeného admina) s vzorovými daty (faktura `2605001`, klient „Test
Klient s.r.o.", …).

### 36.4.4 Placeholders

Závisí na typu šablony. `invoice_new`:

| Placeholder | Význam |
|---|---|
| `{{ varsymbol }}` | Variabilní symbol |
| `{{ amount }}` | Částka (formátovaná) |
| `{{ currency }}` | Měna |
| `{{ due_date }}` | Splatnost |
| `{{ client_name }}` | Klient |
| `{{ supplier_name }}` | Dodavatel |
| `{{ pdf_url }}` | Odkaz pro stažení PDF (pokud máš public link) |

## 36.5 Activity log

**Systém → Activity log**.

![Activity log](img/15_activity.webp)

Audit všech mutací — kdo a kdy co změnil. Lze filtrovat:

| Filtr | Hodnoty |
|---|---|
| Akce | `invoice.created`, `invoice.issued`, `invoice.sent`, `invoice.paid`, `client.updated`, … |
| Uživatel | Dropdown se všemi |
| Entita | Typ (`invoice` / `client` / `project` / …) + ID |
| IP | IPv4 / IPv6 |
| Období | Měsíc / vlastní rozsah |
| Dodavatel | Per-dodavatel filtrování |

Použití:

- **Audit chyby** — „Kdo upravil fakturu 2605007?" → filter `entity_type=invoice, entity_id=N`
- **Bezpečnostní audit** — „Bylo to z očekávané IP?" → filter `ip`
- **Outage timeline** — všechny akce v intervalu

> 🛈 Activity log se nepromaže automaticky. Cron `cron-cleanup.sh`
> standardně **neničí** activity log, ale lze nastavit retention v
> `cfg.php → app.activity_log_retention_days`.

## 36.6 Elektronické podpisy

Elektronické podpisy mají vlastní stránku **Systém -> Elektronické podpisy**.
Aktuální konfigurace už není jeden certifikát dodavatele, ale sada
podpisových profilů a mapování pro jednotlivé výstupy. Detailní postup je v
[kapitole 28. Elektronické podpisy](38_Elektronicke_podpisy.md).

## 36.7 SMTP log analýza

**Systém → E-maily → záložka SMTP log analýza** (čtvrtá záložka vedle
Odeslaných e-mailů, Šablon a Elektronických podpisů). Přístup pouze pro **admin**.

Zatímco *Odeslané e-maily* ukazují, co se aplikace pokusila poslat (z pohledu
aplikace), tahle záložka ukazuje, **co se reálně stalo na poštovním serveru** —
kam byla zpráva doručena a kde nastal problém. Čte přímo logy MTA (poštovního
serveru) a převádí je na přehledný seznam událostí. Jen čte; nic neodesílá ani
nemění.

### 36.7.1 Co uvidíš

- **Souhrnné karty** — počty doručovacích pokusů, doručeno / odloženo /
  odmítnuto a počet přijatých podání.
- **Cílové servery s problémy** — rychlé dlaždice serverů, kam se nedaří
  doručovat (klik nastaví filtr na daný server).
- **Tabulka událostí** s filtry (fulltext, typ, stav, rozsah dat). Každý řádek
  nese čas, stav, *od → komu*, cílový server + IP, předmět (pokud ho log nese)
  a doslovnou odpověď serveru.
- **Odkaz na fakturu** — pokud událost patří k e-mailu, který aplikace sama
  odeslala, doplní se klikací odkaz na příslušnou fakturu. Páruje se přes
  příjemce a čas odeslání (z interního auditu odeslané pošty); u serverů, které
  logují předmět, pomůže i číslo faktury v předmětu. Pošta, kterou neposlala
  aplikace (např. jiný systém na stejném serveru), se k faktuře neváže.

Druhy událostí (sloupec *typ*):

| Typ | Význam |
|---|---|
| **podání** | Zpráva vstoupila na server (klient/aplikace → MTA). Tady je vidět obálka tak, jak byla podána — pozná se tu např. chybějící příjemce. |
| **doručení** | Pokus o doručení na cílový MX. Nese výsledný stav a odpověď. |
| **událost** | Informativní/chybový záznam vázaný na zprávu (odložení, relay na smart host). |

Stavy:

| Stav | Význam |
|---|---|
| **Doručeno** | Cílový server zprávu přijal (2xx po DATA). |
| **Zařazeno** | Přijato k doručení (podání), zatím neodesláno dál. |
| **Odloženo** | Dočasné selhání (4xx) — greylisting, plná schránka, rDNS. Server to zkusí znovu. |
| **Odmítnuto** | Trvalé odmítnutí (5xx) — antispam politika, neexistující schránka, neověřený odesílatel. |
| **Chyba** | Neúplný dialog / chyba spojení. |

> 🛈 **Box „SMTP analýza" v detailu faktury.** Když je analýza zapnutá, najdeš
> u každé odeslané faktury (sekce pod historií PDF a aktivitou, jen pro admina)
> rozbalovací box, který na kliknutí dohledá v logu doručení právě této faktury —
> prohledá **den odeslání a následující den** pro její příjemce a ukáže per-příjemce
> stav (doručeno / odloženo / odmítnuto) i jednotlivé pokusy s odpovědí serveru.

### 36.7.2 Typické použití

- **„Došlo to klientovi?"** — fulltext na e-mail příjemce → uvidíš poslední stav
  doručení a odpověď jeho serveru.
- **Diagnostika odložení** — `450 4.7.1 cannot find your hostname` značí chybějící
  PTR/rDNS záznam tvé odchozí IP; `452 inbox out of storage` = plná schránka příjemce.
- **Diagnostika odmítnutí** — `541/554 antispam policy`, `550 unauthenticated`
  ukazují na problém s reputací / SPF / DKIM / DMARC.

### 36.7.3 Nastavení

Konfigurace je v `cfg.php` (vzor v `cfg.sample.php`) v sekci `smtp_log`:

| Klíč | Význam |
|---|---|
| `enabled` | `true` = záložka je aktivní. |
| `connector` | Parser pro konkrétní server: `hmailserver` nebo `mailenable`. |
| `path` | Glob vzor k log souborům (absolutní cesta). Hvězdička pokryje denní rotaci. |
| `max_files` | Strop počtu souborů (nejnovější dle data). |
| `max_bytes` | Strop velikosti čteného souboru; větší se čtou od konce. |

Příklady cest:

- **hMailServer** — `C:\Program Files (x86)\hMailServer\Logs\hmailserver_*.log`
- **MailEnable** — `C:\Program Files\Mail Enable\Logging\SMTP\SMTP-Activity-*.log`
  (čte se sada *SMTP-Activity*; *SMTP-Debug* a W3C `ex*` se ignorují)

> 🛈 Podpora dalších serverů (Postfix, Exim…) je připravená architektonicky —
> stačí doplnit nový konektor; konfigurace zůstává stejná, jen se změní
> `connector`.

## 36.8 Tipy

- **Test šablony** vždy před produkčním nasazením — typo v Twig syntaxi by
  rozbilo odesílání všem klientům.
- **Role accountant** je dobrá pro externí účetní — vidí faktury, banku,
  exporty i daňové výkazy, ale nemůže upravit uživatele ani konfiguraci.
- **Role readonly** dej auditorovi nebo klientovi — vidí a exportuje totéž co
  účetní (vč. DPH podkladů), ale nemůže nic změnit.
- **Z Activity logu** zjistíš všechno — i kdo neúspěšně se zkoušel přihlásit
  (filter akce `auth.login_failed`).
