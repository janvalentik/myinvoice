# Public REST API v1 — Implementační plán

Cílový stav: bearer tokeny (`mi_pat_…`) v hlavičce `Authorization`, scope `read` / `read_write`, bound na `supplier_id`, step-up TOTP při tvorbě, OpenAPI 3.1 spec ručně udržovaný v repu, Redoc UI na `/api/docs`, routes alias `/api/v1/*` paralelně s `/api/*`.

Issue: https://github.com/radekhulan/myinvoice/issues/19

## Rozhodnutí

| Otázka | Volba |
|---|---|
| Auth | Personal Access Token (PAT). OAuth2 ne. |
| Scopes | `read` / `read_write` (jedno pole `scope`). |
| Supplier scope | Token je při tvorbě bound na konkrétní `supplier_id` (NULL = všichni supplier-i usera). |
| Verzování | Alias `/api/v1/*` paralelně s `/api/*` (rewrite MW). SPA se nemění. |
| Step-up | Tvorba tokenu vyžaduje re-ověření TOTP, pokud má user TOTP zapnuté. |
| Dokumentace | Ručně psaný `api/openapi.yaml`, Redoc HTML page. |
| Webhooks | Out of scope. |
| OAuth2 | Out of scope. |
| Idempotency-Key | Odloženo na v1.1. |

## Fáze 1: Databáze + doménová logika

### 1.1 Migrace
**`db/migrations/0019_api_tokens.sql`** — tabulka `api_tokens`:

| sloupec | typ | popis |
|---|---|---|
| `id` | INT UNSIGNED PK | |
| `user_id` | INT UNSIGNED FK users.id ON DELETE CASCADE | |
| `supplier_id` | INT UNSIGNED FK suppliers.id ON DELETE CASCADE NULL | NULL = all user's suppliers |
| `name` | VARCHAR(100) | user-supplied label |
| `token_hash` | CHAR(64) UNIQUE | SHA-256 hex z plaintextu |
| `prefix` | VARCHAR(16) | prvních 12 znaků pro UI listing |
| `scope` | ENUM('read','read_write') | |
| `last_used_at` / `last_used_ip` | DATETIME / VARCHAR(45) NULL | telemetry |
| `expires_at` | DATETIME NULL | NULL = neexpiruje |
| `revoked_at` | DATETIME NULL | |
| `created_at` | DATETIME DEFAULT NOW() | |

Spustit přes `php api/bin/migrate.php`.

### 1.2 Service: `api/src/Service/Auth/ApiTokenService.php`

- `generate(userId, supplierId?, name, scope, expiresAt?)` → `['plaintext'=>'mi_pat_…', 'prefix'=>'mi_pat_abcd', 'id'=>42]`. `random_bytes(32)` → base64url, prefix `mi_pat_`. Plaintext **jen** v návratové hodnotě, v DB pouze SHA-256.
- `validate(plaintext)` → row JOIN users JOIN suppliers nebo null. Lookup přes `token_hash` index, respektuje `revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW())`.
- `touch(tokenId, ip)` — update `last_used_at` / `last_used_ip`. Throttle 5 min přes Redis, ať netluče DB na každý request.
- `list(userId)`, `revoke(tokenId, userId)`.

### 1.3 Wire do DI
`api/src/Bootstrap.php` — `ApiTokenService` jako factory.

## Fáze 2: Middleware

### 2.1 `AuthMiddleware` (úprava `api/src/Middleware/AuthMiddleware.php`)
Před session-cookie větví:
- Pokud `Authorization: Bearer mi_pat_…`, validuj přes `ApiTokenService`. Při úspěchu nastav `ATTR_USER`, `auth.api_token` (celá řádka), `auth.method = 'bearer'`, fire-and-forget `touch()`.
- Neplatný bearer → 401 ihned (žádný fallback na cookie).

### 2.2 `CsrfMiddleware` — skip při bearer
Pokud `auth.method === 'bearer'`, projít bez CSRF check.

### 2.3 `SupplierScopeMiddleware` — bind na token's supplier_id
Pokud `auth.api_token.supplier_id` není NULL → forcuj, ignoruj `X-Supplier-Id` header. Jinak fallback na header (power-user).

### 2.4 Nový `ApiScopeMiddleware` (`api/src/Middleware/ApiScopeMiddleware.php`)
Mapping HTTP metody → nutný scope: `GET/HEAD` = `read`, ostatní = `read_write`. Pouze pro `auth.method === 'bearer'`. 403 pokud token nemá scope. Pořadí v Bootstrapu: mezi `RoleMiddleware` a `RateLimitMiddleware`.

### 2.5 `RateLimitMiddleware` — per-token key
Pokud bearer, klíč = `token:{id}` místo `user:{id}` / `ip:{ip}`. Default 600 req/min/token (konfig).

## Fáze 2b: Endpointy pro správu tokenů

V `api/src/Action/Auth/Tokens/`:
- `GET /api/auth/tokens` → `ListTokensAction` — bez plaintextu.
- `POST /api/auth/tokens` → `CreateTokenAction` — body `{name, supplier_id?, scope, expires_at?, totp_code}`. Vyžaduje **aktivní session** + ověření TOTP (pokud user má `totp_enabled`). Vrátí plaintext jen v této response. Log do `activity_log`.
- `DELETE /api/auth/tokens/{id}` → `RevokeTokenAction` — set `revoked_at`, log.

**`/api/auth/api-me`** (`GET`) — vrátí `{user, supplier, scope, token_prefix, expires_at}`. Connection-test pro Make/Zapier. Funguje s bearer i session.

Tyto management endpointy běží **session-only** (kromě api-me).

Update `Routes.php`.

## Fáze 3: Verzování `/api/v1`

Nový MW **`api/src/Middleware/ApiVersionRewriteMiddleware.php`** — pokud URI začíná `/api/v1/`, přepiš na `/api/` před routerem. Přidej response header `X-API-Version: 1`.

Wire jako outermost v Bootstrapu (před `IpAllowlistMiddleware`, aby všechny ostatní MW viděly už přepsanou cestu).

Update `AuthMiddleware::PUBLIC_PATHS` (po rewritu už ne nutné, jen safety).

## Fáze 4: OpenAPI dokumentace

### 4.1 `api/openapi.yaml`
Ručně psaný OpenAPI 3.1. Initial scope (~600 řádek):
- `securitySchemes`: BearerAuth (`mi_pat_…`)
- Tags: Auth, Tokens, Clients, Invoices, Codebooks, System
- Paths: `/api/v1/auth/api-me`, `/api/v1/auth/tokens` (list/create/revoke), `/api/v1/clients/*`, `/api/v1/invoices/*` (basics: list, get, create, update, issue, mark-paid, pdf)
- Schemas: `Invoice`, `Client`, `Money`, `PaginationMeta`, `Error`, `ApiToken`

Strategie: invoices+clients+me napřed (≈300 ř), pak inkrementálně doplnit zbytek.

### 4.2 Servírování
**`api/src/Action/System/OpenApiAction.php`**:
- `GET /api/openapi.yaml` → raw soubor s `Content-Type: application/yaml`.
- `GET /api/docs` → HTML s Redoc CDN inclusion.

Oba endpointy public.

## Fáze 5: Postupné doplnění OpenAPI
Postupně doplnit zbytek endpointů (Projects, WorkReports, BankStatements, Settings). Admin endpointy záměrně NEdokumentovat (nemotivovat integrátory na nich stavět).

## Fáze 6: Frontend UI

**`app/src/views/settings/ApiTokensView.vue`**:
- List: prefix, name, supplier, scope, last_used_at, expires_at, status badge.
- "Create" modal: name input, supplier select, scope radio, expiry datepicker (optional), TOTP input (jen pokud user má TOTP). Po submitu zobrazit plaintext s "Copy" + warning "už nikdy neuvidíte". Modal nezavírá bez explicitního potvrzení.
- "Revoke" button → confirm modal.

Update `app/src/router/index.ts` — `/settings/api-tokens`. Nav link v Settings.

i18n: `app/src/i18n/cs.json` + `en.json` — nové klíče.

## Fáze 7: Manuál + release

- Nová kapitola manuálu (jak vytvořit token, `curl` příklady, link na `/api/docs`, rate limity, scopes).
- `CHANGELOG.md` — "Public REST API v1 (bearer tokeny, /api/v1, /api/docs)".
- `VERSION` bump.

## Fáze 8: Testy

V `api/tests/`:
- `Auth/ApiTokenServiceTest.php` — generate/validate/revoke/expiry/hash uniqueness.
- `Http/BearerAuthTest.php` — token funguje, expired = 401, revoked = 401, wrong scope na write = 403, supplier_id binding se nedá obejít přes X-Supplier-Id.
- `Http/ApiVersioningTest.php` — `/api/v1/invoices` == `/api/invoices`, `X-API-Version: 1` header.

## Pořadí PR

| PR | Obsah | LOC | Risk |
|---|---|---|---|
| 1 | Migrace + `ApiTokenService` + service test | ~400 | low |
| 2 | Bearer MW + CSRF skip + ScopeMW + RateLimit + management endpointy + UI | ~800 | medium |
| 3 | `/api/v1/*` rewrite + `X-API-Version` | ~150 | low |
| 4 | `openapi.yaml` (invoices+clients+me) + `/api/docs` Redoc | ~400 | low |
| 5 | Doplnit zbytek `openapi.yaml` | ~1200 | low |
| 6 | Manuál + CHANGELOG + version bump | ~200 | low |

## Co je explicitně out-of-scope

- OAuth2 client registry
- Webhooks
- Idempotency-Key (až přijde stížnost)
- Per-resource scopes (`invoices:read` apod.)
- Dokumentace `/api/admin/*` (interní, nedokumentovat)
