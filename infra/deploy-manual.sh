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
# A diferencia del CD real, este script corre PHPStan+PHPUnit localmente ANTES de
# tocar el servidor (mismo gate que el job "ci" de GHA) — nunca saltea la
# verificación solo porque Actions esté caído.
#
# Uso: infra/deploy-manual.sh <dev|test|prod>
set -euo pipefail

ENV="${1:-}"
if [[ "$ENV" != "dev" && "$ENV" != "test" && "$ENV" != "prod" ]]; then
  echo "Uso: $0 <dev|test|prod>" >&2
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

echo "==> [local] PHPStan Level 8 (mismo gate que el job CI de GitHub Actions)"
php -d memory_limit=1G vendor/bin/phpstan analyse --no-progress --memory-limit=1G

echo "==> [local] PHPUnit vía infra/test.sh (mismo gate que el job CI de GitHub Actions)"
./infra/test.sh

echo "==> [remoto $HOST] deploy"
$SSH bash -s << ENDSSH
set -euo pipefail
cd /var/www/backend

echo "==> git checkout $BRANCH (forzar rama correcta, evitar drift)"
git fetch origin $BRANCH
git checkout $BRANCH
git reset --hard origin/$BRANCH

echo "==> composer install"
docker run --rm \
  -v /var/www/backend:/app -w /app \
  composer:latest install \
    --no-dev --optimize-autoloader --no-interaction \
    --ignore-platform-req=ext-pcntl --ignore-platform-req=ext-gd

echo "==> fix ownership + permissions"
sudo chown -R ubuntu:ubuntu /var/www/backend
chmod -R 777 storage bootstrap/cache

echo "==> docker compose build + up"
docker compose -f $COMPOSE_FILE up -d --build --remove-orphans

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
docker compose -f $COMPOSE_FILE exec -T laravel \
  php artisan migrate --force < /dev/null

echo "==> sync tenant schemas (crea los que falten para modulos nuevos)"
docker compose -f $COMPOSE_FILE exec -T laravel \
  php artisan tenant:sync-schemas < /dev/null

echo "==> migrate (tenant schemas)"
docker compose -f $COMPOSE_FILE exec -T laravel \
  php artisan tenants:artisan "migrate --force --path=database/migrations/tenant" < /dev/null

echo "==> seed (tenant + usuarios)"
docker compose -f $COMPOSE_FILE exec -T laravel \
  php artisan db:seed --force < /dev/null

echo "==> reload nginx"
docker compose -f $COMPOSE_FILE exec -T nginx \
  nginx -s reload < /dev/null || true

echo "==> horizon terminate"
docker compose -f $COMPOSE_FILE exec -T laravel \
  php artisan horizon:terminate < /dev/null || true

echo "==> Deploy $ENV completado."
ENDSSH

echo "==> [local] verificando /up en $HOST"
curl -sf "https://$HOST/up" > /dev/null && echo "OK: https://$HOST/up responde." || echo "ADVERTENCIA: /up no respondió — revisar manualmente."
