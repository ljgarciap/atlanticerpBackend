#!/usr/bin/env bash
# audit.sh — SCRUM-962: auditoría de dependencias de atlanticerp-backend (`composer audit --locked`).
#
# NO BLOQUEANTE por diseño (spec docs/specs/scrum-962-dependency-scanning.md): imprime un resumen y
# sale 0 aunque haya advisories, para que ni el gate local (recibo) ni el CI de master dependan de él.
# Pasa a bloqueante solo por decisión de Luis, tras 2 semanas con la línea base depurada.
#
# Uso:
#   infra/audit.sh            reporte (exit 0 siempre)
#   infra/audit.sh --strict   exit 1 si hay advisories de severidad alta o crítica (lo usa el workflow semanal)
#
# Sin red / sin composer: avisa y sale 0 — un audit que no corrió no debe tumbar nada.
# Si $GITHUB_STEP_SUMMARY existe (Actions), el resumen también se escribe ahí.
set -uo pipefail

cd "$(dirname "$0")/.."
STRICT=0
[[ "${1:-}" == "--strict" ]] && STRICT=1

command -v composer >/dev/null 2>&1 || { echo "audit: composer no disponible — auditoría omitida."; exit 0; }
command -v python3  >/dev/null 2>&1 || { echo "audit: python3 no disponible — auditoría omitida."; exit 0; }

# composer puede imprimir un warning (p. ej. de memory_limit) antes del JSON: se descarta todo lo previo a '{'.
RAW="$(composer audit --locked --format=json --no-interaction 2>/dev/null || true)"
JSON="$(printf '%s\n' "$RAW" | sed -n '/^{/,$p')"
[[ -n "$JSON" ]] || { echo "audit: composer audit no devolvió resultado (¿sin red?) — auditoría omitida."; exit 0; }

printf '%s' "$JSON" | STRICT="$STRICT" REPO="$(basename "$PWD")" python3 -c '
import json, os, sys
try:
    d = json.load(sys.stdin)
except ValueError:
    print("audit: respuesta de composer audit ilegible — auditoría omitida."); sys.exit(0)

adv = d.get("advisories") or {}
if isinstance(adv, list):          # composer devuelve [] cuando no hay ninguno
    adv = {}
sev = {"critical": 0, "high": 0, "medium": 0, "low": 0}
per_pkg = {}
for pkg, items in adv.items():
    for a in items:
        s = (a.get("severity") or "low").lower()
        sev[s] = sev.get(s, 0) + 1
        per_pkg.setdefault(pkg, {}).setdefault(s, 0)
        per_pkg[pkg][s] += 1
total = sum(sev.values())
aband = d.get("abandoned") or {}
if isinstance(aband, list):
    aband = {}

lines = ["audit [{}] composer: {} advisories (críticas {}, altas {}, medias {}, bajas {}); paquetes abandonados: {}".format(
    os.environ["REPO"], total, sev["critical"], sev["high"], sev["medium"], sev["low"], len(aband))]
for pkg, c in sorted(per_pkg.items()):
    lines.append("  - {}: {}".format(pkg, ", ".join("{} {}".format(n, s) for s, n in c.items())))
for pkg in sorted(aband):
    lines.append("  - abandonado: {}".format(pkg))
print("\n".join(lines))

summary = os.environ.get("GITHUB_STEP_SUMMARY")
if summary:
    with open(summary, "a") as f:
        f.write("### Auditoría de dependencias — composer\n\n```\n" + "\n".join(lines) + "\n```\n")

sys.exit(1 if os.environ["STRICT"] == "1" and (sev["critical"] or sev["high"]) else 0)
'
