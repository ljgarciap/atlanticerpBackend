#!/usr/bin/env bash
#
# backup-audit-logs-immutable.sh
#
# SCRUM-815 (refuerzo de REQ-634, Epic SCRUM-788 — Logs y Telemetría) — dump diario de SOLO la
# tabla audit_logs (de TODOS los schemas de tenant, vía "pg_dump -t '*.audit_logs'") a un bucket
# S3 separado con Object Lock (modo Governance, retención 90 días configurada como default en el
# bucket — ver `aws s3api put-object-lock-configuration` corrido una sola vez al crearlo, no acá).
#
# Por qué un canal aparte del pg_dump general (backup-postgres.sh) y del archivado de REQ-636
# (comando `audit-logs:archive`): ninguno de los dos es inmutable — son objetos S3 normales,
# borrables por cualquiera con permiso s3:DeleteObject. Si alguien con acceso superuser a Postgres
# lograra alterar audit_logs, este dump sobrevive hasta que expire su lock, incluso para un
# superuser de la cuenta AWS — mientras nadie tenga el permiso de bypass (s3:BypassGovernanceRetention),
# que nunca se le da al usuario de servicio de la app (ver policy IAM
# atlanticerp-prod-audit-immutable-write-policy: solo s3:PutObject sobre este bucket, nunca
# s3:DeleteObject ni el permiso de bypass).
#
# Solo prod — dev/test no tienen datos que no estén también en la app real de esos entornos
# (mismo criterio ya aplicado a los backups EBS/AWS Backup desde 2026-08-31).
#
# Nota de diseño: mismo patrón que backup-postgres.sh — postgres corre en un contenedor Docker
# sin puerto publicado al host, así que el dump se hace con "docker compose exec" dentro del
# propio contenedor, no con un pg_dump instalado en el host EC2.
#
# Variables de entorno requeridas (mismo mecanismo que backup-postgres.sh — exportadas en el
# entorno donde corre el cron):
#   DB_DATABASE                - nombre de la base de datos
#   DB_USERNAME                - usuario de PostgreSQL
#   AWS_AUDIT_IMMUTABLE_BUCKET - bucket de S3 destino (atlanticerp-prod-audit-immutable)
#   AWS_DEFAULT_REGION         - región de AWS
#
# Variables opcionales:
#   COMPOSE_FILE   - archivo docker-compose a usar (default infra/docker-compose.prod.yml)
#   BACKUP_DIR     - carpeta local temporal de backups (default /var/backups/atlanticerp-audit)
#   S3_PREFIX      - prefijo/carpeta dentro del bucket (default "audit-logs-immutable")
#   RETENTION_DAYS - días de object-lock-retain-until-date puesto EXPLÍCITO por objeto (default
#                    90) — además del default retention ya configurado en el bucket, para que la
#                    retención real quede aplicada aunque algún día cambie el default del bucket
#                    sin que alguien actualice este script (belt-and-suspenders).
#
# Requiere: docker compose (el contenedor "postgres" debe estar corriendo), gzip, aws cli
# configurado con credenciales con permiso s3:PutObject sobre el bucket — NUNCA s3:DeleteObject ni
# s3:BypassGovernanceRetention (ver policy IAM del bucket).
#
# Retención: a diferencia de backup-postgres.sh, este script NUNCA borra el objeto subido a S3 (ni
# siquiera lo intenta — el IAM ya se lo impide). El Object Lock del bucket es la única forma real
# de que un dump viejo desaparezca, una vez expirado su retain-until-date, vía una lifecycle rule
# de expiración configurada aparte en el bucket (no acá).
#
# Programación recomendada vía cron, mismo host que backup-postgres.sh, horario distinto para no
# competir por I/O del contenedor postgres con el dump general:
#   30 3 * * * /path/backup-audit-logs-immutable.sh >> /var/log/atlanticerp-backup-audit.log 2>&1

set -euo pipefail

# --- Validación de variables requeridas -------------------------------------
required_vars=(DB_DATABASE DB_USERNAME AWS_AUDIT_IMMUTABLE_BUCKET AWS_DEFAULT_REGION)
for var in "${required_vars[@]}"; do
  if [ -z "${!var:-}" ]; then
    echo "[backup-audit-logs-immutable] ERROR: falta la variable de entorno requerida: $var" >&2
    exit 1
  fi
done

# Directorio del repo (infra/scripts/.. = raíz del backend)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

COMPOSE_FILE="${COMPOSE_FILE:-${REPO_ROOT}/infra/docker-compose.prod.yml}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/atlanticerp-audit}"
S3_PREFIX="${S3_PREFIX:-audit-logs-immutable}"
RETENTION_DAYS="${RETENTION_DAYS:-90}"

TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
FILENAME="atlanticerp_audit_logs_${TIMESTAMP}.sql.gz"
LOCAL_PATH="${BACKUP_DIR}/${FILENAME}"
S3_KEY="${S3_PREFIX}/${FILENAME}"
RETAIN_UNTIL="$(date -u -d "+${RETENTION_DAYS} days" +%Y-%m-%dT%H:%M:%S)"

mkdir -p "$BACKUP_DIR"

echo "[backup-audit-logs-immutable] $(date -Iseconds) Iniciando dump de audit_logs (todos los schemas de tenant)..."

# -t '*.audit_logs' — pg_dump matchea el patrón contra la tabla audit_logs de CUALQUIER schema, no
# hace falta enumerar tenants a mano (un tenant nuevo queda cubierto automáticamente).
docker compose -f "${COMPOSE_FILE}" exec -T postgres \
  pg_dump --username="${DB_USERNAME}" --format=plain -t '*.audit_logs' "${DB_DATABASE}" \
  | gzip > "${LOCAL_PATH}"

echo "[backup-audit-logs-immutable] Dump generado: ${LOCAL_PATH} ($(du -h "${LOCAL_PATH}" | cut -f1))"

# --- Subida a S3 con Object Lock explícito por objeto -------------------------
# aws s3api put-object (no "aws s3 cp") — es el único subcomando que acepta los flags de
# object-lock por objeto.
echo "[backup-audit-logs-immutable] Subiendo a s3://${AWS_AUDIT_IMMUTABLE_BUCKET}/${S3_KEY} (Object Lock Governance, retain-until ${RETAIN_UNTIL})..."
aws s3api put-object \
  --bucket "${AWS_AUDIT_IMMUTABLE_BUCKET}" \
  --key "${S3_KEY}" \
  --body "${LOCAL_PATH}" \
  --object-lock-mode GOVERNANCE \
  --object-lock-retain-until-date "${RETAIN_UNTIL}" \
  --region "${AWS_DEFAULT_REGION}" \
  > /dev/null

echo "[backup-audit-logs-immutable] Subida completada."

# --- Limpieza local ------------------------------------------------------------
rm -f "${LOCAL_PATH}"

echo "[backup-audit-logs-immutable] $(date -Iseconds) Backup local eliminado. Proceso finalizado OK."
