# One-click install z pre-built image na GHCR (zadny local build).
#
#   1. Vygeneruje .env s random DB hesly (pokud chybi)
#   2. Vygeneruje cfg.docker.php z cfg.sample.php s random secrets (pokud chybi)
#   3. docker compose pull (image z ghcr.io/radekhulan/myinvoice:latest)
#   4. docker compose up -d (entrypoint sam spusti migrace pred apache2-foreground)
#   5. Pocka az app odpovi na HTTP (= migrace dobehly, apache bezi)
#   6. Vypise URL k setup wizardu
#
# Pouziva docker-compose.production.yml (image pull, zadny build).
# Idempotentni - bezpecne spoustet opakovane.
[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

$ComposeFile = 'docker-compose.production.yml'

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) {
    Write-Error "docker not found in PATH"
}
& docker compose version > $null 2>&1
if ($LASTEXITCODE -ne 0) {
    Write-Error "'docker compose' (v2) plugin required - install Docker Desktop"
}
if (-not (Test-Path $ComposeFile)) {
    Write-Error "$ComposeFile not found in $ProjectRoot"
}

function Invoke-Compose {
    & docker compose -f $ComposeFile @args
}

function New-RandomToken([int]$Bytes = 24) {
    $buf = New-Object byte[] $Bytes
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($buf)
    return ([Convert]::ToBase64String($buf) -replace '[+/=]', '').Substring(0, [math]::Min($Bytes + 4, 32))
}

# --- 1. .env --------------------------------------------------------------
if (-not (Test-Path .env)) {
    Write-Host "==> Generating .env with random DB passwords..."
    $rootPass = New-RandomToken 24
    $userPass = New-RandomToken 24
    @"
# MyInvoice.cz - Docker compose env (gitignored)
APP_PORT=8080
DB_PORT=3307
DB_NAME=myinvoice
DB_USER=myinvoice
DB_ROOT_PASSWORD=$rootPass
DB_PASSWORD=$userPass
"@ | Set-Content -Encoding UTF8 -NoNewline .env
    Write-Host "    .env written (passwords randomised)"
} else {
    Write-Host "==> .env already exists (skipping)"
}

$envVars = @{}
Get-Content .env | ForEach-Object {
    if ($_ -match '^\s*([A-Z_]+)\s*=\s*(.*)\s*$') { $envVars[$Matches[1]] = $Matches[2] }
}

# --- 2. cfg.docker.php ----------------------------------------------------
if (-not (Test-Path cfg.docker.php)) {
    Write-Host "==> Generating cfg.docker.php from cfg.sample.php with Docker defaults..."
    $pepper = [Convert]::ToBase64String((1..32 | ForEach-Object { Get-Random -Maximum 256 }))
    $encKey = [Convert]::ToBase64String((1..32 | ForEach-Object { Get-Random -Maximum 256 }))
    $cfg = Get-Content cfg.sample.php -Raw
    $appUrl = "http://localhost:$($envVars.APP_PORT)"
    $cfg = [regex]::Replace($cfg, "'host'    => '127\.0\.0\.1',", "'host'    => 'db',",    1)
    $cfg = [regex]::Replace($cfg, "'host'    => '127\.0\.0\.1',", "'host'    => 'redis',", 1)
    $cfg = $cfg -replace "'name'    => 'myinvoice',", "'name'    => '$($envVars.DB_NAME)',"
    $cfg = $cfg -replace "'user'    => 'root',",      "'user'    => '$($envVars.DB_USER)',"
    $cfg = $cfg -replace "'pass'    => 'CHANGE-ME',", "'pass'    => '$($envVars.DB_PASSWORD)',"
    $cfg = $cfg -replace "'pepper' => 'CHANGE-ME',",  "'pepper' => '$pepper',"
    $cfg = $cfg -replace "'secret_encryption_key' => '',", "'secret_encryption_key' => '$encKey',"
    $cfg = $cfg -replace "'env'    => 'production',",      "'env'    => 'development',"
    $cfg = $cfg -replace "'url'    => 'https://dev\.example\.com',", "'url'    => '$appUrl',"
    $cfg = $cfg -replace "'cookie_name'   => '__Host-myinvoice_session',", "'cookie_name'   => 'myinvoice_session',"
    $cfg = $cfg -replace "'cookie_secure' => true,", "'cookie_secure' => false,"
    Set-Content -Encoding UTF8 -NoNewline cfg.docker.php -Value $cfg
    Write-Host "    cfg.docker.php written"
    Write-Host ""
    Write-Host "    !!  Edit cfg.docker.php to fill in SMTP, Cloudflare Turnstile, IP allowlist  !!" -ForegroundColor Yellow
    Write-Host ""
} else {
    Write-Host "==> cfg.docker.php already exists (skipping)"
}

# --- 3. pull image from GHCR ---------------------------------------------
Write-Host "==> Pulling image from GHCR..."
Invoke-Compose pull app
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose pull failed" }

# --- 4. up ----------------------------------------------------------------
Write-Host "==> Starting stack..."
Invoke-Compose up -d db app
if ($LASTEXITCODE -ne 0) { Write-Error "docker compose up failed" }

# --- 5. wait for DB + migrate --------------------------------------------
Write-Host "==> Waiting for database to become healthy..."
$ready = $false
for ($i = 1; $i -le 30; $i++) {
    $json = Invoke-Compose ps --format json db 2>$null
    if ($json -match '"Health":"healthy"') { $ready = $true; Write-Host "    DB ready."; break }
    Start-Sleep -Seconds 2
}
if (-not $ready) {
    Write-Error "DB failed to become healthy in 60s. Check 'docker compose -f $ComposeFile logs db'."
}

# Migrace se spousti automaticky z docker-entrypoint.sh pred apache2-foreground.
# Misto druheho explicitniho migrate (= race condition s entrypointem, viz issue
# s duplicate PK v `migrations` tabulce) jen cekame, az app odpovi na HTTP.
# Pouzivame /api/health - je v ALLOWED_PATHS pro FirstRunLockMiddleware, takze
# vraci 200 i ve fresh-install state (kdy /api/version dostane 423 Locked).
Write-Host "==> Waiting for app to become available (entrypoint runs migrations)..."
$ready = $false
for ($i = 1; $i -le 60; $i++) {
    try {
        $resp = Invoke-WebRequest -Uri "http://localhost:$($envVars.APP_PORT)/api/health" -UseBasicParsing -TimeoutSec 3 -ErrorAction Stop
        if ($resp.StatusCode -eq 200) { $ready = $true; Write-Host "    App ready."; break }
    } catch {}
    Start-Sleep -Seconds 2
}
if (-not $ready) {
    Write-Error "App failed to respond in 120s. Check 'docker compose -f $ComposeFile logs app'."
}

# --- 6. report -----------------------------------------------------------
$port = $envVars.APP_PORT
if (-not $port) { $port = '8080' }
Write-Host ""
Write-Host "============================================================"
Write-Host " MyInvoice.cz is up at:  http://localhost:$port"
Write-Host " Image:                  ghcr.io/radekhulan/myinvoice:latest"
Write-Host ""
Write-Host " The browser will land on the setup wizard:"
Write-Host "   1. Admin user (name, email, password >= 12 chars)"
Write-Host "   2. Supplier (IC -> Nacist z ARES -> bank account)"
Write-Host "   3. Optional sample data"
Write-Host ""
Write-Host " Useful (-f $ComposeFile flag is needed for all compose calls):"
Write-Host "   docker compose -f $ComposeFile logs -f app"
Write-Host "   docker compose -f $ComposeFile pull; docker compose -f $ComposeFile up -d   # update"
Write-Host "   docker compose -f $ComposeFile down           # stop (data persists)"
Write-Host "   docker compose -f $ComposeFile down -v        # stop + WIPE volumes"
Write-Host "============================================================"
