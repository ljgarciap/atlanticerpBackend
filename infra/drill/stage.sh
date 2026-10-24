#!/usr/bin/env bash
# stage.sh — SCRUM-1029: marca de tiempo por etapa para medir el RTO de un restore drill.
# Uso:  infra/drill/stage.sh <nombre-de-etapa> [archivo]      (el archivo por defecto es ./drill-tiempos.log)
# Cada llamada añade "<epoch> <iso> <etapa>"; al final, `infra/drill/stage.sh --resumen` imprime la duración de cada etapa y el total.
set -euo pipefail
LOG="${2:-./drill-tiempos.log}"
if [[ "${1:-}" == "--resumen" ]]; then
  awk 'NR>1 { printf "%-28s %6d s\n", $3, $1-prev } { prev=$1; if (NR==1) first=$1; last=$1 } END { printf "%-28s %6d s\n", "TOTAL (RTO medido)", last-first }' "${2:-./drill-tiempos.log}"
  exit 0
fi
printf '%s %s %s\n' "$(date +%s)" "$(date -u +%FT%TZ)" "${1:?etapa}" >> "$LOG"
