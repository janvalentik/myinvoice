#!/usr/bin/env bash
# Update a running MyInvoice.cz Docker stack to the latest code.
#
#   1. Pulls (registry mode) or rebuilds (source mode) the app image
#   2. Restarts the stack
#   3. Waits for DB health and runs pending migrations
#
# Detekuje režim z IMAGE běžícího app kontejneru (ne podle compose souborů):
#   - běžící registry image (ghcr.io/...) → registry mode → docker compose pull
#   - běžící lokální build (myinvoice:latest) → source mode → git pull + build
#   - nic neběží + lokálně je GHCR image → registry (byls GHCR deploy, jen zhasnutý)
#   - nic neběží + .git + build: → source, jinak registry
# Přebití: MYINVOICE_UPDATE_MODE=registry|source.
#
# Idempotent — safe to re-run. Volumes (DB data) persist; backup is your responsibility.
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

if ! command -v docker >/dev/null 2>&1; then
  echo "ERROR: docker not found in PATH" >&2; exit 1
fi
if ! docker compose version >/dev/null 2>&1; then
  echo "ERROR: 'docker compose' (v2) plugin required" >&2; exit 1
fi
if [[ ! -f .env ]]; then
  echo "ERROR: .env not found — run docker-install.sh first" >&2; exit 1
fi

set -a; . ./.env; set +a

# Detect mode z IMAGE běžícího app kontejneru (autoritativní — nezávisí na tom,
# který compose file je zrovna po ruce; staré řešení podle compose souborů bylo
# křehké, protože git klon má oba soubory + kolidující název projektu).
#   - běžící image z registru (ghcr.io/...) → registry mode → `docker compose pull`
#   - běžící lokálně stavěný image (myinvoice:latest, bez registru) → source mode → build
#   - nic neběží → fallback podle přítomných souborů (.git + build: → source, jinak registry)
# Přebití: MYINVOICE_UPDATE_MODE=registry|source.
MODE="${MYINVOICE_UPDATE_MODE:-}"

running_image="$(docker ps --filter label=com.docker.compose.service=app --format '{{.Image}}' 2>/dev/null | grep -i myinvoice | head -1)"

if [[ -z "$MODE" ]]; then
  if [[ -n "$running_image" ]]; then
    case "$running_image" in
      */*) MODE="registry" ;;   # má registry/namespace → např. ghcr.io/radekhulan/myinvoice
      *)   MODE="source"   ;;   # bare lokální tag → myinvoice:latest
    esac
  elif docker images --format '{{.Repository}}' 2>/dev/null | grep -qiE 'ghcr\.io/.*myinvoice'; then
    # Stack neběží, ALE lokálně je stažený GHCR image → dřív se pullovalo = registry deploy.
    # (Bez tohohle by se u zhasnutého GHCR stacku v git klonu spadlo na source/build.)
    MODE="registry"
  elif [[ -d .git ]] && grep -qE '^\s*build:' docker-compose.yml 2>/dev/null; then
    MODE="source"
  else
    MODE="registry"
  fi
fi

COMPOSE_ARGS=""
if [[ "$MODE" == "registry" ]] && [[ -f docker-compose.production.yml ]]; then
  COMPOSE_ARGS="-f docker-compose.production.yml"
fi

if [[ -n "$running_image" ]]; then
  echo "==> Detekováno: běžící image '${running_image}' → režim '${MODE}'"
else
  echo "==> Žádný běžící app kontejner → režim '${MODE}' (dle přítomných souborů)"
fi
echo "    (přebít lze přes MYINVOICE_UPDATE_MODE=registry|source)"
[[ -n "$COMPOSE_ARGS" ]] && echo "    compose: ${COMPOSE_ARGS#-f }"

DC=(docker compose)
[[ -n "$COMPOSE_ARGS" ]] && DC+=($COMPOSE_ARGS)

# --- 1. fetch new code/image ---------------------------------------------
if [[ "${MODE}" == "source" ]]; then
  if [[ -n "$(git status --porcelain)" ]]; then
    echo "WARN: working tree is dirty — local changes won't be pulled." >&2
    echo "      Consider 'git stash' or commit first. Continuing in 5s…" >&2
    sleep 5
  fi
  echo "==> git pull"
  git pull --ff-only
  echo "==> Rebuilding app image…"
  "${DC[@]}" build --pull app
else
  echo "==> Pulling latest image from registry…"
  "${DC[@]}" pull app
fi

# --- 1b. detect legacy 3-volume layout and auto-migrate (3.5.x → 3.6.0) --
# Od 3.6.0 je default Compose layout single-volume (`app-data:/data`). Pokud
# existují staré 3-volume volumes (`app-log`, `app-storage`, `app-private`)
# a nový `app-data` ne, je to úvodní migrace — proběhne automaticky, jinak
# by app po `up -d` viděla prázdný `app-data` a žádné staré faktury/uploady.
PROJECT="${COMPOSE_PROJECT_NAME:-$(basename "$PROJECT_ROOT" | tr '[:upper:]' '[:lower:]' | tr -cd '[:alnum:]_-')}"
old_log="${PROJECT}_app-log"
old_storage="${PROJECT}_app-storage"
old_private="${PROJECT}_app-private"
new_data="${PROJECT}_app-data"
has_old=false
for v in "$old_log" "$old_storage" "$old_private"; do
  if docker volume inspect "$v" >/dev/null 2>&1; then has_old=true; break; fi
done
has_new=false
if docker volume inspect "$new_data" >/dev/null 2>&1; then has_new=true; fi

if [[ "$has_old" == "true" ]] && [[ "$has_new" == "false" ]]; then
  echo ""
  echo "############################################################"
  echo "#  MIGRACE VOLUMES (3.5.x → 3.6.0)"
  echo "#"
  echo "#  Detekován starý 3-volume Docker layout. 3.6.0 přechází na"
  echo "#  single-volume (\`/data\`), který drží i cfg.local.php — tím se"
  echo "#  per-instance konfigurace (app.url, auth.require_totp) chová"
  echo "#  korektně i po image updatu."
  echo "#"
  echo "#  Skript teď automaticky:"
  echo "#    1. Snapshotne cfg.local.php z běžícího kontejneru"
  echo "#    2. Zastaví stack (DB volume zůstává)"
  echo "#    3. Zkopíruje data ze starých volumes do nového app-data"
  echo "#    4. Obnoví cfg.local.php v novém volumu"
  echo "#    5. Spustí stack na novém layoutu"
  echo "#"
  echo "#  Staré volumes NEMAZÁM — po ověření je smaž ručně příkazy z výpisu."
  echo "############################################################"
  echo ""
  bash "$PROJECT_ROOT/cmd/docker-migrate-volumes.sh"
  echo ""
fi

# --- 2. restart -----------------------------------------------------------
echo "==> Restarting stack…"
"${DC[@]}" up -d db app

# --- 3. wait for DB + migrate --------------------------------------------
echo "==> Waiting for database to become healthy…"
for i in {1..30}; do
  status=$("${DC[@]}" ps --format json db 2>/dev/null | grep -o '"Health":"[^"]*"' | head -1 | cut -d'"' -f4)
  if [[ "$status" == "healthy" ]]; then echo "    DB ready."; break; fi
  sleep 2
  if [[ $i -eq 30 ]]; then
    echo "ERROR: DB failed to become healthy in 60s. Check '${DC[*]} logs db'." >&2
    exit 1
  fi
done

# Migrace běží automaticky z `docker-entrypoint.sh` před apache2-foreground.
# Místo druhého explicitního migrate (= race condition s entrypointem) jen
# čekáme, až app odpoví na /api/health (v ALLOWED_PATHS pro FirstRunLockMiddleware).
echo "==> Waiting for app to become available (entrypoint runs migrations)…"
for i in {1..60}; do
  if curl -fsS -o /dev/null "http://localhost:${APP_PORT:-8080}/api/health"; then
    echo "    App ready."
    break
  fi
  sleep 2
  if [[ $i -eq 60 ]]; then
    echo "ERROR: App failed to respond in 120s. Check '${DC[*]} logs app'." >&2
    exit 1
  fi
done

# --- 3c. úklid osiřelých vrstev po updatu (bezpečné — jen dangling) -------
echo "==> Úklid dangling image vrstev…"
docker image prune -f >/dev/null 2>&1 || true
echo "    (staré tagované verze uklidíš přes cmd/docker-prune-images.sh)"

# --- 4. report -----------------------------------------------------------
APP_PORT="${APP_PORT:-8080}"
echo ""
echo "============================================================"
echo " Update complete. App: http://localhost:${APP_PORT}"
echo ""
echo " Tail logs:        docker compose logs -f app"
echo " Restart only:     docker compose restart app"
echo "============================================================"
