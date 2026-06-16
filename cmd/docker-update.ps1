# Update a running MyInvoice.cz Docker stack to the latest code.
#
#   1. Pulls (registry mode) or rebuilds (source mode) the app image
#   2. Restarts the stack
#   3. Waits for DB health and runs pending migrations
#
# Detects mode automatically - preferuje aktualne RUNNING stack:
#   1. Pokud bezi stack z `docker-compose.production.yml` -> registry mode
#      (GHCR pull, dale pouziva `-f docker-compose.production.yml`).
#   2. Pokud bezi stack z `docker-compose.yml` a je `.git/` + `build:` blok
#      -> source mode (git pull + local build).
#   3. Fallback bez bezicího stacku - podle existujicich souboru.
#
# Idempotent — safe to re-run. Volumes (DB data) persist; backup is your responsibility.
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "docker not found in PATH"
}
& docker compose version > $null 2>&1
if ($LASTEXITCODE -ne 0) { Write-Error "'docker compose' (v2) plugin required" }
if (-not (Test-Path .env)) { Write-Error ".env not found - run docker-install.ps1 first" }

# Load .env into hashtable
$envVars = @{}
Get-Content .env | ForEach-Object {
    if ($_ -match '^\s*([A-Z_]+)\s*=\s*(.*)\s*$') { $envVars[$Matches[1]] = $Matches[2] }
}

# Detect mode z IMAGE bezicho app kontejneru (autoritativni - nezavisi na tom, ktery
# compose file je po ruce; stara detekce podle compose souboru byla krehka, protoze
# git klon ma oba soubory + kolidujici nazev projektu). Prebiti: MYINVOICE_UPDATE_MODE.
#   - bezici registry image (ghcr.io/...) -> registry mode -> docker compose pull
#   - bezici lokalni build (myinvoice:latest)  -> source mode -> git pull + build
#   - nic nebezi + lokalne GHCR image -> registry (byls GHCR deploy, jen zhasnuty)
#   - nic nebezi + .git + build: -> source, jinak registry
$mode = $env:MYINVOICE_UPDATE_MODE

$runningImage = (& docker ps --filter 'label=com.docker.compose.service=app' --format '{{.Image}}' 2>$null |
    Where-Object { $_ -match 'myinvoice' } | Select-Object -First 1)

if (-not $mode) {
    if ($runningImage) {
        $mode = if ($runningImage -match '/') { 'registry' } else { 'source' }
    } elseif (& docker images --format '{{.Repository}}' 2>$null | Select-String -Quiet -Pattern 'ghcr\.io/.*myinvoice') {
        # Stack nebezi, ALE lokalne je stazeny GHCR image -> drive se pullovalo = registry deploy.
        $mode = 'registry'
    } elseif ((Test-Path .git) -and (Select-String -Path docker-compose.yml -Pattern '^\s*build:' -Quiet)) {
        $mode = 'source'
    } else {
        $mode = 'registry'
    }
}

$composeArgs = @()
if ($mode -eq 'registry' -and (Test-Path docker-compose.production.yml)) {
    $composeArgs = @('-f', 'docker-compose.production.yml')
}

if ($runningImage) {
    Write-Host "==> Detekovano: bezici image '$runningImage' -> rezim '$mode'"
} else {
    Write-Host "==> Zadny bezici app kontejner -> rezim '$mode' (dle pritomnych souboru)"
}
Write-Host "    (prebit lze pres MYINVOICE_UPDATE_MODE=registry|source)"
if ($composeArgs.Count -gt 0) { Write-Host "    compose: $($composeArgs[1])" }

# --- 1. fetch new code/image ---------------------------------------------
if ($mode -eq 'source') {
    $dirty = & git status --porcelain
    if ($dirty) {
        Write-Warning "Working tree is dirty - local changes won't be pulled."
        Write-Warning "Consider 'git stash' or commit first. Continuing in 5s..."
        Start-Sleep -Seconds 5
    }
    Write-Host "==> git pull"
    & git pull --ff-only
    if ($LASTEXITCODE -ne 0) { Write-Error "git pull failed" }
    Write-Host "==> Rebuilding app image..."
    & docker compose @composeArgs build --pull app
    if ($LASTEXITCODE -ne 0) { Write-Error "docker compose build failed" }
} else {
    Write-Host "==> Pulling latest image from registry..."
    & docker compose @composeArgs pull app
    if ($LASTEXITCODE -ne 0) { Write-Error "docker compose pull failed" }
}

# --- 1b. detect legacy 3-volume layout and auto-migrate (3.5.x -> 3.6.0) --
# Od 3.6.0 je default Compose layout single-volume (`app-data:/data`). Pokud
# existuji stare 3-volume volumes (`app-log`, `app-storage`, `app-private`)
# a novy `app-data` ne, je to uvodni migrace - probehne automaticky.
$project = $env:COMPOSE_PROJECT_NAME
if (-not $project) {
    $project = (Split-Path -Leaf $ProjectRoot).ToLower() -replace '[^a-z0-9_-]', ''
}
$oldVolumes = @("${project}_app-log", "${project}_app-storage", "${project}_app-private")
$newData = "${project}_app-data"
$hasOld = $false
foreach ($v in $oldVolumes) {
    & docker volume inspect $v *>$null
    if ($LASTEXITCODE -eq 0) { $hasOld = $true; break }
}
& docker volume inspect $newData *>$null
$hasNew = ($LASTEXITCODE -eq 0)

if ($hasOld -and (-not $hasNew)) {
    Write-Host ""
    Write-Host "############################################################" -ForegroundColor Yellow
    Write-Host "#  MIGRACE VOLUMES (3.5.x -> 3.6.0)"                          -ForegroundColor Yellow
    Write-Host "#"                                                            -ForegroundColor Yellow
    Write-Host "#  Detekovan stary 3-volume Docker layout. 3.6.0 prechazi na" -ForegroundColor Yellow
    Write-Host "#  single-volume (/data), ktery drzi i cfg.local.php - tim se"  -ForegroundColor Yellow
    Write-Host "#  per-instance konfigurace (app.url, auth.require_totp) chova" -ForegroundColor Yellow
    Write-Host "#  korektne i po image updatu."                                -ForegroundColor Yellow
    Write-Host "#"                                                            -ForegroundColor Yellow
    Write-Host "#  Skript ted automaticky:"                                    -ForegroundColor Yellow
    Write-Host "#    1. Snapshotne cfg.local.php z bezicho kontejneru"        -ForegroundColor Yellow
    Write-Host "#    2. Zastavi stack (DB volume zustava)"                     -ForegroundColor Yellow
    Write-Host "#    3. Zkopiruje data ze starych volumes do noveho app-data" -ForegroundColor Yellow
    Write-Host "#    4. Obnovi cfg.local.php v novem volumu"                   -ForegroundColor Yellow
    Write-Host "#    5. Spusti stack na novem layoutu"                         -ForegroundColor Yellow
    Write-Host "#"                                                            -ForegroundColor Yellow
    Write-Host "#  Stare volumes NEMAZU - po overeni je smaz rucne."           -ForegroundColor Yellow
    Write-Host "############################################################" -ForegroundColor Yellow
    Write-Host ""
    # Volame migrate.ps1 v AKTUALNIM PS hostu (& path). Sub-`powershell -File` by
    # spustil PS 5.1, ktery nezna $PSNativeCommandUseErrorActionPreference a
    # `docker compose down` (progress do stderr) by trigoval NativeCommandError.
    & (Join-Path $ProjectRoot 'cmd\docker-migrate-volumes.ps1')
    if ($LASTEXITCODE -ne 0) {
        Write-Host "ERROR: Migrace volumes selhala (rc=$LASTEXITCODE) - check log above" -ForegroundColor Red
        exit 1
    }
    Write-Host ""
}

# --- 2. restart ----------------------------------------------------------
Write-Host "==> Restarting stack..."
& docker compose @composeArgs up -d db app
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose up failed" }

# --- 3. wait for DB + migrate -------------------------------------------
Write-Host "==> Waiting for database to become healthy..."
$ready = $false
for ($i = 1; $i -le 30; $i++) {
    $json = & docker compose @composeArgs ps --format json db 2>$null
    if ($json -match '"Health":"healthy"') { $ready = $true; Write-Host "    DB ready."; break }
    Start-Sleep -Seconds 2
}
if (-not $ready) {
    Write-Error "DB failed to become healthy in 60s. Check 'docker compose logs db'."
}

# Migrace bezi automaticky z docker-entrypoint.sh pred apache2-foreground.
# Misto druheho explicitniho migrate (= race condition s entrypointem) cekame,
# az app odpovi na /api/health (v ALLOWED_PATHS pro FirstRunLockMiddleware).
$curl = (Get-Command curl.exe -ErrorAction SilentlyContinue)?.Source
if (-not $curl) { $curl = 'C:\Windows\System32\curl.exe' }
if (-not (Test-Path $curl)) {
    Write-Error "curl.exe nenalezen (potreba na Win 10/11+). Updatuj OS nebo doinstaluj curl."
}

$port = $envVars.APP_PORT
if (-not $port) { $port = '8080' }
Write-Host "==> Waiting for app to become available (entrypoint runs migrations)..."
$appReady = $false
$lastErr = ''
for ($i = 1; $i -le 60; $i++) {
    $out = & $curl -fsS -m 3 -o NUL "http://localhost:$port/api/health" 2>&1
    if ($LASTEXITCODE -eq 0) { $appReady = $true; Write-Host "    App ready."; break }
    $lastErr = ($out | Out-String).Trim()
    Start-Sleep -Seconds 2
}
if (-not $appReady) {
    Write-Host "    Last curl error: $lastErr" -ForegroundColor Yellow
    Write-Error "App failed to respond in 120s. Check 'docker compose @composeArgs logs app'."
}

# --- 3c. uklid osirelych vrstev po updatu (bezpecne - jen dangling) -------
Write-Host "==> Uklid dangling image vrstev..."
& docker image prune -f *> $null
Write-Host "    (stare tagovane verze uklidis pres cmd\docker-prune-images.ps1)"

# --- 4. report -----------------------------------------------------------
Write-Host ""
Write-Host "============================================================"
Write-Host " Update complete. App: http://localhost:$port"
Write-Host ""
Write-Host " Tail logs:        docker compose logs -f app"
Write-Host " Restart only:     docker compose restart app"
Write-Host "============================================================"
