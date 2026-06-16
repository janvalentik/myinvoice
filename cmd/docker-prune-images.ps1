# Detekuje a maze OBSOLETE Docker images po MyInvoice.cz (uvolni disk).
#
# Co se maze:
#   1. stare myinvoice / ghcr myinvoice image, ktere NEpouziva zadny kontejner
#      (running i exited) ANI je nereferencuje compose soubor,
#   2. dangling (<none>) vrstvy.
# Co je VZDY chranene: image pouzivany jakymkoli kontejnerem + image z `image:`
# radku docker-compose*.yml.
#
#   cmd\docker-prune-images.ps1            # smaze obsolete (auto-proceed)
#   cmd\docker-prune-images.ps1 -DryRun    # jen vypise, co by smazal
[CmdletBinding()]
param([switch]$DryRun)

$ErrorActionPreference = 'Stop'
$ProgressPreference = 'SilentlyContinue'
$ProjectRoot = Resolve-Path (Join-Path $PSScriptRoot '..')
Set-Location $ProjectRoot

if (-not (Get-Command docker -ErrorAction SilentlyContinue)) { Write-Error "docker not found in PATH" }

function Ref-ToId([string]$ref) { (& docker image inspect $ref --format '{{.Id}}' 2>$null) }

Write-Host "==> MyInvoice image ted:"
docker images --format '  {{.Repository}}:{{.Tag}}  {{.ID}}  {{.Size}}' | Select-String -Pattern 'myinvoice' | ForEach-Object { $_.Line }
Write-Host ""

# --- chranene image ID (kontejnery + compose reference) --------------------
$protected = New-Object System.Collections.Generic.HashSet[string]
$cids = & docker ps -a -q
foreach ($cid in $cids) {
    $img = & docker inspect $cid --format '{{.Image}}' 2>$null
    if ($img) { [void]$protected.Add($img.Trim()) }
}
Get-ChildItem -Filter 'docker-compose*.yml' -ErrorAction SilentlyContinue | ForEach-Object {
    Select-String -Path $_.FullName -Pattern '^\s*image:\s*(\S+myinvoice\S*)' | ForEach-Object {
        $ref = ($_.Matches[0].Groups[1].Value) -replace '\$\{[^}]*\}', ''
        if ($ref -and $ref -notmatch '^\s*$') {
            $id = Ref-ToId $ref
            if ($id) { [void]$protected.Add($id.Trim()) }
        }
    }
}

# --- kandidati = myinvoice image NEchranene --------------------------------
$toRemove = @()
$rows = & docker images --no-trunc --format '{{.ID}}|{{.Repository}}:{{.Tag}}'
foreach ($row in $rows) {
    $parts = $row -split '\|', 2
    $id = $parts[0]; $ref = $parts[1]
    if (-not $id -or $ref -match '<none>') { continue }
    if ($ref -notmatch 'myinvoice') { continue }
    if ($protected.Contains($id)) { continue }
    $toRemove += $ref
}

if ($toRemove.Count -eq 0) {
    Write-Host "==> Zadne obsolete myinvoice image (vse se pouziva nebo je v compose)."
} else {
    Write-Host "==> Obsolete myinvoice image k odstraneni:"
    $toRemove | ForEach-Object { Write-Host "    $_" }
    if ($DryRun) {
        Write-Host "    (-DryRun: nemazu)"
    } else {
        foreach ($ref in $toRemove) {
            & docker rmi $ref *>$null
            if ($LASTEXITCODE -eq 0) { Write-Host "    smazano: $ref" } else { Write-Host "    preskoceno (zrejme pouzivane): $ref" }
        }
    }
}

Write-Host ""
Write-Host "==> Dangling (osirele) vrstvy:"
if ($DryRun) {
    $n = (& docker images -f dangling=true -q | Measure-Object).Count
    Write-Host "    $n dangling image (-DryRun: nemazu)"
} else {
    (& docker image prune -f | Select-Object -Last 1)
}
