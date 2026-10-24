#!/usr/bin/env bash
# test.sh — corre PHPUnit dentro del contenedor `laravel` con el entorno de testing real.
#
# docker-compose.yml carga ../.env vía `env_file`, lo que hornea APP_ENV=local y
# QUEUE_CONNECTION=redis (entre otras) como variables de entorno REALES del proceso del
# contenedor — no solo valores dentro de un archivo .env que Laravel podría sobreescribir.
# phpdotenv nunca pisa una variable ya presente en el entorno del proceso, así que
# `.env.testing` (que Laravel carga como overlay cuando detecta APP_ENV=testing) nunca
# llega a aplicarse si corrés `docker compose exec laravel php artisan test` directo:
# el test corre bajo APP_ENV=local, con QUEUE_CONNECTION=redis real — cualquier Job
# dispatcheado sin Bus::fake() se encola en Redis de verdad en vez de correr síncrono,
# sin ningún error visible (queda "pending" para siempre).
#
# `.github/workflows/ci-backend.yml` ya resuelve esto mismo para CI pasando las
# variables de entorno divergentes al nivel del step de GitHub Actions (ver el
# comentario ahí: "las variables de entorno del step tienen precedencia... porque
# phpdotenv no sobreescribe vars ya presentes"). Este script replica el mismo
# mecanismo para desarrollo local: lee .env.testing como fuente única de verdad y
# pasa cada clave como -e al `docker compose exec`, para que gane sobre lo horneado
# por env_file en el contenedor.
#
# Uso: infra/test.sh [argumentos de "php artisan test"]
#   infra/test.sh
#   infra/test.sh --filter=ShareLinkControllerTest
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
ENV_TESTING="$SCRIPT_DIR/../.env.testing"

if [ ! -f "$ENV_TESTING" ]; then
    echo "ERROR: no se encontró $ENV_TESTING" >&2
    exit 1
fi

ENV_ARGS=()
while IFS='=' read -r key value; do
    [[ -z "$key" || "$key" == \#* ]] && continue
    ENV_ARGS+=(-e "${key}=${value}")
    case "$key" in
        DB_USERNAME) TEST_DB_USERNAME="$value" ;;
        DB_DATABASE) TEST_DB_DATABASE="$value" ;;
        DB_HOST) TEST_DB_HOST="$value" ;;
    esac
done < "$ENV_TESTING"

# ─────────────────────────────────────────────────────────────────────────────
# Reset de schemas de tenant (2026-07-16 — ver memoria del proyecto,
# feedback_local_test_db_no_es_fresh.md, 4 ocurrencias reales de contaminación
# el mismo día).
#
# `RefreshDatabase` (usado por FeatureTestCase) solo hace `migrate:fresh` sobre
# la conexión por defecto (`pgsql`, search_path=public) — ahí vive la tabla
# landlord `tenants`. Los schemas de tenant reales (atlantic_auth,
# atlantic_crm, etc.) los crea `TenantSeeder` → `tenant:create` →
# `CREATE SCHEMA IF NOT EXISTS` + migrate del path `database/migrations/tenant`,
# y ESO nunca se dropea entre corridas: el Postgres de este compose es el mismo
# que usa el desarrollo local real, persistente entre corridas de test (la BASE de pruebas es otra: ver más abajo).
#
# Resultado real (2026-07-16): filas huérfanas de sesiones anteriores, tablas
# de una rama no mergeada quedando registradas en el Postgres compartido, y una
# corrida interrumpida a medias dejando tablas a medio crear → "relation
# already exists" en cientos de tests, todos falsos positivos.
#
# Fix: antes de correr los tests, dropeamos con CASCADE cualquier schema que no
# sea de sistema (public/pg_catalog/information_schema/pg_toast*) directamente
# contra Postgres, sin depender de la tabla landlord `tenants` (que ya está
# vacía en este punto) ni de una lista hardcodeada de schemas de módulo — así
# que un tenant nuevo o un módulo nuevo con su propio schema queda cubierto
# automáticamente. `tenant:create` (vía DatabaseSeeder → TenantSeeder, que
# corre `$seed=true` de RefreshDatabase) los vuelve a crear desde cero en cada
# corrida, incluida la migración del path de tenant. Esto también resuelve el
# caso de corrida interrumpida a medias: el DROP ... CASCADE no depende de que
# el estado previo sea consistente, solo de que el schema exista.
: "${TEST_DB_USERNAME:?DB_USERNAME no encontrado en $ENV_TESTING}"
: "${TEST_DB_DATABASE:?DB_DATABASE no encontrado en $ENV_TESTING}"

# ─────────────────────────────────────────────────────────────────────────────
# Base de datos APARTE para las pruebas (2026-10-24). Antes las pruebas corrían contra `atlanticerp`, la MISMA base del
# desarrollo local, y el DROP de schemas de abajo la dejaba vacía en cada corrida (hubo que volver a sembrar `tenant:create` +
# `db:seed` cada vez). Ahora el script solo opera sobre una base cuyo nombre termina en `_test` y que vive en el Postgres del
# compose local; si no, se niega a borrar nada. Así no se puede apuntar por error a una base con datos que importan
# (la de desarrollo, la de un entorno remoto o la de producción).
if [[ "$TEST_DB_DATABASE" != *_test ]]; then
    echo "ERROR: DB_DATABASE='$TEST_DB_DATABASE' en $ENV_TESTING no termina en _test. Este script borra TODOS los schemas de esa" >&2
    echo "       base antes de correr; solo se permite sobre una base de pruebas dedicada (p. ej. atlanticerp_test)." >&2
    exit 1
fi
if [ "${TEST_DB_HOST:-}" != "postgres" ]; then
    echo "ERROR: DB_HOST='${TEST_DB_HOST:-}' en $ENV_TESTING no es el servicio local 'postgres' del compose; se aborta sin borrar nada." >&2
    exit 1
fi

# La crea si falta (el rol del compose local es superusuario; se conecta a la base `postgres` para poder crearla).
if [ "$(docker compose -f "$SCRIPT_DIR/docker-compose.yml" exec -T postgres \
        psql -U "$TEST_DB_USERNAME" -d postgres -tAc "SELECT 1 FROM pg_database WHERE datname = '$TEST_DB_DATABASE'" </dev/null)" != "1" ]; then
    echo "infra/test.sh: creando la base de pruebas '$TEST_DB_DATABASE'..."
    docker compose -f "$SCRIPT_DIR/docker-compose.yml" exec -T postgres \
        psql -v ON_ERROR_STOP=1 -U "$TEST_DB_USERNAME" -d postgres -c "CREATE DATABASE \"$TEST_DB_DATABASE\"" </dev/null
fi

read -r -d '' DROP_TENANT_SCHEMAS_SQL <<'SQL' || true
DO $$
DECLARE
    r RECORD;
BEGIN
    FOR r IN
        SELECT nspname
        FROM pg_catalog.pg_namespace
        WHERE nspname NOT IN ('public', 'pg_catalog', 'information_schema')
          AND nspname NOT LIKE 'pg\_%'
    LOOP
        EXECUTE format('DROP SCHEMA IF EXISTS %I CASCADE', r.nspname);
    END LOOP;
END
$$;
SQL

echo "infra/test.sh: dropeando schemas de tenant para arrancar de un estado limpio..."
docker compose -f "$SCRIPT_DIR/docker-compose.yml" exec -T postgres \
    psql -v ON_ERROR_STOP=1 -U "$TEST_DB_USERNAME" -d "$TEST_DB_DATABASE" -c "$DROP_TENANT_SCHEMAS_SQL" </dev/null

# memory_limit=512M (2026-07-22, Batch A2 de SCRUM-329) — la suite COMPLETA corrida en un solo
# proceso PHP acumula uso de memoria de dompdf entre tests (Cpdf.php cachea fuentes/métricas sin
# liberar entre PDFs generados en el mismo proceso — Compras ya tenía varios servicios de PDF
# antes de este batch; Bodega agrega OrderDocumentService/generateGuiaEntrega). Con el límite
# default de PHP (128M) la corrida completa revienta con "Allowed memory size exhausted" dentro
# de Cpdf.php a mitad de la suite de Compras — no es un bug de ningún test puntual (confirmado:
# la suite de Bodega completa, incluida DeliveryFlowTest que también genera PDFs, pasa limpia
# mucho antes del punto de la corrida donde revienta).
#
# `php -d memory_limit=... artisan test` NO alcanza: `artisan test` (Illuminate\Testing\...\
# TestCommand) spawnea `vendor/bin/phpunit` como PROCESO HIJO separado (vía Symfony Process), que
# arranca con el php.ini default (128M) sin heredar el `-d` del proceso padre. Se invoca
# `vendor/bin/phpunit` directamente para que el override sí aplique al proceso que realmente corre
# los tests — mismos argumentos que `artisan test` acepta (`--filter=X`, etc.), sin wrapper de por
# medio. Solo afecta este proceso de test, no toca `php.ini` de php-fpm/producción.
#
# 1G (2026-10-07, SCRUM-1116) — la suite creció a ~5.000 tests y con 512M el proceso se quedó sin memoria al 99 %
# de la corrida (en `routes/admin-contab.php`, al arrancar la app de uno de los últimos tests): las corridas verdes
# anteriores ya estaban al borde (5.004 tests) y 40 tests nuevos lo cruzaron. Es la misma acumulación de memoria entre
# tests de arriba, no una fuga de un test puntual; 1G es lo que ya usa el CI (`ini-values: memory_limit=1G`).
docker compose -f "$SCRIPT_DIR/docker-compose.yml" exec -T "${ENV_ARGS[@]}" laravel php -d memory_limit=1G vendor/bin/phpunit "$@"
