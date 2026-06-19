# 30. Souhrnné hlášení (DPHSHV)

### Cesta: `Daně → Souhrnné hlášení`

Souhrnné hlášení (anglicky **Recapitulative Statement**) je výkaz **EU dodání zboží a služeb** v režimu B2B (vystavené faktury klientům — plátcům DPH v jiných členských státech EU). Podává se měsíčně nebo kvartálně.

> [!NOTE]
> **Kvartální SH je povoleno jen tehdy, pokud dodáváš výhradně služby** (kód 22, § 9/1). Dodání zboží do EU (kód 20) vyžaduje měsíční podání bez ohledu na typ plátce (§ 102 odst. 3 ZDPH). Přepínač Měsíčně / Kvartálně je vždy dostupný; aplikace na tuto podmínku upozorní.

> [!IMPORTANT]
> Souhrnné hlášení **podávají i identifikované osoby** (neplátci DPH), pokud poskytují B2B služby plátcům v EU, nebo nakupují zboží z EU nad limit.

### Co se generuje

Per VAT_ID protistrany + typ plnění:

| Kód | Typ plnění | VAT klasifikační kód v MyInvoice |
|---|---|---|
| **0** | Dodání zboží do jiného členského státu EU | **20** |
| **1** | Trojstranný obchod (prostředník) | **21** (pokud máte custom kód) |
| **2** | Poskytnutí služby s místem plnění v EU | **22** |
| **3** | Přemístění zboží | — |

Hodnota plnění = suma `total_without_vat` (základ daně, BEZ DPH) v CZK.

### Předpoklady

1. Vystavené faktury klientům **z EU** (country_iso2 ≠ CZ AND countries.is_eu = 1)
2. Klient má vyplněné **DIČ** (pro EU obvykle s prefixem země: SK1234567890, DE123456789, atd.)
3. Faktury musí mít VAT klasifikační kód 20 (zboží) nebo 22 (služby) — auto-default je řeší, ale ověř manuálně

### XML formát

Generuje DPHSHV verze 06.01. Per řádek VetaA1:
- `k_stat` = ISO2 kódu země (SK, DE, FR, …)
- `vatid_pod` = VAT ID s prefixem
- `kod_plneni` = 0/1/2/3
- `pln_hodnota` = celé Kč (zaokrouhleno)
- `pln_pocet` = počet faktur agregovaných pod tento řádek

### Termín podání

**Vždy 25. den následujícího měsíce** (stejně jako [kontrolní hlášení](28_Vykazy_DPH.md#kontrolni-hlaseni-dphkh1)).
