#!/usr/bin/env bash
#
# legacy-sync-heartbeat.sh — SCRUM-1114: latido del sync de ICG, independiente del scheduler de la app.
#
# Por qué existe: `legacy:check-sync-health` corre DENTRO del contenedor `scheduler`; si ese contenedor (o
# Horizon, o el host) está caído, ni la corrida ni su chequeo ocurren y nadie se entera. Este script corre en
# el HOST (cron, cada 30 min), lee de Postgres cuánto hace que corrió por última vez el sync y publica la edad
# en CloudWatch (namespace AtlanticERP). La alarma de CloudWatch usa treat-missing-data=breaching: si el cron, el
# host o la conexión mueren, la falta de datos también dispara el aviso (a `atlanticerp-alerts`, vía SNS).
#
# Métricas (dimensión Environment = APP_ENV):
#   LegacySyncLastRunAgeHours       horas desde la última corrida de `productos` (primera entidad de la cadena),
#                                   con cualquier status: mide "la cadena arrancó", no si salió bien (eso lo
#                                   avisa la app por correo). Sin ninguna corrida: 99999.
#   LegacySyncStockLastRunAgeHours  idem para `stock`; solo se publica si la entidad ya corrió alguna vez
#                                   (para no alarmar antes de activarla).
#   LegacySyncLastSuccessAgeHours   horas desde la última corrida EXITOSA (status success) de `productos`. A
#   LegacySyncStockLastSuccessAgeHours  diferencia de las anteriores, sube si la cadena corre pero falla o se
#                                   retiene cada noche (revisión de seguridad S-05): es la que debe alarmar.
# Solo lectura sobre la BD y solo `cloudwatch:PutMetricData` (el rol de la instancia ya lo tiene, igual que
# ship-wal.sh). Lee únicamente AWS_DEFAULT_REGION y APP_ENV del .env (nunca imprime ni exporta el resto).
#
# Uso (cron del host, como root, cada 30 min):
#   */30 * * * * root /var/www/backend/infra/scripts/legacy-sync-heartbeat.sh >> /var/log/legacy-sync-heartbeat.log 2>&1

set -uo pipefail

ENV_FILE="${ENV_FILE:-/var/www/backend/.env}"
PG_CONTAINER="${PG_CONTAINER:-infra-postgres-1}"
TENANT="${TENANT:-atlantic}"

getenv() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d "\"'"; }
REGION="$(getenv AWS_DEFAULT_REGION)"; APP_ENV="$(getenv APP_ENV)"
if [ -z "$REGION" ]; then
  echo "[legacy-sync-heartbeat] ERROR: falta AWS_DEFAULT_REGION en $ENV_FILE" >&2; exit 1
fi
case "$TENANT" in *[!a-z0-9_]*|"") echo "[legacy-sync-heartbeat] ERROR: TENANT inválido" >&2; exit 1 ;; esac

# Horas desde la última corrida de una entidad ('' si la entidad nunca corrió). $1 viene de una lista fija de este script.
# $2 (opcional) es una condición fija de este script, p. ej. "and status = 'success'".
edad() {
  docker exec "$PG_CONTAINER" sh -c "psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -At -c \"select round(extract(epoch from (now() - max(started_at)))/3600.0, 1) from ${TENANT}_legacy.sync_runs where entidad = '$1' ${2:-}\"" </dev/null 2>/dev/null | head -1
}

productos="$(edad productos)"; productos="${productos:-99999}"
productos_ok="$(edad productos "and status = 'success'")"; productos_ok="${productos_ok:-99999}"
stock="$(edad stock)"
stock_ok="$(edad stock "and status = 'success'")"

datos=("MetricName=LegacySyncLastRunAgeHours,Value=${productos},Unit=None,Dimensions=[{Name=Environment,Value=${APP_ENV:-unknown}}]"
       "MetricName=LegacySyncLastSuccessAgeHours,Value=${productos_ok},Unit=None,Dimensions=[{Name=Environment,Value=${APP_ENV:-unknown}}]")
if [ -n "$stock" ]; then
  datos+=("MetricName=LegacySyncStockLastRunAgeHours,Value=${stock},Unit=None,Dimensions=[{Name=Environment,Value=${APP_ENV:-unknown}}]")
  datos+=("MetricName=LegacySyncStockLastSuccessAgeHours,Value=${stock_ok:-99999},Unit=None,Dimensions=[{Name=Environment,Value=${APP_ENV:-unknown}}]")
fi

aws cloudwatch put-metric-data --region "$REGION" --namespace AtlanticERP --metric-data "${datos[@]}" \
  || { echo "[legacy-sync-heartbeat] $(date -Iseconds) no se pudo publicar la métrica en CloudWatch" >&2; exit 1; }
