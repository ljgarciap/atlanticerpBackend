#!/usr/bin/env bash
#
# backup-postgres.sh
#
# Hace un dump de la base de datos PostgreSQL de AtlanticERP, lo comprime y lo sube
# a S3. Pensado para ejecutarse vía cron diario en el host EC2 (QA/Prod).
#
# Nota de diseño: postgres corre en un contenedor Docker (servicio "postgres"
# de infra/docker-compose.qa.yml o infra/docker-compose.prod.yml) y, siguiendo
# la convención del proyecto, NO publica su puerto al host. Por eso el dump no
# se hace con un pg_dump instalado en el host EC2 contra DB_HOST, sino con
# "docker compose exec" para correr pg_dump dentro del propio contenedor.
#
# Variables de entorno requeridas (deben estar exportadas en el entorno donde
# corre el cron, por ejemplo cargadas desde el .env del proyecto o desde
# /etc/environment):
#   DB_DATABASE        - nombre de la base de datos
#   DB_USERNAME        - usuario de PostgreSQL
#   AWS_BUCKET         - bucket de S3 destino
#   AWS_DEFAULT_REGION - región de AWS (usada por el AWS CLI)
#
# Variables opcionales:
#   COMPOSE_FILE  - archivo docker-compose a usar (default infra/docker-compose.prod.yml,
#                   en QA usar infra/docker-compose.qa.yml)
#   BACKUP_DIR    - carpeta local temporal de backups (default /var/backups/atlanticerp)
#   S3_PREFIX     - prefijo/carpeta dentro del bucket (default "postgres-backups")
#
# Requiere: docker compose (el contenedor "postgres" debe estar corriendo),
# gzip, aws cli configurado con credenciales con permiso de escritura sobre
# el bucket (vía IAM role de la instancia EC2 es lo recomendado, sin
# necesidad de access keys en el host).
#
# Retención: este script NO borra backups antiguos en S3. La retención de
# 30 días se gestiona con una lifecycle rule de S3 sobre el prefijo
# "${S3_PREFIX}/", configurada una sola vez en el bucket (no se crea aquí):
#   aws s3api put-bucket-lifecycle-configuration --bucket "$AWS_BUCKET" \
#     --lifecycle-configuration '{
#       "Rules": [{
#         "ID": "atlanticerp-postgres-backups-retention-30d",
#         "Filter": {"Prefix": "postgres-backups/"},
#         "Status": "Enabled",
#         "Expiration": {"Days": 30}
#       }]
#     }'
#
# Programación recomendada vía cron (ver Paso 16 de docs/aws-ec2-setup-walkthrough.md):
#   0 3 * * * /path/backup-postgres.sh >> /var/log/atlanticerp-backup.log 2>&1

set -euo pipefail

# --- Validación de variables requeridas -------------------------------------
required_vars=(DB_DATABASE DB_USERNAME AWS_BUCKET AWS_DEFAULT_REGION)
for var in "${required_vars[@]}"; do
  if [ -z "${!var:-}" ]; then
    echo "[backup-postgres] ERROR: falta la variable de entorno requerida: $var" >&2
    exit 1
  fi
done

# Directorio del repo (infra/scripts/.. = raíz del backend)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "${SCRIPT_DIR}/../.." && pwd)"

COMPOSE_FILE="${COMPOSE_FILE:-${REPO_ROOT}/infra/docker-compose.prod.yml}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/atlanticerp}"
S3_PREFIX="${S3_PREFIX:-postgres-backups}"

TIMESTAMP="$(date +%Y%m%d_%H%M%S)"
FILENAME="atlanticerp_${DB_DATABASE}_${TIMESTAMP}.sql.gz"
LOCAL_PATH="${BACKUP_DIR}/${FILENAME}"
S3_PATH="s3://${AWS_BUCKET}/${S3_PREFIX}/${FILENAME}"

mkdir -p "$BACKUP_DIR"

echo "[backup-postgres] $(date -Iseconds) Iniciando backup de '${DB_DATABASE}'..."

# --- Dump (dentro del contenedor postgres) + compresión ----------------------
docker compose -f "${COMPOSE_FILE}" exec -T postgres \
  pg_dump --username="${DB_USERNAME}" --format=plain "${DB_DATABASE}" \
  | gzip > "${LOCAL_PATH}"

echo "[backup-postgres] Dump generado: ${LOCAL_PATH} ($(du -h "${LOCAL_PATH}" | cut -f1))"

# --- Subida a S3 --------------------------------------------------------------
echo "[backup-postgres] Subiendo a ${S3_PATH}..."
aws s3 cp "${LOCAL_PATH}" "${S3_PATH}" --region "${AWS_DEFAULT_REGION}"

echo "[backup-postgres] Subida completada."

# --- Limpieza local ------------------------------------------------------------
# Se borra el backup local inmediatamente después de subirlo: la retención de
# 30 días vive en S3 (lifecycle rule), no en el disco del EC2.
rm -f "${LOCAL_PATH}"

echo "[backup-postgres] $(date -Iseconds) Backup local eliminado. Proceso finalizado OK."
