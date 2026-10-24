#!/usr/bin/env bash
#
# setup-wal-archiving.sh — SCRUM-1073 (HA F2): prepara el HOST para el archivado de WAL. Idempotente. Ejecutar como root
# ANTES de desplegar el compose que activa archive_mode (si la carpeta no existe o no es del usuario postgres, el
# archivado fallaria y el WAL se acumularia en el disco).
#   sudo /var/www/backend/infra/scripts/setup-wal-archiving.sh
set -euo pipefail
[ "$(id -u)" -eq 0 ] || { echo "ejecutar como root" >&2; exit 1; }

SCRIPTS=/var/www/backend/infra/scripts
ARCHIVE_DIR=/data/wal-archive

mkdir -p "$ARCHIVE_DIR"
chown 70:70 "$ARCHIVE_DIR"   # uid 70 = usuario postgres de la imagen alpine (no existe como usuario en el host)
chmod 700 "$ARCHIVE_DIR"
chmod 755 "$SCRIPTS/ship-wal.sh" "$SCRIPTS/basebackup-postgres.sh"

cat > /etc/cron.d/atlanticerp-wal-archiving <<CRON
# SCRUM-1073: sube los segmentos de WAL cada minuto y hace una copia base semanal (domingo 04:15 UTC)
SHELL=/bin/bash
PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
* * * * * root $SCRIPTS/ship-wal.sh >> /var/log/atlanticerp-wal-ship.log 2>&1
15 4 * * 0 root $SCRIPTS/basebackup-postgres.sh >> /var/log/atlanticerp-basebackup.log 2>&1
CRON
chmod 644 /etc/cron.d/atlanticerp-wal-archiving
echo "OK: $ARCHIVE_DIR (uid 70) y cron instalados. Siguiente: desplegar el compose con archive_mode=on."
