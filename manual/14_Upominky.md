# 14. Upomínky po splatnosti

Když klient nezaplatil včas, můžeš mu poslat **upomínku** — speciální e-mail
s textem typu „Vaše faktura č. XXX byla splatná YY dní zpět, prosíme o úhradu".

Upomínky lze posílat **3 způsoby**:

1. **Manuálně** z detailu jedné faktury (tlačítko)
2. **Hromadně** z [Seznamu faktur](09_Faktury.md) (bulk action)
3. **Automaticky** z cronu (`cron-send-reminders.php`)

## 14.1 Předpoklady

Aby šla upomínka odeslat, faktura musí:

- Být typu `Faktura` (ne proforma, dobropis ani storno)
- Být ve stavu `issued`, `sent` nebo `reminded`
- Být **po splatnosti** (`due_date < dnes`)
- Mít k dispozici klientův e-mail (hlavní + případné fakturační)

## 14.2 Manuální upomínka

Otevři [Detail faktury](12_Faktura_PDF.md) → tlačítko **Upomínka**.

![Tlačítko upomínka](img/12_upominka_btn.webp)

Po kliknutí:

- E-mail jde na: `klient.hlavni_email + zakazka.fakturacni_emaily[]`
- Šablona: `invoice_reminder` (CZ / EN podle jazyka klienta)
- Status faktury → `reminded`
- `last_reminder_at` = teď
- `reminder_count` += 1

Activity log: `invoice.reminded` s počtem dní po splatnosti.

### 14.2.1 Test upomínky

Vedle **Upomínka** je tlačítko **Test upomínky** — pošle stejný e-mail jen na
**tvůj** e-mail (admina, kterého jsi přihlášen). Užitečné pro:

- Vyzkoušení šablony před odesláním klientovi
- Ověření, že SMTP funguje
- Náhled, jak vypadá HTML verze e-mailu v tvém klientu

## 14.3 Hromadná upomínka

Z **Faktury → filtr „Po splatnosti"** zaškrtni více faktur → bulk action
**Upomínka (N)**.

![Hromadná upomínka](img/12_upominka_bulk.webp)

Server:

1. Pro každou fakturu zkontroluje, že splňuje předpoklady (§ 14.1)
2. Cooldown — pokud byla upomínka poslána před **<14 dny**, faktura se
   přeskočí
3. Pošle e-mail
4. Update statusu

Hláška o výsledku: `Odesláno: 8, přeskočeno (cooldown): 2, chyb: 0`.

## 14.4 Cron — automatické upomínky

Pro pravidelné upomínání nastav cron:

```bash
cmd/cron-send-reminders.sh    # 1× denně, doporučeně 09:00 Po–Pá
```

Skript `php api/bin/cron-send-reminders.php` má parametry:

| Parametr | Default | Význam |
|---|---|---|
| `--days=N` | `1` | Faktura musí být po splatnosti alespoň N dní |
| `--cooldown=N` | `14` | Min. počet dní mezi dvěma upomínkami stejné faktury |
| `--dry-run` | — | Jen vypíše, co by udělal, **bez odeslání** |
| `--supplier=N` | (všichni) | Omezit na jednoho dodavatele |

### 14.4.1 Doporučené nastavení

```cron
# Po-Pá v 9:00 — upomínat faktury 5+ dní po splatnosti, max 1× za 14 dní
0 9 * * 1-5  /var/www/myinvoice.cz/cmd/cron-send-reminders.sh --days=5 --cooldown=14
```

> 💡 `--days=5` je rozumný „grace period" — klient mohl mít dovolenou,
> bankovní poplatek, nebo sis ty zapomněl naimportovat výpis.

### 14.4.2 Dry-run pro test

Před produkčním nasazením:

```bash
php api/bin/cron-send-reminders.php --days=5 --dry-run
```

Vypíše:

```
[dry-run] Faktura #2604012 (ACME s.r.o., 12 dní po splatnosti) — by se odeslala na 3 adresy
[dry-run] Faktura #2604015 (Studio Fialka, 7 dní po splatnosti) — by se odeslala na 1 adresu
[dry-run] Faktura #2604008 — přeskočena (poslední upomínka před 4 dny < cooldown 14)
[dry-run] CELKEM: 2 by se odeslaly, 1 přeskočena.
```

## 14.5 Šablona upomínky

Šablona je v **Systém → E-mail šablony → invoice_reminder**.

![Editor šablony upomínky](img/12_sablona.webp)

Můžeš editovat:

- **Předmět** — `{{ varsymbol }}` placeholder pro VS faktury
- **HTML tělo** — Twig template
- **Plain text tělo** — fallback pro klienty bez HTML

### 14.5.1 Dostupné placeholders

| Placeholder | Význam |
|---|---|
| `{{ varsymbol }}` | Variabilní symbol faktury |
| `{{ amount }}` | Částka k úhradě, formátovaná |
| `{{ currency }}` | Měna |
| `{{ due_date }}` | Datum splatnosti |
| `{{ days_overdue }}` | Počet dní po splatnosti |
| `{{ client_name }}` | Jméno klienta |
| `{{ supplier_name }}` | Jméno dodavatele |
| `{{ payment_link }}` | (volitelné) odkaz na platební bránu |
| `{{ reminder_count }}` | Počet již odeslaných upomínek (1 = první, 2 = druhá, …) |

### 14.5.2 Multi-jazyčnost

Pro každou šablonu jsou **4 varianty**:

- `cs.html` (CZ HTML)
- `cs.txt` (CZ plain)
- `en.html` (EN HTML)
- `en.txt` (EN plain)

Vybere se podle `klient.language`.

## 14.6 Tipy

- **Cooldown 14 dní** je rozumný — kratší by byl agresivní, delší se obchází.
- **Eskalace tónu** — pomocí `{{ reminder_count }}` můžeš v šabloně použít
  Twig logiku: `{% if reminder_count >= 3 %}poslední výzva{% endif %}`.
- **Cron nepouštěj v sobotu/neděli** — klient nečte e-maily, vyřeší to až
  v pondělí, ale na statistikách to vypadá divně. Cron expression `1-5`
  (Po–Pá) je standard.
- **Po druhé upomínce zvaž osobní telefonát** — automatika neřeší vztahy.
  E-mailová upomínka je jen formalita.
- **Test upomínky** = vždy před produkčním cronem. Nešťastné je posílat
  klientovi rozbitý HTML.
