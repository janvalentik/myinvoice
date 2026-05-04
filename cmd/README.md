# cmd/ — provozní a deploy skripty

Adresář obsahuje **wrappery a operační skripty** pro plánované úlohy (cron),
Docker provoz a build frontendu. Pro každý skript jsou typicky **dvě varianty**:

- `*.sh` — Linux / macOS (bash)
- `*.cmd` nebo `*.ps1` — Windows (cmd nebo PowerShell)

Skripty samy detekují cestu k projektu (`PROJECT_ROOT`) podle umístění
samotného skriptu, takže jsou přenositelné mezi `C:\inetpub\wwwroot\…`,
`C:\work\…` (junction), `/var/www/…` na Linuxu apod.

## Přehled všech skriptů

### Cron — plánované úlohy

| Skript | Co dělá |
|---|---|
| `cron-cleanup.{cmd,sh}` | Čištění expirovaných session, starých logů, PDF cache, login_attempts |
| `cron-backup.{cmd,sh}` | mysqldump celé DB do `private/backups/YYYY-MM-DD.sql.gz`, retention 30 dní |
| `cron-bank-scan.{cmd,sh}` | Auto-import nových GPC výpisů z `private/bank-incoming/` + matching plateb na faktury |
| `cron-send-reminders.{cmd,sh}` | Odeslání upomínkových e-mailů na faktury po splatnosti (`--days=N`, `--cooldown=N`, `--dry-run`) |
| `cron-send-approval-reminders.{cmd,sh}` | Upomínky zákazníkům, kteří neschválili výkaz víceprací (`--days=N`, `--dry-run`) |

### Docker — vývoj v kontejnerech

| Skript | Co dělá |
|---|---|
| `docker-build.{sh,ps1}` | `docker compose build app` — postaví image (volitelné `--no-cache`, `--pull`) |
| `docker-install.{sh,ps1}` | First-run setup: vygeneruje `.env` + `cfg.docker.php`, postaví image, `up -d`, počká na DB healthcheck, spustí migrace, vypíše URL setup wizardu |

### Build / deploy / kvalita

| Skript | Co dělá |
|---|---|
| `publish.{sh,ps1}` | `cd web && pnpm install && pnpm build` — produkční build frontendu do `web/dist/` (před commitem nebo nasazením na produkční IIS / Apache) |
| `test.{sh,ps1}`    | `cd api && vendor/bin/phpunit` — spustí testovou sadu (94 testů, ~1 s). Lze passnout filter / testsuite (`cmd/test.sh --filter=GpcParser`) |

## Cron — doporučené frekvence

| Skript | Frekvence | Příklad času |
|---|---|---|
| `cron-cleanup` | 1× denně | 03:00 |
| `cron-backup` | 1× denně | 02:00 (před cleanupem) |
| `cron-bank-scan` | každých 15–30 minut | `*/30 * * * *` |
| `cron-send-reminders` | 1× denně (pracovní dny) | 09:00, Po–Pá |
| `cron-send-approval-reminders` | 1× denně (pracovní dny) | 09:15, Po–Pá |

Logy se ukládají do `log/cron/<nazev>-YYYY-MM-DD.log`. Stav úloh sleduj
v admin/activity-log (každý cron sám zapíše záznam `cron.<nazev>`).

### Windows — Task Scheduler

```cmd
schtasks /create /tn "MyInvoice Cleanup"   /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-cleanup.cmd"        /sc daily /st 03:00 /ru SYSTEM
schtasks /create /tn "MyInvoice Backup"    /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-backup.cmd"         /sc daily /st 02:00 /ru SYSTEM
schtasks /create /tn "MyInvoice BankScan"  /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-bank-scan.cmd"      /sc minute /mo 30 /ru SYSTEM
schtasks /create /tn "MyInvoice Reminders" /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-send-reminders.cmd" /sc weekly /d MON,TUE,WED,THU,FRI /st 09:00 /ru SYSTEM
schtasks /create /tn "MyInvoice ApprovalReminders" /tr "C:\inetpub\wwwroot\myinvoice.cz\cmd\cron-send-approval-reminders.cmd" /sc weekly /d MON,TUE,WED,THU,FRI /st 09:15 /ru SYSTEM
```

> ⚠️ PHP musí být v `PATH` účtu, pod kterým úloha běží (typicky `SYSTEM`
> nemá uživatelský PATH — ověř `where php` v cmd spuštěném jako SYSTEM přes
> `PsExec -s -i cmd`). Případně uprav `.cmd` skripty a doplň absolutní cestu
> k `php.exe`.
>
> ⚠️ `cron-backup` potřebuje `mariadb-dump` (nebo `mysqldump`). Skript zkouší
> `PATH` a běžné Windows lokace (`C:\Program Files\MariaDB*\bin`,
> `C:\inetpub\MariaDB\bin`, XAMPP, Laragon). Pokud máš binárku jinde, nastav
> v `cfg.php` (resp. `cfg.docker.php`) absolutní cestu:
> `'db' => ['dump_tool' => 'D:\\mariadb\\bin\\mariadb-dump.exe', ...]`.

### Linux — crontab

Edituj `crontab -e` (nebo `/etc/cron.d/myinvoice`):

```cron
# m  h  dom mon dow  command
  0  3  *   *   *    /var/www/myinvoice.cz/cmd/cron-cleanup.sh
  0  2  *   *   *    /var/www/myinvoice.cz/cmd/cron-backup.sh
*/30 *  *   *   *    /var/www/myinvoice.cz/cmd/cron-bank-scan.sh
  0  9  *   *   1-5  /var/www/myinvoice.cz/cmd/cron-send-reminders.sh
 15  9  *   *   1-5  /var/www/myinvoice.cz/cmd/cron-send-approval-reminders.sh
```

`*.sh` skripty musí být spustitelné: `chmod +x cmd/*.sh`.

### Manuální spuštění (debug)

Skripty jsou bezpečné spustit ručně. Pro `cron-send-reminders` je
k dispozici `--dry-run`:

```cmd
cmd\cron-send-reminders.cmd --dry-run
cmd\cron-send-reminders.cmd --days=5 --cooldown=14
```

## Docker

V rootu projektu je `Dockerfile` (multi-stage: node → composer → php:8.5-apache)
a `docker-compose.yml` se službami **app** + **db** (MariaDB 11) + volitelně
**redis** (profile).

### První spuštění

```bash
# Linux / macOS
cmd/docker-install.sh

# Windows PowerShell
.\cmd\docker-install.ps1
```

Skript je **idempotentní** — bezpečně se dá pustit znovu (existující `.env`
a `cfg.docker.php` přeskočí). Po dokončení běží aplikace na
**http://localhost:8080** a v prohlížeči naskočí setup wizard.

### Rebuild image

```bash
cmd/docker-build.sh --no-cache    # po změnách v Dockerfile / composer.json / pnpm-lock.yaml
cmd/docker-build.sh --pull        # pull nových verzí base images (php:8.5-apache, mariadb:11)
```

### Konfigurace přes `.env`

Vzniká při prvním spuštění install skriptu:

| Proměnná           | Default     | Význam                                                |
|--------------------|-------------|-------------------------------------------------------|
| `APP_PORT`         | `8080`      | Host port pro Apache                                  |
| `DB_PORT`          | `3307`      | Host port pro MariaDB (vázán jen na `127.0.0.1`)      |
| `DB_NAME`          | `myinvoice` | Název DB                                              |
| `DB_USER`          | `myinvoice` | App user                                              |
| `DB_PASSWORD`      | random      | Heslo app usera (28 znaků base64)                     |
| `DB_ROOT_PASSWORD` | random      | Heslo MariaDB roota                                   |

### Volitelný Redis

```bash
docker compose --profile redis up -d
```

a v `cfg.docker.php` nastavit `redis.enabled => true` (host už je `redis`).

### Daily ops

```bash
docker compose up -d                                       # start
docker compose down                                        # stop (data v named volumes přežijí)
docker compose down -v                                     # stop + WIPE volumes (zničí DB)
docker compose logs -f app                                 # live logs
docker compose exec app bash                               # shell do kontejneru
docker compose exec app php api/bin/migrate.php --status   # cli z hostu
```

### Cron uvnitř kontejneru

Apache image nemá `cron`. Cron skripty z `cmd/` spouštěj z hosta přes
`docker compose exec`:

```cron
0 9 * * 1-5  docker compose -f /opt/myinvoice/docker-compose.yml exec -T app php api/bin/cron-send-reminders.php
```

## Build / deploy

### `publish.{sh,ps1}` — produkční build frontendu

Spusť před deploy na IIS / Apache (frontend assety v `web/dist/`):

```bash
# Linux / macOS
cmd/publish.sh

# Windows PowerShell
.\cmd\publish.ps1
```

Co dělá (3 kroky):

1. `cd web/`
2. `pnpm install` — synchronizuje `web/node_modules/` s `pnpm-lock.yaml`
3. `pnpm build` — Vite build do `web/dist/` (s production optimalizacemi,
   tree-shaking, minifikací)

> 💡 `web/dist/` je v `.gitignore` — produkce si build dělá sama (tj. po
> `git pull` na produkci spusť `cmd/publish.sh`). Alternativně lze build
> commitnout (vyžaduje úpravu `.gitignore`).

> ⚠️ Vyžaduje `pnpm` v PATH. Instalace: `npm install -g pnpm`.

### `test.{sh,ps1}` — PHPUnit testy

```bash
# Linux / macOS
cmd/test.sh                            # všechny testy
cmd/test.sh --testsuite=Unit           # jen unit
cmd/test.sh --filter=GpcParser         # jen testy s názvem obsahujícím "GpcParser"

# Windows PowerShell
.\cmd\test.ps1
.\cmd\test.ps1 --filter=InvoiceMath
```

Pokrývá: GpcParser, InvoiceMath, AccountNumberNormalizer, SupplierGuard,
TurnstileVerifier, SecretEncryption, TotpService, IpMatcher, varsymbol +
month-increment helpers, error catalog. Integration test: unauthenticated
access (smoke check že middleware blokuje bez session).

## Konvence

- **Návratový kód 0** = OK, **non-zero** = chyba (vhodné pro shell pipes
  i Task Scheduler trigger).
- **`set -euo pipefail`** ve všech `.sh` (strict mode — fail fast).
- **`$ErrorActionPreference = 'Stop'`** ve všech `.ps1`.
- **PROJECT_ROOT** vždy resolvuju z `dirname` skriptu — žádné absolutní cesty
  v kódu.
- **Žádný `cd $HOME`** — pracuje se relativně k umístění skriptu, ne CWD volajícího.
