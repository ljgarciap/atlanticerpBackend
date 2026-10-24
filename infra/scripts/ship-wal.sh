#!/usr/bin/env bash
#
# ship-wal.sh — SCRUM-1073 (HA F2): sube a S3 los segmentos de WAL que Postgres deja en /data/wal-archive.
#
# Postgres (contenedor) comprime cada segmento terminado en /wal-archive (= /data/wal-archive en el host) con su
# archive_command; este script, que corre en el host como root cada minuto (cron.d, ver setup-wal-archiving.sh),
# los sube a s3://<bucket>/postgres-backups/wal/ y los borra del disco SOLO si la subida fue bien. El rol de la
# instancia solo puede escribir (s3:PutObject) en postgres-backups/*: nunca lee ni borra respaldos.
# Publica en CloudWatch (namespace AtlanticERP): WalArchivePending (segmentos esperando) y WalArchiveFailing (1 si
# pg_stat_archiver reporta que el último intento falló); las alarmas avisan si se atasca (el disco se llenaría).
# Lee solo AWS_BUCKET, AWS_DEFAULT_REGION y APP_ENV del .env (nunca imprime ni exporta el resto).

set -uo pipefail

ENV_FILE="${ENV_FILE:-/var/www/backend/.env}"
ARCHIVE_DIR="${ARCHIVE_DIR:-/data/wal-archive}"
S3_PREFIX="${S3_PREFIX:-postgres-backups/wal}"
PG_CONTAINER="${PG_CONTAINER:-infra-postgres-1}"

getenv() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d "\"'"; }
BUCKET="$(getenv AWS_BUCKET)"; REGION="$(getenv AWS_DEFAULT_REGION)"; APP_ENV="$(getenv APP_ENV)"
if [ -z "$BUCKET" ] || [ -z "$REGION" ]; then
  echo "[ship-wal] ERROR: falta AWS_BUCKET o AWS_DEFAULT_REGION en $ENV_FILE" >&2; exit 1
fi

exec 9>"/var/lock/ship-wal.lock"; flock -n 9 || exit 0   # una sola instancia a la vez

shopt -s nullglob
fails=0
for f in "$ARCHIVE_DIR"/*.gz; do
  b="$(basename "$f")"
  if aws s3 cp "$f" "s3://${BUCKET}/${S3_PREFIX}/${b}" --region "$REGION" --only-show-errors; then
    rm -f "$f"
  else
    fails=$((fails + 1)); echo "[ship-wal] $(date -Iseconds) fallo al subir $b" >&2
  fi
done

pending=$(find "$ARCHIVE_DIR" -maxdepth 1 -name '*.gz' | wc -l | tr -d ' ')
failing=$(docker exec "$PG_CONTAINER" sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -c "select (last_failed_time is not null and (last_archived_time is null or last_failed_time > last_archived_time))::int from pg_stat_archiver"' </dev/null 2>/dev/null | head -1)
failing="${failing:-1}"

aws cloudwatch put-metric-data --region "$REGION" --namespace AtlanticERP \
  --metric-data "MetricName=WalArchivePending,Value=${pending},Unit=Count,Dimensions=[{Name=Environment,Value=${APP_ENV:-unknown}}]" \
              "MetricName=WalArchiveFailing,Value=${failing},Unit=Count,Dimensions=[{Name=Environment,Value=${APP_ENV:-unknown}}]" \
  || echo "[ship-wal] $(date -Iseconds) no se pudo publicar la metrica en CloudWatch" >&2

[ "$fails" -eq 0 ]
