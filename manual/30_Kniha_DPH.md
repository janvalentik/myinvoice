# 30. Kniha DPH (měsíční VAT žurnál)

### Cesta: `Daně → Kniha DPH`

Interní reportingový výkaz — **není to EPO podání na finanční úřad**, slouží
jen pro vnitřní přehled a archivaci. Žurnál seskupený podle řádků [DPH přiznání](29_Vykazy_DPH.md):

- `15.040` — Přijaté tuzemsko, sazba 21 % (ř.40 přiznání = nárok na odpočet)
- `36.001` — Uskutečněná tuzemsko, základ daně 21 % (ř.1 přiznání)
- `43.012` + `43.043` — Dovoz služby ze 3. země (ř.12 přiznání DPH +
  ř.43 nárok na odpočet z téhož plnění)
- `43.003` + `43.043` — RC pořízení zboží z EU (ř.3 výstup + ř.43 mirror
  odpočet)
- `47.047` — Hodnota pořízeného majetku (§ 4 odst. 4 písm. c).
  Doplňující údaj k ř. 40-45 — informativní řádek, nepřičítá se do celkového
  součtu odpočtu (jinak by se daň majetku duplikovala).
- a další řádky podle klasifikací v `vat_classifications`

Per řádek faktury sekce: **Datum plnění | Zaúčtování | Doklad (PF / VF +
číslo) | Popis | Základ daně CZK | DPH CZK | Celkem CZK | Partner + DIČ |
Orig. číslo dokladu | Orig. datum plnění | KH kód (A.4. / B.2. / B.3.)**.

Měsíční selektor (rok + měsíc), tlačítko **Stáhnout PDF** (landscape A4).
Zahrnuje i drafty (vizuálně označené) — užitečné pro pracovní přehled před
uzavřením období. Storno faktury (status `cancelled`) se neukazují.
