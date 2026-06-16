# 32. Daňový optimalizátor (OSVČ)

> ⚠️ **Orientační pomůcka, ne daňové přiznání.** Výpočet vychází jen z fakturačních
> dat a zadaného profilu; nezohledňuje vše (ostatní příjmy, skutečné zálohy na daň,
> speciální slevy). Před rozhodnutím ověř u účetní / daňového poradce.

**Cesta:** `Daně → Daňový optimalizátor`. Položka se zobrazí **jen pro OSVČ**
(dodavatel s typem poplatníka *OSVČ* v Nastavení) — pro s.r.o. nedává smysl.

## K čemu to slouží

Pomáhá živnostníkovi rozhodnout, **který daňový režim se mu vyplatí**, a hlídá
**limity** — vše na jeho reálném vyfakturovaném příjmu (zaplacené faktury, kasová
metoda, přepočet na CZK).

## Uzavřený rok (retrospektiva)

Pro minulý rok porovná dva režimy a vybere levnější:

- **Paušální daň** — jedna měsíční částka dle pásma (sloučí daň i pojistné).
- **Standardní režim** — výdajový paušál (40/60/80 %) **nebo skutečné výdaje**,
  základ daně dle §7, progresivní daň 15 / 23 %, slevy (poplatník, manžel/ka,
  děti vč. daňového bonusu) a sociální + zdravotní pojistné.

Rozpad ukáže cestu **příjem → výdaje → základ → daň → pojistné → odvody celkem →
čistý příjem** a **efektivní sazbu odvodů**. Pokud byl loni příjem, přidá i
**meziroční (YoY) srovnání** čistého příjmu.

## Běžící rok (predikce)

Z dosavadního tempa (run-rate) projektuje příjem do konce roku a na **teploměru**
hlídá překročení limitů:

- **strop zvoleného pásma** paušální daně,
- **2 000 000 Kč** — limit DPH / paušálu,
- **2 536 500 Kč** — hranice okamžitého plátcovství DPH.

Když překročení 2 M hrozí na konci roku, poradí **odložit prosincové faktury do
ledna** a zůstat pod limitem.

Při zaškrtnuté **vedlejší činnosti** přibude pod teploměrem ještě řádek
**rozhodné částky pro povinnou účast na důchodovém (sociálním) pojištění**
(2025 = 111 736 Kč, 2026 = 117 521 Kč dle ČSSZ). Na rozdíl od limitů výše se
měří proti **projektovanému zisku** (příjmy − výdaje dle zvoleného paušálu /
skutečných výdajů), ne proti příjmu — proto je samostatným řádkem mimo teploměr.
Ukáže, zda zisk zůstane pod limitem (sociální pojištění z vedlejší činnosti se
neplatí), nebo limit překročíš a v kterém měsíci. Částku lze pro daný rok upravit
v `Nastavení → Číselníky → Daňové konstanty`.

## Profil (per rok)

Nastav jednou, dopočítá se automaticky. Uloží se k danému roku:

| Pole | Význam |
|---|---|
| Typ činnosti | Výdajový paušál 40 / 60 / 80 % (dle živnosti) |
| Výdaje | **Paušál %** nebo **Skutečné** (reálné roční výdaje z daňové evidence) |
| Pásmo paušální daně | Přihlášené pásmo (none / 1. / 2. / 3.) |
| Vedlejší činnost | Jiná minima pojistného; v běžícím roce přidá na teploměr i hlídání rozhodné částky pro účast na sociálním pojištění |
| Slevy / odpočty | Manžel/ka, počet dětí, úroky hypotéky, penzijní/životní, dary |

## Dashboard

Na úvodním dashboardu je pro OSVČ karta **„čistý příjem"** s projektovaným
výsledkem běžícího roku a proklikem do optimalizátoru.

## Daňové konstanty

Sazby, limity a vyměřovací základy jsou v aplikaci jako **ověřené výchozí
hodnoty**; admin je může pro daný rok upravit bez nového nasazení v
`Nastavení → Číselníky → Daňové konstanty` (sazby se mění každý rok). „Reset na
výchozí" vrátí hodnoty z aplikace.

První (zvýrazněná) skupina **DPH a výkazy** se na rozdíl od ostatních netýká
jen daně z příjmů OSVČ — platí pro **všechny plátce DPH**:

| Konstanta | K čemu slouží |
| --- | --- |
| Základní / snížená sazba DPH | rozřazení částek do sloupců DPH výkazů (přiznání, KH, Kniha DPH), Pohoda export, auto-klasifikace importovaných dokladů a samovyměření u reverse charge |
| Limit KH | hranice (Kč vč. DPH), od které jde doklad do kontrolního hlášení jednotlivě (A.4/B.2) místo sumace (A.5/B.3) |
| Limity registrace DPH | hlídání obratu pro povinnou registraci plátce |

Výkazy vždy používají konstanty **roku vykazovaného období** — když se limit
nebo sazba změní, zpětně generovaný výkaz za starší období počítá s tehdejšími
hodnotami.
