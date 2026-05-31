# 25. Daň z příjmů (DPFO / DPPO)

V menu **Daně → Daň z příjmů** najdeš nástroj pro **roční přiznání**:
- **DPFO** (Daň z příjmů Fyzických osob, formulář DPFDP5) — pro OSVČ
- **DPPO** (Daň z příjmů Právnických osob, formulář DPPDP9) — pro s.r.o., a.s.

> [!CAUTION]
> **⚠️ Pouze foundation — výkaz NENÍ kompletní.** Tento výkaz obsahuje jen orientační čísla z fakturačního systému (tržby z vydaných faktur, náklady z přijatých) a identifikaci poplatníka. **Skutečné daňové přiznání vyžaduje účetní data, která MyInvoice.cz neeviduje**:
> - Daňové odpisy hmotného majetku
> - Mzdové náklady + odvody
> - Sociální a zdravotní pojištění OSVČ
> - Zálohy na daň zaplacené během roku
> - Slevy na dani (na poplatníka, manželku, děti)
> - Daňově neuznatelné výdaje
>
> **Před podáním VŽDY doplňte** ve spolupráci s účetní/poradcem nebo v účetnickém software.

## K čemu to slouží

XML kostra obsahuje:
- **Identifikaci poplatníka** (z Nastavení → Daňové)
- **Identifikaci finančního úřadu** (kód FÚ + ÚzP)
- **Orientační hospodářský výsledek** (tržby − náklady z faktur) jako startovací bod
- **Upozornění v XML**: "Tato čísla jsou orientační z invoicing systému, ne účetní výkaz."

Účetní si pak doplní:
- Daňové odpisy (vyžaduje evidence majetku)
- Mzdové náklady + odvody
- Soc + zdrav pojištění OSVČ
- Slevy na dani
- ...

## Použití

1. **Cesta:** `Daně → Daň z příjmů`
2. **Typ poplatníka** (DPFO / DPPO) se **odvozuje z dodavatele** (Nastavení → Typ poplatníka): OSVČ → DPFO, s.r.o. → DPPO. Nepřepíná se ručně.
3. **Year picker** — default předchozí rok (daně se podávají za uplynulý)
4. **4 karty:** Tržby orientačně / Náklady / Hospodářský výsledek / Termín podání
5. **Export CSV** — orientační podklady (příjmy, výdaje, zisk, termín) pro DP1 / účetní
6. **Stáhnout XML kostru** — generuje DPFDP5 / DPPDP9 verze 05.01 / 09.01

## Příjem mimo základ daně z příjmů (osvobozený / přefakturace)

Některé **vydané** doklady nejsou základem daně z příjmů — typicky:

- **Prodej movité věci osvobozený dle § 4 odst. 1 písm. c) ZDP** — např. vozidlo prodané po více než 1 roce od nabytí; u OSVČ na paušálu, kde věc nebyla v obchodním majetku, neběží ani 5letý test po vyřazení.
- **Přefakturace / průběžné položky** (§ 23 odst. 4 ZDP) — částka, která není ani příjmem, ani výdajem.

U takové faktury zaškrtni v editoru **„Osvobozeno od daně z příjmů"** (volitelně doplň důvod osvobození). Příznak:

- **nezahrne částku do základu daně z příjmů** (DPFO/DPPO výkaz i Daňový optimalizátor; osvobozená část se ukáže odděleně jako „z toho osvobozeno"),
- **nedotkne se DPH** — doklad zůstává v přiznání DPH, kontrolním hlášení i v tržbách/obratu beze změny. Osvobození od daně z příjmů ≠ osvobození od DPH (prodej majetku plátcem je obvykle s DPH).

### Souvislost se sociálním a zdravotním pojištěním (OSVČ)

Důležité a často přehlížené: u OSVČ se **vyměřovací základ** pojistného **odvozuje z daňového základu § 7**. Proto když částka nevstoupí do základu daně z příjmů, **zmizí i z vyměřovacího základu SP a ZP** — jeden příznak tedy sedí na daň z příjmů i na pojistné.

Při čtení nezaměňuj dvě různé veličiny:

| Veličina | Co znamená | OSVČ |
|---|---|---|
| **Vyměřovací základ** | z čeho se pojistné počítá | odvozen z daňového základu § 7 (SP i ZP), v rámci ročního **minimálního** (a u SP i **maximálního**) vyměřovacího základu |
| **Sazba odvodu** | kolik se z vyměřovacího základu odvádí | SP 29,2 %, ZP 13,5 % (orientačně; přesná čísla drží roční daňové konstanty) |

> 🛈 Snížení pojistného se projeví **jen nad rámec minimálního vyměřovacího základu** — OSVČ, která je na zákonném minimu, osvobozením příjmu na pojistném neušetří. U **právnické osoby (s.r.o.)** se SP/ZP z obratu netýká; příznak tam ovlivní jen základ DPPO.

## Termíny podání

- **Daň z příjmů FO (OSVČ bez účetní):** **1.4. následujícího roku** (např. za rok 2026 → do 1.4.2027)
- **S účetní:** prodloužený termín **1.7.**
- **Daň z příjmů PO:** podle účetního období (typicky 1.4. nebo 1.7.)

## Doporučený workflow

1. Vyplňte v MyInvoice **všechny faktury** za rok (vydané + přijaté)
2. Stáhněte XML kostru jako referenci pro účetní
3. Účetní:
   - Použije čísla z MyInvoice jako základ
   - Doplní účetní data (odpisy, mzdy, atd.)
   - Vygeneruje finální XML ve svém software (Pohoda, Money S3, …)
4. Podání na EPO portál MF ČR

## Kde získat plnohodnotný výkaz

MyInvoice.cz je **invoicing software**, ne plné účetnictví. Pro plný daňový výkaz použijte:
- **Pohoda / Money S3 / Helios** — desktop accounting software
- **iDoklad / Fakturoid** — online (jsou napojené přes naši **Externí integrace**)
- **Externí účetní** — předá data v Pohoda XML / ISDOC formátu, MyInvoice ji umí importovat

## Plánované rozšíření (v4.0+)

V budoucnu plánujeme:
- **Evidence majetku + odpisy** (modul Assets)
- **Evidence záloh na daň** (per quarter)
- **Slevy na dani** v Nastavení
- **Plný DPFO / DPPO výkaz** s validací proti XSD

Do té doby je nástroj jen jako **startovací bod**.
