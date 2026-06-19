# 17. Přijaté faktury (nákupy)

**Přijaté faktury** jsou doklady, které **dostáváš od svých dodavatelů** — peníze
odcházejí z firmy. Oproti vystaveným fakturám:

| | Vystavené (faktura) | Přijaté (purchase invoice) |
|---|---|---|
| Směr peněz | Klient → my (příjem) | My → dodavatel (výdaj) |
| Protistrana | Zákazník (`is_customer=1`) | Dodavatel (`is_vendor=1`) — stejná tabulka klientů, jiný flag |
| DPH role | Sbíráme od klientů (výstupní DPH) | Odečítáme z dodavatelských (vstupní DPH) |
| Číslování | Naše `2605001` | Číslo dodavatele (na originálu) + naše interní `PF2605001` (dle daňového typu, viz 10.2.4) |
| Status flow | draft → issued → sent → paid | draft → received → booked → paid |
| Schvalování / odesílání | Ano, klient potvrdí | Ne, doklad jen evidujeme |

V hlavním menu **Přijaté faktury**.

> [!TIP]
> Samostatné kapitoly k nákupní agendě: [Export přijatých faktur](18_Export_prijatych.md)
> (naše PDF / ISDOC / Pohoda) a [AI extrakce](19_AI_extrakce.md) (import z PDF přes Claude).

## 17.1 Stavy přijaté faktury

| Stav | Význam | Co lze |
|---|---|---|
| **Koncept** (draft) | Rozpracovaný — ještě jsi nepotvrdil že to je platná faktura | Upravit, smazat, přejít na Přijatá |
| **Přijatá** (received) | Doklad potvrzený jako platný — visí na nezaplacených | Označit jako zaúčtovaná, uhrazená, stornovat |
| **Zaúčtovaná** (booked) | Předala se účetní / poslala do účetnictví | Označit jako uhrazená, stornovat |
| **Uhrazená** (paid) | Zaplaceno (manuálně nebo automaticky z bankovního výpisu) | — (terminal) |
| **Stornovaná** (cancelled) | Stornovaný doklad — necháváme pro audit | — (terminal) |

Smazat jde **jen koncept**. Pro pozdější stavy použij Stornovat (zachová auditní stopu).

## 17.2 Nová přijatá faktura

V seznamu klikni **+ Nová přijatá faktura**. Otevře se formulář.

### 17.2.1 Drag & drop PDF
Nad formulářem je **drag & drop zóna**. Pokud máš PDF od dodavatele:

- Přetáhni PDF do zóny (nebo klikni a vyber soubor).
- Systém prohledá PDF zda obsahuje **embedded ISDOC** přílohu:
  - **Pokud ano** (fakturační software jako Money S3, Pohoda, Stormware, sám MyInvoice) → pole formuláře se předvyplní strukturovanými daty.
  - **Pokud ne** (běžné PDF bez přílohy) → můžeš použít **AI extrakci přes Anthropic Claude** (viz [AI extrakce](19_AI_extrakce.md)), nebo pole vyplnit ručně.
- Originál PDF se po prvním uložení faktury automaticky **archivuje** mimo webroot a v detailu si ho můžeš kdykoli stáhnout zpět.

> 💡 Doklad jde nahrát **už při zakládání nové faktury** (po přetažení se ukáže kartička „soubor připraven, nahraje se po uložení") i **z detailu** faktury, která zatím doklad nemá. Kromě PDF lze přetáhnout i **fotku** (JPG/PNG/WEBP/HEIC) — systém ji převede na PDF — nebo přímo **ISDOC / ISDOCX** balíček: ten se rozbalí a naparsuje deterministicky (bez AI), z `.isdocx` se navíc archivuje zabalené PDF pro náhled.

Limity:
- Max 20 MiB per soubor
- Akceptujeme PDF, fotku (JPG/PNG/WEBP/HEIC) a ISDOC/ISDOCX (magic bytes se ověřují server-side)
- SHA-256 deduplikace — stejný doklad už archivovaný u jiné faktury nebude akceptován

### 17.2.2 Povinná pole

| Pole | Význam |
|---|---|
| **Dodavatel** | Vyber z dropdownu (autocomplete). Pokud chybí, klikni „+ Vytvořit nového dodavatele" — využije ARES lookup podle IČO. |
| **Číslo dokladu dodavatele** | Tak jak je vytištěno na originálu (např. `FA-2026-001`). Max 50 znaků. Unique per (dodavatel, datum vystavení) — nelze importovat 2× stejnou. |
| **Naše interní číslo** | Volitelné. Pokud necháš prázdné, vygeneruje se automaticky podle **šablony** při přechodu na stav Přijatá. Výchozí šablona je `{PP}{YY}{MM}{CCC}` (např. `PF2602001`), prefix `{PP}` odpovídá daňovému typu (viz 10.2.4): **PF/PN** plný nárok (uznatelný/ne), **KU/KN** krácený, **NU/NN** bez nároku. Počítadlo je per měsíc (přeteče na 4+ místa nad 999 dokladů). Šablonu lze změnit v **Nastavení → Číslování faktur → Šablona pro přijatou fakturu** (např. legacy `PF-{YYYY}{MM}-{CCCC}` → `PF-202605-0001`). Při ručním zadání čísla systém hlídá kolize (nepovolí duplicitu) a auto-generátor obsazená čísla přeskakuje. |
| **Typ dokladu** | Faktura / Doklad o úhradě / Dobropis / Záloha (pro filtrování v seznamu). |
| **Datum vystavení** | Z faktury. |
| **DUZP (datum uskutečnění zdanitelného plnění)** | Klíčové pro DPH období. Default = datum vystavení. U **reverse charge** se doklad zařazuje do DPH období právě podle DUZP (povinnost přiznat daň vzniká bez ohledu na doručení dokladu); u **pořízení zboží z EU** je DUZP dle § 25 ZDPH **15. den měsíce následujícího po dodání**, pokud doklad nebyl vystaven dříve — editor to připomene hintem. |
| **Splatnost** | Z platebních podmínek dodavatele. |
| **Datum přijetí** | Kdy jsi to fyzicky / e-mailem dostal. Default = dnes. |
| **Měna faktury** | Měna, ve které je doklad vystaven (USD, EUR, CZK…). |
| **Kurz k DUZP** | Pokud je měna ≠ CZK, **musíš zafixovat kurz**. Tlačítko „Načíst z ČNB" stáhne aktuální nebo poslední dostupný denní kurz. |
| **Reverse charge** | Zaškrtni, pokud je doklad B2B s přenesenou daňovou povinností (pořízení zboží z EU, služby z EU/3. země, tuzemský §92a). Položkám nastav **tuzemskou sazbu** (typicky 21 %) a odpovídající klasifikační kód — daň na dokladu zůstane 0 (dodavatel ji neúčtuje), samovyměření i zrcadlový odpočet dopočítají výkazy DPH. Viz [§ 17.2.6](#1726-reverse-charge-z-eu-porizeni-zbozi-vs-sluzba). |

### 17.2.3 Položky

Tlačítkem **+ Přidat položku** přidej řádek. Per řádek:

- Popis
- Množství (např. 1)
- Měrná jednotka (ks / hod / kus…)
- Cena za MJ bez DPH
- Sazba DPH (z číselníku — 21 % / 12 % / 0 %)
- (volitelně) MFČR DPH klasifikační kód — pro výkazy DPH (sekce Daně, auto-default podle sazby)

Souhrn dole se přepočítá automaticky po každé změně.

> 💡 **Ceny „s DPH" (brutto režim)** — přepínačem **Ceny zadávám
> s DPH** (u DPH v hlavičce) lze zadat položky **včetně DPH** (typicky účtenka /
> paragon), takže celková částka sedí na haléř. DPH se pak počítá „shora"
> koeficientovou metodou (§37 ZDP). Zadání ceny do sloupce „Celkem s DPH" respektuje
> aktuální režim (nepřepíná ho); jednotková cena se v detailu i PDF zobrazuje jako
> netto. Funguje stejně jako u vystavených faktur — viz
> [§ 10.2.6](10_Faktura_editor.md#1026-ceny-s-dph-vs-bez-dph-brutto-netto-rezim).
> AI import účtenek režim nastaví sám.

### 17.2.4 Daňová uznatelnost a nárok na odpočet

V boxu **Klasifikace** jsou dva nezávislé příznaky řídící, jak faktura vstupuje do daňových výkazů:

| Příznak | Možnosti | Co ovlivňuje |
|---|---|---|
| **Nárok na odpočet DPH** | Plný / Bez nároku / Krácený | DPH evidenci |
| **Daňově uznatelný náklad** | ano / ne | daň z příjmů (DPFO/DPPO) |

- **Nárok na odpočet DPH:**
  - **Plný** (výchozí) — standardní odpočet, faktura jde do Knihy DPH, DPHDP3 (ř. 40–45) i Kontrolního hlášení.
  - **Bez nároku** — faktura **vůbec nevstupuje** do DPH evidence (Kniha DPH, DPHDP3, KH); je to jen účetní náklad. Typicky reprezentace, osobní spotřeba.
  - **Krácený (poměrný §75)** — odpočet jen v poměrné výši (např. auto 70 % pro ekonomickou činnost). Po výběru zadáš **Odpočet %** a o toto procento se zkrátí základ i daň odpočtu v Knize DPH a DPHDP3 (ř. 40–45); zbytek je nedaňová část.
- **Daňově uznatelný náklad** — řídí pouze daň z příjmů: když je vypnuto, náklad se nezahrne do orientačního hospodářského výsledku (DPFO/DPPO). S DPH to nesouvisí (faktura může mít odpočitatelné DPH a být daňově neuznatelná, i naopak).

Oba příznaky jsou vidět i v **detailu** přijaté faktury (box Měna/DPH).

> 💡 **Interní číslo se řídí daňovým typem.** Prefix automaticky generovaného
> interního čísla (viz 10.2.2) odpovídá těmto dvěma příznakům — **PF/PN** plný
> nárok (uznatelný/ne), **KU/KN** krácený §75, **NU/NN** bez nároku. Když u už
> očíslované faktury daňové uplatnění **změníš**, přepíše se jen **prefix**
> (`PF2602001` → `NN2602001`); číselná řada `{YYMM}{CCC}` i ručně zadaná čísla
> zůstanou. Počítadlo je **sdílené per dodavatel a měsíc napříč všemi prefixy**,
> takže čísla jsou v rámci měsíce souvislá bez ohledu na daňový typ; případné
> mezery po smazaných konceptech jsou u interního označení neškodné (na rozdíl
> od vystavených faktur se u přijatých dokladů souvislá řada nevyžaduje).

#### Rekapitulace DPH dle dokladu (§ 73 ZDPH)

Pod položkami je box **Rekapitulace DPH** se základem a daní **za každou sazbu**. Hodnoty se dopočítají ze řádků, ale pokud doklad dodavatele uvádí kvůli zaokrouhlení jiný **základ** nebo **DPH**, můžeš je **přepsat** přesně podle dokladu. Důvod je daňový: nárok na odpočet je svázaný s **částkou daně uvedenou na dokladu** (§ 73 odst. 6 ZDPH), proto je primární shoda s dokladem, ne náš přepočet.

- Přepsat lze základ i DPH, samostatně pro každou sazbu. Ručně upravené pole je zvýrazněné; odkaz **Spočítat automaticky** vrátí vypočtenou hodnotu.
- Override se uloží do faktury a promítne se konzistentně do **DPH přiznání, kontrolního hlášení, knihy DPH** i do **daně z příjmů** a daňového optimalizátoru.
- Při **AI importu** se rekapitulace předvyplní automaticky dle dokladu (pro jednu i více sazeb), pokud sedí v toleranci.
- Box se nezobrazuje u **reverse-charge** (na dokladu zahraničního dodavatele není česká DPH).

#### Dodavatel neplátce DPH → bez nároku na odpočet

Pokud je dodavatel **neplátce DPH**, na jeho dokladu žádná DPH není a **není co
odpočítat** — uplatnit odpočet by byla daňová chyba (neoprávněný odpočet v ř. 40
přiznání / sekci B kontrolního hlášení). MyInvoice proto plátcovství dodavatele
**sleduje a vynucuje**:

- **Zjištění plátcovství** — autoritativně z **ARES** podle IČO (CZ), u
  zahraničních EU subjektů z **VIES** podle DIČ. Ověří se online při výběru /
  editaci dodavatele ve formuláři (výsledek se cachuje 24 h).
- **Volba v editoru** — pod checkboxem „Reverse charge" je přepínač **„Dodavatel
  je plátce DPH"**. Nastaví se automaticky podle ARES/VIES, ale můžeš ho vědomě
  přepsat.
- **Vynucení u neplátce** — když je dodavatel neplátce, faktura se automaticky
  nastaví na **Nárok na odpočet = Bez nároku** (`vat_deduction='none'`), sazby
  řádků se vynulují na 0 % a zobrazí se varování. Doklad pak do DPH evidence
  nevstupuje (je to jen účetní náklad). Override je možný.
- **AI import** — extraktor plátcovství ověří a u neplátce (signál „DIČ:
  Neplátce DPH" / žádné DIČ + žádná DPH na řádcích, případně ARES) odpočet
  automaticky zakáže a doplní varování (viz [AI extrakce](19_AI_extrakce.md)).

Plátcovství dodavatele je vidět i ve **výpisu klientů/dodavatelů** jako badge
*Plátce DPH* (viz [§ 13.1](13_Klienti.md#131-seznam-klientu)).

> 🛠️ **Zpětná oprava existujících dodavatelů** — jednorázově
> spusť `php api/bin/backfill-vendor-vat-payer.php`. Skript podle ARES/VIES doplní příznak
> plátcovství a u neplátců opraví už zaevidované přijaté faktury (zakáže odpočet,
> sazby na 0 %, **celková částka beze změny**). Výchozí běh je **dry-run** (jen
> náhled); zápis až s `--apply`.

### 17.2.5 Platba v jiné měně (multi-currency)

Klikni na **„Platba v jiné měně než měna faktury"** pokud máš tento scénář:

> Faktura je v USD ($1000), ale platíš ji z CZK účtu (banka konvertuje na ~24 500 Kč
> s 1–2% spread / poplatkem).

V tomto bloku zadáš:

- Měna platebního účtu (např. CZK)
- Kurz platba → měna faktury (např. 0.0408 USD/CZK, nebo opačně dle UI)
- Kolik reálně odešlo z účtu (24 500 CZK)

Systém automaticky vypočte:

- **Ekvivalent v měně faktury** — pro spárování proti `amount_to_pay`
- **Kurzový rozdíl** — v základní měně (CZK). Záporný = kurzová ztráta, kladný = zisk. Zaznamenává se pro reporting a účetně se automaticky promítne do správných řádků DPH výkazů.

### 17.2.6 Reverse charge z EU — pořízení zboží vs. služba

Typický případ: nákup **zboží od EU dodavatele** (např. auto z Německa) — doklad
je vystaven **bez DPH** (osvobozené intrakomunitární dodání) a daň si samovyměříš
v ČR. Správné zaevidování:

| Co | Zboží z EU (pořízení z JČS) | Služba z EU/3. země |
|---|---|---|
| **Sazba na řádcích** | tuzemská **21 %** (případně 12 %) | tuzemská **21 %** |
| **Klasifikační kód** | **23** „Pořízení zboží z JČS" | **24** „Přijetí služby" |
| **DPH přiznání** | ř. 3 (samovyměření) + ř. 43 (odpočet); u majetku navíc ř. 47 | ř. 5/12 + ř. 43 |
| **Kontrolní hlášení** | sekce **A.2** | — |
| **DUZP** | **§ 25**: 15. den měsíce po dodání, pokud doklad nebyl vystaven dříve | den uskutečnění služby |

Klíčové principy:

- **Sazba 0 % na řádku je chyba** — samovyměření by vyšlo nulové. Sazba na
  řádku je *nominální* (daň na dokladu zůstává 0, částka k úhradě se nemění),
  výkazy z ní dopočítají samovyměřenou daň i zrcadlový odpočet. Pojistka: pokud
  řádek s RC klasifikací přesto sazbu nemá, výkazy použijí sazbu klasifikačního
  kódu (21 %).
- **Doklad se do DPH období zařadí podle DUZP** — povinnost přiznat daň vzniká
  k DUZP bez ohledu na to, kdy faktura fyzicky dorazila (§ 25 odst. 1), a pozdní
  doklad neblokuje ani odpočet (§ 73 odst. 1 písm. b — nárok lze prokázat jiným
  způsobem, např. protokolem o převzetí + smlouvou + platbou). Pozdě vystavená
  faktura za zboží převzaté v dubnu tak patří do **května** (DUZP 15. 5.), ne do
  měsíce vystavení.
- **Kurz ČNB se váže k DUZP** (§ 4 odst. 8 — den vzniku povinnosti přiznat daň).
- **AI import tohle vše nastaví sám** — viz [AI extrakce](19_AI_extrakce.md).

> ⚠️ U **vybraných osobních automobilů** pohlídej limit odpočtu dle § 72
> (strop základu 2 000 000 Kč / DPH 420 000 Kč) — aplikace ho nehlídá.

## 17.3 Detail přijaté faktury

Po uložení / přechodu na detail:

- Vidíš dodavatele (s IČO/DIČ), datumy, položky, DPH rozpis, totály, K úhradě.
- Sekce **Originální PDF od dodavatele** — pokud jsi nahrál, můžeš stáhnout zpět.
- Tlačítka pro **přechod stavu** podle state-machine:
  - Z draft: Označit jako přijaté / Stornovat
  - Z received: Označit jako zaúčtované / uhrazené / Stornovat
  - Z booked: Označit jako uhrazené / Stornovat
- „**Označit jako uhrazené**" otevře modální okno s výběrem **data úhrady** (předvyplněno dneškem) — datum se zapíše do záznamu faktury.
- Tlačítko **Upravit** je dostupné jen u draft. Po označení jako přijatá je doklad immutable (kromě admin override `?force=1` u received).
- Tlačítko **Smazat** je dostupné jen u draft. Pro pozdější stavy použij Stornovat.
- Tlačítko **Zaplatit pomocí QR** (u nezaplacených faktur s kladnou částkou k úhradě) — zobrazí QR platbu dodavateli, viz [§ 17.3.2](#1732-zaplatit-pomoci-qr).

### 17.3.1 Propojení zálohy s vyúčtovací fakturou (proti dvojímu započtení)

Když ti dodavatel pošle nejdřív **zálohovou fakturu** (typ dokladu *Záloha* / proforma)
a po zaplacení samostatnou **vyúčtovací (finální) fakturu**, máš v systému dva doklady
na tentýž náklad. Bez propojení by se náklad počítal **dvakrát** (Náklady, CRM, daň
z příjmů). Proto je lze spárovat.

**Jak na to** — v detailu **finální** faktury je box **Zálohová faktura**:

- Pokud vazba není, klikni **Spárovat se zálohou** a vyber zálohu od stejného
  dodavatele. Nabídka řadí napřed zálohy ve **stejné měně** a s **nejbližší částkou**
  (porovnává hrubou částku faktury *před* odečtem zálohy, takže i faktura uhrazená
  zálohou „na 0 Kč" se napáruje správně).
- Po spárování se zobrazí odkaz na zálohu a tlačítko **Zrušit propojení**. Na finální
  fakturu se zároveň doplní odečet zálohy (`advance_paid_amount`), pokud byl nulový.
- V detailu **zálohy** vidíš reverzně, kterou fakturou je vyúčtována. Nevyúčtovanou
  zálohu lze spárovat i **odtud** — tlačítkem **Spárovat s fakturou** (nabídne
  nepropojené vyúčtovací faktury téhož dodavatele). *(Tlačítka se zobrazí jen když existuje vhodný protějšek.)*

Jedna záloha může být navázaná **jen na jednu** finální fakturu.

### 17.3.2 Zaplatit pomocí QR

U **nezaplacené** přijaté faktury (stav koncept / přijatá / zaúčtovaná) s kladnou
částkou k úhradě je v hlavičce detailu tlačítko **Zaplatit pomocí QR**. Otevře okno
s **QR platbou**, kterou naskenuješ v mobilní bankovní aplikaci — pro CZK doklady
ve formátu **QR Platba (SPAYD)**, pro doklady v cizí měně jako **SEPA (EPC)**.

QR sestavujeme z **platebního účtu dodavatele**, částky k úhradě a variabilního
symbolu. Účet se získává v tomto pořadí:

1. **Z ISDOC** — pokud má PDF embedded ISDOC přílohu, vezme se z ní účet/IBAN i VS (zdroj „z ISDOC").
2. **AI rozpoznání** — když uložený účet není a doklad má PDF, lze ho jednorázově
   **rozpoznat z faktury** (krátký dotaz na Anthropic Claude jen na platební údaje).
   Spustí se automaticky při otevření okna (vyžaduje nastavený API klíč — viz
   [AI extrakce](19_AI_extrakce.md)). Proběhne **jen jednou**; pokud účet na dokladu
   není, příště se už neptáme.
3. **Ručně** — účet vyplníš/upravíš přímo v okně (tlačítko **Upravit účet**) nebo
   v editoru faktury v boxu **Platební účet dodavatele**. Stačí buď **číslo účtu +
   kód banky**, nebo **IBAN** (u zahraničních dodavatelů).
4. **Obrázek QR z PDF** — když účet nelze získat, ale v PDF je obrázek, který vypadá
   jako QR kód (čtvercový, černobílý), zobrazí se jako **náhradní řešení** rovnou
   (kód nerozpoznáváme, jen ho ukážeme k naskenování).

Známý účet se zobrazí i v **detailu** faktury (box *Platební účet dodavatele* vedle
měny) a předvyplní se v editoru i v okně QR.

> 💡 QR platbu uvidí i uživatel s rolí **jen pro čtení** (pokud je účet uložený);
> rozpoznání z faktury a ruční úpravu účtu může provést jen uživatel s právem zápisu.

**Co propojení (a zaplacení) ovlivní:**

| Oblast | Chování zálohy |
|---|---|
| **Náklady, CRM statistiky** | Spárovaná **nebo zaplacená** záloha se nepočítá (náklad nese vyúčtovací faktura). Nezaplacená a nespárovaná záloha se počítá jako očekávaný náklad. |
| **Daň z příjmů (DPFO/DPPO)** | Záloha **nikdy** není uznatelný náklad (není daňový doklad) — bez ohledu na zaplacení/párování. |
| **Výkazy DPH** (Kniha DPH, DPHDP3, KH, souhrnné hlášení) | Záloha do nich **nevstupuje vůbec** (není daňový doklad; tím je až vyúčtovací faktura). |
| **Závazky / cashflow** | Nezaplacená záloha zůstává jako reálný závazek k úhradě. |

**AI návrh propojení** — když naimportuješ vyúčtovací fakturu přes AI extrakci z PDF
(viz 10.7) a ta odkazuje na zálohu (text typu *„zaplaceno zálohou č. X"*), systém
zkusí najít odpovídající zálohu a v detailu nabídne **návrh propojení**. Stačí ho
**Potvrdit** (nebo **Zamítnout**) — nic se nepáruje automaticky.

## 17.4 Scan inbox — automatický import z adresáře

Pokud máš dodavatele kteří ti **posílají PDF e-mailem** nebo máš složku
sdílených dokladů, nakonfiguruj **inbox adresář** v `cfg.php`:

```php
'purchase_invoice' => [
    'inbox_dir'         => 'C:/inetpub/wwwroot/myinvoice.cz/inbox',
    'inbox_recursive'   => true,
    'allowed_exts'      => ['pdf', 'isdoc', 'isdocx', 'xml'],
    'archive_storage'   => __DIR__ . '/storage/purchase-invoices',
],
```

V seznamu Přijaté faktury klikni **📥 Nascanovat inbox**:

- Systém rekurzivně projde nakonfigurovaný adresář.
- Pro každý soubor spočte SHA-256 — pokud už existuje faktura se stejným otiskem, soubor přeskočí.
- Z PDF s embedded ISDOC rozpozná data dodavatele a obsah.
- Samostatné `.isdoc` i `.isdocx` balíčky v inboxu rozbalí a naimportuje přímo (z `.isdocx` navíc archivuje zabalené PDF pro náhled).
- Plain PDF (bez ISDOC) jsou při scanu inboxu přeskakovány; takový doklad nahraj přes formulář, kde lze použít AI extrakci.

Modal po skončení zobrazí přehled: vytvořeno / přeskočeno / chyby + per-soubor detail.

**Bezpečnost:** soubory mimo configured `inbox_dir` jsou odmítnuty (path traversal guard
přes `realpath()`). Maximum 500 souborů per běh (DoS protection na velké adresáře).

## 17.5 Klienti vs. dodavatelé

V tabulce klientů jsme zavedli dva flagy:

- `is_customer` — klient, kterému fakturuješ (default `1` pro všechny existující záznamy)
- `is_vendor` — dodavatel, od kterého přijímáš faktury

Některé firmy jsou **současně zákazník i dodavatel** (např. partnerská IT firma, kterou
fakturuješ za development a od níž kupuješ hosting) — jedna entita = jedna řádka,
**oba flagy = 1**. ARES synchronizace, kontakty, historie jsou sdílené.

V hlavním menu **Klienti** vidíš defaultně jen `is_customer=1`. V budoucí verzi
přidáme oddělený view **Dodavatelé** pro `is_vendor=1`.

## 17.6 Audit log

Akce s přijatými fakturami jsou logované v aktivním logu (Systém → Log):

- `purchase_invoice.created`
- `purchase_invoice.updated` / `force_updated`
- `purchase_invoice.items_updated`
- `purchase_invoice.exchange_rate_set`
- `purchase_invoice.transitioned` (s payloadem `{from, to}`)
- `purchase_invoice.extraction_warning_dismissed`
- `purchase_invoice.advance_linked` / `advance_unlinked` (propojení se zálohou)
- `purchase_invoice.advance_suggestion_dismissed` (zamítnutý AI návrh propojení)
- `purchase_invoice.deleted`
- `purchase_invoice.pdf_uploaded` / `pdf_downloaded`
- `purchase_invoice.our_pdf_downloaded`
- `purchase_invoice.isdoc_exported` / `pohoda_exported`
- `purchase_invoice.inbox_scanned`

## 17.7 REST API

Všechny operace jsou dostupné i přes REST API (`/api/v1/purchase-invoices/*`) —
viz [Swagger UI](/api/docs) nebo [Redoc](/api/reference). PAT token musí mít scope
`read_write` pro mutace.
