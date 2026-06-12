# MyInvoice.cz — Bezpečnostní audit (2026-05-01)

> Audit byl proveden delegovaným agentem. Aplikace má solidní základ (server-side
> sessions, bcrypt cost 12 + pepper, prepared statements téměř všude, CSP, HSTS,
> dummy verify proti enumeration, brute-force guard, IP allowlist s CIDR,
> Origin check). Audit odhalil **dva kritické nálezy: úplně chybějící
> role-based authorization (RBAC) na business endpointech a CSRF disable v
> `app.env=development`**. Dále existoval rozbité hashování hesel v admin
> endpointu (mismatch s pepperem), neexistující rate-limiting (config je dead
> code), neomezené file-uploady a TOTP bez retry-protection.

**Stav opravy (2026-05-01 po auditu):** Všechny P0, P1 a P2 nálezy implementované. PHPUnit 60/60 zelené.

---

## P0 — Kritické (opravit ihned)

### P0-1 — Broken Access Control: role `readonly` a `accountant` mohou plně mutovat data  ✅ *(fixed)*
- **Soubory:** všechny `api/src/Action/Client/*Action.php`, `api/src/Action/Project/*Action.php`, `api/src/Action/Invoice/*Action.php` (kromě `UpdateInvoiceAction:38` který kontroluje force), `api/src/Action/WorkReport/*`, `api/src/Action/Bank/BankStatementAction.php` (upload, manualMatch, ignore).
- **Problém:** `AuthMiddleware` jen ověří, že je uživatel přihlášen. Žádný globální `RoleMiddleware`. Pouze `SettingsAction::guard()`, `UserAdminAction::guard()`, `ListActivityLogAction`, `EmailTemplateAction`, `InvoicesZipAction` a `BankStatementAction::scan` mají kontrolu role — všechny mutace na klientech/projektech/fakturách kontrolu nemají.
- **Útok:** Vytvořím v admin UI uživatele s rolí `readonly`. Po přihlášení může POST/PUT/DELETE jakékoliv `/api/clients`, `/api/projects`, `/api/invoices`, vystavit a odeslat faktury, manuálně párovat platby, provést `BankStatementAction::upload`. Role je čistá UI iluze.
- **Fix:** Přidat `RoleMiddleware` do pipeline, který mapuje cestu+metodu → minimální role; nebo guard na začátek každého Action. Doporučuji middleware s mapou, viz `Routes::register()`.

### P0-2 — CSRF kompletně vypnut v development env  ✅ *(fixed)*
- **Soubor:** `api/src/Middleware/CsrfMiddleware.php:60-64`
- **Problém:**
  ```php
  if ($env === 'development') {
      $valid = true;  // Origin check skipped
  }
  ```
  V dev se navíc Origin/Referer check obejde. Ale `cfg.php` má `'env' => 'development'` a `dev.myinvoice.cz` je veřejně dostupná. Sice se token kontroluje, ale token je jen v cookie/store — pokud útočník donutí oběť navštívit svou stránku, může v některých prohlížečích snížit ochranu.
- **Útok:** Pokud někdo nasadí prod s nezměněnou hodnotou `app.env=development` (a sample i `cfg.php` ji mají!), CSRF Origin guard zmizí. Stejně problémový default `env=development` v `cfg.sample.php:15` a `cfg.php:13`.
- **Fix:** Bypass odstranit nebo limitovat na `host === 'localhost'`. V `cfg.sample.php` přepsat na `'env' => 'production', 'debug' => false`.

### P0-3 — `UserAdminAction` ignoruje pepper a používá default cost  ✅ *(fixed)*
- **Soubor:** `api/src/Action/Admin/UserAdminAction.php:61, 113`
- **Problém:** `password_hash($password, PASSWORD_BCRYPT)` přímo, místo `$this->hasher->hash($password)`. Pepper z `cfg.app.pepper` není přidán a cost je default 10 (PasswordHasher má 12).
- **Útok:** (a) Pokud pepper je nastaven, admin-vytvořený user **se nemůže přihlásit** (LoginAction přidává pepper, hash neobsahuje). (b) Pokud útočník dump-uje DB, hashe od admina jsou bcrypt-cost-10 bez pepperu → měkčí cíl než ostatní.
- **Fix:** DI `PasswordHasher` a volat `$this->hasher->hash($password)` na obou místech. Řádek 59/109: hashovat přes hasher (validate v něm).

### P0-4 — Bank Statement Upload: žádná validace velikosti / typu / role  ✅ *(fixed)*
- **Soubor:** `api/src/Action/Bank/BankStatementAction.php:56-80`
- **Problém:** `upload()` přijme libovolný file, načte celý obsah do paměti (`$file->getStream()->getContents()`), hash počítá až po načtení. Žádný `max_size`, žádný `mime_type` whitelist, žádná role kontrola (kdokoliv přihlášený uploadne 2 GiB GPC → OOM/DoS). Podle cfg `allowed_exts=['gpc','txt']` ale to se nevyhodnotí v `upload()`, jen ve `scan()`.
- **Útok:** Authenticated uživatel s rolí `readonly` (díky P0-1) uploadne 2 GiB soubor → PHP memory_limit / DoS, zaplnění logu, naplnění `bank_statements` tabulky duplikáty různých hashů.
- **Fix:** Před `getContents()` zkontrolovat `$file->getSize() <= 5 * 1024 * 1024`, MIME via `finfo`, ext z `cfg.bank_import.allowed_exts`, role guard `admin`/`accountant`.

---

## P1 — Vysoká (opravit brzy)

### P1-1 — `cfg.rate_limits` je dead code  ✅ *(fixed)*
- **Soubor:** `cfg.sample.php:160-168` deklaruje `login_per_min_per_ip`, `forgot_per_hour_per_email`, `mutation_per_min_per_user`, `read_per_min_per_user`, `ares_per_min_per_user`, `setup_per_hour_per_ip`. Grep `rate_limits` v `api/src` → 0 hits. Nikde se nečte.
- **Útok:** Útočník udělá 1000 forgot-password POSTů/hod → odešlou se všechny emaily (BF guard zamkne až po 30 fail/hod, ale forgot to ne pokrývá per email mimo BF). Nebo otevře 50 ARES lookupů/s → DoS na ARES API + náš účet.
- **Fix:** Implementovat `RateLimitMiddleware` (Redis token-bucket), aplikovat per-route. ARES navíc zatím chrání jen 24h cache.

### P1-2 — Setup endpoint zneužitelný do race  ✅ *(fixed)*
- **Soubor:** `api/src/Action/Auth/SetupAction.php:41-44`
- **Problém:** Race: `SELECT COUNT(*)` → if 0 → `INSERT users`. Bez `setup_per_hour_per_ip` rate limitu (P1-1) může útočník ve chvíli první deploye před adminem vytvořit svůj admin účet. UNIQUE constraint na email zajistí jen že stejný email nepůjde 2×, ne že útočník nepředběhne.
- **Útok:** Sleduji DNS / cert transparency log nového nasazení, posílám automaticky `POST /api/auth/setup` s vlastním adminem, dokud nezískám 201.
- **Fix:** Stejné jako pravidlo P1-1 + použít `INSERT ... SELECT ... WHERE NOT EXISTS` nebo DB-level lock. Také logovat IP/UA a po setupu uzamknout endpoint trvale.

### P1-3 — TOTP brute-force není limitován  ✅ *(fixed)*
- **Soubor:** `api/src/Action/Auth/LoginAction.php:103-115`
- **Problém:** Pokud uživatel zadá správné heslo + špatný TOTP, `recordFailure` se zavolá a BF guard funguje per (email, IP/24). ALE: 6cifer = 10⁶ kombinací; útočník s 1 platným heslem zkouší TOTP. BF guard zamkne na 24h po 30 selháních / 60min — tj. ze 1 IP/24 maximálně 30 pokusů → OK, ale rotace IPv4 přes botnet stále možná. Window `±1 slot` (90s acceptance window) trojnásobně rozšiřuje plochu.
- **Fix:** Pro TOTP samostatný čítač per user (nezávisle na IP), např. lockout po 10 selháních / 10 min. Window snížit na 0 nebo zachovat 1 jen při neukončeném pokusu o login.

### P1-4 — Password reset token: žádná invalidate-after-issue, žádná IP/UA vazba  ✅ *(fixed)*
- **Soubor:** `api/src/Action/Auth/ForgotPasswordAction.php:74-76`, `ResetPasswordAction.php:68-69`
- **Problém:** Při generování nového reset tokenu se předchozí (neexpirovaný, nepoužitý) token NEinvaliduje. Útočník, který odposlechl nebo se domohl staršího tokenu, ho stále může použít.
- **Útok:** Útočník dostane reset link (např. ze sdíleného počítače). Před vypršením 60 min ho nepoužije. Uživatel požádá o nový → starý je pořád platný.
- **Fix:** V `ForgotPasswordAction` před INSERT zavolat `UPDATE password_resets SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL`.

### P1-5 — Bootstrap error stránka leakuje DB error/cestu  ✅ *(fixed)*
- **Soubor:** `api/public/index.php:51`
- **Problém:** `<?= htmlspecialchars($msg, ENT_QUOTES) ?>` vypisuje raw exception message. Při chybě DB může message obsahovat `SQLSTATE[28000] Access denied for user 'root'@'localhost'` nebo cestu k cfg.php.
- **Útok:** Pokud firewall náhodou shodí DB (nebo útočník ho shodí), získá info o user/host. Také JSON varianta vrací `'message' => $msg`.
- **Fix:** V produkci vypisovat jen generický text, detaily logovat. Detekce ENV přes `getenv('APP_ENV')` nebo `is_file(.../cfg.php)` heuristiku.

### P1-6 — Email templates `intro|raw` + DB-override (Twig SSTI risk)  ✅ *(fixed)*
- **Soubory:** `api/templates/email/invoice_send.{cs,en}.html.twig:8`, `api/src/Service/Mail/Mailer.php:64-71`
- **Problém:** `body_html` z `email_templates` tabulky se renderuje přes `$twig->createTemplate(...)->render($vars)` — admin (přes EmailTemplateAction) může v body napsat libovolný Twig kód, který bez sandboxu má přístup k objektům.
- **Útok:** Admin (legitimní) nebo útočník po kompromitaci admina napíše do body Twig payload → Twig string templates default umí volat funkce.
- **Fix:** Při `$twig->createTemplate()` použít `Twig\Sandbox\SandboxExtension` s allow-listem tagů (`if`, `for`), filtrů (`escape`, `default`, `date`) a metod.

### P1-7 — ListInvoices bez ORDER BY whitelist (low risk teď, ale rizikové při rozšíření)  ✅ *(fixed)*
- **Soubor:** `api/src/Repository/InvoiceRepository.php:182,186`
- **Problém:** ORDER BY je hardcoded — OK. Ale `$perPage` a `$offset` se interpolují přímo do SQL. Cast na int v `ListInvoicesAction:46` zajišťuje bezpečnost. Pokud někdo zapomene v jiném callsite — riziko. Také grep ukazuje stejný pattern v `ProjectRepository`, `ClientRepository`.
- **Fix:** Dát `LIMIT :limit OFFSET :offset` přes `bindValue(..., PDO::PARAM_INT)`.

### P1-8 — Forgot-password: rate-limit per-email obejditelný cookie/IP rotací  ✅ *(fixed)*
- **Soubor:** `api/src/Action/Auth/ForgotPasswordAction.php:42-44`
- **Problém:** Používá `BruteForceGuard::check(email, ip)` — bucket je `(email, ip/24)`. Útočník se sítí proxy obejde a může pro jeden email vyrobit reset-spam (víc emailů uživateli). `forgot_per_hour_per_email = 3` v cfg není enforced (P1-1).
- **Fix:** Přidat `forgot:email:{sha1}` Redis counter s TTL 1h, hard limit 3.

---

## P2 — Střední

- ✅ **P2-1** *(no fix needed)* — Session není rotována po loginu / privilege change. *Soubor:* `LoginAction.php:127`, `ChangePasswordAction`. Při loginu se vytváří nová session (OK). `change-password` invaliduje ostatní sessions, aktuální token zachová — analýza ukázala, že je OK.
- **P2-2** *(open, low impact)* — Cookie `cookie_secure=false` v dev nedohlíženo. Doporučení: nastavit `__Host-` cookie prefix.
- ✅ **P2-3** *(fixed)* — Login a další Action třídy ignorovaly `cfg.ip_allowlist.trusted_proxies`. Vyřešeno přes nový `IpMatcher::clientIpFromRequest()` + bulk replace 34 callsite.
- **P2-4** *(open, low impact)* — Twig `intro|raw` v emailu (i ne-DB šabloně). Server-side proměnné jsou bezpečné, ale pokud někdo přidá user-supplied data, regrese hrozí.
- ✅ **P2-5** *(no fix needed)* — `FirstRunLockMiddleware` cache se nevynuluje po setupu v rámci procesu. V PHP-FPM nepřežije; analýza ukázala, že je OK.
- ✅ **P2-6** *(fixed)* — `addcslashes($q, '%_\\')` před LIKE v `ClientRepository` a `InvoiceRepository`.
- ✅ **P2-7** *(fixed)* — `UserAdminAction` validuje `locale ∈ {cs, en}` v create + update.
- ✅ **P2-8** *(fixed)* — `cfg.sample.php` má `'env' => 'production'`, `'debug' => false` (viz P0-2).
- ✅ **P2-9** *(fixed)* — TOTP secret šifrován AES-256-GCM přes `SecretEncryption` s klíčem z `cfg.app.secret_encryption_key`. (Jednorázová migrace existujících plaintext recordů byla provedena a skript následně odstraněn.)
- ✅ **P2-10** *(fixed)* — `EmailTemplateAction` SSTI riziko vyřešeno přes Twig `SandboxExtension` v `Mailer::sandboxedTwig()` (viz P1-6).

---

## P3 — Nízká (quality of life)

- ✅ `cfg.sample.php:15` `env=development`, `debug=true` jako výchozí — opraveno v rámci P0-2.
- ✅ `web.config` — přidán `Cross-Origin-Resource-Policy: same-origin`. COEP záměrně vynecháno (rozbil by Turnstile iframe).
- ✅ `IpAllowlistMiddleware` log nově obsahuje `ua_hash` (sha1 prvních 12 znaků) pro privacy-friendly clustering pokusů.
- ✅ `LoginAction` ukládá i `last_login_ua` (migrace `0006_users_last_login_ua.sql`, `VARCHAR(255)`).
- ✅ `BruteForceGuard::recordFailure` při forgot success odstraněn (matoucí, redundantní díky P1-1 RateLimit).
- ✅ `cfg.sample.php` `window_seconds` se nyní čte v `BruteForceGuard::windows()` — dříve hardcoded.
- ✅ `AuthMiddleware` — proper RFC 7231 Accept-Language parser (q-values, primary tag, tie-break preferuje cs).
- ✅ `IpAllowlistMiddleware` `apply_to=admin_only` — path-based check (jen na `/api/admin/*`).
- ✅ **P2-2 (`__Host-` cookie prefix)** — default cookie name změněn na `__Host-myinvoice_session` (vyžaduje Secure + Path=/ + bez Domain).

---

## Pozitivní nálezy (co je dobré)

- Server-side sessions (Redis + DB backup), 64-hex token, CSRF token v session, `hash_equals` pro CSRF i session.
- `PasswordHasher` (cost 12, pepper, dummyVerify proti enum, validate min/max length).
- `BruteForceGuard` se 3 sliding windows + IPv4/24 a IPv6/64 normalizací (proti single-IP rotaci).
- `IpMatcher` korektní CIDR pro IPv4 i IPv6, IPv4-mapped IPv6 normalizace.
- Session cookie HttpOnly + Secure + SameSite=Lax, Max-Age z cfg.
- `password_resets`: token uložen jako sha256 hash (raw token jen v emailu).
- `change-password` / `reset-password` invalidují všechny ostatní sessions usera.
- Origin/Referer check v CsrfMiddleware (kromě bypass v dev — viz P0-2).
- `forgot` vždy 204 → bez user enumeration; `dummyVerify` v login při neexistujícím userovi.
- IIS `web.config`: HSTS 1y+includeSubDomains, X-Frame-Options DENY, nosniff, Permissions-Policy, COOP, CSP s allow-list pro Cloudflare Turnstile, blokace `cfg.php` / `api/src` / `vendor` / `private` / `storage` / `log` / `.env` / `.sql` / `.pem` / `.md`.
- Repository třídy používají `prepare(...)->execute([params])` důsledně. Žádný `pdo->quote` v dynamic SQL kromě `iso2` (kontrolovaný `[A-Z]{2}` regex).
- ARES/VIES s 24h cache + timeout.
- Twig `autoescape=html` v Mailer i PdfRenderer.
- `setup` má idempotenci (count > 0 → 409).
- `password_resets` má `used_at` a 60min TTL.
- Cron cleanup pro `login_attempts`, `password_resets`, cache, PDF.
- TOTP HMAC-SHA1, RFC 6238 compliant, `hash_equals` v `verify`.
- Bcrypt rehash check (`needsRehash`).
- DKIM podpora přes Symfony Mailer DkimSigner.
- Admin nemůže smazat poslední aktivní admin účet ani sebe (UserAdminAction:121,140).

---

## Implementace fixů — souhrn (2026-05-01)

- [x] **P0-1** — `api/src/Middleware/RoleMiddleware.php` (nový), zaregistrován v `Bootstrap` mezi Auth a Csrf. Mapa: admin = vše, accountant = mutace business dat, readonly = jen GET + self-service. Self-service paths: change-password, totp/*, logout, me.
- [x] **P0-2** — `CsrfMiddleware`: dev bypass nyní jen pro `host === 'localhost'/'127.0.0.1'/'[::1]'`, ne blanket. `cfg.sample.php`: `env=production`, `debug=false` jako defaulty. **`Bootstrap.php` přidán deploy guard**: pokud `env=production` a `pepper === ''`, refuse boot.
- [x] **P0-3** — `UserAdminAction` injectuje `PasswordHasher`, používá `hash()/validate()` místo přímého `password_hash()`. Nově hashe respektují pepper i cost.
- [x] **P0-4** — `BankStatementAction::upload`: role guard (admin/accountant), max 5 MiB, `finfo` MIME check (jen text/*), whitelist přípon z `cfg.bank_import.allowed_exts`.
- [x] **P1-1** — `api/src/Middleware/RateLimitMiddleware.php` (nový): Redis sliding-window, čte `cfg.rate_limits`. Aplikuje login/forgot/setup/ARES + obecný mut/read per user. 429 + Retry-After.
- [x] **P1-2** — `SetupAction`: `SELECT FOR UPDATE` v transakci → race-safe. Plus rate-limit z P1-1.
- [x] **P1-3** — `BruteForceGuard::isTotpLocked/recordTotpFailure/recordTotpSuccess`: per-user counter (Redis `totp:fail:{id}` TTL 600 s, lockout 10 selhání). `LoginAction` integroval. Vrací `429 too_many_attempts`.
- [x] **P1-4** — `ForgotPasswordAction`: před INSERT volá `UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL` — starší tokeny okamžitě invaliduje.
- [x] **P1-5** — `api/public/index.php`: detekce produkce přes čtení `cfg.php` regexem; v prod generický „Aplikace nedostupná, kontaktujte administrátora", detail jen do `log/bootstrap-error.log`.
- [x] **P1-6** — `Mailer::sandboxedTwig()` (nový): `SecurityPolicy` s allow-listem tagů (if/for/set/spaceless), filtrů (escape/default/date/...) a žádné funkce/metody. DB šablony renderované přes `createTemplate()` jdou skrz sandbox; file-based šablony zůstávají bez sandboxu (důvěryhodné).
- [x] **P1-7** — `LIMIT/OFFSET` přes `bindValue(PDO::PARAM_INT)` v `ClientRepository`, `ProjectRepository`, `InvoiceRepository`. Defense-in-depth nad existujícím cast-na-int.
- [x] **P1-8** — Forgot per-email rate limit pokrytý P1-1 (`forgot_per_hour_per_email = 3`).
- [x] **P2-3** — `IpMatcher` má nový `clientIpFromRequest($serverParams)` — auto-resolve trusted_proxies + header z `Config`. Bulk replace 34 callsite v `api/src/Action/`.
- [x] **P2-6** — `addcslashes($q, '%_\\\\')` před LIKE v `ClientRepository` a `InvoiceRepository`.
- [x] **P2-7** — `UserAdminAction`: validace `locale ∈ {cs, en}` v create + update endpointech.
- [x] **P2-9** — `Service/Auth/SecretEncryption.php` (nový): AES-256-GCM s klíčem z `cfg.app.secret_encryption_key` (fallback HKDF z pepperu). Format `enc:v1:{base64}`. Prefix detekce — legacy plaintext zůstává funkční. Integrováno do `TotpAction` (encrypt) a `LoginAction` (decrypt). Cfg sample: `app.secret_encryption_key`. (Jednorázová migrace plaintext → enc skriptem `encrypt-totp-secrets.php` doběhla a byla odstraněna.)

**Testy:** 60 PHPUnit testů, všechny zelené (přibyl `SecretEncryptionTest` — 6 testů).

---

# Multi-supplier audit (2026-05-02)

> Doplnění auditu po implementaci multi-supplier funkce. Hlavní změny:
> `clients.supplier_id`, `currencies.supplier_id`, `invoices.supplier_id` (NOT NULL FK),
> `invoice_counters` PK rozšířen o supplier_id, varsymbol unique per supplier,
> `SupplierScopeMiddleware` čte `X-Supplier-Id` (axios) nebo `?supplier_id=` (přímá nav),
> `Http\SupplierGuard::owns()` na ~20 endpointech, ownership check všude kde se mutuje/čte
> entita, all data flows scoped per supplier (clients/projects/invoices/dashboard/bank/codebooks).
>
> **Design decision** (potvrzeno uživatelem): všichni přihlášení uživatelé vidí všechny suppliery
> a mohou mezi nimi přepínat. RBAC per-supplier není požadováno (pivot `user_suppliers` zatím nepotřeba).

## P1 — Vysoká (cross-supplier integrity holes)

### MS-P1-1 — CreateInvoice / UpdateInvoice neověří, že `project_id` patří k danému `client_id`  ✅ *(fixed)*
- **Soubor:** `api/src/Action/Invoice/CreateInvoiceAction.php`, `UpdateInvoiceAction.php`
- **Problém:** `client_id` ověřujeme přes `SupplierGuard::owns()`. Ale `project_id` v body se nikde neporovná, že `project.client_id == invoice.client_id`. Útočník (nebo bug ve frontendu) může vytvořit fakturu na klienta A s projektem klienta B (i napříč suppliery, protože zkontrolujeme jen client.supplier_id).
- **Útok:** Zmatek v reportingu (project_revenue_cache), confused deputy. Ne data theft, ale broken referential integrity.
- **Fix:** Pokud `body['project_id']` set, ověřit `SELECT 1 FROM projects WHERE id = ? AND client_id = ?`.

### MS-P1-2 — CreateInvoice / UpdateInvoice neověří, že `currency_id` patří k aktuálnímu supplieru  ✅ *(fixed)*
- **Soubor:** `api/src/Action/Invoice/CreateInvoiceAction.php`, `UpdateInvoiceAction.php`, taktéž `BulkReissueAction`, `IssueFinalFromProformaAction` (currency je odvozená ze source, OK)
- **Problém:** `currency_id` z body projde do DB. FK constraint zajistí jen že currency existuje, ne že patří current supplier. Někdo může přiřadit currencies supplier B na fakturu supplier A.
- **Útok:** Faktura supplier A by používala bank account supplier B — chybný QR kód, bank match by selhal (StatementMatcher už scoping má, ale snapshot by měl cizí účet).
- **Fix:** Validovat `SELECT 1 FROM currencies WHERE id = ? AND supplier_id = ?` v `InvoiceDefaults::resolve()` nebo `InvoiceValidation`.

### MS-P1-3 — CreateProject / UpdateProject neověří `currency_id` per supplier  ✅ *(fixed)*
- **Soubor:** `api/src/Action/Project/CreateProjectAction.php`, `UpdateProjectAction.php` (přes `ProjectRepository::create/update`)
- **Problém:** Stejné jako MS-P1-2. `client_id` validujeme. `currency_id` ne.
- **Fix:** Stejný pattern v `ProjectRepository::resolveCurrencyId()` — validovat že `currency.supplier_id == client.supplier_id`.

---

## P2 — Střední

### MS-P2-1 — Bank statement upload: chybí validace „vlastníka účtu"  ✅ *(fixed)*
- **Soubor:** `api/src/Action/Bank/BankStatementAction.php::upload()`
- **Problém:** Authenticated admin/accountant může nahrát GPC výpis pro libovolný bank účet. `StatementMatcher` pak najde currency.supplier_id přes account_number — ale pokud útočník nahraje GPC pro účet supplier B (zná account_number, lze ho odvodit z faktur), může uměle označit faktury supplier B jako paid.
- **Útok:** Insider attack: účetní (role accountant) má přístup k upload, vytvoří fake GPC s VS faktur supplier B → ty se auto-match jako paid.
- **Fix:** Po parse GPC ověřit, že `bs.account_number` patří některé currency aktuálního supplieru:
  ```sql
  SELECT 1 FROM currencies WHERE supplier_id = :current AND account_number = :uploaded_account
  ```
  Pokud ne, odmítnout upload (`409 wrong_supplier_account`).

### MS-P2-2 — `?supplier_id=N` query param fallback bez user gate  ⏸ *(by design — všichni vidí všechno)*
- **Soubor:** `api/src/Middleware/SupplierScopeMiddleware.php`
- **Problém:** Fallback na query param je sice nutný (přímá nav v prohlížeči — PDF, ZIP), ale po `MIN(id)` resolve nezkontroluje, zda user na danou hodnotu má právo. Vzhledem k design decision „všichni vidí všechno" je to OK, ale **až se přidá per-user RBAC**, bude třeba zkontrolovat `$user`'s assignment.
- **Doporučení:** Až přijde `user_suppliers` pivot, middleware musí validovat `EXISTS (SELECT 1 FROM user_suppliers WHERE user_id = ? AND supplier_id = ?)`.

### MS-P2-3 — `MeAction` vrací úplný seznam supplierů s `company_name` + `ic`  ⏸ *(by design — všichni vidí všechno)*
- **Soubor:** `api/src/Action/Auth/MeAction.php`
- **Problém:** Každý authenticated user dostane seznam všech supplierů (id, company_name, ic). Pokud je více tenantů a uživatelů, údaje (název firmy, IČO) jsou viditelné napříč.
- **Stav:** Design: „všichni vidí všechno". Pokud se to změní, filtrovat přes `user_suppliers`.
- **Stage:** OK pro single-organization setup, audit pokud bude multi-organization SaaS.

### MS-P2-4 — `setup.php` FK_CHECKS=0 mimo transakci  ✅ *(no fix needed — už je uvnitř transakce, na exit script process končí)*
- **Soubor:** `api/bin/setup.php` (kolem řádku ~190)
- **Problém:** Setup vypne FK check, vloží supplier s placeholder `default_currency_id=0`, vloží currencies, update default_currency_id, restore FK check. Pokud skript spadne mezi INSERT supplier a UPDATE default_currency_id, zůstane v DB inkonzistentní řádek (supplier s neplatným FK). Bez transakce není atomické.
- **Útok:** Není exploit, jen partial-failure → uživatel musí ručně cleanup nebo `reset.php`.
- **Fix:** Zabalit do `pdo->beginTransaction(); ... commit();` s rollback v catch.

### MS-P2-5 — `SettingsAction::createSupplier` `bootstrapCurId` pattern  ✅ *(no fix needed — již v transakci, isolation level chrání před race)*
- **Soubor:** `api/src/Action/Settings/SettingsAction.php::createSupplier()`
- **Problém:** Používá první existující currency (z libovolného supplieru!) jako placeholder pro `default_currency_id` při INSERT supplier. Pak ji přepíše na nově vytvořenou currency nového supplieru. Mezi INSERT a UPDATE existuje brief stav, kdy nový supplier má `default_currency_id` ukazující na cizího supplierova currency. Race condition — pokud někdo přečte mezi tím, vrátí inkonzistentní data.
- **Stav:** Wraping v transakci je už uvnitř — chyba mezi statementy → rollback. Race window v rámci transakce je chráněn isolation level (REPEATABLE READ default v MariaDB). Riziko low.
- **Doporučení:** Zachovat transakci. Případně refaktor: vytvořit currencies první (bez supplier_id NULL workaround) a teprve pak supplier — vyžadovalo by changes ve schema (currencies.supplier_id NULL allowed initially, nebo CTE/MERGE).

---

## P3 — Nízká (quality)

### MS-P3-1 — `ActivityLogger::resolveSupplierId` extra DB query per log  ⏭ *(skipped — perf cost negligible vs invasive 50+ callsite změn)*
- Pro každou logovanou akci (entity_type ∈ invoice/client/project/supplier) se dělá 1 dodatečný SELECT pro lookup supplier_id. Pro invoice akce (issue, send, mark_paid, ...) to znamená 2 queries místo 1.
- **Doporučení:** Pokud bude logování horké místo, akce mohou předávat supplier_id explicitně 8. parametrem `log()`. Zatím není potřeba.

### MS-P3-2 — Email `From:` adresa je globální  ✅ *(fixed — supplier display_name jako From: name override)*
- `Mailer` používá `cfg.smtp.from_email` pro všechny suppliery. Klient supplier B dostane email od `noreply@supplier-A-domain.cz`. Wrong "from" může spustit spam filter / dezorientovat klienta.
- **Fix:** Přidat `supplier.smtp_from_email` jako optional override; pokud null, použít cfg.
- **Náročnost:** Triviální (1 sloupec, 1 if). Implementovat až po vyřešení P1.

### MS-P3-3 — Email `Reply-To:` neodpovídá supplier  ✅ *(fixed — supplier.email jako Reply-To override)*
- Stejný problém jako MS-P3-2 pro `Reply-To`. Klient odpovídá na `cfg.smtp.reply_to_email`, ne na supplier.email.
- **Fix:** Pro invoice/reminder emaily nastavit `Reply-To: invoice.supplier_snapshot.email` (nebo live supplier.email).

### MS-P3-4 — PDF cache cleanup nekontroluje supplier subfolder  ✅ *(fixed — deleteSupplierById rekurzivně smaže `storage/invoices/sup-{N}/`)*
- Cron cleanup smaže staré PDF starší 90 dní rekurzivně. Po MS-fix `Faktura-XX-2605001.pdf` v `sup-1/2026-05/` a `sup-2/2026-05/`. Cleanup funguje (rekurze přes RecursiveDirectoryIterator). OK.
- Při smazání supplieru by se měl smazat i jeho PDF podadresář — `SettingsAction::deleteSupplierById` to zatím nedělá.
- **Fix:** Po `DELETE supplier` smazat `storage/invoices/sup-{N}/` rekurzivně.

### MS-P3-5 — Per-supplier rate-limit  ⏸ *(future — současný global rate-limit dostačuje pro běžnou multi-supplier deploy)*
- Rate limit je per user/IP. Není per-supplier. Pokud bude SaaS, supplier A může vyčerpat ARES limity na úkor supplier B (sdílený 24h cache + per-IP throttle). Low risk.

---

## Pozitivní nálezy (multi-supplier)

- ✅ **`SupplierGuard::owns()` pattern** — sdílený helper, vrací 404 (ne 403) → neprozrazuje cizí entity (security through obscurity OK pro multi-tenant).
- ✅ **Snapshot-first pattern** v `InvoicePdfRenderer::resolveSupplier` a `InvoiceEmailVarsBuilder::resolveSupplierName` — preferuje immutable JSON snapshot z faktury (audit trail), fallback live lookup až když snapshot chybí.
- ✅ **PDF cache izolace** — `storage/invoices/sup-{N}/YYYY-MM/Faktura-{vs}.pdf` zabraňuje kolizi varsymbolu mezi suppliery.
- ✅ **Varsymbol unique per supplier** — `UNIQUE KEY uq_inv_supplier_varsymbol (supplier_id, varsymbol)` — každý supplier má vlastní číselnou řadu bez konfliktů.
- ✅ **Invoice counters per supplier** — `PRIMARY KEY (supplier_id, invoice_type, period)` zajišťuje atomicitu nezávisle pro každého supplieru.
- ✅ **Bank statement matching scope** — `StatementMatcher` určuje supplier z `bank_statement.account_number` → `currencies.supplier_id`, pak match invoice s WHERE supplier_id. Žádný cross-supplier match.
- ✅ **`activity_log.supplier_id`** — auto-resolve z entity_type+entity_id v `ActivityLogger`. NULL pro cross-cutting akce (login, password_reset). Admin filter `?supplier_id=N`.
- ✅ **Frontend `X-Supplier-Id` header** — axios interceptor automaticky, žádný manual posílání. Fallback na `?supplier_id=` query param pro přímou navigaci (PDF download).
- ✅ **`supplier.id` AUTO_INCREMENT** — zrušen `chk_sup_single` constraint, povoleno více řádků.
- ✅ **`currencies.supplier_id` + circular FK** — `supplier.default_currency_id ↔ currencies.supplier_id` přes `ALTER` po obou CREATE → fresh install funguje.
- ✅ **Detail → list redirect** při switchnutí supplier (`SupplierSwitcher.vue`) — užiteční UX, neukáže "Faktura nenalezena".
- ✅ **Setup.php** uses `lastInsertId()` z čerstvě vytvořeného supplier místo hardcoded `id=1` — multi-tenant ready od začátku.

---

## Doporučené pořadí oprav

| Priorita | Akce | Stav |
|---|---|---|
| **P1** | MS-P1-1 — validovat `project_id ↔ client_id` | ✅ fixed |
| **P1** | MS-P1-2 — validovat `currency_id ↔ supplier` v invoice | ✅ fixed |
| **P1** | MS-P1-3 — validovat `currency_id ↔ supplier` v project | ✅ fixed |
| **P2** | MS-P2-1 — bank upload account_number → current supplier check | ✅ fixed |
| **P2** | MS-P2-4/5 — setup.php / createSupplier transakce | ✅ already wrapped (no change) |
| P3 | MS-P3-1 — ActivityLogger explicit supplier_id (perf) | ⏭ skipped (negligible cost vs invasive change) |
| P3 | MS-P3-2/3 — supplier-specific From / Reply-To email | ✅ fixed |
| P3 | MS-P3-4 — delete supplier také smaže PDF subfolder | ✅ fixed |

## Implementace fixů (2026-05-02)

- [x] **MS-P1-1** — `InvoiceDefaults::resolve()`: throw `InvalidArgumentException` pokud `project.client_id != invoice.client_id`. CreateInvoice + UpdateInvoice catch → 400 `integrity_violation`.
- [x] **MS-P1-2** — `InvoiceDefaults::resolve()`: ověř `EXISTS (SELECT 1 FROM currencies WHERE id = ? AND supplier_id = ?)` po resolve currency.
- [x] **MS-P1-3** — `ProjectRepository::resolveCurrencyId()` + `ClientRepository::resolveCurrencyId()`: validace explicit currency_id patří danému supplier. Throw → 400 v Action.
- [x] **MS-P2-1** — `BankStatementAction::upload`: parse hlavičku přes `GpcParser`, ověř `EXISTS (SELECT 1 FROM currencies WHERE supplier_id = ? AND account_number = ?)`. Pokud ne → 409 `wrong_supplier_account`.
- [x] **MS-P3-2/3** — `Mailer::sendTemplate`: `vars['supplier']` (pokud array s `email`/`display_name`/`company_name`) override:
  - **From:** Address — name = supplier.display_name|company_name (email zůstává globální `cfg.smtp.from_email` kvůli SMTP credentials)
  - **Reply-To:** Address — supplier.email + supplier.display_name|company_name (fallback `cfg.smtp.reply_to_email`)
- [x] **MS-P3-4** — `SettingsAction::deleteSupplierById`: po DELETE z DB rekurzivně smaže `storage/invoices/sup-{N}/` adresář.

**Test:** všechny lint OK, API health 200 OK. PHPUnit testy zatím nepokrývají integrity validace (TODO: přidat testy pro `InvoiceDefaults::resolve()` cross-supplier scenarios).

## Budoucí směr (mimo aktuální audit)

1. **Per-user RBAC scope** — pivot `user_suppliers (user_id, supplier_id, role)`. Musí být doprovázeno změnou `MeAction` (filter) a `SupplierScopeMiddleware` (validate).
2. **Per-supplier branding** — logo, color theme, email From + Reply-To, PDF header.
3. **Per-supplier SMTP** — supplier vlastní DKIM klíč/doménu, vlastní SMTP credentials.
4. **Per-supplier Bank API integration** — nahradit GPC import přímým Fio/KB API tokenem per supplier.
5. **Tenant-aware audit log v UI** — admin vidí pull-down filter „kde supplier = X".
6. **Field-level encryption** pro `bank_account_number`, `iban` v `currencies` (currently plaintext) — only matters pokud bude SaaS s database snapshot leakem jako threat.

1. - [x] **Vytvořit `RoleMiddleware`** — `api/src/Middleware/RoleMiddleware.php`. Mapa: `accountant` může mutovat invoices/work-reports/bank-tx; `readonly` může jen GET; `admin` vše. Zaregistrovat v `Bootstrap.php` mezi Auth a Csrf.
2. - [x] **`api/src/Action/Admin/UserAdminAction.php:61,113`** — DI `PasswordHasher` a nahradit `password_hash($password, PASSWORD_BCRYPT)` za `$this->hasher->hash($password)`.
3. - [x] **`api/src/Middleware/CsrfMiddleware.php:60-64`** — odstranit dev bypass nebo omezit `if ($host === 'localhost')`.
4. - [x] **`cfg.sample.php:13-14`** — změnit na `'env' => 'production', 'debug' => false`.
5. - [x] **Vytvořit `RateLimitMiddleware`** (Redis sliding window) a aplikovat dle `cfg.rate_limits` na `/forgot`, `/login`, mutations, `/setup`, `/clients/lookup-ares`.
6. - [x] **`api/src/Action/Bank/BankStatementAction.php:56`** — přidat: role guard, `getSize() <= 5MiB`, finfo MIME check (`text/plain`, `application/octet-stream`), ext check proti cfg.
7. - [x] **`api/src/Action/Auth/ForgotPasswordAction.php:74`** — před INSERT volat `UPDATE password_resets SET used_at=NOW() WHERE user_id=? AND used_at IS NULL`.
8. - [x] **TOTP brute-force counter** — Redis `totp:fail:{user_id}`, TTL 600 s, lockout 10 selhání.
9. - [x] **`api/src/Service/Mail/Mailer.php:175-179`** — pro `createTemplate` použít `SandboxExtension` s allow-listem.
10. - [x] **TOTP secret encryption** — `openssl_encrypt` AES-256-GCM s klíčem z `cfg.app.secret_encryption_key`, jednorázová migrace existujících recordů provedena a skript odstraněn.
11. - [x] **`api/public/index.php:51`** — generický error, detail jen do logu.
12. - [x] **`InvoiceRepository` / `ClientRepository` / `ProjectRepository`** — escape `%` a `_` v `LIKE` (`addcslashes($q, '%_\\')`).
13. - [x] **`cfg.app.pepper` deploy guard** — pokud `env=production` a `pepper === ''`, refuse boot.

---

# Follow-up audit (2026-05-05) — features přidané po multi-supplier auditu

> Audit nových funkcí v1.4–v1.9.1 + post-v1.9.1 (PDF history, invoice import,
> exchange rate fetch, public approval flow, bulk reissue, final from proforma).
> Cíl: chytit nové vstupní body (XML upload, public endpoints, file downloads).

## P1 — Vysoká

### FA-P1-1 — Invoice import: chybí XXE / billion-laughs hardening v XML parserech  ✅ *(fixed)*
- **Soubory:** `api/src/Service/Import/IsdocParser.php`, `PohodaXmlParser.php`
- **Problém:** `$dom->loadXML($xml)` byl volán bez `LIBXML_NONET` a bez kontroly DOCTYPE.
  V PHP 8 / libxml ≥ 2.9 jsou external entities default-off, ale **internal entity
  expansion (billion-laughs)** je pořád možná. `<!DOCTYPE>` mohlo způsobit DoS přes
  rekurzivní entity expansion.
- **Útok:** Authenticated admin/accountant uploadne 1 KB XML s nested entities
  rozbalitelnými na GB → memory exhaustion / OOM.
- **Fix:** Pre-parse regex `<!DOCTYPE` reject + `LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING`.
  Pokrytí PHPUnit testy (`IsdocParserTest::testRejectsBillionLaughsViaDoctype`,
  `PohodaXmlParserTest::testRejectsDoctype`).

### FA-P1-2 — Invoice import: chybí limity proti zip-bomb a velkým souborům  ✅ *(fixed)*
- **Soubory:** `api/src/Service/Import/InvoiceImportService.php`, `api/src/Action/Admin/ImportAction.php`
- **Problém:** `unzip()` ani `collectFiles()` nelimitovaly počet entries, total uncompressed
  size, ani per-file size. Authenticated uživatel mohl uploadnout zip-bomb (1 KB → GB)
  nebo 1 GB XML → OOM.
- **Fix v `InvoiceImportService::unzip()`:** `MAX_ZIP_ENTRIES=500`,
  `MAX_TOTAL_UNCOMPRESSED_BYTES=50 MiB`, `MAX_SINGLE_ENTRY_BYTES=10 MiB` (z `statIndex`
  čteme `size` PŘED extrakcí — žádný getFromIndex bombu nerozbalí).
  Také odmítáme entry names s `..`, absolutní cestou nebo Win drive prefixem
  (defense-in-depth, i když jen čteme do paměti).
- **Fix v `ImportAction::collectFiles()`:** `MAX_FILES=50`, `MAX_PER_FILE=20 MiB`,
  `MAX_TOTAL_UPLOAD=50 MiB`, vrací `413 upload_too_large` při překročení.

## P2 — Střední

### FA-P2-1 — Public approval decide leakuje interní error message  ✅ *(fixed)*
- **Soubor:** `api/src/Action/Approval/PublicApprovalDecideAction.php:114-124`
- **Problém:** Když `AutoIssueAndSendService::run()` selhal, response obsahovala
  `'auto_send_error' => $e->getMessage()`. Veřejný (bez auth) endpoint mohl odhalit
  DB error, mailer error, filesystem cestu, SMTP credential leak (rare ale possible).
- **Fix:** Detail jen do `activity_log` (`invoice.approval_auto_send_failed`),
  klientovi vrátit jen generický „Faktura bude obratem zaslána".

### FA-P2-2 — Header injection v `Content-Disposition` archive download  ✅ *(fixed, defense-in-depth)*
- **Soubor:** `api/src/Action/Invoice/DownloadArchivedPdfAction.php:54`
- **Problém:** `Content-Disposition: attachment; filename="{$filename}"` — `$filename`
  pochází z `basename(path)` z DB. Ačkoliv filename generuje server (formát
  `Ymd-His-{sha8}-{orig}`), defense-in-depth říká escape CR/LF/" v případě, že by se
  schema někdy změnilo. Header splitting → set-cookie injection.
- **Fix:** `preg_replace('/[\r\n"\\\\]/', '_', $filename)` před vložením do hlavičky.

### FA-P2-3 — Bulk reissue bez limit počtu invoices  ✅ *(fixed)*
- **Soubor:** `api/src/Action/Invoice/BulkReissueAction.php`
- **Problém:** Body `invoice_ids` array bez velikostního limitu. 100k IDs → 100k
  individual `find()` queries + 100k inserts.
- **Fix:** Hard limit 200 IDs per call (`422 too_many`).

## P3 — Nízká / bez fixu

- **FA-P3-1** *(no fix needed)* — `CnbExchangeRateClient`: URL je hardcoded, pouze
  date param je user-controlled (pochází z `issue_date` faktury, validovaný DATE typ
  v DB). Timeout 5 s, status check 200/404. Žádný SSRF / nekontrolovaný response size.
  Parser je čistý helper, robustní na malformed input.
- **FA-P3-2** *(no fix needed)* — `PdfArchiveService::pathFor()`: filename pochází
  z DB, kde byl generován serverem přes `basename($sourcePath)` po stripu `.new`
  suffixu — nelze do něj injectnout `..`. Path concatenation safe.
- **FA-P3-3** *(no fix needed)* — `PublicApprovalGetAction`: vrací jen omezenou
  whitelist polí faktury (varsymbol, currency, totals, klient.company_name,
  project.name) — žádné citlivé údaje. Token formát pre-validovaný.
- **FA-P3-4** *(no fix needed)* — `FinalFromProformaCreator`: idempotentní lookup
  přes `parent_invoice_id` před INSERT, transaction-safe (detekce
  `inTransaction()`), advance ≥ 0 validation. Caller ověřuje supplier ownership.

## Implementace fixů (2026-05-05) — souhrn

- [x] **FA-P1-1** — XXE/billion-laughs reject v `IsdocParser` + `PohodaXmlParser`.
- [x] **FA-P1-2** — Zip-bomb / file-size limity v `InvoiceImportService::unzip()`
  + `ImportAction::collectFiles()`.
- [x] **FA-P2-1** — `PublicApprovalDecideAction` neleakuje internal error.
- [x] **FA-P2-2** — Header escape v `DownloadArchivedPdfAction`.
- [x] **FA-P2-3** — Bulk reissue hard limit 200 IDs.

## Nové PHPUnit testy

- `IsdocParserTest` — 8 testů (happy path, DOCTYPE reject, billion-laughs,
  proforma/credit_note/reverse_charge, malformed XML).
- `PohodaXmlParserTest` — 7 testů (happy path, type mapping, DOCTYPE reject,
  malformed XML, foreign currency).

**Test:** 147 PHPUnit testů (290 assertions), všechny zelené (+15 nových).

---

# Třetí externí audit (2026-05-13) — security report @andrejtomci

Externí code review nahlášené uživatelem **[@andrejtomci](https://github.com/andrejtomci)**
proti `v3.3.1-2-gfaeeacf`. Pack `c:/tmp/security/` obsahoval 4 reports
+ PoC artefakty (sanitizované, reprodukovatelné na lokálním Docker stacku).
Všechny 4 nálezy ověřené jako reálné v `v3.5.0` a opravené v `v3.5.1`.

## P0 — Kritické

### TA-P0-1 — Cross-tenant bank-transaction tamper + forensic blindness  ✅ *(fixed v3.5.1)*
- **CVSS 3.1:** 8.1 High — `AV:N/AC:L/PR:L/UI:N/S:C/C:L/I:H/A:L`
- **CWE:** 639 (BOLA) + 285 (Improper Authorization) + 778 (Insufficient Logging)
- **Soubory:** `api/src/Action/Bank/BankStatementAction.php`
  - `manualMatch:248` — jen invoice ownership check; `txId` z URL bez supplier scope
  - `ignore:429` — žádný ownership check, žádný `activity_log` write
  - `unmatch:322` — scope check projde přes attacker-linked invoice
- **Útok:** `accountant` z tenanta S1 přes 4 curl příkazy spáruje cizí
  bank-tx S2 se svou fakturou (→ S2 invoice se označí jako paid, forged tax
  doc z proformy), nebo tiše `ignore` cizí incoming customer payment
  (bez audit logu → S2 admin nezjistí, kdo to udělal).
- **Fix:** Nový privátní helper `txBelongsToCurrentSupplier()` v
  `BankStatementAction` — JOIN `bank_transactions → bank_statements → currencies`
  ověří, že tx patří current supplier (přes účet supplier-a, mirror logiky
  z `list()` a `detail()`). Volaný hned na začátku `manualMatch`, `unmatch`,
  `ignore`. Plus `ignore` teď zapisuje `bank.tx_ignore` action do
  `activity_log` s `previous_status` + `previous_invoice_id` (forensic trace).

### TA-P0-2 — Arbitrary local file read via `logo_path` mass-assignment  ✅ *(fixed v3.5.1)*
- **CVSS 3.1:** 6.2 High — `AV:N/AC:L/PR:H/UI:N/S:C/C:H/I:N/A:N`
- **CWE:** 915 (Mass Assignment) + 22 (Path Traversal) + 285 + 538 (Information Exposure)
- **Soubory:**
  - `api/src/Action/Settings/SettingsAction.php:192` — mass-assign whitelist obsahuje `logo_path`, `signature_path`
  - `api/src/Action/Settings/EmailBrandingAction.php:139-197` — `preview` čte `file_get_contents($supplier['logo_path'])`, bez admin role guardu, vrací bytes base64 v inline `<img>` data: URI
  - `api/src/Service/Mail/Mailer.php:117-126` — parity sink přes `embedFromPath` (off-box exfil přes MIME attachment)
- **Útok (chain):** malicious / compromised admin podstrčí
  `logo_path = "cfg.php"` přes mass-assign na PUT `/api/settings/supplier`.
  Pak **libovolný auth user** (i `readonly`!) zavolá `GET /api/settings/email-branding/preview`
  a vyparsne bytes `cfg.php` z `<img src="data:image/png;base64,...">` HTML
  odpovědi. Leakne `app.pepper`, `secret_encryption_key` (defeat 2FA system-wide),
  `db.password` (direct MariaDB connection bypasses tenant scope), SMTP creds.
- **Fix:**
  - `logo_path` + `signature_path` **odebrány z `$allowed` mass-assign whitelistu**.
    Logo se mění jen přes dedikovaný `EmailBrandingAction::uploadLogo` (multipart
    upload → `SupplierLogoConverter` → fixed path `storage/supplier-logos/sup-{ID}.png`).
  - `EmailBrandingAction::preview` má teď `if (!$this->isAdmin($request))` guard.
  - Nový helper `\MyInvoice\Service\Mail\SafeLogoPath::resolve()` validuje cestu
    proti pattern `storage/supplier-logos/sup-{ID}.{png|jpg|jpeg|svg|webp}` s
    `realpath()` rejection mimo `storage/supplier-logos/`, null-byte + `..`
    traversal rejection, foreign-supplier-id rejection. Použito ve 4 sinks:
    - `Mailer::sendTemplate` (embedFromPath)
    - `Mailer::addLogoDisplaySize` (getimagesize)
    - `EmailBrandingAction::preview` (file_get_contents)
    - `InvoicePdfRenderer::resolveLogoPath` (PDF render)

## P1 — Vysoká

### TA-P1-1 — HTML injection v outbound emailu přes `{{ intro|raw }}` + neomezený varsymbol  ✅ *(fixed v3.5.1)*
- **CVSS 3.1:** 5.4 Medium — `AV:N/AC:L/PR:L/UI:R/S:C/C:L/I:L/A:N`
- **CWE:** 20 (Improper Input Validation) + 79 (HTML Injection v emailu; ne stored XSS — JS se v moderních mail klientech nevykonává)
- **Soubory:**
  - `api/src/Service/Import/InvoiceImportService.php:163` — `processOne()` neaplikuje `InvoiceValidation::invoice()` ani charset whitelist na varsymbol z ISDOC/Pohoda XML
  - `api/src/Service/Mail/InvoiceEmailVarsBuilder.php:72-78` — `intro` skládán s embedovaným `<strong>č. {VS}</strong>`
  - `api/templates/email/invoice_send.{cs,en}.html.twig:8` — `{{ intro|raw }}` bypassuje Twig autoescape
  - **Parity sinks (DiD):** PDF cache filenamy, ZIP entry names, CSV cells
- **Útok:** `accountant` z libovolného tenanta uploadne ISDOC s
  `<inv:symVar><a href=//attacker.tld></inv:symVar>` (16 znaků = fitne do
  `VARCHAR(20)`). Po `POST /api/invoices/{id}/send` dostane klient
  DKIM-podepsaný email z legitimního MTA supplier-a, kde unclosed `<a>`
  udělá phishing link z celého zbytku těla emailu. Trust-laundering přes
  cizí DKIM authority.
- **Fix:**
  - **Gateway**: `InvoiceImportService::processOne()` validuje varsymbol
    proti `^[A-Za-z0-9_-]{1,20}$` — neplatný varsymbol → import řádek
    skončí `failed`.
  - **Sink**: šablony `invoice_send.{cs,en}.html.twig:8` přepsané z
    `{{ intro|raw }}` na `{{ intro_prefix }} <strong>č. {{ invoice.varsymbol }}</strong>.`
    — `intro_prefix` je plain text z PHP, `<strong>` static v šabloně,
    `varsymbol` projde Twig autoescape (HTML entities). EN šablona používá
    `No.` místo `č.` (preexistující i18n bug, vyřešený u příležitosti fixu).
  - **DiD na parity sinks**: `InvoicePdfRenderer::cachePath` +
    `WorkReportPdfRenderer` sanitizují varsymbol pro filesystem
    (`preg_replace('/[^A-Za-z0-9_-]/', '_', $vs)`); `ExportAction` +
    `InvoicesZipAction` totéž pro ZIP entry names; `ExportCsvAction`
    escape OWASP CSV formula injection (prefix `'` u buněk začínajících
    `=/+/-/@/TAB/CR`).

## P2 — Střední

### TA-P2-1 — WorkReport cross-supplier `project_id` (parity miss MS-P1-1)  ✅ *(fixed v3.5.1)*
- **CVSS 3.1:** 4.3 Medium — `AV:N/AC:L/PR:L/UI:N/S:U/C:N/I:L/A:N`
- **CWE:** 639 (BOLA) + 285 + 915
- **Soubor:** `api/src/Action/WorkReport/SaveWorkReportAction.php:36, 50-53, 81`
- **Problém:** `SupplierGuard::owns($request, $invoice)` ověří jen parent
  invoice; `project_id` z body se předává na `WorkReportRepository::save()`
  bez validace, že project patří ke stejnému supplier. Parity-miss audit
  fixu **MS-P1-1** (Invoice→Project edge), který tuhle samou anti-pattern
  zavřel v `CreateInvoiceAction`/`UpdateInvoiceAction` přes
  `InvoiceDefaults::resolve()`.
- **Útok (latentní):** `accountant` z S1 uloží `work_reports` řádek s
  `project_id` ze S2 (FK constraint chybí v `wr.project_id` — je jen
  `bigint unsigned NULL, MUL`). Dnes silent (žádný API endpoint nepivotuje
  na `wr.project_id`), ale latentní pro budoucí aggregátor (project revenue,
  hours-per-project, budget burn-down) → mis-attribuce hodin přes tenant boundary.
- **Fix:** Inject `ProjectRepository` do `SaveWorkReportAction`. Po
  invoice scope checku přidaná validace:
  ```php
  if ($projectId !== null) {
      $project = $this->projects->find($projectId);
      if (!SupplierGuard::owns($request, $project)) {
          return Json::error($response, 'validation_failed',
              'Zakázka neexistuje nebo nepatří k aktuálnímu dodavateli.', 400);
      }
      if ((int) $project['client_id'] !== (int) $invoice['client_id']) {
          return Json::error($response, 'validation_failed',
              'Zakázka nepatří k odběrateli této faktury.', 400);
      }
  }
  ```

## Implementace fixů (2026-05-13) — souhrn

- [x] **TA-P0-1** — Bank tx supplier scope check + audit log na ignore
- [x] **TA-P0-2** — `logo_path` mass-assign drop + SafeLogoPath helper + admin guard
- [x] **TA-P1-1** — varsymbol charset whitelist + Twig autoescape pro `intro` + DiD parity sinks
- [x] **TA-P2-1** — WorkReport project supplier scope check

## Nové PHPUnit testy

- `SafeLogoPathTest` — 8 unit testů (path traversal rejection, null byte,
  wrong prefix, wrong supplier_id, wrong extension, missing file)
- `SecurityFixesTest` — 8 integration testů (regression guards — kód
  inspection že fixy zůstávají uzamknuté: bank tx scope check ve všech
  3 metodách, audit log na ignore, no `intro|raw` v šablonách, varsymbol
  regex v ImportService, no `logo_path` v mass-assign, ProjectRepository
  v SaveWorkReportAction, SafeLogoPath v PDF rendereru)

**Test:** 241 PHPUnit testů (~520 assertions), všechny zelené (+16 nových).
Plus regression guard struktura — pokud někdo v budoucnu znovu otevře jeden
z těchto sinkú, testy ho chytí v CI.

---

# Čtvrtý interní audit (2026-06-12) — externí zneužitelnost dat

> **Zadání majitele:** ověřit nemožnost zneužít data **zvenčí** a dostat se k fakturám,
> dokumentům a klientům. Připomínka: `supplier_id` viditelné napříč přihlášenými uživateli
> je **by design** (jedna instalace, sdílení uživatelé, více dodavatelských firem) — to se
> neřeší. Audit cílí na (a) neautentizovanou plochu, (b) přístup k souborům zvenčí,
> (c) IDOR/path-traversal, (d) injekce/RCE/SSRF, (e) únik tajemství, (f) eskalaci práv
> nad rámec role.
>
> **Metoda:** review modelem **Fable 5** (`claude-fable-5`), 6 paralelních auditních
> průchodů (dokumenty, neautentizovaná plocha, PAT+RBAC, přijaté faktury+soubory,
> SQLi+mass-assignment, integrace+tajemství+RCE) + manuální ověření klíčových nálezů na
> úrovni `file:line`. Pokrývá funkce přidané od minulého auditu (v3.5.1 → v4.24): sekce
> Dokumenty, přijaté faktury, AI extrakce, QR platby, IMAP avíza, PAT public API, CRM.
>
> **Celkový verdikt:** **žádný P0**. Aplikace je z pohledu externí zneužitelnosti ve
> velmi dobrém stavu — veškerá data i soubory jsou za `AuthMiddleware`, IIS blokuje přímý
> přístup ke `storage/`, repozitáře jsou důsledně parametrizované, žádné `eval`/SSRF přes
> uživatelské URL, tajemství šifrovaná at-rest. Nálezy se týkají hlavně **rozsahu PAT
> tokenů (P1)**, neautentizovaného schvalovacího toku (P2) a křehkosti RBAC (P2 latentní).

## P1 — Vysoká

### NX-P1-1 — Personal Access Token (PAT) = plná role tvůrce na celém interním API, mimo CSRF i 2FA  ✅ *(opraveno 2026-06-12)*
- **Soubory:** `api/src/Action/Auth/Tokens/CreateTokenAction.php:58` (default scope `read_write`),
  `api/src/Middleware/ApiScopeMiddleware.php:42-54` (řeší jen read/read_write, **ne cestu**),
  `api/src/Middleware/ApiVersionRewriteMiddleware.php:31-34` (`/api/v1/*` → `/api/*`, stejný handler),
  `api/src/Middleware/RoleMiddleware.php:119-131` (admin token = „smí všechno", token nemá vlastní strop role),
  `api/src/Service/Auth/ApiTokenService.php:98-108` (token dědí živou `users.role`),
  `api/src/Middleware/CsrfMiddleware.php` (bearer přeskakuje CSRF), `RequireTotpMiddleware.php` (bearer přeskakuje vynucenou 2FA).
- **Problém:** Veřejné API je dokumentované (OpenAPI) jako read-only kurátorovaný subset
  `/api/v1/*`. Ve skutečnosti je `/api/v1/` jen kosmetický alias — `ApiVersionRewrite` přepíše
  cestu na `/api/*` a token může volat i `/api/*` přímo. Žádný middleware neomezuje, **které
  cesty** smí bearer token zasáhnout. Token nese **plnou živou roli uživatele**, který ho
  vytvořil. Výchozí scope je navíc `read_write`.
- **Útok / dopad:** Únik PAT (z CI proměnné, logu integrace, .env na klientovi, screenshotu)
  = headless plný přístup k účtu. `read` token admina přečte `/api/admin/users`,
  `/api/admin/activity-log`, všechna data všech dodavatelů; `read_write` token (default!) admina
  zmůže vše co admin v UI — `/api/admin/users` (založení admina), `/api/admin/email-templates`
  (Twig úprava), `/api/admin/update/trigger`, `/api/settings/*`, smazání dat — **bez CSRF tokenu
  a bez 2FA**. Uživatel přitom čeká „omezený API klíč na čtení". Toto je nejvýznamnější
  externí vektor: jeden uniklý řetězec ≠ jen read-only veřejná data, ale celý účet.
- **Návrh opravy:**
  1. **Path allowlist pro bearer** — nový check (v `ApiScopeMiddleware` nebo `RoleMiddleware`),
     který bearer requesty pustí jen na dokumentovaný veřejný subset (`/api/v1/*` resp. mapu
     povolených `/api/*` cest); `/api/admin/*`, `/api/settings/*`, `/api/auth/tokens` → 403.
  2. **Strop role tokenu** — token nikdy nedostane admin-tier endpointy, i když ho vytvořil
     admin (nebo zavést explicitní per-token scope nad rámec read/read_write).
  3. **Default scope `read`** v `CreateTokenAction` (opt-in k zápisu).
  4. Zvážit odmítnutí vydání tokenu uživateli bez TOTP, když je `auth.require_totp` zapnuté
     (token jinak obchází 2FA bránu).
- **Pozn.:** Hygiena tokenu jinak solidní — 256bit `random_bytes`, ukládá se jen `sha256`,
  plaintext jednou, nikdy nelogován; expiry/revoke/`is_active` vynuceny v SQL; step-up TOTP
  při vytváření; supplier-bound token nemůže přeskočit do jiné firmy. Problém je výhradně
  **rozsah autority** vydaného tokenu.

## P2 — Střední

### NX-P2-1 — RBAC `GET *` blanket: čtecí autorizace zcela delegovaná na Action vrstvu  ✅ *(opraveno 2026-06-12)*
- **Soubor:** `api/src/Middleware/RoleMiddleware.php:87` (`READONLY_RULES = ['GET *', …]`).
- **Problém:** První readonly pravidlo `GET *` znamená, že **každý GET projde RoleMiddlewarem
  pro všechny role** (readonly i accountant). Middleware tedy na čtení neposkytuje žádnou
  ochranu — bezpečnost stojí výhradně na vlastním `guard()`/`isAdmin()` v každém Action.
- **Stav (ověřeno):** Všechny dnešní admin/citlivé GET endpointy mají vlastní kontrolu role
  a vrací 403 pro readonly — ověřeno endpoint po endpointu: `UserAdminAction::list`,
  `ListActivityLogAction`, `ListSentEmailsAction`, `SmtpLogAnalysisAction`,
  `EmailTemplateAction::list/get`, `CronJobsAction`, `*CredentialsAction::status`,
  `UpdateAction::status`, `ApprovalListAction`, `BankEmailNoticeAction::overview`.
  **Stahování podpisového certifikátu** (`SigningProfilesAction::credentialCertificate`)
  vrací **jen metadata** (fingerprint/subject/platnost), nikdy P12/klíč, a readonly dostane 404.
- **Riziko:** Není dnes exploitovatelné, ale je to **defense-in-depth díra** — jakýkoli budoucí
  admin GET, který zapomene vlastní check, je tiše vystaven readonly uživateli (a dle NX-P1-1
  i read-scope tokenu readonly uživatele).
- **Návrh:** Nahradit `GET *` explicitním allowlistem GET cest (data + exporty) a nechat
  `/api/admin/*` a citlivé `/api/settings/*` propadnout do admin-only fallbacku → middleware
  i Action enforce-ují v hloubce.

### NX-P2-2 — Veřejné schvalovací endpointy: bez rate-limitu + neatomické rozhodnutí (TOCTOU)  ✅ *(opraveno 2026-06-12)*
- **Soubory:** `api/src/Middleware/RateLimitMiddleware.php:107-167` (žádné pravidlo pro
  `/api/public/approval/*`; obecné per-user buckety vyžadují `userId>0`, což je pro anonymní
  cestu nikdy), `api/src/Action/Approval/PublicApprovalDecideAction.php:51-115`,
  `api/src/Repository/InvoiceRepository.php:1220-1234` (`setApprovalDecision` dělá bezpodmínečný UPDATE).
- **Problém A (rate-limit):** GET i `decide` na `/api/public/approval/{token}` jsou bez
  jakéhokoli throttle. Token je `random_bytes(24)` = 96 bit → brute-force **neproveditelný**
  (proto ne výš), ale zůstává neomezený anonymní DoS (každý GET = 2 indexované dotazy +
  načtení work-reportu) a obejití zamýšleného CAPTCHA limitu.
- **Problém B (TOCTOU):** `decide` je neatomické read-then-write — kontrola
  `approval_status === 'requested'` a následný UPDATE jsou oddělené, bez transakce/zámku a
  `setApprovalDecision` updatuje bezpodmínečně. Dva souběžné `approve` se stejným tokenem oba
  projdou → faktura se může **vystavit a odeslat e-mailem dvakrát** (přes `AutoIssueAndSendService`).
- **Návrh:** (A) IP-bucket pravidlo pro `/api/public/approval/` v `ruleFor()` (např. 30/min/IP).
  (B) Atomický UPDATE: `… SET approval_status=?, approval_token=NULL WHERE id=? AND approval_status='requested'`
  a pokračovat jen při `rowCount()===1` (nebo `SELECT … FOR UPDATE` v transakci).

### NX-P2-3 — IMAP avíza: admin může nasměrovat server na libovolný host:port (SSRF primitiv)  ⚠️ *(P3 pro single-tenant)*
- **Soubory:** `api/src/Service/Bank/EmailNotice/WebklexImapMailboxClient.php:120-132`
  (host/port/`validate_cert` doslova z nastavení, bez allowlistu),
  `api/src/Action/Bank/BankEmailNoticeAction.php:130-147,291-304` (`browseImapFolders` merguje
  host přímo z těla requestu — bez uložení).
- **Problém:** `POST /api/settings/bank-email-notices/imap/test`, `…/imap-accounts/folders`,
  `…/{id}/test`, `…/scan` otevřou TCP spojení na zadaný host. Admin může poslat
  `{host:"169.254.169.254",port:80}` → interní port-scan / connection oracle; `validate_cert=false`
  umožní MITM odchyt IMAP hesla. `test()` vrací i raw text výjimky klientovi (info leak).
- **Stav:** **Pouze admin** (RoleMiddleware admin fallback + in-action `role==='admin'`).
  Pro self-hosted single-tenant (admin = provozovatel infry) je to **P3**. **P2 jen** pokud
  by model hrozeb zahrnoval nedůvěryhodné/sdílené adminy (SaaS) — role je globální, ne per-tenant.
- **Návrh:** volitelný egress allowlist IMAP hostů; nevracet raw text výjimky; zvážit vynucené
  `validate_cert=true`.

## P3 — Nízká / defense-in-depth

- **NX-P3-1** — `DocumentRepository::search` (**`api/src/Repository/DocumentRepository.php:159`**)
  neescapuje LIKE wildcardy (`%`/`_`). Není SQLi (hodnota bindovaná), ale `q=%%%…` vynutí full
  scan `content_text` (slow-query DoS) a nekonzistentní match. Všude jinde se používá
  `addcslashes($q,'%_\\')`. Fix: `'%' . addcslashes($q,'%_\\') . '%'` (MATCH AGAINST nechat raw).
- **NX-P3-2** — CAPTCHA je defaultně no-op (`captcha.provider='none'`) a i nakonfigurovaná má
  `fail_open=true` (**`api/src/Service/Captcha/TurnstileVerifier.php:28-44`**). Pro `decide`
  (NX-P2-2) je CAPTCHA jediný throttle a je vypnutá. Návrh: doporučit v produkci
  `captcha.provider=turnstile`, pro approval zvážit `fail_open=false`.
- **NX-P3-3** — Bank upload obejití velikostního limitu při `getSize()===null`
  (**`api/src/Action/Bank/BankStatementAction.php:94` a `:377`**): `($file->getSize() ?? 0) > $max`
  s null projde a celý soubor se načte do paměti. `UploadPurchaseInvoicePdfAction` to řeší
  fallbackem na stream-size — sjednotit. Reálně ohraničeno PHP `post_max_size`.
- **NX-P3-4** — `DownloadAttachmentAction` (**`:51-58`**) a `DownloadArchivedPdfAction` servírují
  bez per-response `X-Content-Type-Options: nosniff`/CSP (na rozdíl od `DocumentFileAction`,
  které přidává `Content-Security-Policy: default-src 'none'; sandbox`). Dnes kryté globálním
  nosniff v `web.config`/`.htaccess` + MIME whitelistem při uploadu. Návrh: přidat nosniff+CSP
  přímo na response (nezáviset na web-server vrstvě).
- **NX-P3-5** — `GET /api/settings/supplier`/`/api/suppliers` (**`SettingsAction.php:518-526`**)
  redaguje sloupce **konvencí přípony `_enc`** (+ explicitně `idoklad_access_token`). Tajemství
  neuniká, ale readonly vidí semi-citlivé identifikátory (`fakturoid_client_id/email/slug`,
  `idoklad_client_id`) a **budoucí tajný sloupec bez přípony `_enc` by tiše unikl**. Návrh:
  explicitní allowlist vracených polí místo `SELECT *` + denylist.
- **NX-P3-6** — `/api/health` (**`HealthAction.php:33-40`**) anonymně leakuje dostupnost DB/Redis
  + warning o klíči šifrování. Drobná rekognoskace; zvážit gate za auth nebo zúžení payloadu.
- **NX-P3-7** — `CrpDphClient::parseResponse` (**`:130-137`**) volá `loadXML` bez `LIBXML_NONET`.
  Na PHP 8.5 jsou externí entity default off a host je pinned (vládní registr), takže riziko
  zanedbatelné — přidat `LIBXML_NONET` defenzivně (parita s ISDOC/Pohoda parsery).
- **NX-P3-8** — `SecretEncryption::decrypt` (**`:45-48`**) vrací neprefixované hodnoty jako legacy
  plaintext (zpětná kompatibilita) — exploitovatelné jen po kompromitaci DB; legacy řádky nemají
  integritu. Po doběhnutí migrace zvážit tvrdé odmítnutí neprefixovaných hodnot.
- **NX-P3-9** — `DocumentsAction::addLink` (**`:209-224`**) neověří, že cílová entita (`entity_id`)
  patří aktuálnímu dodavateli (dokument ano). Read-back je bezpečný (`labelFor` scope-uje a vrací
  `#id`), takže jen vznikne dangling link. Návrh: validovat vlastnictví `entity_id` před `attach()`.
- **NX-P3-10** — `DeletePurchaseInvoicePdfAction` (**`:65-69`**) počítá „soubor ještě používán
  jinde" globálně bez `supplier_id` filtru → při identickém PDF u dvou dodavatelů zůstane vlastní
  fyzický soubor jako orphan (žádné cizí smazání — `@unlink` je realpath-confined na vlastní dir).
  Hygiena: scope-ovat count na `AND supplier_id = ?`.
- **NX-P3-11** — Amplifikace zdrojů u ZIP importu (**`ZipImporter` `MAX_ENTRIES=5000`**) — 5000
  drobných obrázků = 5000 thumbnail/text-extraction průchodů; a `UploadDocumentAction` nemá
  agregátní cap na součet bajtů jedné multipart dávky (jen per-file + count 2000). Ohraničeno
  PHP ini; návrh: snížit `MAX_ENTRIES`/agregátní rozpočet bajtů, throttle thumbnailů.

## By-design / informational (bez opravy)

- **SupplierScopeMiddleware** (`:74-81`) přijme jakýkoli existující `supplier_id`
  z `X-Supplier-Id`/`?supplier_id=` bez kontroly členství uživatele → každý přihlášený
  (i **readonly**) je čtenářem všech firem. **Potvrzeno majitelem jako by design.** Upozornění:
  od zavedení sekce **Dokumenty** to znamená, že readonly vidí i libovolné nahrané soubory
  (vč. ZFO z datové schránky) všech firem — vědomé rozhodnutí. PAT s `supplier_id=null` (default)
  také pivotuje mezi firmami; bound token ne.
- ~~**RoleMiddleware ACCOUNTANT_RULES** nemá záznam pro `/api/purchase-invoices` → accountant
  nemůže mutovat přijaté faktury.~~ ✅ **Opraveno 2026-06-12** — přidáno
  `'* #^/api/purchase-invoices(/|$)#'` do `ACCOUNTANT_RULES` (účetní má teď plnou CRUD na
  přijatých fakturách). Selhávalo uzavřeně, takže šlo o funkční mezeru, ne bezpečnostní díru.
- **Cron runner** (`RunCronJobAction`) a **`/api/admin/update/trigger`** (`UpdateAction`) — žádné
  RCE: cron jméno proti statickému allowlistu `CronCatalog` + `escapeshellarg`; update jen zapíše
  JSON flag (`storage/upgrade-requested.json`), `target_version` se nikdy nedostane do shellu.

## Pozitivní nálezy (co drží)

- **Žádné neautentizované čtení dat** — všechny `/api/documents*`, `/api/invoices*`,
  `/api/clients*`, `/api/purchase-invoices*` jsou mimo `PUBLIC_PATHS` → 401 bez session/bearer.
- **IIS `web.config`** blokuje přímý přístup ke `storage/`, `private/`, `db/`, `log/`, `source/`,
  `tools/`, `api/src`, `api/vendor`, plus `.env/.sql/.pem/.log/cfg.php/*.md`. Nahrané soubory
  tečou výhradně přes API s auth + ownership scope, nikdy přímým URL.
- **Path-traversal uzavřen** — Dokumenty jsou content-addressed (sha256 jako jméno na disku,
  nikdy user-input), `assertInsideBase()` + case-insensitive compare na Windows; přijaté PDF/
  výpisy přes `realpath()` prefix check; přílohy/výpisy jako DB BLOB. Zip-slip i zip-bomb
  ošetřeny (entry count, total uncompressed, per-entry, ratio guard, streamed abort), XXE v ZFO
  i import parserech odmítá `<!DOCTYPE` + `LIBXML_NONET`.
- **IDOR** — všechny read/download/mutate dle `{id}` scope-ují `WHERE supplier_id=?` /
  `SupplierGuard::owns`; bank-tx tamper fix (TA-P0-1) intaktní ve všech metodách včetně
  `createPurchaseInvoice`.
- **SQLi** — napříč repozitáři důsledně prepared statements (`ATTR_EMULATE_PREPARES=false`),
  žádný `PDO::quote` v dynamickém SQL; ORDER BY/sloupce whitelistované přes `match()`, IN(…)
  přes placeholdery, LIMIT/OFFSET int-cast nebo `bindValue(PARAM_INT)`. **Žádná injekce.**
- **Mass-assignment** — supplier whitelist stále bez `logo_path`/`signature_path`; žádný endpoint
  nedovolí z těla nastavit `supplier_id`/`user_id`/`role`/`*_enc`/`paid_total`/`status`.
- **Tajemství at-rest** — AES-256-GCM, čerstvý nonce per encrypt, auth tag, formát `enc:v1:…`;
  všechny integrační klíče (Anthropic/iDoklad/Fakturoid/IMAP/TOTP/podpisové passphrase) šifrované,
  v GET odpovědích jen maskovaný status, nikde nelogované. SMTP heslo jen z configu, ne v DB.
- **Žádný SSRF přes uživatelské URL** — base URL všech integrací (Anthropic/iDoklad/Fakturoid/
  ČNB/ARES/CRPDPH/GitHub) jsou hardcoded konstanty; user-řízené jsou jen bezpečné parametry
  (model v body, urlencode-ovaný slug, digit-only IČO/DIČ, formátované datum).
- **Reset hesla / session** — token 256bit, ukládá se jen sha256, single-use + 60 min TTL,
  invalidace starších + všech sessions; session token 64hex, `hash_equals`, `__Host-` cookie.

## Doporučené pořadí oprav

| Priorita | Akce | Stav |
|---|---|---|
| **P1** | NX-P1-1 — path/role scope pro PAT (+ default scope `read`) | ✅ hotovo |
| **P2** | NX-P2-2 — atomický approval `decide` + rate-limit `/api/public/approval/*` | ✅ hotovo |
| **P2** | NX-P2-1 — zúžit RBAC `GET *` na explicitní allowlist | ✅ hotovo |
| **P2** | (bonus) accountant CRUD na `/api/purchase-invoices` (funkční mezera) | ✅ hotovo |
| P3 | NX-P3-1 — LIKE escape v `DocumentRepository::search` | ✅ hotovo |
| P3 | NX-P3-3/4/6 — nosniff/CSP na download, bank size guard, supplier redakce | ✅ hotovo |
| P3 | NX-P3-5/7/8 — health gate, `LIBXML_NONET` CRPDPH | ✅ hotovo |
| P3 | NX-P3-9/10 — addLink ownership, purchase PDF dedup scope | ✅ hotovo |
| — | NX-P3-11 — ZIP entry-count amplifikace | ⏸ ponecháno (ohraničeno PHP ini, nízká priorita) |
| — | NX-P2-3 — IMAP egress allowlist | ⏸ jen pokud SaaS/nedůvěryhodní admini |

## Implementace fixů (2026-06-12) — souhrn

Review i implementace proběhly modelem **Fable 5**. Žádné API routy ani dokumentované
schéma se neměnily → `openapi.yaml` netknuto. Testy: **Unit 863 zelených** (4 skip = DB/
integration), **Architecture 6 zelených**, lint všech 16 změněných souborů čistý.

- [x] **NX-P1-1** — `ApiScopeMiddleware`: nový `BEARER_ALLOWED` path allowlist (zrcadlí
  veřejný subset `openapi.yaml`); bearer mimo subset → `403 token_endpoint_forbidden`
  (vyhodnoceno PŘED scope, takže ani admin token se nedostane na `/api/admin/*`,
  `/api/auth/*` mimo `api-me`, ani na signing/email-branding/IMAP nastavení).
  `CreateTokenAction` default scope `read_write` → `read`. (Vydání tokenu bez TOTP při
  `auth.require_totp` už blokuje `RequireTotpMiddleware` — netřeba duplikovat.)
  Test: `ApiScopeMiddlewareTest` (9 testů).
- [x] **NX-P2-1 + bonus** — `RoleMiddleware`: blanket `'GET *'` nahrazeno explicitním
  allowlistem datových/exportních skupin; `/api/admin/*` (mimo export carve-outy) a citlivá
  nastavení propadnou do admin-only fallbacku. Přidáno `/api/purchase-invoices` do
  `ACCOUNTANT_RULES` (+ čtení signing settings a import-job statusu pro účetního).
  Test: rozšířený `RoleMiddlewareTest` (+6 testů).
- [x] **NX-P2-2** — `InvoiceRepository::decideIfRequested()` (atomický UPDATE s
  `WHERE … AND approval_token = ? AND approval_status = 'requested'`, vrací rowCount===1);
  `PublicApprovalDecideAction` ho používá v obou větvích (jen vítěz závodu pokračuje →
  žádné dvojí vystavení/odeslání). `RateLimitMiddleware` nové pravidlo
  `approval_per_min_per_ip` (default 30/min/IP) pro `/api/public/approval/*` + klíč v
  `cfg.sample.php`.
- [x] **NX-P3-1** — `DocumentRepository::search`: `addcslashes($q,'%_\\')` v LIKE větvích.
- [x] **NX-P3-3** — `DownloadAttachmentAction` + `DownloadArchivedPdfAction`:
  `X-Content-Type-Options: nosniff` + `Content-Security-Policy: default-src 'none'; sandbox`.
- [x] **NX-P3-4** — `BankStatementAction` (upload GPC i PDF): `getSize()` null fallback na
  stream + backstop přes `strlen` po načtení.
- [x] **NX-P3-5** — `HealthAction`: diagnostické `warnings` (vč. secret-key warning) jen pro
  přihlášené; anonymní healthcheck dostane jen status/db/redis/time.
- [x] **NX-P3-6** — `SettingsAction` supplier redakce rozšířena o secret-ish názvy
  (`password`/`secret`/`api_key`/`access_token`/`passphrase`/`private_key`) nad rámec `*_enc`.
- [x] **NX-P3-7** — `CrpDphClient::parseResponse`: `loadXML(..., LIBXML_NONET | …)`.
- [x] **NX-P3-9** — `DocumentLinkRepository::entityBelongsToSupplier()` + volání v
  `DocumentsAction::addLink` (cizí/neexistující entita → 404, žádná dangling vazba).
- [x] **NX-P3-10** — `DeletePurchaseInvoicePdfAction`: dedup count scope-ovaný na `supplier_id`.

Regression guard: `tests/Unit/Security/SecurityHardening202606Test.php` (code-inspection,
13 testů) — uzamyká fixy v CI.

> Implementováno commitnutelně, ale **bez commitu** (čeká na souhlas). NX-P3-11 (ZIP
> amplifikace) a NX-P2-3 (IMAP egress allowlist) ponechány jako vědomé „won't fix teď"
> (ohraničené PHP ini / relevantní jen pro nedůvěryhodné adminy).
