#!/usr/bin/env bash
#
# basebackup-postgres.sh — SCRUM-1073 (HA F2): copia base fisica de Postgres (punto de partida de la recuperación a un
# instante). Con los segmentos de WAL de ship-wal.sh permite restaurar a cualquier momento posterior a esta copia.
# Cron semanal en el host como root (ver setup-wal-archiving.sh). Lee solo AWS_BUCKET y AWS_DEFAULT_REGION del .env.
# La retención la da la lifecycle rule del bucket sobre postgres-backups/ (dev/test 30 días, prod 90): la copia base y
# su WAL caducan juntos, así que la ventana de recuperación a un instante es la retención menos una semana de margen.

set -euo pipefail

ENV_FILE="${ENV_FILE:-/var/www/backend/.env}"
PG_CONTAINER="${PG_CONTAINER:-infra-postgres-1}"
S3_PREFIX="${S3_PREFIX:-postgres-backups/basebackup}"
TMP_DIR="${TMP_DIR:-/var/backups/atlanticerp}"

getenv() { grep -E "^$1=" "$ENV_FILE" | head -1 | cut -d= -f2- | tr -d "\"'"; }
BUCKET="$(getenv AWS_BUCKET)"; REGION="$(getenv AWS_DEFAULT_REGION)"
[ -n "$BUCKET" ] && [ -n "$REGION" ] || { echo "[basebackup] ERROR: faltan variables en $ENV_FILE" >&2; exit 1; }

mkdir -p "$TMP_DIR"
OUT="${TMP_DIR}/base_$(date -u +%Y%m%dT%H%M%SZ).tar.gz"
echo "[basebackup] $(date -Iseconds) iniciando"
docker exec "$PG_CONTAINER" sh -c 'pg_basebackup -U "$POSTGRES_USER" -D - -F tar -X fetch -z' </dev/null > "$OUT"
[ -s "$OUT" ] || { echo "[basebackup] ERROR: copia vacia" >&2; rm -f "$OUT"; exit 1; }
echo "[basebackup] generada $(du -h "$OUT" | cut -f1); subiendo"
aws s3 cp "$OUT" "s3://${BUCKET}/${S3_PREFIX}/$(basename "$OUT")" --region "$REGION" --only-show-errors
rm -f "$OUT"
echo "[basebackup] $(date -Iseconds) OK"
