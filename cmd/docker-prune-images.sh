#!/usr/bin/env bash
# Detekuje a maže OBSOLETE Docker images po MyInvoice.cz (uvolní disk).
#
# Co se maže:
#   1. staré myinvoice / ghcr myinvoice image, které NEpoužívá žádný kontejner
#      (running i exited) ANI je nereferencuje compose soubor → typicky osiřelý
#      build nebo předchozí verze po updatu,
#   2. dangling (<none>) vrstvy.
#
# Co je VŽDY chráněné: image používaný jakýmkoli kontejnerem + image z `image:`
# řádků docker-compose*.yml (aby se nesmazal ten, kterým bys stack znovu nahodil).
#
#   cmd/docker-prune-images.sh            # smaže obsolete (auto-proceed)
#   cmd/docker-prune-images.sh --dry-run  # jen vypíše, co by smazal
set -euo pipefail

PROJECT_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$PROJECT_ROOT"

DRY=0; [[ "${1:-}" == "--dry-run" ]] && DRY=1
command -v docker >/dev/null 2>&1 || { echo "ERROR: docker not found in PATH" >&2; exit 1; }

ref_to_id() { docker image inspect "$1" --format '{{.Id}}' 2>/dev/null || true; }

echo "==> MyInvoice image teď:"
docker images --format '  {{.Repository}}:{{.Tag}}  {{.ID}}  {{.Size}}' | grep -Ei 'myinvoice' || echo "  (žádné)"
echo ""

# --- chráněné image ID (kontejnery + compose reference) --------------------
protected=""
# kontejnery (running i exited)
cids="$(docker ps -a -q || true)"
if [[ -n "$cids" ]]; then
  protected="$(echo "$cids" | xargs -r docker inspect --format '{{.Image}}' 2>/dev/null | sort -u)"
fi
# compose image: řádky (literály bez ${...} substituce)
while read -r ref; do
  [[ -z "$ref" ]] && continue
  id="$(ref_to_id "$ref")"
  [[ -n "$id" ]] && protected="$protected"$'\n'"$id"
done < <(grep -hE '^[[:space:]]*image:[[:space:]]' docker-compose*.yml 2>/dev/null \
          | sed -E 's/^[[:space:]]*image:[[:space:]]*//' | sed -E 's/\$\{[^}]*\}//g' | grep -i myinvoice || true)
protected="$(echo "$protected" | sort -u | grep -v '^$' || true)"

# --- kandidáti = myinvoice image NEchráněné --------------------------------
remove_refs=()
while IFS='|' read -r id ref; do
  [[ -z "$id" || "$ref" == *"<none>"* ]] && continue
  if [[ -n "$protected" ]] && grep -qF "$id" <<<"$protected"; then continue; fi
  remove_refs+=("$ref")
done < <(docker images --no-trunc --format '{{.ID}}|{{.Repository}}:{{.Tag}}' | grep -Ei 'myinvoice')

if [[ ${#remove_refs[@]} -eq 0 ]]; then
  echo "==> Žádné obsolete myinvoice image (vše se používá nebo je v compose)."
else
  echo "==> Obsolete myinvoice image k odstranění:"
  printf '    %s\n' "${remove_refs[@]}"
  if [[ "$DRY" == "1" ]]; then
    echo "    (--dry-run: nemažu)"
  else
    for ref in "${remove_refs[@]}"; do
      if docker rmi "$ref" >/dev/null 2>&1; then echo "    smazáno: $ref"; else echo "    přeskočeno (zřejmě používané): $ref"; fi
    done
  fi
fi

echo ""
echo "==> Dangling (osiřelé) vrstvy:"
if [[ "$DRY" == "1" ]]; then
  n="$(docker images -f dangling=true -q | wc -l | tr -d ' ')"
  echo "    $n dangling image (--dry-run: nemažu)"
else
  docker image prune -f | tail -1
fi
