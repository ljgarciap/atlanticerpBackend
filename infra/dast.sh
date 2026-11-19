#!/usr/bin/env bash
# dast.sh — SCRUM-964: DAST pasivo con OWASP ZAP (API autenticada, solo GET) contra el ambiente de desarrollo declarado en DAST_ALLOWED_TARGET.
#
# BAJO DEMANDA, NO BLOQUEANTE por diseño (spec docs/specs/scrum-964-dast-zap-baseline.md): no forma parte del gate ni del CI porque
# golpea un entorno compartido. Imprime un resumen y sale 0 aunque haya alertas.
#
# ANTES DE LA PRIMERA CORRIDA: la IP desde la que corres debe estar en la allowlist de CrowdSec de dev (y en `ignoreip` de fail2ban),
# o el escaneo puede banearla EN TODOS LOS PUERTOS, SSH incluido. Ver docs/runbooks/crowdsec-host-protection.md.
#
# Uso:
#   DAST_EMAIL=… DAST_PASSWORD=… infra/dast.sh            reporte (exit 0 siempre)
#   DAST_EMAIL=… DAST_PASSWORD=… infra/dast.sh --strict   exit 1 si hay alertas de riesgo ALTO no aceptadas o falla la cordura
#   infra/dast.sh --self-test                             prueba el resumen y el modo estricto con un informe sintético (sin red)
#
# Variables: DAST_EMAIL / DAST_PASSWORD (cuenta de PRUEBA, nunca real; solo por entorno de la shell, no se escriben a ningún archivo),
#            DAST_ALLOWED_TARGET (el ÚNICO host escaneable, p. ej. https://dev.<dominio>; lo declara quien opera el ambiente de desarrollo),
#            DAST_TARGET (por defecto DAST_ALLOWED_TARGET; cualquier valor distinto se RECHAZA, así producción y test no se escanean por error),
#            DAST_ALLOW_LOCAL=1 (solo para probar el propio script contra http://localhost:<puerto>; no es flujo normal).
set -uo pipefail

cd "$(dirname "$0")/.."
ZAP_IMAGE="ghcr.io/zaproxy/zaproxy:2.17.0"
ALLOWED="${DAST_ALLOWED_TARGET:-}"; ALLOWED="${ALLOWED%/}"
TARGET="${DAST_TARGET:-$ALLOWED}"
MODE="${1:-report}"
OUTDIR="infra/dast/out"
ACCEPTED="infra/zap/accepted-alerts.txt"
EXCLUDE="infra/zap/exclude.txt"
COMPOSE=(docker compose -f infra/docker-compose.yml)

command -v python3 >/dev/null 2>&1 || { echo "dast: python3 no disponible — omitido."; exit 0; }

# Resumen y veredicto sobre un informe JSON de ZAP. Salida: 0 ok; 1 si MODE=--strict y hay alertas altas no aceptadas o falla la cordura.
summarize() {
  MODE="$MODE" ACCEPTED="$ACCEPTED" PATHS_TOTAL="${2:-0}" python3 - "$1" <<'PY'
import json, os, sys, collections
r = json.load(open(sys.argv[1]))
accepted = set()
for l in open(os.environ["ACCEPTED"]):
    l = l.strip()
    if l and not l.startswith("#"):
        accepted.add(l.split("|")[0].strip())
seen = {}
unauth = 0
for site in r.get("site", []):
    for a in site.get("alerts", []):
        k = (a["pluginid"], a["name"])
        seen[k] = seen.get(k, 0) + len(a.get("instances", []))
        seen.setdefault(("risk",) + k, int(a["riskcode"]))
        # solo 401/419 delatan una autenticación que no llegó; un 403 es el rol sin permiso (esperado con cuentas de bajo nivel)
        unauth += sum(1 for i in a.get("instances", []) if str(i.get("evidence", "")).strip()[:3] in ("401", "419"))
rows = [(int(seen[("risk",) + k]), k, n) for k, n in seen.items() if len(k) == 2 for n in [seen[k]]]
by = collections.Counter()
for risk, k, n in rows: by[risk] += 1
names = {3: "ALTO", 2: "MEDIO", 1: "BAJO", 0: "INFORMATIVO"}
print("== DAST (ZAP pasivo) — alertas distintas por riesgo: " + ", ".join(f"{names[i]} {by[i]}" for i in (3, 2, 1, 0)))
for risk, k, n in sorted(rows, key=lambda x: (-x[0], x[1][1])):
    if risk >= 1:
        print(f"  [{names[risk]}] {k[1]} ({n} instancias){'  · aceptada' if k[0] in accepted else ''}")
bad = [(k, n) for risk, k, n in rows if risk >= 3 and k[0] not in accepted]
# Cordura (trampa de la spec §spike): si casi todo es 4xx, la autenticación no llegó y el informe no vale.
total = int(os.environ.get("PATHS_TOTAL") or 0)
client = unauth
insane = total > 0 and client / total > 0.5
if insane:
    print(f"!! CORDURA: {client} de {total} rutas respondieron 401/419 (>50 %): la autenticación probablemente no llegó; el informe NO es fiable.")
strict = os.environ["MODE"] == "--strict"
if bad: print("!! alertas de riesgo ALTO no aceptadas: " + ", ".join(k[1] for k, n in bad))
sys.exit(1 if strict and (bad or insane) else 0)
PY
}

if [[ "$MODE" == "--self-test" ]]; then
  T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
  python3 - "$T" <<'PY'
import json, sys
d = sys.argv[1]
mk = lambda alerts: {"site": [{"alerts": alerts}]}
al = lambda pid, name, risk, n, ev="": {"pluginid": pid, "name": name, "riskcode": str(risk), "instances": [{"evidence": ev}] * n}
json.dump(mk([al("90001", "Alta sintética", 3, 2), al("10021", "Cabecera faltante", 1, 5)]), open(d + "/high.json", "w"))
json.dump(mk([al("10021", "Cabecera faltante", 1, 5)]), open(d + "/clean.json", "w"))
json.dump(mk([al("100000", "A Client Error response code was returned by the server", 0, 90, "401")]), open(d + "/all4xx.json", "w"))
PY
  fail=0
  check() { local want="$1" label="$2"; shift 2; "$@" >/dev/null 2>&1; local got=$?; [[ "$got" == "$want" ]] && echo "ok   $label" || { echo "FALLA $label (esperado $want, obtenido $got)"; fail=1; }; }
  MODE=--strict; check 1 "alerta alta sintética → --strict sale 1" summarize "$T/high.json" 100
  check 0 "informe limpio → --strict sale 0" summarize "$T/clean.json" 100
  check 1 "100 % 401 → cordura, --strict sale 1" summarize "$T/all4xx.json" 100
  MODE=report;   check 0 "alerta alta sintética en modo reporte → exit 0" summarize "$T/high.json" 100
  # rechazo de hosts
  for h in https://prod.example.test https://test.example.test https://www.example.test http://dev.example.test.evil.com; do
    DAST_ALLOWED_TARGET=https://dev.example.test DAST_TARGET="$h" DAST_EMAIL=x DAST_PASSWORD=x bash "$0" >/dev/null 2>&1; [[ $? == 2 ]] && echo "ok   rechaza $h" || { echo "FALLA no rechaza $h"; fail=1; }
  done
  exit "$fail"
fi

# ── lista cerrada de objetivos ────────────────────────────────────────────────────────────────────────────────────────────────
HOST_FOR_ZAP="$TARGET"; ADD_HOST=()
if [[ -n "$ALLOWED" && "$ALLOWED" =~ ^https://[A-Za-z0-9.-]+$ && "${TARGET%/}" == "$ALLOWED" ]]; then
  TARGET="$ALLOWED"; HOST_FOR_ZAP="$TARGET"
elif [[ "${DAST_ALLOW_LOCAL:-}" == "1" && "$TARGET" =~ ^http://localhost:[0-9]+$ ]]; then
  HOST_FOR_ZAP="${TARGET/localhost/host.docker.internal}"; ADD_HOST=(--add-host host.docker.internal:host-gateway)
else
  echo "dast: objetivo rechazado: '$TARGET'. Solo el host declarado en DAST_ALLOWED_TARGET (${ALLOWED:-sin definir}); producción y test NO se escanean con este script."; exit 2
fi

command -v docker >/dev/null 2>&1 && docker info >/dev/null 2>&1 || { echo "dast: Docker no disponible — omitido."; exit 0; }
command -v curl   >/dev/null 2>&1 || { echo "dast: curl no disponible — omitido."; exit 0; }
[[ -n "${DAST_EMAIL:-}" && -n "${DAST_PASSWORD:-}" ]] || { echo "dast: faltan DAST_EMAIL/DAST_PASSWORD (cuenta de PRUEBA) — omitido."; exit 0; }
curl -fsS -m 15 -o /dev/null "$TARGET/up" 2>/dev/null || { echo "dast: $TARGET no responde — omitido."; exit 0; }
"${COMPOSE[@]}" exec -T laravel true </dev/null >/dev/null 2>&1 || { echo "dast: el stack local (contenedor laravel) no está levantado; hace falta para listar las rutas — omitido."; exit 0; }

mkdir -p "$OUTDIR"; chmod 777 "$OUTDIR"; rm -f "$OUTDIR"/report.* "$OUTDIR"/openapi-get.json

# ── 1. OpenAPI solo-GET desde las rutas reales, sin las exclusiones ─────────────────────────────────────────────────────────
ROUTES="$("${COMPOSE[@]}" exec -T laravel php artisan route:list --json </dev/null 2>/dev/null)"
PATHS_TOTAL="$(EXCLUDE="$EXCLUDE" SERVER="$HOST_FOR_ZAP" OUT="$OUTDIR/openapi-get.json" python3 -c '
import json, os, re, sys
rx = [re.compile(l.strip()) for l in open(os.environ["EXCLUDE"]) if l.strip() and not l.startswith("#")]
paths = {}
for x in json.loads(sys.stdin.read()):
    u = x["uri"]
    if not u.startswith("api/") or "GET" not in x["method"].split("|"): continue
    p = "/" + u
    if any(r.search(p) for r in rx): continue
    ps = re.findall(r"\{([^}?]+)\??\}", p)
    p = re.sub(r"\{([^}?]+)\??\}", r"{\1}", p)
    paths[p] = {"get": {"responses": {"200": {"description": "ok"}},
        "parameters": [{"name": n, "in": "path", "required": True, "schema": {"type": "string"}, "example": "1"} for n in ps]}}
json.dump({"openapi": "3.0.0", "info": {"title": "atlanticerp-routes-get", "version": "0"}, "servers": [{"url": os.environ["SERVER"]}], "paths": paths}, open(os.environ["OUT"], "w"))
print(len(paths))' <<<"$ROUTES")"
[[ "${PATHS_TOTAL:-0}" -gt 0 ]] || { echo "dast: no se pudo generar la lista de rutas — omitido."; exit 0; }

# ── 2. UN login y pre-chequeo de que el token funciona ──────────────────────────────────────────────────────────────────────
TOKEN="$(curl -fsS -m 20 -X POST "$TARGET/api/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
  -d "$(python3 -c 'import json,os;print(json.dumps({"email":os.environ["DAST_EMAIL"],"password":os.environ["DAST_PASSWORD"]}))')" 2>/dev/null \
  | python3 -c 'import sys,json;print(json.load(sys.stdin).get("token",""))' 2>/dev/null)"
[[ -n "$TOKEN" ]] || { echo "dast: el login de la cuenta de prueba falló (¿MFA activo, cuenta inexistente o IP bloqueada?) — omitido."; exit 0; }
code="$(curl -s -m 15 -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' "$TARGET/api/auth/me")"
[[ "$code" == "200" ]] || { echo "dast: el token no pasa /api/auth/me (HTTP $code) — omitido."; exit 0; }

# ── 3. ZAP pasivo (-S): dos replacers (Authorization y Accept). Clave `matchstr`, valor con espacio entre comillas (spec §spike) ──
REP='-config replacer.full_list(%d).description=%s -config replacer.full_list(%d).enabled=true -config replacer.full_list(%d).matchtype=REQ_HEADER -config replacer.full_list(%d).matchstr=%s -config replacer.full_list(%d).regex=false -config "replacer.full_list(%d).replacement=%s"'
Z="-config connection.defaultUserAgent=atlanticerp-dast-zap -config scanner.threadPerHost=2 -config connection.timeoutInSecs=30"
Z="$Z $(printf -- "$REP" 0 auth 0 0 0 Authorization 0 0 "Bearer $TOKEN") $(printf -- "$REP" 1 accept 1 1 1 Accept 1 1 "application/json")"
echo "dast: objetivo $TARGET · $PATHS_TOTAL rutas GET · imagen $ZAP_IMAGE (esto tarda unos minutos)…"
docker run --rm ${ADD_HOST[@]+"${ADD_HOST[@]}"} -v "$PWD/$OUTDIR":/zap/wrk:rw "$ZAP_IMAGE" zap-api-scan.py \
  -t /zap/wrk/openapi-get.json -f openapi -S -I -J report.json -r report.html -z "$Z" >"$OUTDIR/zap.log" 2>&1
[[ -s "$OUTDIR/report.json" ]] || { echo "dast: ZAP no produjo informe (ver $OUTDIR/zap.log) — omitido."; exit 0; }

# El token jamás debe quedar en los artefactos: se enmascara y, si aun así aparece, se avisa.
python3 - "$TOKEN" "$OUTDIR" <<'PY'
import sys, pathlib
tok, d = sys.argv[1], pathlib.Path(sys.argv[2])
for f in ("report.json", "report.html", "zap.log"):
    p = d / f
    if p.exists():
        s = p.read_text(errors="replace")
        if tok in s: p.write_text(s.replace(tok, "***TOKEN***"))
PY
rm -f "$OUTDIR/openapi-get.json"
unset TOKEN

summarize "$OUTDIR/report.json" "$PATHS_TOTAL"
rc=$?
echo "Informes: $OUTDIR/report.html y report.json (no se versionan)."
exit "$rc"
