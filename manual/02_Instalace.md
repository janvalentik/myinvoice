# 2. Instalace

> Tato kapitola je technická — určená pro osobu, která systém nasazuje (IT
> administrátor, hostingový tým). Běžný uživatel ji může přeskočit.

Nabízíme dvě cesty: **Docker** (nejrychlejší, doporučeno pro nové instalace)
nebo **nativní install** (PHP + MariaDB + web server, tradiční hosting).

## 2.1 Docker (3 minuty)

Předpoklady: **Docker Desktop** (Windows / macOS) nebo **Docker Engine
+ compose-plugin** (Linux).

Klon repa je společný krok pro obě varianty:

```bash
git clone <repo-url> myinvoice
cd myinvoice
```

Pak si vyber variantu podle toho, jestli chceš stavět image lokálně, nebo
si stáhnout pre-built z GHCR.

### 2.1.1 Varianta A — pre-built image z GHCR (rychlejší, bez local buildu)

Stáhne hotový multi-arch image (`ghcr.io/radekhulan/myinvoice:latest`,
`linux/amd64` + `linux/arm64`). Nepotřebuješ na hostu `pnpm`/`composer`
ani několikaminutový build.

```bash
# Linux / macOS
cmd/docker-ghcr.sh

# Windows PowerShell
.\cmd\docker-ghcr.ps1
```

Skript `docker-ghcr` postupně:

1. Vygeneruje `.env` s náhodnými DB hesly (28 znaků base64)
2. Vygeneruje `cfg.docker.php` z `cfg.sample.php` (host=db / redis,
   randomized `app.pepper` + `secret_encryption_key`, dev-friendly cookies)
3. `docker compose pull` — stáhne image z GHCR
4. `up -d` + počká na DB healthy + spustí migrace

Používá `docker-compose.production.yml` (image-only, žádný `build:` block),
takže další compose příkazy vyžadují flag `-f docker-compose.production.yml`
(viz [2.1.6 Daily ops](#216-daily-ops)).

> 💡 V produkci pinuj konkrétní verzi — uprav `docker-compose.production.yml`
> a změň `:latest` na konkrétní tag (např. `:1.7.0`). Update pak přes
> `cmd/docker-update.{sh,ps1}` (auto-detekuje registry mode = `pull` + `up -d`
> + migrace).

**Aktualizace na novou verzi:**

```bash
# Linux / macOS
cmd/docker-update.sh

# Windows PowerShell
.\cmd\docker-update.ps1
```

Skript v registry módu sám zavolá `docker compose pull app` (stáhne nový
image z GHCR), restartuje stack a doběhne pending migrace. Volumes (DB data)
zůstávají zachovány. Mód detekuje automaticky — pokud nemáš `.git/`
nebo `build:` blok v compose, jede přes `pull`.

Nový image se publikuje automaticky při každém release tagu `v*.*.*`,
takže aktualizace je otázkou jednoho příkazu.

### 2.1.2 Varianta B — build z source

Postaví image lokálně z repa — vhodné pro vývoj a vlastní úpravy.

```bash
# Linux / macOS
cmd/docker-install.sh

# Windows PowerShell
.\cmd\docker-install.ps1
```

Skript `docker-install` postupně:

1. Vygeneruje `.env` s náhodnými DB hesly (28 znaků base64)
2. Vygeneruje `cfg.docker.php` z `cfg.sample.php` (host=db / redis,
   randomized `app.pepper` + `secret_encryption_key`, dev-friendly cookies pro
   HTTP loopback)
3. Postaví image `myinvoice:latest` (multi-stage: Vue build → composer →
   PHP 8.5 + Apache)
4. Spustí stack: **app** (Apache:80 → host:8080) + **db** (MariaDB 11)
5. Počká, až bude DB healthy, a spustí migrace

### 2.1.3 Varianta C — bez klonování repa (jen Docker, manuálně)

Pokud nechceš mít na hostiteli ani klon repa (typicky produkční Linux server,
jen Docker daemon), stáhni si přes `curl` jen dva soubory a sestav konfiguraci
ručně:

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

> ⚠️ Tato varianta NEgeneruje hesla a secrets automaticky — musíš je do
> `cfg.docker.php` doplnit ručně (viz komentář v ukázce). Pro one-click
> ekvivalent použij **Variantu A** (vyžaduje klon repa).

### 2.1.4 Po dokončení (všechny varianty)

**Otevři: 👉 http://localhost:8080**

V prohlížeči naskočí setup wizard — viz [3. První spuštění](03_Setup_wizard.md).

> ⚠️ **Použij `http://`, ne `https://`, a explicitní port `:8080`.** Docker
> stack běží na plain HTTP — pokud zadáš `https://...` nebo defaultní port,
> dostaneš `SSL_ERROR_RX_RECORD_TOO_LONG` / `ERR_SSL_PROTOCOL_ERROR`. Pro
> HTTPS na LAN/produkčním serveru viz [2.1.8 HTTPS / TLS terminace](#218-https--tls-terminace).
>
> 🛈 **Přístup z LAN přes IP** (např. `http://192.168.1.50:8080`) — od v2.1.1
> automaticky funguje. RFC1918 privátní IP (`10.*`, `172.16-31.*`, `192.168.*`),
> `127.*`, `localhost` a `*.local` jsou vyjmuty z HTTPS redirectu v `.htaccess`
> a `web.config`. Také požadavek s hlavičkou `X-Forwarded-Proto: https`
> (reverse proxy s TLS terminací) redirect přeskočí.

### 2.1.5 Změna portu

Edituj `.env` (vznikl po prvním spuštění):

```
APP_PORT=9000          # místo 8080
DB_PORT=3308           # místo 3307 (vázán jen na 127.0.0.1)
```

a `docker compose up -d`. URL pak `http://localhost:9000`.

### 2.1.6 Daily ops

```bash
docker compose up -d                                 # start
docker compose down                                  # stop (data v named volumes přežijí)
docker compose down -v                               # stop + WIPE volumes (ZNIČÍ DB!)
docker compose logs -f app                           # live logs
docker compose exec app bash                         # shell do kontejneru
docker compose exec app php api/bin/migrate.php      # CLI uvnitř kontejneru
cmd/docker-build.sh --no-cache                       # rebuild image (po PHP/JS změnách, jen Varianta B)
```

> 💡 Pokud jsi instaloval přes **Variantu A (docker-ghcr)**, všechny
> `docker compose` příkazy potřebují flag `-f docker-compose.production.yml`,
> např. `docker compose -f docker-compose.production.yml logs -f app`.

### 2.1.7 Volitelný Redis

```bash
docker compose --profile redis up -d
```

a v `cfg.docker.php` nastav `redis.enabled => true`. Restart appky.

### 2.1.8 HTTPS / TLS terminace

Docker stack sám TLS nedělá — Apache uvnitř kontejneru poslouchá na portu 80
(HTTP) a mapuje se na host port `8080`. Pokud potřebuješ HTTPS (LAN server,
produkce, doménové jméno), postav před stack reverse proxy s TLS terminací.

**Symptom špatné konfigurace:** prohlížeč hodí `SSL_ERROR_RX_RECORD_TOO_LONG`
(Firefox) nebo `ERR_SSL_PROTOCOL_ERROR` (Chrome) — znamená to, že browser mluví
TLS, ale server odpovídá plain HTTP.

**Tři rozumné cesty:**

1. **Caddy (nejjednodušší)** — automatický Let's Encrypt pro doménu nebo
   self-signed pro IP, jeden Caddyfile řádek:
   ```
   vase-domena.cz {
       reverse_proxy localhost:8080
   }
   ```

2. **Nginx + self-signed cert** (`mkcert` nebo `openssl`) — pro intranet
   bez veřejného doménového jména.

3. **Cloudflare Tunnel / Tailscale Funnel** — pokud chceš veřejný přístup
   bez otevírání portů na firewallu.

**A v `cfg.docker.php` přepni production cookie nastavení:**

```php
'cookie_secure' => true,
'cookie_name'   => '__Host-myinvoice_session',
'url'           => 'https://vase-domena.cz',
```

Restart stacku: `docker compose -f docker-compose.production.yml restart app`
(nebo bez `-f` flagu pro Variantu B).

## 2.2 Nativní install (5 minut)

Předpoklady:

- **PHP 8.5+** s extensions: `pdo`, `pdo_mysql`, `mbstring`, `openssl`, `json`,
  `iconv`, `gd`
- **MariaDB 10.6+** (doporučeno 11.x)
- **Composer 2.x**, **Node.js 20+**, **pnpm 9+**
- **Redis** (volitelné — fallback na MariaDB MEMORY)
- Web server: **IIS** nebo **Apache** (oba podporované, repo má `web.config`
  i `.htaccess`)

### 2.2.1 Klon a konfigurace

```bash
git clone <repo-url> myinvoice
cd myinvoice
cp cfg.sample.php cfg.php
```

Otevři `cfg.php` a vyplň:

- `db.user` / `db.pass` — připojení k MariaDB
- `app.pepper` — vygeneruj `openssl rand -base64 32`
- `smtp.host` / `user` / `pass` — odchozí pošta
- `captcha.site_key` / `secret_key` — z dash.cloudflare.com → Turnstile
- `ip_allowlist.allow` — volitelné, doporučeno v produkci

### 2.2.2 Vytvoř databázi

```bash
mysql -u root -p -e "CREATE DATABASE myinvoice CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 2.2.3 Backend + migrace

```bash
cd api && composer install && cd ..
php api/bin/migrate.php
```

### 2.2.4 Frontend build

```bash
cd web
pnpm install
pnpm build       # produkční build do web/dist/
```

### 2.2.5 Web server

- **IIS** — `web.config` v rootu repa nakonfiguruje rewrite + statiku.
- **Apache** — `.htaccess` v rootu repa, vyžaduje `mod_rewrite`, `mod_headers`.

## 2.3 Po instalaci

Otevři aplikaci v prohlížeči — pokračuj na [3. První spuštění](03_Setup_wizard.md).

## 2.4 CLI nástroje

```bash
php api/bin/migrate.php              # spustí pending migrace
php api/bin/migrate.php --status     # vypíše stav migrací
php api/bin/setup.php                # interaktivní úvodní zřízení
php api/bin/sample.php               # vygeneruje testovací data (po setupu)
php api/bin/reset.php                # smaže všechna user-data (vyžaduje "ANO")
php api/bin/recompute-stats.php      # přepočítá agregované statistiky
```

### 2.4.1 Cron skripty

V `cmd/` jsou připravené `.cmd` (Windows Task Scheduler) i `.sh` (Linux cron) wrappery:

| Skript | Doporučená frekvence |
|---|---|
| `cron-cleanup` | 1× denně 03:00 |
| `cron-backup` | 1× denně 02:00 |
| `cron-bank-scan` | každých 30 min |
| `cron-send-reminders` | 1× denně 09:00, Po–Pá |

Detaily v `cmd/README.md`.
