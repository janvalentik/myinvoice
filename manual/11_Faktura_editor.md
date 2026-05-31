# 11. Faktura — editor a výkaz víceprací

Editor faktury slouží k tvorbě nového konceptu nebo úpravě existujícího.
Otevře se přes **+ Nová faktura** (z dashboardu), **Faktury → Nová faktura**,
nebo z detailu klienta / zakázky.

## 11.1 Editor — celkový přehled

![Editor faktury](img/09_editor.webp)

Editor je rozdělený na tři bloky:

1. **Hlavička** (vlevo nahoře) — typ, klient, zakázka, data
2. **Položky** (střed) — řádky faktury
3. **Sumář a akce** (vpravo nahoře + dole) — částky, sleva, tlačítka

## 11.2 Hlavička

### 11.2.1 Typ dokladu

| Typ | Popis | Variabilní symbol |
|---|---|---|
| **Faktura** | Standardní daňový doklad | YYMMNNN — `2605001` |
| **Zálohová (proforma)** | Před DUZP, není daňový doklad. Po zaplacení můžeš z ní vytvořit „Daňový doklad" se započtením zálohy. | `9` + YYMMNNN — `92605001` |
| **Dobropis (opravný daňový doklad)** | Záporné částky, stornuje původní fakturu | `7` + YYMMNNN — `72605001` |
| **Storno (interní)** | Pouze interní označení, nevystavuje se klientovi | (bez prefixu) |

### 11.2.2 Klient + Zakázka

- **Klient** (povinný) — vyber z dropdownu, vyhledávání podle jména / IČO.
- **Zakázka** (volitelná) — pokud klient má zakázky, dropdown nabídne jen jeho
  vlastní. Po výběru zakázky se předvyplní hodinová sazba a splatnost.

> ⚠️ Pokud změníš klienta uprostřed editace, zakázka se vyresetuje (původní
> patřila jinému klientovi).

### 11.2.3 Data

| Pole | Význam |
|---|---|
| Vystaveno | Datum vystavení (dnes default) |
| DUZP | Datum uskutečnění zdanitelného plnění (= vystaveno default) |
| Splatnost | Datum splatnosti — automaticky vypočítáno z `vystaveno + splatnost zakázky` (nebo klienta nebo systému) |
| Datum úhrady | Vyplní se automaticky při zaplacení (přes banku nebo manuálně) |

### 11.2.4 Měna a DPH

- **Měna** — předvyplní se z klienta (nebo zakázky), lze přepsat.
- **Reverse charge** — checkbox; pokud zatržené, faktura bude bez DPH s textem
  „Daň přiznává odběratel". Předvyplní se z klienta.

### 11.2.5 Číslo dokladu — ruční override (volitelné)

V hlavičce konceptu je pole **Číslo faktury** (resp. „Číslo zálohové faktury" /
„Číslo dobropisu" podle typu). Pole je **volitelné**:

- **Prázdné** — při Vystavení (status `draft → issued`) systém automaticky
  vygeneruje číslo dle šablony (per-dodavatel v Nastavení nebo globální cfg).
  V placeholderu pole vidíš live náhled, např. `2605002` — to je číslo, které
  fakturu dostane, pokud nepřepíšeš.
- **Vyplněné** — backend použije přesně tvou hodnotu, neinkrementuje counter.
  Při Vystavení se ověří, že číslo není už použité u jiné faktury **stejného
  dodavatele** (jinak vrátí chybu „Číslo už existuje…"). Max 20 znaků.

> 💡 **Použití:** standardně nech prázdné, automatika tě nezklame. Manual
> override použij jen výjimečně — např. když migruješ historickou fakturu
> z jiného systému a potřebuješ zachovat originální číslo.

> ⚠️ **Po Vystavení je číslo immutable** — admin force-edit ho NEodemkne.
> Pokud chceš číslo změnit, musíš vystavit storno/dobropis a fakturu vystavit
> znovu pod jiným číslem.

Šablonu pro automatické generování nastavuješ v **Systém → Dodavatelé →
[tvůj dodavatel] → Číslování faktur** — viz [§ 18.5.3](18_Multi_supplier.md#1853-číslování-faktur).

### 11.2.6 Ceny „s DPH" vs „bez DPH" (brutto / netto režim)

> Přidáno v4.7.0.

Přepínač **Ceny zadávám s DPH / bez DPH** (v hlavičce u DPH) určuje, jak se na
faktuře počítá daň:

| Režim | Co je vstupem | Jak se počítá DPH | Typické použití |
|---|---|---|---|
| **bez DPH (netto)** *(výchozí)* | cena bez DPH | „zdola": `DPH = základ × sazba` | běžné B2B faktury |
| **s DPH (brutto)** | cena včetně DPH | „shora" koeficientem (§37 ZDP): `DPH = round(brutto × sazba/(100+sazba))`, `základ = brutto − DPH` | účtenky, paragony, B2C, kde má sedět **celková částka** |

V režimu **s DPH** se zadává cena do sloupce **„Celkem s DPH"** a celková částka
faktury **sedí na haléř** — např. 33 Kč s DPH @ 21 % → základ **27,27** / DPH
**5,73** / celkem **33,00** (ne 32,9967, které by vyšlo přepočtem zdola). U více
řádků stejné sazby se haléřové reziduum dorovná na nejsilnějším řádku, takže
součet daně přesně odpovídá dani z celkového brutto — **detail faktury, PDF i
DPH výkazy (přiznání, kontrolní hlášení, kniha DPH) ukazují stejné číslo.**

- **Zadání „Celkem s DPH":** editor **respektuje aktuální režim** dokladu (nepřepíná
  ho za tebe). V běžném režimu „bez DPH" se z brutto **dopočítá jednotková cena bez
  DPH** (odečtením DPH shora); v režimu „s DPH" se uloží brutto. Režim přepínáš jen
  ručně přepínačem nahoře.
- **Zobrazení jednotkové ceny:** v detailu, PDF i exportech (ISDOC, Pohoda) se „Cena/MJ"
  vždy ukazuje jako **netto** (bez DPH) — i v režimu „s DPH", kde se netto dopočítá z
  řádkového základu.
- **Předvyplnění per dodavatel:** výchozí režim nové faktury nastavíš v
  **Nastavení → Můj dodavatel → Ceny s DPH** (viz [§ 18.3](18_Multi_supplier.md#183-co-je-per-dodavatel-izolované)).
- **Zpětná kompatibilita:** výchozí stav je „bez DPH" a všechny existující
  faktury zůstávají beze změny.

> 💡 Režim „s DPH" funguje stejně i u **přijatých faktur** (viz [§ 10.2.3](10_Prijate_faktury.md#1023-položky))
> a u **šablon pravidelné fakturace** (viz [§ 15.2.2](15_Pravidelne_fakturace.md#1522-sekce-faktura)).

## 11.3 Položky

Tabulka řádků faktury. Tlačítko **+ Přidat položku** přidá nový řádek.

| Sloupec | Význam |
|---|---|
| Popis | Co fakturuješ. Lze multiline. **Tip:** pokud je v popisu měsíc (`Konzultace 3/2026`), klonování faktury automaticky inkrementuje. |
| Množství | Počet jednotek (kusy / hodiny / …) |
| Jednotka | Z číselníku (default `h` / hodina). Číselník spravuješ v **Systém → Číselníky → Jednotky** — viz [§ 19.1.4](19_Nastaveni.md#1914-jednotky). |
| Cena/jed. | Jednotková cena (v režimu „bez DPH" netto, v režimu „s DPH" brutto — viz [§ 11.2.6](#1126-ceny-s-dph-vs-bez-dph-brutto--netto-režim)) |
| DPH | Sazba — `21 %`, `12 %`, `0 %` (osvobozeno), `RC` (reverse charge) |
| Celkem | Auto-počítáno (množství × cena/jed.) |
| Celkem s DPH | Cena řádku včetně DPH. Zadání respektuje aktuální režim: v režimu „bez DPH" se z brutto zpětně dopočte cena bez DPH, v režimu „s DPH" se uloží brutto — viz [§ 11.2.6](#1126-ceny-s-dph-vs-bez-dph-brutto--netto-režim). |

### 11.3.1 Drag & drop pořadí

Levým úchytem (☰) přetáhni položky pro změnu pořadí. Pořadí se zachová
v PDF.

### 11.3.2 Smazání položky

Křížek vpravo. Pokud položka je propojená s výkazem víceprací (viz § 11.6),
smazání se zeptá, jestli i smazat výkaz.

## 11.4 Sumář (vpravo)

Automaticky se přepočítává:

- **Mezisoučet** — součet `množství × cena/jed.` všech položek
- **Sleva** — pokud je vyplněná (% nebo absolutní, pole vlevo)
- **Základ DPH** — po slevě
- **DPH 21 % / 12 % / 0 % (osvob.) / 0 % (RC)** — rozdělené podle sazeb v položkách
- **Celkem k úhradě** — final částka v měně faktury

> 💡 **Sazby DPH ve výběru** — `0 % (osvob.)` znamená osvobozeno od DPH,
> `0 % (RC)` znamená reverse charge (přenesená daňová povinnost). Sazby mají
> stejné procento, ale jiný legislativní význam — vybírej dle situace.

### 11.4.1 Sleva z celé faktury

Pole **Sleva z celé faktury** (v sumáři vpravo) je **procentuální sleva (0–100 %)** na
úrovni celého dokladu — typicky „sleva 10 % z celé faktury".

Jak to funguje:

- Při uložení se sleva **materializuje jako záporná položka „Sleva X %"** přímo mezi
  položkami faktury (vidíš ji v náhledu, detailu i v PDF).
- Pokud má faktura **víc sazeb DPH** (např. 21 % + 12 %), rozpadne se na zápornou
  položku **na každou sazbu zvlášť** — tím zůstává DPH po slevě účetně správně.
  U běžné faktury s jednou sazbou je to jedna položka „Sleva X %".
- Díky tomu, že je sleva reálná položka, se promítne do souhrnu, rozpisu DPH
  i do všech DPH výkazů (Kniha DPH, přiznání DPH, Kontrolní hlášení).
- Slevovou položku needituješ ručně — generuje se z pole „Sleva"; mění se jen změnou
  procenta. Při klonování faktury a při vystavení daňového dokladu k proformě se sleva
  přenáší.

> 💡 **Sleva pevnou částkou** — pevnou slevu (např. −1 500 Kč) zadáš prostě jako běžnou
> položku se zápornou cenou. Procentuální pole je pro slevu z celé faktury.

### 11.4.2 Faktura v cizí měně (EUR / USD / …) — přepočet do CZK

Pokud je faktura v jiné měně než CZK, MyInvoice po **uložení** automaticky
stáhne denní devizový kurz **z ČNB** a uloží ho na fakturu. Kurz se použije
pro přepočet **základů DPH** a **DPH** do CZK (kvůli českému účetnictví).

**Kdy se kurz načítá:**

- Po každém uložení EUR (cizí) faktury — server pošle požadavek na
  `cnb.cz` (kurzovní lístek pro `issue_date`).
- Pokud kurz pro daný den ještě není (víkend, svátek, pozdě večer):
  systém zkusí **až 7 dní zpět** a najde nejbližší dříve dostupný kurz.
- Kurz se cachuje v lokální DB — opakované otevření faktury už neposílá
  nový request.
- Pokud ČNB nedostupné a žádný kurz není v cache: použije se **poslední
  známý kurz** (z dřívější faktury) a zobrazí se ⚠️ upozornění toast po uložení.

**Co se přepočítává:**

- ✅ **Základy DPH per sazba** (21 %, 12 %, 0 %) → CZK
- ✅ **DPH per sazba** → CZK
- ✅ **Celkem bez DPH / DPH celkem / Celkem** → CZK
- ❌ **Jednotlivé řádky položek** se nepřepočítávají (zůstávají v cizí měně)

Zaokrouhlování CZK přepočtu: **HALF_UP, 2 desetinná místa, zvlášť per sazba DPH**.

**Kde je přepočet vidět:**

- **Detail faktury** — sekce „Přepočet do CZK" pod hlavními totály
- **PDF** — samostatná tabulka „Přepočet do CZK" pod sumářem (světle šedé
  podbarvení), plus drobná řádka „Kurz ČNB: X CZK / 1 EUR (datum)"
- **Editor (re-edit)** — informativní řádka pod totály s použitým kurzem

## 11.5 Tlačítka

| Tlačítko | Funkce |
|---|---|
| **Uložit koncept** | Uloží jako `draft` — zůstane v konceptech, neviditelné pro klienta |
| **Vystavit** | Přidělí variabilní symbol, vygeneruje PDF, status → `issued`. **Nelze vrátit zpět** (jen storno / dobropis). |
| **Vystavit a odeslat** | Vystaví + okamžitě pošle e-mailem klientovi |
| **Náhled PDF** | Otevře PDF v novém tabu (jen pro koncepty s vodoznakem „NÁHLED") |
| **Smazat koncept** | Jen pro `draft` — nelze smazat vystavenou |
| **Klonovat** | Vytvoří nový koncept jako kopii (kapitola 8 „Vystavit znovu") |

## 11.6 Výkaz víceprací (work report)

Pokud fakturuješ za **hodiny**, můžeš ke každé hodinové položce přidat detailní
výkaz, který se vytiskne na **2. stranu PDF**.

![Výkaz víceprací](img/09_vykaz_vicepraci.webp)

### 11.6.1 Aktivace

V editoru klikni na šedou ikonu „Přidat výkaz víceprací" v řádku položky.
Zobrazí se modal/sekce:

| Pole | Význam |
|---|---|
| Datum | Den práce |
| Popis | Co bylo děláno |
| Hodiny | Desetinné číslo (1.5, 0.25, …) |
| Sazba | Default ze zakázky, lze přepsat |
| Celkem | Auto: `hodiny × sazba` |

Přidej řádky → tlačítko **Uložit výkaz**. Suma hodin × sazba se přenese do
hlavní položky faktury (pole „Množství" + „Cena/jed.").

### 11.6.2 PDF výstup

Druhá strana PDF má formát:

```
+--------------------------------------------------+
| Výkaz víceprací — faktura 2605001               |
|                                                  |
| Datum     Popis                Hod.  Sazba   Kč |
| 03.05.    Konzultace strategie  2.0  1500   3000 |
| 04.05.    Code review           1.5  1500   2250 |
| ...                                              |
|                                                  |
| Celkem hodin: 12.5                              |
| Celkem k úhradě: 18 750 Kč                      |
+--------------------------------------------------+
```

### 11.6.3 Smazání výkazu

V editoru klik na ikonu odpojit (řetěz). Položka faktury zůstane, ale ztratí
detailní rozpis.

## 11.7 Schvalování výkazu zákazníkem

Pokud má zakázka zapnuté **„Vyžaduje schválení výkazu práce zákazníkem"** (viz
[§ 8.6](08_Zakazky.md)) a faktura obsahuje výkaz víceprací, faktura **nepůjde
vystavit**, dokud zákazník výkaz neschválí přes e-mailový odkaz. Po schválení
se faktura **automaticky vystaví a odešle**.

V detailu faktury se objeví:

- **Badge stavu schválení** v hlavičce vedle status (Neurčeno / Vyžádán /
  Schválen / Zamítnut)
- Tlačítko **„Odeslat ke schválení"** (vedle Vystavit, jen pro draft)
- Tlačítko **„Test schválení"** (v sekci Další akce — pošle test e-mail
  na adresu dodavatele bez vygenerování reálného tokenu)
- Sekce **„Schválení výkazu zákazníkem"** s detaily (datum žádosti, datum
  rozhodnutí, kdo rozhodl, případný důvod zamítnutí)
- Tlačítko **„Změnit stav"** (jen admin) — manuální override pro případy
  schválení mimo systém (telefonem, mailem mimo aplikaci)

### 11.7.1 Workflow

1. Vytvoříš **draft fakturu** s výkazem víceprací na zakázce, která vyžaduje
   schválení.
2. Klikneš **„Odeslat ke schválení"** → systém:
   - vygeneruje jednorázový bezpečný token (uložený v DB)
   - vyrenderuje samostatné PDF jen výkazu (`Vykaz-XYZ.pdf`)
   - pošle e-mail s velkým červeným tlačítkem **„✓ Schválit vícepráce"**
     na fakturační e-maily zakázky (fallback hlavní e-mail klienta)
3. Tlačítko **„Vystavit"** je nyní **zablokované** s nápovědou „Faktura
   nepůjde vystavit, dokud zákazník neschválí výkaz."
4. Zákazník v e-mailu klikne na tlačítko → otevře se **veřejná schvalovací
   stránka** (bez přihlášení), kde vidí výpis víceprací.

   ![Schvalovací stránka pro zákazníka](img/09_schvalit_vykaz_prace.webp)

5. Vybere **Schválit** nebo **Zamítnout** (s povinným důvodem). CAPTCHA
   ochrana proti botům, e-mail rozhodujícího se uloží do auditu.
6. Po schválení:
   - Stav schválení faktury se přepne na **Schválen**
   - Faktura se **automaticky vystaví** (přidělí variabilní symbol, snapshoty)
   - Faktura se **automaticky odešle** standardním procesem (na hlavní e-mail
     klienta + všechny fakturační e-maily zakázky)
7. Po zamítnutí:
   - Stav přepnut na **Zamítnut**, důvod uložen
   - Faktura zůstává jako draft — můžeš výkaz upravit a poslat znovu
     ke schválení (vygeneruje se nový token, předchozí ztrácí platnost)

### 11.7.2 Test schválení

Pro náhled e-mailu před produkčním odesláním klikni **„Test schválení"** v
sekci Další akce — e-mail půjde **na adresu aktuálního dodavatele**, link
v něm vede na placeholder, který nic neudělá. Slouží jen ke kontrole vzhledu.

### 11.7.3 Manuální změna stavu (admin)

Pokud zákazník schválil mimo systém (telefonem, e-mailem), admin může v sekci
„Schválení výkazu zákazníkem" kliknout **„Změnit stav"** a vybrat:

| Stav | Akce |
|---|---|
| **Neurčeno** | Reset — token zruší, vymaže timestamps. Vrátíš fakturu před žádost. |
| **Schválen** | Faktura se okamžitě vystaví a odešle (jako kdyby zákazník schválil přes web). |
| **Zamítnut** | Uloží zápis o zamítnutí s povinným důvodem. Faktura zůstává jako draft. |

> ⚠️ Stav „Vyžádán" v dropdownu chybí — k němu vede jen tlačítko „Odeslat ke
> schválení", které generuje token a posílá e-mail. Ručně se nedá nastavit.

### 11.7.4 Bezpečnost

- **Token je jednorázový** — po schválení/zamítnutí přestane platit. Druhý
  klik na e-mailový odkaz vrátí „Tento odkaz byl již použit nebo není platný".
- **Public stránka chráněna CAPTCHA** (Cloudflare Turnstile) — chrání proti
  botům a anonymnímu spamu.
- **Origin/CSRF check vypnutý** pro public endpointy — zákazník přijde
  z e-mailového klienta s prázdným/cizím Origin headerem. Anti-bot řeší token
  + CAPTCHA.
- **Audit log** — každá akce (`approval_requested`, `approval_approved`,
  `approval_rejected`, `approval_reset`) se zapíše do activity logu faktury
  včetně IP a user-agenta.

## 11.8 Zálohová faktura → daňový doklad

Workflow:

1. Vystavíš **zálohovou (proforma)** — variabilní symbol `9NNNNNN`, status
   `issued`, žádné DUZP.
2. Klient zaplatí — banka spáruje (nebo manuálně označíš jako `paid`).
3. Klikneš **Vystavit daňový doklad** (tlačítko v detailu zálohové).
4. Vytvoří se **daňový doklad** typu „Faktura" s automatickým **odečtem
   zaplacené zálohy** (záporná položka „Odpočet zálohy 92605001").

## 11.9 Storno vs. dobropis

Pokud zjistíš, že vystavená faktura je špatně:

- **Storno (interní)** — pouze interní označení, faktura zmizí ze statistik
  jako „neexistuje". **Klientovi se nic neposílá.** Použij, když jsi fakturu
  ještě neposlal a nechceš ji v evidenci.
- **Dobropis (opravný daňový doklad)** — vystavíš nový doklad se zápornými
  položkami, který klientovi pošleš jako oficiální opravu. Účetně správné, ale
  vyžaduje, abys měl s klientem komunikaci o tom, co a proč.

## 11.10 Tipy

- **Vždy uložené jako koncept** — Ctrl+S kdykoli uloží rozpracovanou fakturu.
- **Klonování zachová položky i výkaz víceprací** — datum se aktualizuje na
  dnešní, popis položky inkrementuje měsíc.
- **Sleva v procentech** se počítá z mezisoučtu **před** DPH.
- **Reverse charge** automaticky nastaví všechny položky na sazbu „RC" (0 %)
  a v PDF přidá text „Daň přiznává odběratel".
- **PDF náhled konceptu** má vodoznak „NÁHLED" přes celou stranu — klient si ho
  spletl s vystavenou fakturou by neměl.
