#!/usr/bin/env bash
# redis-down.sh — SCRUM-965: caos REAL, manual y SOLO local. Detiene el contenedor Redis del stack unos segundos, comprueba qué
# responde la app y lo reinicia. Es el complemento de la suite PHPUnit (tests/Feature/Chaos), que simula la caída por
# configuración SIN tocar contenedores: este script prueba lo que la simulación no puede (Horizon, reconexión, health reales).
#
# NUNCA dentro de PHPUnit ni del gate: el Redis y el Postgres locales los comparten otras sesiones de trabajo.
#
# Uso:
#   infra/chaos/redis-down.sh [--seconds N] [--yes]
#   CHAOS_EMAIL=... CHAOS_PASSWORD=... infra/chaos/redis-down.sh   # además prueba login y refresh (usar un usuario de PRUEBA)
#
# Salvaguardas: solo corre si el contenedor `laravel` monta ESTE repositorio; se niega si hay `phpunit`/`artisan test` activos;
# pide confirmación (salvo --yes); un `trap` garantiza que Redis se reinicia aunque el script se interrumpa.
set -uo pipefail

cd "$(dirname "$0")/../.."
SECONDS_DOWN=15
ASSUME_YES=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --seconds) SECONDS_DOWN="${2:?falta el valor de --seconds}"; shift 2 ;;
    --yes)     ASSUME_YES=1; shift ;;
    *) echo "uso: $0 [--seconds N] [--yes]" >&2; exit 2 ;;
  esac
done
PORT="${NGINX_PORT:-8090}"
BASE="http://localhost:${PORT}"

fail() { echo "chaos: ERROR — $1" >&2; exit 1; }

MOUNT="$(docker inspect infra-laravel-1 --format '{{range .Mounts}}{{.Source}}{{"\n"}}{{end}}' 2>/dev/null | grep -Fx "$PWD" || true)"
[[ -n "$MOUNT" ]] || fail "infra-laravel-1 no está corriendo o no monta $PWD (otro proyecto comparte el nombre 'infra')."
docker inspect infra-redis-1 >/dev/null 2>&1 || fail "no existe el contenedor infra-redis-1."
if docker top infra-laravel-1 2>/dev/null | grep -qE 'phpunit|artisan test'; then
  fail "hay tests corriendo en infra-laravel-1: detener Redis ahora los rompería. Reintentar cuando terminen."
fi

if [[ "$ASSUME_YES" -ne 1 ]]; then
  read -r -p "Esto DETIENE Redis local ${SECONDS_DOWN}s (lo comparten otras sesiones). ¿Continuar? [y/N] " ans
  [[ "$ans" == "y" || "$ans" == "Y" ]] || { echo "cancelado."; exit 0; }
fi

code() { curl -s -o /dev/null -w '%{http_code}' --max-time 20 "$@" 2>/dev/null || echo "---"; }
restore() {
  if [[ "$(docker inspect -f '{{.State.Running}}' infra-redis-1 2>/dev/null)" != "true" ]]; then
    echo "chaos: reiniciando Redis…"; docker start infra-redis-1 >/dev/null
  fi
}
trap restore EXIT INT TERM

TOKEN="" ; REFRESH=""
if [[ -n "${CHAOS_EMAIL:-}" && -n "${CHAOS_PASSWORD:-}" ]]; then
  RESP="$(curl -s --max-time 20 -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' \
          -d "{\"email\":\"$CHAOS_EMAIL\",\"password\":\"$CHAOS_PASSWORD\"}")"
  TOKEN="$(printf '%s' "$RESP" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("token",""))' 2>/dev/null)"
  REFRESH="$(printf '%s' "$RESP" | python3 -c 'import sys,json;print(json.load(sys.stdin).get("refresh_token",""))' 2>/dev/null)"
  [[ -n "$TOKEN" ]] && echo "chaos: sesión previa abierta con $CHAOS_EMAIL" || echo "chaos: no se pudo abrir sesión previa (se omiten las pruebas autenticadas)"
fi

echo "== ANTES      /up=$(code "$BASE/up")  /api/health=$(code "$BASE/api/health")"
docker stop infra-redis-1 >/dev/null || fail "no se pudo detener Redis."
echo "== REDIS DETENIDO (${SECONDS_DOWN}s)"
sleep 2
echo "   /up=$(code "$BASE/up")  /api/health=$(code "$BASE/api/health")   (200 = el health check NO detecta la caída, hallazgo R-05)"
if [[ -n "$TOKEN" ]]; then
  echo "   login=$(code -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"email\":\"$CHAOS_EMAIL\",\"password\":\"$CHAOS_PASSWORD\"}")   (500 = A-01)"
  echo "   refresh=$(code -X POST "$BASE/api/auth/refresh" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"refresh_token\":\"$REFRESH\"}")   (500 = A-01)"
  echo "   sesión previa /api/auth/me=$(code -H "Authorization: Bearer $TOKEN" -H 'Accept: application/json' "$BASE/api/auth/me")   (200 = las sesiones abiertas sobreviven)"
fi
sleep "$(( SECONDS_DOWN > 2 ? SECONDS_DOWN - 2 : 0 ))"

docker start infra-redis-1 >/dev/null
echo "== REDIS REINICIADO"
for _ in $(seq 1 30); do
  [[ "$(docker inspect -f '{{.State.Health.Status}}' infra-redis-1 2>/dev/null)" == "healthy" ]] && break
  sleep 1
done
echo "   redis=$(docker inspect -f '{{.State.Health.Status}}' infra-redis-1 2>/dev/null)"
[[ -n "$TOKEN" ]] && echo "   login tras recuperar=$(code -X POST "$BASE/api/auth/login" -H 'Content-Type: application/json' -H 'Accept: application/json' -d "{\"email\":\"$CHAOS_EMAIL\",\"password\":\"$CHAOS_PASSWORD\"}")   (200 = se recupera sola)"
HZ="$(docker compose -f infra/docker-compose.yml exec -T laravel php artisan horizon:status < /dev/null 2>&1 | tr -s ' \n' ' ')"
echo "   horizon:${HZ}"
echo "chaos: listo."
