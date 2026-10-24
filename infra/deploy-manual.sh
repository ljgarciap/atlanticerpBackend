#!/bin/bash
# deploy-manual.sh — respaldo de CD por SSH cuando GitHub Actions no está disponible
# (ej. cuota mensual de Actions agotada en el plan Free de la organización, ver
# memory/atlanticerp/project_sesion_20260815_scrum427_431_765_752_767.md 2026-08-17).
#
# Replica EXACTAMENTE los mismos pasos que el job deploy-{env} de
# .github/workflows/ci-backend.yml — mismo compose file, mismo orden de comandos,
# mismos `< /dev/null` en cada `exec -T` (gotcha real ya documentado: sin eso, el
# primer comando que corre con éxito se come el stdin del resto del script).
#
# Antes de tocar el servidor exige el gate local (infra/gate.sh: PHPStan + suite completa, ADR-009).
# Si el commit ya tiene recibo verde (lo normal: se corrió antes del push) NO lo repite — el deploy
# manual queda en 1-2 minutos. Nunca se saltea la verificación: sin recibo, la corre.
#
# Uso: infra/deploy-manual.sh <dev|test|prod> [--force-gate]
#   --force-gate   vuelve a correr el gate aunque ya haya recibo verde para este commit
set -euo pipefail

ENV="${1:-}"
GATE_ARGS=()
[[ "${2:-}" == "--force-gate" ]] && GATE_ARGS=(--force)
if [[ "$ENV" != "dev" && "$ENV" != "test" && "$ENV" != "prod" ]]; then
  echo "Uso: $0 <dev|test|prod> [--force-gate]" >&2
  exit 1
fi

case "$ENV" in
  dev)  HOST="dev.atlanticerp.ai";  BRANCH="dev";  COMPOSE_FILE="infra/docker-compose.qa.yml" ;;
  test) HOST="test.atlanticerp.ai"; BRANCH="test"; COMPOSE_FILE="infra/docker-compose.qa.yml" ;;
  prod) HOST="atlanticerp.ai";      BRANCH="master"; COMPOSE_FILE="infra/docker-compose.prod.yml" ;;
esac

SSH_KEY="$HOME/.ssh/atlanticerp/atlanticerp-key.pem"
SSH="ssh -i $SSH_KEY -o StrictHostKeyChecking=no ubuntu@$HOST"

echo "==> [local] verificando rama actual está pusheada a origin/$BRANCH"
LOCAL_SHA=$(git rev-parse HEAD)
REMOTE_SHA=$(git rev-parse "origin/$BRANCH")
if [[ "$LOCAL_SHA" != "$REMOTE_SHA" ]]; then
  echo "ERROR: HEAD local ($LOCAL_SHA) no coincide con origin/$BRANCH ($REMOTE_SHA)." >&2
  echo "Pushear primero — este script despliega lo que está en origin/$BRANCH, no el working tree local." >&2
  exit 1
fi

echo "==> [local] gate (infra/gate.sh) — reutiliza el recibo verde de este commit si ya existe"
./infra/gate.sh ${GATE_ARGS[@]+"${GATE_ARGS[@]}"}

echo "==> [remoto $HOST] deploy"
$SSH bash -s << ENDSSH
set -euo pipefail
cd /var/www/backend

# SCRUM-810 (REQ-634) — una vez que atlanticerp_app (rol sin superuser) sea el DB_USERNAME horneado por
# env_file (paso 5 del diseño, todavía sin aplicar en ningún entorno), todo paso que necesita
# crear/alterar tablas o schemas (migrate, seed, grants) tiene que forzar las credenciales
# superuser vía -e, igual que ya hace infra/test.sh para pisar env_file. Con fallback a
# DB_USERNAME/DB_PASSWORD tal cual cuando DB_MIGRATE_* todavía no existe en este entorno (test/prod
# antes de su propio bootstrap) — para que este script no se rompa en entornos que no pasaron por
# los pasos 1-2 de SCRUM-810 todavía.
echo "==> [remoto] resolviendo credenciales de migrate"
# "grep ... || true" — bajo set -e, un grep que no encuentra nada devuelve 1 y mata el script
# ANTES de llegar al fallback de abajo (bug real encontrado 2026-08-30 al desplegar a prod, el
# primer entorno que de verdad ejercitó esta rama — dev/test ya tenían DB_MIGRATE_* seteado antes
# de desplegar, nunca la pisaron).
DB_MIGRATE_USERNAME=\$(grep "^DB_MIGRATE_USERNAME=" .env | cut -d= -f2- || true)
DB_MIGRATE_PASSWORD=\$(grep "^DB_MIGRATE_PASSWORD=" .env | cut -d= -f2- || true)
if [ -z "\$DB_MIGRATE_USERNAME" ] || [ -z "\$DB_MIGRATE_PASSWORD" ]; then
  echo "DB_MIGRATE_USERNAME/DB_MIGRATE_PASSWORD no están en .env — este entorno no pasó todavía"
  echo "por el bootstrap de SCRUM-810, usando DB_USERNAME/DB_PASSWORD tal cual (comportamiento actual)."
  DB_MIGRATE_USERNAME=\$(grep "^DB_USERNAME=" .env | cut -d= -f2- || true)
  DB_MIGRATE_PASSWORD=\$(grep "^DB_PASSWORD=" .env | cut -d= -f2- || true)
fi

echo "==> git checkout $BRANCH (forzar rama correcta, evitar drift)"
git fetch origin $BRANCH
git checkout $BRANCH
git reset --hard origin/$BRANCH

echo "==> composer install"
# Solo dev instala dependencias de desarrollo (Scramble sirve /docs/api); test y prod siguen con --no-dev.
COMPOSER_NO_DEV="--no-dev"
[ "$ENV" = "dev" ] && COMPOSER_NO_DEV=""
docker run --rm \
  -v /var/www/backend:/app -w /app \
  composer:latest install \
    \$COMPOSER_NO_DEV --optimize-autoloader --no-interaction \
    --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-gd

echo "==> fix ownership + permissions"
sudo chown -R ubuntu:ubuntu /var/www/backend
chmod -R 777 storage bootstrap/cache

echo "==> docker compose build + up"
docker compose -f $COMPOSE_FILE up -d --build --pull always --remove-orphans

echo "==> wait for postgres"
until docker compose -f $COMPOSE_FILE exec -T postgres \
  pg_isready -U atlanticerp -d atlanticerp < /dev/null 2>/dev/null; do sleep 2; done

echo "==> wait for laravel"
until docker compose -f $COMPOSE_FILE exec -T laravel \
  php artisan --version < /dev/null > /dev/null 2>&1; do sleep 3; done

echo "==> config:clear (flush stale bootstrap cache)"
docker compose -f $COMPOSE_FILE exec -T laravel \
  php artisan config:clear < /dev/null

echo "==> migrate (landlord)"
docker compose -f $COMPOSE_FILE exec -T -e DB_USERNAME="\$DB_MIGRATE_USERNAME" -e DB_PASSWORD="\$DB_MIGRATE_PASSWORD" laravel \
  php artisan migrate --force < /dev/null

echo "==> sync tenant schemas (crea los que falten para modulos nuevos)"
docker compose -f $COMPOSE_FILE exec -T -e DB_USERNAME="\$DB_MIGRATE_USERNAME" -e DB_PASSWORD="\$DB_MIGRATE_PASSWORD" laravel \
  php artisan tenant:sync-schemas < /dev/null

echo "==> migrate (tenant schemas)"
docker compose -f $COMPOSE_FILE exec -T -e DB_USERNAME="\$DB_MIGRATE_USERNAME" -e DB_PASSWORD="\$DB_MIGRATE_PASSWORD" laravel \
  php artisan tenants:artisan "migrate --force --path=database/migrations/tenant" < /dev/null

echo "==> grant permisos de atlanticerp_app (REQ-634, SCRUM-810) — no-op si el rol no existe todavía en este entorno"
docker compose -f $COMPOSE_FILE exec -T -e DB_USERNAME="\$DB_MIGRATE_USERNAME" -e DB_PASSWORD="\$DB_MIGRATE_PASSWORD" laravel \
  php artisan tenant:grant-app-role < /dev/null || echo "  (tenant:grant-app-role falló — normal si atlanticerp_app todavía no existe en este entorno, ver SCRUM-810)"

echo "==> seed (tenant + usuarios)"
docker compose -f $COMPOSE_FILE exec -T -e DB_USERNAME="\$DB_MIGRATE_USERNAME" -e DB_PASSWORD="\$DB_MIGRATE_PASSWORD" laravel \
  php artisan db:seed --force < /dev/null

if [ "$ENV" = "dev" ]; then
  echo "==> scramble: regenerar la documentación de la API (cache) y reiniciar nginx para tomar API_DOCS_BASIC_AUTH_B64"
  docker compose -f $COMPOSE_FILE exec -T laravel php artisan scramble:clear < /dev/null || true
  docker compose -f $COMPOSE_FILE exec -T laravel php -d memory_limit=768M artisan scramble:cache < /dev/null || echo "  (scramble:cache falló — /docs/api no estará disponible)"
  docker compose -f $COMPOSE_FILE up -d --force-recreate nginx < /dev/null || true
fi

echo "==> reload nginx"
docker compose -f $COMPOSE_FILE exec -T nginx \
  nginx -s reload < /dev/null || true

echo "==> horizon terminate"
docker compose -f $COMPOSE_FILE exec -T laravel \
  php artisan horizon:terminate < /dev/null || true

# Cada 'up --build' agrega capas a la caché de builds de Docker y nadie la limpiaba: a
# 2026-09-23 dev y test tenían 7-8 GB recuperables (disco al 74%, a un paso de la alarma del 80%).
# Se conserva la caché de las últimas 72 h para que el próximo deploy siga siendo rápido, y se
# borran las imágenes huérfanas (dangling). '|| true': una limpieza fallida nunca debe dar por
# fallido un deploy que ya terminó bien.
echo "==> limpiar caché de builds > 72 h e imágenes huérfanas"
docker builder prune -f --filter until=72h < /dev/null || true
docker image prune -f < /dev/null || true

echo "==> Deploy $ENV completado."
ENDSSH

echo "==> [local] verificando /up en $HOST"
curl -sf "https://$HOST/up" > /dev/null && echo "OK: https://$HOST/up responde." || echo "ADVERTENCIA: /up no respondió — revisar manualmente."
