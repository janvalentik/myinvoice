#!/usr/bin/env bash
# =============================================================================
#  cron-backup-documents.sh — denní záloha sekce Dokumenty (storage/documents/,
#  všechny typy souborů) do storage/backup/{dbname}-documents-YYYY-MM-DD.zip
#  Záměrně oddělené od cron-backup-pdf.sh (ten Dokumenty nezahrnuje).
#  Frekvence: 1× denně, doporučeno 02:35 (po cron-backup-pdf)
#  Retention: 30 denních + 12 měsíčních (1. v měsíci se zachová déle)
#
#  crontab:
#    35 2 * * *  /var/www/myinvoice.cz/cmd/cron-backup-documents.sh
# =============================================================================
set -euo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
LOG_DIR="${MYINVOICE_DATA_DIR:-$PROJECT_ROOT}/log/cron"
mkdir -p "$LOG_DIR"
exec php "$PROJECT_ROOT/api/bin/cron-backup-documents.php" "$@" \
    >> "$LOG_DIR/backup-documents-$(date +%Y-%m-%d).log" 2>&1
