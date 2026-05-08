# MyInvoice.cz — Docker upgrade watcher (Windows / PowerShell verze).
#
# Sleduje storage/upgrade-requested.json **uvnitř** kontejneru (přes
# `docker compose exec`) a když ho UI vytvoří (POST /api/admin/update/
# trigger), spustí docker-update.ps1 a výsledek zapíše zpět do kontejneru
# do storage/upgrade-result.json. UI to v Systém → Aktualizace zobrazí
# jako „aplikováno / selhalo".
#
# Storage je Docker named volume (ne bind-mount), takže host watcher
# musí na flag soubor sahat přes `exec`. Tohle je oprava bugu v3.0.0/3.0.1
# kdy watcher na hostu neviděl flag uvnitř volume.
#
# Provoz:
#   - Pust jako Scheduled Task (Trigger: At startup, Action: powershell.exe
#     -NoProfile -ExecutionPolicy Bypass -File C:\inetpub\myinvoice\cmd\docker-update-watcher.ps1)
#     s "Run whether user is logged in or not" + "Run with highest privileges".
#
# Idempotent — flag se zpracovává jednou (rename před spuštěním).
[CmdletBinding()]
param()

$ErrorActionPreference = 'Continue'   # nevalíme se na non-fatal errorech v poll smyčce

# PS 7.3+ vypne "stderr z native commandů = error stream" — `docker compose pull`
# zapisuje download progress do stderr, který by jinak PS označoval červeně
# jako NativeCommandError, i když exit code je 0 (čistě kosmetický šum).
$PSNativeCommandUseErrorActionPreference = $false

$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

$intervalS = if ($env:MYINVOICE_WATCHER_INTERVAL) { [int]$env:MYINVOICE_WATCHER_INTERVAL } else { 30 }

# Auto-detect compose file — preferuj production.yml pokud běží.
$composeArgs = @()
if ((Test-Path 'docker-compose.production.yml')) {
    $prodPs = & docker compose -f docker-compose.production.yml ps app 2>$null
    if ($LASTEXITCODE -eq 0 -and $prodPs -match 'running') {
        $composeArgs = @('-f', 'docker-compose.production.yml')
    }
}

function Invoke-DC {
    param([Parameter(ValueFromRemainingArguments=$true)] [string[]]$Args)
    & docker compose @composeArgs @Args
}

$composeFileLabel = if ($composeArgs.Count -gt 0) { $composeArgs[1] } else { '<default docker-compose.yml>' }
Write-Host "[watcher] start, polling storage/upgrade-requested.json inside container every $intervalS s"
Write-Host "[watcher] compose: $composeFileLabel"

function Write-ResultIntoContainer {
    param(
        [string]$Status,
        [string]$Target,
        [string]$Message
    )
    $payload = @{
        status         = $Status
        target_version = $Target
        applied_at     = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
        message        = $Message
    }
    $json = $payload | ConvertTo-Json -Depth 4 -Compress
    # Pipe do `sh -c 'cat > storage/upgrade-result.json'`
    $json | & docker compose @composeArgs exec -T app sh -c 'cat > storage/upgrade-result.json' 2>$null
    if ($LASTEXITCODE -ne 0) {
        Write-Warning "[watcher] nelze zapsat upgrade-result.json"
    }
}

while ($true) {
    # Test, jestli flag soubor existuje uvnitř kontejneru.
    & docker compose @composeArgs exec -T app test -f storage/upgrade-requested.json 2>$null
    if ($LASTEXITCODE -eq 0) {
        $flagJson = & docker compose @composeArgs exec -T app cat storage/upgrade-requested.json 2>$null

        $target = 'latest'
        if ($flagJson) {
            try {
                $payload = $flagJson | ConvertFrom-Json -ErrorAction Stop
                if ($payload.target_version) { $target = [string]$payload.target_version }
            } catch {
                Write-Warning "[watcher] nelze parsnout flag JSON: $_"
            }
        }

        $ts = (Get-Date).ToUniversalTime().ToString('yyyyMMddTHHmmssZ')
        Write-Host "[watcher] $((Get-Date).ToUniversalTime().ToString('s'))Z upgrade requested → $target"

        # Lock — přejmenuj uvnitř kontejneru, ať ho další iterace nevezme znovu.
        & docker compose @composeArgs exec -T app mv -f storage/upgrade-requested.json storage/upgrade-inflight.json 2>$null

        $log = Join-Path $env:TEMP "myinvoice-upgrade-$ts.log"
        try {
            # 2>&1 mergne stderr → success stream; bez `*>&1` PS 5.1 wrapper
            # nevyhazuje cosmetické "RemoteException" pro stderr-as-error.
            & powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $ProjectRoot 'cmd\docker-update.ps1') 2>&1 | Tee-Object -FilePath $log | Out-Host
            if ($LASTEXITCODE -eq 0) {
                $status  = 'applied'
                $message = "Upgrade dokoncen. Log na hostu: $log"
                Write-Host "[watcher] OK"
            } else {
                $status  = 'failed'
                $message = "Upgrade selhal (rc=$LASTEXITCODE). Log na hostu: $log"
                Write-Host "[watcher] FAILED (rc=$LASTEXITCODE). Viz $log"
            }
        } catch {
            $status  = 'failed'
            $message = "Watcher exception: $_. Log: $log"
            Write-Host "[watcher] EXCEPTION: $_"
        }

        # Po update se kontejner restartuje — počkej, až bude zase responzivní.
        for ($i = 1; $i -le 30; $i++) {
            & docker compose @composeArgs exec -T app true 2>$null
            if ($LASTEXITCODE -eq 0) { break }
            Start-Sleep -Seconds 2
        }

        Write-ResultIntoContainer -Status $status -Target $target -Message $message
        & docker compose @composeArgs exec -T app rm -f storage/upgrade-inflight.json 2>$null
    }
    Start-Sleep -Seconds $intervalS
}
