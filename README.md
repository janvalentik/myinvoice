# MyInvoice.cz

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![PHP 8.5+](https://img.shields.io/badge/PHP-8.5+-777BB4?logo=php&logoColor=white)](https://www.php.net/)
[![MariaDB 10.6+](https://img.shields.io/badge/MariaDB-10.6+-003545?logo=mariadb&logoColor=white)](https://mariadb.org/)
[![Vue 3](https://img.shields.io/badge/Vue-3-4FC08D?logo=vuedotjs&logoColor=white)](https://vuejs.org/)
[![Docker](https://img.shields.io/badge/Docker-multi--arch-2496ED?logo=docker&logoColor=white)](https://github.com/radekhulan/myinvoice/pkgs/container/myinvoice)
[![GHCR](https://img.shields.io/github/v/tag/radekhulan/myinvoice?label=GHCR&color=2496ED&logo=docker&logoColor=white)](https://github.com/radekhulan/myinvoice/pkgs/container/myinvoice)

> **Český fakturační systém pro freelancery, OSVČ a malé firmy.**
> Rychlé vystavování opakovaných faktur, QR platby, výkaz víceprací,
> import bankovních výpisů, exporty pro účetní software — vše na vlastním serveru.

Vyvíjí **[MyWebdesign.cz s.r.o.](https://mywebdesign.cz/)**

🌐 **Projektový web: [myinvoice.cz](https://myinvoice.cz/)**

📖 **Online dokumentace: [myinvoice.cz/manual](https://myinvoice.cz/manual/)**

![Přehled (dashboard)](manual/img/01_dashboard.webp)

---

## Proč MyInvoice.cz?

Většina českých online fakturačních služeb je SaaS s měsíčními poplatky a vašimi
fakturačními daty mimo váš dosah. **MyInvoice.cz je open-source, self-hosted**
alternativa s důrazem na:

- **Tvoji databázi, tvoje data** — vše běží na vlastním (nebo pronajatém) serveru, žádný cloud.
- **Multi-supplier od první verze** — fakturuj za více firem / IČ z jedné instalace, snadný přepínač v UI.
- **Český kontext první** — ARES + VIES lookup, SPAYD QR (ČR) i SEPA EPC QR (EU),
  ISDOC + Pohoda XML exporty, mod-11 validace bankovních účtů, GPC import výpisů.
- **Nulové měsíční náklady** — jednorázový setup, žádné per-fakturové poplatky, žádné limity.

---

## Co umí

### 📄 Fakturace
- 4 typy dokladů: **faktura**, **zálohová (proforma)**, **opravný daňový doklad** (dobropis), **interní storno**
- Vystavení daňového dokladu z proformy s automatickým **odečtem zaplacené zálohy**
- **Klonování faktur** s auto-inkrementem měsíce v popiscích (`3/2026 → 4/2026`)
- Hromadné akce: *Vystavit znovu (N)*, *Odeslat klientovi (N)*, *Označit jako zaplacené*, *Upomínka*
- **Výkaz víceprací** (work_report) jako 2. strana PDF s přenosem sumy do položky
- **Schvalování výkazu zákazníkem přes e-mailový odkaz** — volitelné per zakázka:
  zákazník dostane e-mail s odkazem na veřejnou stránku (token + CAPTCHA),
  jedním klikem schválí/zamítne, faktura se po schválení automaticky vystaví
  a odešle
- PDF se **snapshotem dodavatele/odběratele/banky** — vystavená faktura je neměnná
- Editace vystavené faktury jen pro admina s `?force=1` + audit záznam

![QR platba na PDF faktuře](manual/img/10_qr_platba.webp)

### 💳 Platby
- **QR platby** přímo v PDF: SPAYD pro CZK, SEPA EPC pro EUR
- **Import GPC** výpisů (ABO formát, KB / FIO / ČSOB / RB / ČS) s SHA256 dedupe
- **Auto-matching** transakcí na faktury podle VS + částky → automaticky `paid`
- Manuální párování + označení transakce jako "ignorovat"
- **Upomínky** po splatnosti — manuální tlačítko na detailu, hromadná akce, nebo cron

![Schvalovací stránka pro zákazníka](manual/img/09_schvalit_vykaz_prace.webp)

### 👥 Klienti & zakázky
- Klienti s **ARES** (IČ → adresa, název) a **VIES** (DIČ) lookupem
- Zakázky 1:N pod klientem, fakturační emaily per zakázka (účetní, PM…)
- Filter zakázek podle klienta
- Reverse charge přepínatelný per klient
- Smazání chráněné 409, pokud má klient/zakázka navázané faktury

### 🏢 Multi-supplier
- Z jedné instalace fakturuj **za libovolný počet dodavatelů (firem / IČ)**
- Přepínač v horní liště, izolovaná data (klienti, zakázky, faktury, číselníky)
- Každý dodavatel má vlastní sadu měn + bankovních účtů, vlastní řadu varsymbolů
- Per-dodavatel: ARES údaje, logo, podpis, SMTP `From:` jméno + `Reply-To:` adresa, Pohoda kódy

### 📦 Exporty pro účetní
- **Hromadný export PDF** (ZIP po měsících)
- **ISDOC 6.0.2** — český národní standard pro B2B výměnu faktur
- **Pohoda XML** (Stormware data package) — přímý import do Pohody bez ručního opisu
- Per-dodavatel konfigurace Pohoda kódů (středisko, činnost, předkontace, číselná řada)

### 📧 Komunikace
- Odesílání faktur **e-mailem** (Symfony Mailer + DKIM podpora)
- **Editor e-mailových šablon** v UI (Twig) — CZ / EN, HTML + plaintext varianty
- Šablony: nová faktura, upomínka, reset hesla, test
- Per-dodavatel branding (`From:` jméno, `Reply-To:`)

### 🔒 Bezpečnost
- **CZ + EN lokalizace** UI i faktur
- **Brute-force ochrana** (Redis nebo MariaDB MEMORY fallback) — 5 selhání → CAPTCHA, 30/h → 24h lockout
- **Cloudflare Turnstile** CAPTCHA
- **IP allowlist** (IPv4 + IPv6 + CIDR)
- **CSRF** + Origin check, **TOTP 2FA**, peppered bcrypt hesla
- **RBAC** (admin / accountant / readonly)
- **Activity log** všech mutací (včetně IP)

### 📊 Dashboard
- KPI tiles, **dynamický počet sloupců** dle aktivních měn (4–6)
- Top klienti — koláč letošního i loňského roku
- Obrat po měsících (line chart letos vs. minulý rok)
- Po splatnosti + nezaplacené faktury (s tlačítkem upomínka)

---

## Quick start: Docker (3 minuty)

Nejrychlejší cesta k běžící aplikaci. Stačí mít nainstalovaný **Docker Desktop**
(Windows / macOS) nebo **Docker Engine + compose-plugin** (Linux) — nepotřebuješ
lokálně PHP, MariaDB, Node ani nic dalšího.

### Varianta A — pre-built image z GHCR (bez klonování repa)

Pro produkční nasazení tam, kde nechceš klonovat celý repo. Image `ghcr.io/radekhulan/myinvoice`
je multi-arch (`linux/amd64` + `linux/arm64`) — funguje na běžném Linux serveru
i na M1/M2 Macu nebo Raspberry Pi 4/5.

```bash
mkdir myinvoice && cd myinvoice
curl -O https://raw.githubusercontent.com/radekhulan/myinvoice/master/docker-compose.production.yml
curl -O https://raw.githubusercontent.com/radekhulan/myinvoice/master/cfg.sample.php
mv docker-compose.production.yml docker-compose.yml
cp cfg.sample.php cfg.docker.php
# uprav cfg.docker.php — minimálně:
#   db.host => 'db', db.user => 'myinvoice', db.pass => '<heslo z .env níže>'
#   app.pepper a secret_encryption_key (oboje: openssl rand -base64 32)

cat > .env <<EOF
DB_PASSWORD=$(openssl rand -base64 28)
DB_ROOT_PASSWORD=$(openssl rand -base64 28)
EOF

docker compose up -d
docker compose exec app php api/bin/migrate.php
```

Otevři **http://localhost:8080** → setup wizard.

> 💡 V produkci pinuj konkrétní verzi — v `docker-compose.yml` změň `:latest`
> na `:1.2.0`. Update pak: bumpni tag, `docker compose pull && up -d`, spusť `migrate.php`.

### Varianta B — build z source (pro vývoj)

S klonem repa máš přístup k celému kódu, můžeš upravovat a build si vyrobí lokálně.
Použij stejný flow s automatickým install scriptem:

```bash
git clone https://github.com/radekhulan/myinvoice.git
cd myinvoice

# Linux / macOS
cmd/docker-install.sh

# Windows PowerShell
.\cmd\docker-install.ps1
```

Skript automaticky:

1. Vygeneruje `.env` s náhodnými DB hesly (28 znaků base64)
2. Vygeneruje `cfg.docker.php` z `cfg.sample.php` (host=db / redis, randomized
   `app.pepper` + `secret_encryption_key`, dev-friendly cookies pro HTTP loopback)
3. Postaví `myinvoice:latest` image (multi-stage: Vue build → composer → PHP 8.5 + Apache)
4. Spustí stack: **app** (Apache:80→host:8080) + **db** (MariaDB 11)
5. Počká, až bude DB healthy, a spustí migrace

**Po dokončení otevři: 👉 [http://localhost:8080](http://localhost:8080)**

V prohlížeči naskočí **setup wizard** (3 kroky):

1. **Administrátor** — jméno, e-mail, heslo (min. 12 znaků)
2. **Dodavatel** — IČ → *Načíst z ARES* → bankovní účet (např. `1000000005 / 0100`)
3. **Sample data** *(volitelné)* — checkboxem 5 klientů + 8 zakázek + 20 faktur + 4 dobropisy

Wizard tě po dokončení **automaticky přihlásí**.

### Další port než 8080?

Edituj `.env` (vznikl po prvním spuštění install skriptu):

```bash
APP_PORT=9000              # místo 8080
DB_PORT=3308               # místo 3307 (vázán jen na 127.0.0.1)
```

a `docker compose up -d`. URL pak bude `http://localhost:9000`.

### Daily ops

```bash
docker compose up -d                                 # start
docker compose down                                  # stop (data v named volumes přežijí)
docker compose down -v                               # stop + WIPE volumes (ZNIČÍ DB!)
docker compose logs -f app                           # live logs
docker compose exec app bash                         # shell do kontejneru
docker compose exec app php api/bin/migrate.php      # CLI uvnitř kontejneru
cmd/docker-build.sh --no-cache                       # rebuild image (po PHP/JS změnách)
```

### Po setupu si edituj `cfg.docker.php`

Install skript nastaví minimum potřebné k běhu, ale tyto věci si musíš doplnit ručně:

- `smtp.*` — odchozí pošta (jinak nepůjdou faktury / upomínky / reset hesla)
- `captcha.site_key` + `captcha.secret_key` — z [dash.cloudflare.com → Turnstile](https://dash.cloudflare.com)
- `ip_allowlist.allow` — volitelné, doporučeno mimo lokál

Po editaci stačí `docker compose restart app` (cfg je bind-mountovaný — žádný rebuild).

### Volitelný Redis

```bash
docker compose --profile redis up -d
```

a v `cfg.docker.php` nastav `redis.enabled => true` (host už je `redis`). Restart appky.

Více detailů (cron uvnitř kontejneru, `.env` proměnné, troubleshooting): viz [`cmd/README.md`](cmd/README.md).

---

## Setup bez Dockeru (native, 5 minut)

Pokud nechceš Docker (např. cílový deploy je IIS / Apache na holém železe).

### Předpoklady

- **PHP 8.5+** s extensions: `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `json`, `iconv`, `gd`
- **MariaDB 10.6+** (doporučeno 11.x)
- **Composer 2.x**, **Node.js 22+**, **pnpm 10+**
- **Redis** (volitelné — fallback na MariaDB MEMORY)
- Web server: **IIS** nebo **Apache** (oba podporované, repo má `web.config` i `.htaccess`)

### 1. Klon a konfigurace

```bash
git clone <repo-url> myinvoice
cd myinvoice
cp cfg.sample.php cfg.php
```

Otevři `cfg.php` a vyplň:

- `db.user` / `db.pass` — připojení k MariaDB
- `app.pepper` — vygeneruj `openssl rand -base64 32`
- `smtp.host` / `user` / `pass` — odchozí pošta
- `captcha.site_key` / `secret_key` — z [dash.cloudflare.com → Turnstile](https://dash.cloudflare.com)
- `ip_allowlist.allow` — volitelné, doporučeno v produkci

### 2. Vytvoř databázi

```bash
mysql -u root -p -e "CREATE DATABASE myinvoice CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 3. Nainstaluj backend a spusť migrace

```bash
cd api && composer install && cd ..
php api/bin/migrate.php
```

### 4. Frontend

```bash
cd web
pnpm install
pnpm build       # produkční build do web/dist/
# nebo pro vývoj:
pnpm dev         # dev server na :5173
```

### 5. Otevři prohlížeč → setup wizard

V prohlížeči navštiv `https://tvoje-domena.cz` (nebo `http://localhost:5173` v devu)
a projdi **3 kroky setup wizardu**:

1. **Administrátor** — jméno, e-mail, heslo (min. 12 znaků, indikátor síly)
2. **Dodavatel** — vyplň IČ a klikni *Načíst z ARES* (předvyplní název, adresu,
   DIČ); doplň první bankovní účet (CZK)
3. **Sample data** *(volitelné)* — checkboxem si necháš vygenerovat 5 klientů,
   8 zakázek, 20 faktur a 4 dobropisy pro vyzkoušení systému

Po dokončení tě wizard **automaticky přihlásí** a přesměruje do aplikace.

### Další dodavatelé

V menu **Systém → Dodavatelé** klikni *Nový dodavatel*. Stačí zadat IČ → ARES doplní
zbytek. V horní liště se objeví přepínač pro snadné přepínání mezi firmami.

---

## CLI nástroje

```bash
php api/bin/migrate.php              # spustí pending migrace
php api/bin/migrate.php --status     # vypíše stav migrací
php api/bin/setup.php                # interaktivní úvodní zřízení (cfg + DB + ARES + admin)
php api/bin/sample.php               # vygeneruje testovací data (po setupu)
php api/bin/reset.php                # smaže všechna user-data (CLI only, vyžaduje "ANO")
php api/bin/reset.php --yes          # bez potvrzení
php api/bin/recompute-stats.php      # přepočítá agregované statistiky
```

### Cron skripty

V `cmd/` jsou připravené `.cmd` (Windows) i `.sh` (Linux) wrappery:

```bash
cmd/cron-bank-scan.sh        # každých 15 min — scan příchozích GPC výpisů
cmd/cron-send-reminders.sh   # 1× denně — upomínky po splatnosti (s --cooldown)
cmd/cron-cleanup.sh          # 1× denně — čištění expirovaných session, logů, PDF cache
```

---

## DKIM (volitelné, doporučeno pro deliverabilitu)

```bash
mkdir -p private/dkim && cd private/dkim
openssl genrsa -out myinvoice.pem 2048
openssl rsa -in myinvoice.pem -pubout -out myinvoice.pub

echo "v=DKIM1; k=rsa; p=$(grep -v '^-----' myinvoice.pub | tr -d '\n')" > dns.txt
```

Publikuj DNS TXT záznam `myinvoice._domainkey.tvoje-domena.cz` s obsahem `dns.txt`,
pak v `cfg.php` přepni `smtp.dkim.enabled => true`.

---

## Stack

| Vrstva | Volba |
|---|---|
| Backend | PHP 8.5 + Slim 4.13 + PHP-DI 7 + Twig 3.10 + Monolog 3.7 + Guzzle 7.9 |
| Frontend | Vue 3.5 + Vite 8 + Tailwind 4 + Pinia 3 + vue-router 5 + vue-i18n 11 + VueUse 14 + axios 1.16 + TypeScript 5.7 |
| Databáze | MariaDB 10.6+ (doporučeno 11.x) |
| PDF | mPDF 8.2 + Twig 3.10 templates |
| Grafy | Chart.js 4 + vue-chartjs 5 |
| QR | rikudou/czqrpayment 5 (SPAYD), smhg/sepa-qr-data 3 (EPC), chillerlan/php-qrcode 6 |
| Mail | Symfony Mailer 8 (SMTP + DKIM) + Symfony Mime 8 |
| Validace | respect/validation 3, enshrined/svg-sanitize 0.22 |
| Cache / brute-force | Redis přes predis 3 (preferred) / MariaDB MEMORY (fallback) |
| Auth | session-based + CSRF + TOTP 2FA |
| Testy / kvalita | PHPUnit 13, PHPStan 2, php-cs-fixer 3, vue-tsc 2 |
| Build | Composer 2 (PHP), pnpm 10 + Node.js 22+ (JS), GitHub Actions CI |

Pokud chybí `cfg.php` nebo nelze do DB, frontend i API vrací **503 s instrukcemi**
(žádná bílá stránka).

---

## Dokumentace

**Uživatelský manuál** (HTML, lokálně po instalaci): `https://tvoje-domena.cz/manual` —
17 kapitol (od přihlášení po Pohoda XML export), fulltext search, sidebar TOC.
Zdroj v `manual/*.md`.

**Vývojářská spec** v `source/`:

- [`source/00-README.md`](source/00-README.md) — rozcestník
- [`source/01-spec.md`](source/01-spec.md) — funkční + technická spec
- [`source/02-database.md`](source/02-database.md) — DB schéma
- [`source/03-architecture.md`](source/03-architecture.md) — architektura, deploy
- [`source/04-api.md`](source/04-api.md) — REST API
- [`source/05-design.md`](source/05-design.md) — design system
- [`source/06-roadmap.md`](source/06-roadmap.md) — plán vývoje
- [`source/07-security-audit.md`](source/07-security-audit.md) — bezpečnostní audit

---

## Bezpečnostní hlášení

Našel jsi zranitelnost? **Nehlas přes public Issues** — pošli přímo přes
formulář na [mywebdesign.cz](https://mywebdesign.cz/) s předmětem
`[SECURITY] MyInvoice.cz`. Detailní postup v [SECURITY.md](SECURITY.md).

---

## Licence

**MIT** — [LICENSE](LICENSE). Můžeš zdarma používat, modifikovat a redistribuovat
(včetně komerčního použití). Jediná podmínka — zachovat copyright + MIT text
v derivátech.

Vyvíjí **[MyWebdesign.cz s.r.o.](https://mywebdesign.cz/)** © 2026.

## Zřeknutí se odpovědnosti

> **Software je poskytován „TAK JAK JE", bez záruky jakéhokoli druhu**,
> výslovné nebo předpokládané, včetně, ale nikoliv pouze, záruk
> obchodovatelnosti, vhodnosti pro určitý účel a neporušení práv třetích osob.
>
> **Použití této aplikace je výhradně na vlastní riziko uživatele.**
> Autoři ani přispěvatelé v žádném případě neodpovídají za jakékoli přímé,
> nepřímé, náhodné, zvláštní, exemplární či následné škody (mimo jiné za
> ztrátu dat, ušlý zisk, výpadek provozu nebo poškození pověsti) vzniklé
> v souvislosti s používáním nebo nemožností použití tohoto softwaru,
> a to ani v případě, že byli o možnosti takových škod informováni.
>
> Aplikace zpracovává **fakturační a účetní data** — uživatel je výhradně
> odpovědný za:
> - **správnost vystavených dokladů** podle platné legislativy ČR / EU
>   (zákon o DPH, zákon o účetnictví, GDPR atd.);
> - **zálohování databáze a souborů** v `storage/`;
> - **zabezpečení produkčního nasazení** (HTTPS, IP allowlist, 2FA, silná
>   hesla, pravidelné aktualizace závislostí);
> - **dodržení daňových a archivačních povinností** (ČR: 10 let pro
>   účetní doklady).
>
> Plné znění viz [LICENSE](LICENSE) (MIT — sekce *„NO WARRANTY"*).

