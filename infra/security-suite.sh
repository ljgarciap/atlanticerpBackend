#!/usr/bin/env bash
# security-suite.sh — SCRUM-959: ejecuta en LOCAL todo lo automatizable de la suite de seguridad y resiliencia de AtlanticERP y deja un
# resumen en infra/security-out/<fecha-hora>/ (summary.md + un log por capa). Es lo que corre el agente `security-tester`.
#
# NO TOCA ningún entorno compartido: todo corre contra el stack Docker local y contra el código. No escribe en dev, test ni prod, no usa
# AWS y no empuja nada. NO corrige nada: solo ejecuta y reporta. Sale 0 siempre (un hallazgo no es un fallo de la herramienta), salvo con
# --strict, que sale 1 si alguna capa falla o hay hallazgos altos.
#
# Capas:
#   1 deps    · composer audit (SCRUM-962)                         infra/audit.sh
#   2 sast    · Semgrep con reglas propias (SCRUM-963)             infra/sast.sh
#   3 caos    · suite de resiliencia (SCRUM-965/1030/1031)         infra/test.sh --filter=Chaos
#   4 authz   · pruebas de autorización y de seguridad funcional   infra/test.sh --filter=<ver SECURITY_FILTER>
#   5 matriz  · inventario de autorización por ruta (SCRUM-1040)   infra/security/route-authz-matrix.py
# No incluidas (requieren aprobación de Luis, comandos propios): DAST contra dev (infra/dast.sh, con allowlist de CrowdSec),
# carga (SCRUM-966), restore drill (SCRUM-1029) y las revisiones de AWS de solo lectura.
#
# Uso:  infra/security-suite.sh [--strict] [--skip=deps,sast,caos,authz,matriz]
set -uo pipefail

cd "$(dirname "$0")/.."
STRICT=0; SKIP=""
for a in "$@"; do case "$a" in --strict) STRICT=1;; --skip=*) SKIP="${a#--skip=}";; esac; done
skip() { [[ ",$SKIP," == *",$1,"* ]]; }

STAMP="$(date +%Y%m%d-%H%M%S)"
OUT="infra/security-out/$STAMP"; mkdir -p "$OUT"
SUMMARY="$OUT/summary.md"
FAILED=0
# Pruebas de seguridad funcional: autorización por objeto, límites de intentos, tokens, cabeceras/CORS, archivos, enlaces compartidos, IA.
SECURITY_FILTER='Tests\\Feature\\(Auth|AI|Users|Security)\\|ShareLinkControllerTest|CorsPolicyTest|PhotoUrlSsrfGuardTest|RedisCacheTypesTest|JobRetryPolicyTest|DocumentControllerTest'

{
  echo "# Suite de seguridad — corrida $STAMP"
  echo
  echo "Commit: \`$(git rev-parse --short HEAD 2>/dev/null)\` · rama: \`$(git branch --show-current 2>/dev/null)\` · $(date '+%Y-%m-%d %H:%M')"
  echo
  echo "| Capa | Estado | Resultado |"
  echo "|---|---|---|"
} > "$SUMMARY"

row() { printf '| %s | %s | %s |\n' "$1" "$2" "$3" >> "$SUMMARY"; }
last() { grep -E "$2" "$1" | tail -1 | sed 's/\x1b\[[0-9;]*m//g' | cut -c1-200; }

run_layer() {   # nombre, comando..., log en $OUT/<nombre>.log
  local name="$1"; shift
  local log="$OUT/$name.log" t0=$SECONDS
  echo "==> $name"
  "$@" > "$log" 2>&1; local rc=$?
  echo "    ($((SECONDS - t0)) s, exit $rc)"
  return $rc
}

skip deps || {
  run_layer deps infra/audit.sh; rc=$?
  res="$(last "$OUT/deps.log" '^audit')"; [[ -z "$res" ]] && res="$(tail -1 "$OUT/deps.log")"
  [[ $rc -eq 0 ]] && row "1 · Dependencias (composer audit)" "ejecutada" "$res" || { row "1 · Dependencias (composer audit)" "FALLÓ" "ver $OUT/deps.log"; FAILED=1; }
  if [[ -x ../atlanticerp-frontend/infra/audit.sh ]]; then
    ( cd ../atlanticerp-frontend && infra/audit.sh ) > "$OUT/deps-frontend.log" 2>&1
    row "1 · Dependencias (npm audit, frontend)" "ejecutada" "$(last "$OUT/deps-frontend.log" '^audit')"
  fi
}

skip sast || {
  run_layer sast infra/sast.sh; rc=$?
  res="$(last "$OUT/sast.log" '^sast')"; [[ -z "$res" ]] && res="$(tail -1 "$OUT/sast.log")"
  [[ $rc -eq 0 ]] && row "2 · SAST (Semgrep)" "ejecutada" "$res" || { row "2 · SAST (Semgrep)" "FALLÓ" "ver $OUT/sast.log"; FAILED=1; }
  if [[ -x ../atlanticerp-frontend/infra/sast.sh ]]; then
    ( cd ../atlanticerp-frontend && infra/sast.sh ) > "$OUT/sast-frontend.log" 2>&1
    row "2 · SAST (Semgrep, frontend)" "ejecutada" "$(last "$OUT/sast-frontend.log" '^sast')"
  fi
}

skip caos || {
  run_layer caos infra/test.sh --filter=Chaos; rc=$?
  res="$(last "$OUT/caos.log" '^(OK|Tests:|FAILURES)')"
  [[ $rc -eq 0 ]] && row "3 · Resiliencia / caos" "PASA" "$res" || { row "3 · Resiliencia / caos" "FALLA" "$res"; FAILED=1; }
}

skip authz || {
  run_layer authz infra/test.sh --filter="$SECURITY_FILTER"; rc=$?
  res="$(last "$OUT/authz.log" '^(OK|Tests:|FAILURES)')"
  [[ $rc -eq 0 ]] && row "4 · Autorización y seguridad funcional" "PASA" "$res" || { row "4 · Autorización y seguridad funcional" "FALLA" "$res"; FAILED=1; }
}

skip matriz || {
  if docker compose -f infra/docker-compose.yml exec -T laravel php artisan route:list --json --path=api 2>/dev/null > "$OUT/routes.json" \
     && python3 infra/security/route-authz-matrix.py "$OUT/routes.json" "$OUT/matriz-autorizacion.md" > "$OUT/matriz.log" 2>&1; then
    row "5 · Matriz de autorización por ruta" "ejecutada" "$(tail -1 "$OUT/matriz.log") — detalle en matriz-autorizacion.md"
  else
    row "5 · Matriz de autorización por ruta" "FALLÓ" "ver $OUT/matriz.log"; FAILED=1
  fi
}

{
  echo
  echo "## No incluido en esta corrida (requiere aprobación de Luis)"
  echo "- DAST contra dev: \`DAST_EMAIL=… DAST_PASSWORD=… infra/dast.sh\` (con allowlist temporal de CrowdSec/fail2ban; ver \`docs/security/DAST-BASELINE.md\`)."
  echo "- Carga y capacidad (SCRUM-966) y restore drill (SCRUM-1029): instancia desechable en AWS."
  echo "- Revisión de S3/CloudFront (solo lectura, comandos \`aws\`) y pentest externo (SCRUM-967)."
} >> "$SUMMARY"

echo; cat "$SUMMARY"; echo; echo "Resumen: backend/$SUMMARY"
[[ $STRICT -eq 1 && $FAILED -eq 1 ]] && exit 1
exit 0
