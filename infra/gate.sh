#!/usr/bin/env bash
# gate.sh — gate local de atlanticerp-backend: PHPStan nivel 8 + suite completa de PHPUnit, con "recibo".
#
# Contexto (ADR-009): GitHub Actions (plan Free, 2.000 min/mes) se quedó sin cuota en agosto y
# septiembre, y el mismo commit corría su suite completa tres veces (dev, test y master). La
# verificación completa ahora se hace UNA vez, acá, en la máquina local, y deja un recibo por SHA:
#   ~/.atlanticerp-gate/<repo>/<sha>
# El hook de pre-push (.githooks/pre-push) exige ese recibo para empujar a dev, test o master. Las
# promociones (`git push origin origin/dev:test`) empujan el MISMO sha, así que ya lo tienen. En GitHub
# Actions solo master vuelve a correr la suite completa (última barrera antes de producción).
#
# Uso:
#   infra/gate.sh            corre el gate sobre HEAD y deja el recibo (si ya hay uno verde, lo reutiliza)
#   infra/gate.sh --force    lo vuelve a correr aunque ya haya recibo
#   infra/gate.sh --check [sha]  solo comprueba si el sha (HEAD por defecto) está cubierto (exit 0 sí / 1 no):
#                            tiene recibo verde, o desciende de uno y solo cambió documentación
#
# El gate falla si el árbol de trabajo no es el commit: archivos de código modificados o sin seguir.
# Un test que pasa por un archivo que se olvidó de commitear es justo lo que el CI limpio atrapaba.
set -euo pipefail

cd "$(dirname "$0")/.."
# ATLANTICERP_GATE_REPO: nombre del repo para el recibo cuando el gate corre desde un clon/worktree con otro nombre de carpeta.
# COMPOSE_PROJECT_NAME: proyecto de Docker Compose propio (stack aislado, sin tocar el de otra sesión); por defecto "infra".
REPO="${ATLANTICERP_GATE_REPO:-$(basename "$PWD")}"
CONTAINER="${COMPOSE_PROJECT_NAME:-infra}-laravel-1"
SHA="$(git rev-parse HEAD)"
DIR="${ATLANTICERP_GATE_DIR:-$HOME/.atlanticerp-gate}/$REPO"
RECEIPT="$DIR/$SHA"
MODE="${1:-run}"

# ¿Este sha está cubierto? Sí si tiene recibo verde, o si desciende de un commit con recibo verde y lo único que
# cambió desde entonces es documentación o .gitignore (docs/**, *.md, .gitignore: no pueden afectar a los tests): un informe de Pre-QA encima de un commit ya verificado
# no debería costar otra corrida completa del gate.
has_green() { [[ -f "$DIR/$1" ]] && grep -q '^result=green$' "$DIR/$1"; }
docs_only() {
  local changed
  changed="$(git diff --name-only "$1" "$2" 2>/dev/null || true)"
  [[ -n "$changed" ]] && ! echo "$changed" | grep -qvE '^docs/|\.md$|(^|/)\.gitignore$'
}
covered_sha() {
  local sha="$1" a
  has_green "$sha" && return 0
  for a in $(git rev-list --max-count=40 "$sha" 2>/dev/null); do
    if has_green "$a"; then docs_only "$a" "$sha"; return $?; fi
  done
  return 1
}

# Rutas cuyo contenido afecta el resultado de los tests (se ignora el resto: informes, docs, ruido de otras sesiones).
CODE_PATHS=(app routes database config tests resources bootstrap composer.json composer.lock phpunit.xml phpstan.neon .github infra/docker-compose.yml infra/test.sh)

if [[ "$MODE" == "--check" ]]; then
  covered_sha "$(git rev-parse "${2:-HEAD}")"
  exit $?
fi

if [[ "$MODE" != "--force" ]] && covered_sha "$SHA"; then
  if has_green "$SHA"; then
    echo "gate: HEAD ${SHA:0:8} ya tiene recibo verde ($(grep '^date=' "$RECEIPT" | cut -d= -f2)) — nada que correr. Use --force para repetirlo."
  else
    echo "gate: HEAD ${SHA:0:8} solo cambió documentación respecto de un commit con recibo verde — cubierto, nada que correr."
  fi
  exit 0
fi

fail() { echo "gate: ERROR — $1" >&2; exit 1; }

echo "==> [1/4] el árbol de trabajo debe ser el commit ${SHA:0:8}"
if ! git diff --quiet HEAD -- "${CODE_PATHS[@]}"; then
  git diff --stat HEAD -- "${CODE_PATHS[@]}" >&2
  fail "hay cambios de código sin commitear (arriba). Commitee o descarte antes de correr el gate."
fi
UNTRACKED="$(git ls-files --others --exclude-standard -- "${CODE_PATHS[@]}")"
if [[ -n "$UNTRACKED" ]]; then
  echo "$UNTRACKED" >&2
  fail "hay archivos de código sin seguir (arriba): los tests podrían pasar por un archivo que no está en el commit."
fi

echo "==> [2/4] el contenedor 'laravel' debe montar ESTE repositorio (gotcha: otro proyecto comparte el nombre 'infra')"
MOUNT="$(docker inspect "$CONTAINER" --format '{{range .Mounts}}{{.Source}}{{"\n"}}{{end}}' 2>/dev/null | grep -Fx "$PWD" || true)"
[[ -n "$MOUNT" ]] || fail "$CONTAINER no está corriendo o no monta $PWD. Levante el stack de este repo (docker compose -f infra/docker-compose.yml up -d)."

echo "==> [3/4] PHPStan nivel 8"
docker compose -f infra/docker-compose.yml exec -T laravel \
  vendor/bin/phpstan analyse --no-progress --memory-limit=1G < /dev/null

echo "==> [4/4] PHPUnit — suite completa (infra/test.sh)"
START=$SECONDS
TEST_OUT="$(mktemp)"
trap 'rm -f "$TEST_OUT"' EXIT
./infra/test.sh 2>&1 | tee "$TEST_OUT" | tail -n 25
SUMMARY="$(grep -E '^(OK|Tests:)' "$TEST_OUT" | tail -1 || true)"

mkdir -p "$DIR"
{
  echo "result=green"
  echo "repo=$REPO"
  echo "sha=$SHA"
  echo "date=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "phpstan=ok"
  echo "phpunit=${SUMMARY:-ok}"
  echo "seconds=$((SECONDS - START))"
} > "$RECEIPT"
echo "gate: VERDE — recibo en $RECEIPT"

# SCRUM-962 — auditoría de dependencias: informativa, DESPUÉS del recibo y sin afectarlo (nunca falla el gate).
echo "==> [audit] dependencias (informativo, no bloqueante)"
./infra/audit.sh || true

# SCRUM-963 — SAST (Semgrep en Docker, ~20 s): informativo, también DESPUÉS del recibo y sin afectarlo. Se omite solo si no hay Docker.
echo "==> [sast] Semgrep (informativo, no bloqueante)"
./infra/sast.sh || true
