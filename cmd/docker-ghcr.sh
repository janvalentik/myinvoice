#!/usr/bin/env bash
# One-click install z pre-built image na GHCR (žádný local build).
#
#   1. Vygeneruje .env s random DB hesly (pokud chybí)
#   2. Vygeneruje cfg.docker.php z cfg.sample.php s random secrets (pokud chybí)
#   3. docker compose pull (image z ghcr.io/radekhulan/myinvoice:latest)
#   4. docker compose up -d (entrypoint sám spustí migrace před apache2-foreground)
#   5. Počká až app odpoví na HTTP (= migrace doběhly, apache běží)
#   6. Vypíše URL k setup wizardu
#
# Používá docker-compose.production.yml (image pull, žádný build).
# Idempotentní — bezpečné spouštět opakovaně.
set -euo pipefail

# Detekce PROJECT_ROOT — skript se pouští dvěma způsoby:
#   a) standalone install (curl 3 souborů do jedné složky): script vedle compose file
#   b) z klonu repa: script v `cmd/`, compose file o úroveň výš
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
COMPOSE_FILE="docker-compose.production.yml"
if [[ -f "${SCRIPT_DIR}/${COMPOSE_FILE}" ]]; then
  PROJECT_ROOT="${SCRIPT_DIR}"
elif [[ -f "${SCRIPT_DIR}/../${COMPOSE_FILE}" ]]; then
  PROJECT_ROOT="$(cd "${SCRIPT_DIR}/.." && pwd)"
else
  echo "ERROR: ${COMPOSE_FILE} not found next to script ani v ${SCRIPT_DIR}/.." >&2
  echo "       Stáhni jej z https://raw.githubusercontent.com/radekhulan/myinvoice/master/${COMPOSE_FILE}" >&2
  exit 1
fi
cd "$PROJECT_ROOT"

if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: docker not found in PATH" >&2; exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "ERROR: 'docker compose' (v2) plugin required" >&2; exit 1
fi

COMPOSE=(docker compose -f "$COMPOSE_FILE")

# Smart: pokud už app běží, tohle je spíš update než čerstvá instalace.
running_image="$(docker ps --filter label=com.docker.compose.service=app --format '{{.Image}}' 2>/dev/null | grep -i myinvoice | head -1 || true)"
if [[ -n "$running_image" ]]; then
  echo "==> Pozn.: app už běží (image '${running_image}'). Pro pouhou aktualizaci použij cmd/docker-update.sh."
  echo "    (tenhle skript je idempotentní — klidně pokračuj, jen přepulluje a nahodí znovu)"
fi

# --- 1. .env ---------------------------------------------------------------
if [[ ! -f .env ]]; then
  echo "==> Generating .env with random DB passwords…"
  DB_ROOT_PASSWORD=$(openssl rand -base64 24 | tr -d '=+/' | head -c 28)
  DB_PASSWORD=$(openssl rand -base64 24      | tr -d '=+/' | head -c 28)
  cat > .env <<EOF
# MyInvoice.cz — Docker compose env (gitignored)
APP_PORT=8080
DB_PORT=3307
DB_NAME=myinvoice
DB_USER=myinvoice
DB_ROOT_PASSWORD=${DB_ROOT_PASSWORD}
DB_PASSWORD=${DB_PASSWORD}
EOF
  echo "    .env written (passwords randomised)"
else
  echo "==> .env already exists (skipping)"
fi
set -a; . ./.env; set +a

# --- 2. cfg.docker.php -----------------------------------------------------
if [[ ! -f cfg.docker.php ]]; then
  echo "==> Generating cfg.docker.php from cfg.sample.php with Docker defaults…"
  PEPPER=$(openssl rand -base64 32)
  ENC_KEY=$(openssl rand -base64 32)
  cp cfg.sample.php cfg.docker.php
  # cfg.sample.php has TWO `'host' => '127.0.0.1',` lines (db block then redis block).
  # First occurrence becomes 'db', second becomes 'redis' — done via perl (portable;
  # BSD sed on macOS does not support GNU's `0,/pat/` range addressing).
  APP_URL="http://localhost:${APP_PORT}"
  perl -i -pe '
      BEGIN { $n = 0 }
      if (/host.*127\.0\.0\.1/) {
          $n++;
          s/127\.0\.0\.1/db/    if $n == 1;
          s/127\.0\.0\.1/redis/ if $n == 2;
      }
  ' cfg.docker.php
  sed -i.bak \
      -e "s|'name'    => 'myinvoice',|'name'    => '${DB_NAME}',|" \
      -e "s|'user'    => 'root',|'user'    => '${DB_USER}',|" \
      -e "s|'pass'    => 'CHANGE-ME',|'pass'    => '${DB_PASSWORD}',|" \
      -e "s|'pepper' => 'CHANGE-ME',|'pepper' => '${PEPPER}',|" \
      -e "s|'secret_encryption_key' => '',|'secret_encryption_key' => '${ENC_KEY}',|" \
      -e "s|'env'    => 'production',|'env'    => 'development',|" \
      -e "s|'url'    => 'https://dev.example.com',|'url'    => '${APP_URL}',|" \
      -e "s|'cookie_name'   => '__Host-myinvoice_session',|'cookie_name'   => 'myinvoice_session',|" \
      -e "s|'cookie_secure' => true,|'cookie_secure' => false,|" \
      cfg.docker.php
  rm -f cfg.docker.php.bak
  echo "    cfg.docker.php written"
  echo ""
  echo "    !!  Edit cfg.docker.php to fill in SMTP, Cloudflare Turnstile, IP allowlist  !!"
  echo ""
else
  echo "==> cfg.docker.php already exists (skipping)"
fi

# --- 3. pull image from GHCR ----------------------------------------------
echo "==> Pulling image from GHCR…"
"${COMPOSE[@]}" pull app

# --- 4. up -----------------------------------------------------------------
echo "==> Starting stack…"
"${COMPOSE[@]}" up -d db app

# --- 5. wait for DB + migrate ---------------------------------------------
echo "==> Waiting for database to become healthy…"
for i in {1..30}; do
  status=$("${COMPOSE[@]}" ps --format json db 2>/dev/null | grep -o '"Health":"[^"]*"' | head -1 | cut -d'"' -f4)
  if [[ "$status" == "healthy" ]]; then echo "    DB ready."; break; fi
  sleep 2
  if [[ $i -eq 30 ]]; then
    echo "ERROR: DB failed to become healthy in 60s. Check '${COMPOSE[*]} logs db'." >&2
    exit 1
  fi
done

# Migrace se spouští automaticky z `docker-entrypoint.sh` před apache2-foreground.
# Místo druhého explicitního migrate (= race condition s entrypointem, viz issue
# s duplicate PK v `migrations` tabulce) jen čekáme, až app odpoví na HTTP.
# Používáme /api/health — je v ALLOWED_PATHS pro FirstRunLockMiddleware, takže
# vrací 200 i ve fresh-install state (kdy /api/version dostane 423 Locked).
echo "==> Waiting for app to become available (entrypoint runs migrations)…"
for i in {1..60}; do
  if curl -fsS -o /dev/null "http://localhost:${APP_PORT}/api/health"; then
    echo "    App ready."
    break
  fi
  sleep 2
  if [[ $i -eq 60 ]]; then
    echo "ERROR: App failed to respond in 120s. Check '${COMPOSE[*]} logs app'." >&2
    exit 1
  fi
done

# --- 6. report -------------------------------------------------------------
APP_PORT="${APP_PORT:-8080}"
echo ""
echo "============================================================"
echo " MyInvoice.cz is up at:  http://localhost:${APP_PORT}"
echo " Image:                  ghcr.io/radekhulan/myinvoice:latest"
echo ""
echo " The browser will land on the setup wizard:"
echo "   1. Admin user (name, email, password ≥ 12 chars)"
echo "   2. Supplier (IČO → Načíst z ARES → bank account)"
echo "   3. Optional sample data"
echo ""
echo " Useful (-f ${COMPOSE_FILE} flag is needed for all compose calls):"
echo "   docker compose -f ${COMPOSE_FILE} logs -f app"
echo "   docker compose -f ${COMPOSE_FILE} pull && docker compose -f ${COMPOSE_FILE} up -d   # update"
echo "   docker compose -f ${COMPOSE_FILE} down           # stop (data persists)"
echo "   docker compose -f ${COMPOSE_FILE} down -v        # stop + WIPE volumes"
echo "============================================================"
