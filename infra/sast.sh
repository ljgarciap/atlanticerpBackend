#!/usr/bin/env bash
# sast.sh — SCRUM-963: análisis estático (SAST) de atlanticerp-backend con Semgrep, en Docker con la versión FIJADA.
#
# NO BLOQUEANTE por diseño (spec docs/specs/scrum-963-sast-semgrep.md): imprime un resumen y sale 0 aunque haya hallazgos, para que
# ni el gate local (recibo) ni el CI de master dependan de él. Pasa a bloqueante solo por decisión de Luis, tras 2 semanas con la
# línea base depurada.
#
# Qué ejecuta:
#   · reglas PROPIAS de AtlanticERP (infra/semgrep/rules): ERROR = flujo (entrada del usuario → SQL/URL), INFO = inventario con contador;
#   · paquetes del registro (p/php, p/owasp-top-ten, p/secrets) como red de seguridad. Si no hay red para bajarlos, corre solo las propias.
#
# Uso:
#   infra/sast.sh            reporte (exit 0 siempre)
#   infra/sast.sh --strict   exit 1 si hay hallazgos ERROR/WARNING, o si algún contador de inventario SUBE respecto de
#                            infra/semgrep/baseline-counts.json
#   infra/sast.sh --test     solo las pruebas de las reglas propias (`semgrep --test`)
#   infra/sast.sh --update-baseline   reescribe baseline-counts.json con los contadores actuales (revisar el diff antes de commitear)
#
# Sin Docker o sin la imagen descargable: avisa y sale 0 (un análisis que no corrió no debe tumbar nada).
set -uo pipefail

cd "$(dirname "$0")/.."
SEMGREP_IMAGE="semgrep/semgrep:1.178.0"
SCAN_PATHS=(app routes config database resources)
REGISTRY_PACKS=(p/php p/owasp-top-ten p/secrets)
BASELINE="infra/semgrep/baseline-counts.json"
MODE="${1:-report}"

command -v docker  >/dev/null 2>&1 || { echo "sast: docker no disponible — análisis omitido."; exit 0; }
docker info >/dev/null 2>&1        || { echo "sast: el daemon de Docker no responde — análisis omitido."; exit 0; }
command -v python3 >/dev/null 2>&1 || { echo "sast: python3 no disponible — análisis omitido."; exit 0; }

# SAST_DOCKER_ARGS: solo para probar el modo sin red (p. ej. SAST_DOCKER_ARGS="--network none")
run_semgrep() { docker run --rm ${SAST_DOCKER_ARGS:-} -v "$PWD":/src:ro -v "$OUT":/out "$SEMGREP_IMAGE" semgrep "$@"; }
OUT="$(mktemp -d)"; trap 'rm -rf "$OUT"' EXIT

if [[ "$MODE" == "--test" ]]; then
  docker run --rm -v "$PWD":/src:ro "$SEMGREP_IMAGE" semgrep --test --metrics=off /src/infra/semgrep/rules
  exit $?
fi

CFG_CUSTOM=(--config /src/infra/semgrep/rules)
CFG_ALL=("${CFG_CUSTOM[@]}"); for p in "${REGISTRY_PACKS[@]}"; do CFG_ALL+=(--config "$p"); done
TARGETS=(); for d in "${SCAN_PATHS[@]}"; do [[ -d "$d" ]] && TARGETS+=("/src/$d"); done
COMMON=(--metrics=off --quiet --json -o /out/result.json --exclude vendor --exclude node_modules --exclude storage --exclude tests --exclude docs --exclude semgrep)

NOTE=""
if ! run_semgrep scan "${CFG_ALL[@]}" "${COMMON[@]}" "${TARGETS[@]}" >/dev/null 2>"$OUT/err.txt" || [[ ! -s "$OUT/result.json" ]]; then
  # lo más probable: sin red para bajar los paquetes del registro → solo las reglas propias (locales)
  NOTE=" (sin paquetes del registro: no hubo red)"
  rm -f "$OUT/result.json"
  run_semgrep scan "${CFG_CUSTOM[@]}" "${COMMON[@]}" "${TARGETS[@]}" >/dev/null 2>>"$OUT/err.txt"
fi
[[ -s "$OUT/result.json" ]] || { echo "sast: Semgrep no produjo resultado ($(head -c 200 "$OUT/err.txt" | tr '\n' ' ')) — análisis omitido."; exit 0; }

REPO="$(basename "$PWD")" MODE="$MODE" BASELINE="$BASELINE" NOTE="$NOTE" python3 - "$OUT/result.json" <<'PY'
import collections, json, os, sys

d = json.load(open(sys.argv[1]))
res = d.get("results", [])
mode, baseline_path = os.environ["MODE"], os.environ["BASELINE"]

def rule(x): return x["check_id"].split(".")[-1]
def sev(x):  return x["extra"]["severity"]

by_sev = collections.Counter(sev(x) for x in res)
by_rule = collections.Counter(rule(x) for x in res)
current_info = {r: n for r, n in by_rule.items() if any(sev(x) == "INFO" and rule(x) == r for x in res)}

if mode == "--update-baseline":
    # solo los contadores de inventario (INFO); ERROR/WARNING nunca se "aceptan" en el baseline
    json.dump(dict(sorted(current_info.items())), open(baseline_path, "w"), indent=2, ensure_ascii=False)
    open(baseline_path, "a").write("\n")
    print("sast: baseline reescrito en %s: %s" % (baseline_path, dict(sorted(current_info.items()))))
    sys.exit(0)

baseline = json.load(open(baseline_path)) if os.path.exists(baseline_path) else {}
lines = ["sast [%s] semgrep%s: %d hallazgos (ERROR %d, WARNING %d, INFO %d); errores de parseo: %d" % (
    os.environ["REPO"], os.environ["NOTE"], len(res), by_sev["ERROR"], by_sev["WARNING"], by_sev["INFO"], len(d.get("errors", [])))]

blocking = [x for x in res if sev(x) in ("ERROR", "WARNING")]
for x in blocking[:20]:
    lines.append("  - [%s] %s  %s:%s" % (sev(x), rule(x), x["path"].replace("/src/", ""), x["start"]["line"]))

increased = []
for r, n in sorted(current_info.items()):
    base = baseline.get(r)
    mark = "" if base is None else (" (baseline %d)" % base if n <= base else " (baseline %d ▲ SUBIÓ)" % base)
    lines.append("  · inventario %s: %d%s" % (r, n, mark))
    if base is not None and n > base:
        increased.append(r)
    if base is None:
        lines.append("    sin baseline para %s: correr `infra/sast.sh --update-baseline` y revisar el diff" % r)

print("\n".join(lines))
summary = os.environ.get("GITHUB_STEP_SUMMARY")
if summary:
    with open(summary, "a") as f:
        f.write("### SAST — Semgrep\n\n```\n" + "\n".join(lines) + "\n```\n")

sys.exit(1 if mode == "--strict" and (blocking or increased) else 0)
PY
