#!/usr/bin/env bash
# legacy-sync.sh — corre `legacy:sync-productos` (o `legacy:sync-productos-fotos` con --fotos, o
# `legacy:sync-clientes` con --clientes, o `legacy:sync-proveedores` con --proveedores, o
# `legacy:sync-cotizaciones` con --cotizaciones, o `legacy:sync-pedidos` con --pedidos) en un
# ambiente real (dev/test/prod) por SSH.
#
# Reemplaza componer SSH + `docker compose exec` a mano cada vez (frágil, no auditable, no
# reproducible sin releer el historial de comandos) — SCRUM-750, agregado 2026-08-12 tras la
# primera corrida real de la migración ICG→AtlanticERP contra dev.atlanticerp.ai. Es el mecanismo pensado para
# sobrevivir a que nadie esté "ahí a mano" ajustando el comando — mismo binario se usa para
# validar en dev/test hoy y, el día que se decida automatizar la cadencia semanal (§8.1 del
# diseño), para correrlo también desde el Laravel Scheduler dentro del propio contenedor (ese
# camino no necesita SSH en absoluto, ver nota al final).
#
# --fotos agregado SCRUM-751 (§13.4 del runbook: "si el comando por entidad cambia de nombre,
# extender el script para aceptar el nombre de entidad como parámetro — no volver a la composición
# manual") — mismo wrapper, mismo logging, invoca `legacy:sync-productos-fotos` en vez de
# `legacy:sync-productos`. Acepta además `--limit=N` (pass-through directo al comando, ver §15.5
# paso 2: primera corrida real acotada antes de soltar el resto).
#
# --clientes agregado SCRUM-755 — mismo criterio que --fotos, invoca `legacy:sync-clientes` en vez
# de `legacy:sync-productos`. Sin `--limit` (el comando de Clientes no lo soporta).
#
# --proveedores agregado SCRUM-756 — mismo criterio que --fotos, invoca `legacy:sync-proveedores`
# en vez de `legacy:sync-productos`. Sin `--limit` (el comando de Proveedores no lo soporta).
#
# --cotizaciones agregado SCRUM-757 — mismo criterio que --fotos, invoca `legacy:sync-cotizaciones`
# en vez de `legacy:sync-productos`. Sin `--limit`. NOTA: el commit original de SCRUM-757 nunca
# tocó este script pese a que su mensaje de commit lo decía (gap real, encontrado por el Backend
# Dev de SCRUM-758 al traer las 3 entidades previas como prerequisito) — se agrega recién acá,
# al mergear SCRUM-758, junto con `--pedidos`.
#
# --pedidos agregado SCRUM-758 — mismo criterio que --fotos, invoca `legacy:sync-pedidos` en vez
# de `legacy:sync-productos`. Sin `--limit` (el comando de Pedidos no lo soporta).
#
# --albaranes agregado SCRUM-759 — mismo criterio que --fotos, invoca `legacy:sync-albaranes` en
# vez de `legacy:sync-productos`. Sin `--limit` (el comando de Albaranes no lo soporta).
#
# --albaranes-compra agregado SCRUM-840 — equivalente de compras a --albaranes, invoca
# `legacy:sync-albaranes-compra` (vincula por proveedor, no por cliente). Sin `--limit`.
#
# --facturas agregado SCRUM-760 — mismo criterio que --fotos, invoca `legacy:sync-facturas` en vez
# de `legacy:sync-productos`. Sin `--limit` (el comando de Facturas no lo soporta).
#
# --pagos agregado SCRUM-761 — mismo criterio que --fotos, invoca `legacy:sync-pagos` en vez de
# `legacy:sync-productos`. Sin `--limit` (el comando de Pagos no lo soporta).
#
# --fix-productos-referencia agregado 2026-08-27 (SCRUM-799) — corrección de DATA, una sola vez
# por entorno: corrige el cruce reference/factory_reference que quedó en los productos YA
# migrados con la asignación vieja de `ProductoMapper` (fix de código aparte, mismo ticket, ver
# docblock de `App\Modules\Legacy\Services\ProductoReferenceSwapService`). No toca ICG — opera
# sobre `catalog_products` ya cargado. Idempotente (correrlo dos veces no repite el swap). Sin
# `--limit`.
#
# --fuse-clientes/--fuse-proveedores agregados 2026-08-26 — antes `legacy:fuse-clientes`/
# `legacy:fuse-proveedores` (Loader-B, SCRUM-749) se corrían a mano por SSH, SIN ninguna de las
# protecciones de este script (sin gate --force en prod, sin log, sin reintento). Encontrado al
# investigar por qué `legacy.sync_runs` tenía `clientes_fusion` en 0/0/0/0 en dev pese a que sí se
# habían promovido 7521 `sub_clients` reales: alguien corrió `--dry-run` primero — en estos 2
# comandos ESO SÍ escribía datos reales (mismo bug ya corregido en el código, ver docblock de
# `LegacyFuseClientesCommand`/`LegacyFuseProveedoresCommand` — `--dry-run` ahora es un rollback
# real), y como el criterio de candidato es "como máximo una vez", la corrida real posterior no
# encontró nada para promover y quedó registrada vacía. Meterlos en este script cierra el gap de
# protecciones; el fix de `--dry-run` en el código cierra la causa raíz. Invocan
# `legacy:fuse-clientes`/`legacy:fuse-proveedores` en vez de `legacy:sync-productos`. Sin `--limit`.
# ⚠️ Dependencia de orden: `--fuse-clientes` necesita `--clientes` (y typicamente `--cotizaciones`/
# `--pedidos`, de donde sale el criterio "≥1 transacción real") ya corridos antes en ese mismo
# ambiente; `--fuse-proveedores` necesita `--proveedores` ya corrido. Este script NO valida esa
# dependencia — el operador es responsable del orden (ver runbook de prod en
# `Illumination/migracion-icg-diseno.md` §14).
# ⚠️ Nota sobre el reintento automático de abajo: para `--fuse-clientes`/`--fuse-proveedores` la
# idempotencia NO viene de una única transacción envolvente como en las demás entidades (acá cada
# candidato se promueve en su propia transacción, uno por uno) — viene de que el criterio de
# candidato excluye automáticamente a quien ya se promovió. Un reintento tras un corte a mitad de
# camino sigue siendo seguro (nunca duplica), pero por un mecanismo distinto — no asumir que es
# "todo o nada" como en `legacy:sync-*` si se audita esto más adelante.
#
# --fotos/--clientes/--proveedores/--cotizaciones/--pedidos/--albaranes/--facturas/--pagos/
# --fuse-clientes/--fuse-proveedores/--fix-productos-referencia son mutuamente excluyentes ENTRE SÍ
# (una corrida es de una sola entidad a la vez) — reconciliado 2026-08-14 al mergear
# SCRUM-755+SCRUM-756: cada batch había agregado su propia exclusión solo contra --fotos (hallazgo
# de Pre-QA de SCRUM-756), faltaba --clientes vs --proveedores. Al agregar una entidad nueva,
# sumarla a la lista de banderas y al chequeo de "más de una activa" de abajo — no repetir el gap.
#
# Uso:
#   infra/legacy-sync.sh dev  --dry-run
#   infra/legacy-sync.sh test --dry-run
#   infra/legacy-sync.sh test
#   infra/legacy-sync.sh prod --dry-run                        # real contra prod exige --force explícito
#   infra/legacy-sync.sh prod --force
#   infra/legacy-sync.sh dev  --fotos --dry-run
#   infra/legacy-sync.sh dev  --fotos --limit=50
#   infra/legacy-sync.sh test --fotos
#   infra/legacy-sync.sh prod --fotos --force
#   infra/legacy-sync.sh dev  --clientes --dry-run
#   infra/legacy-sync.sh test --clientes
#   infra/legacy-sync.sh prod --clientes --force
#   infra/legacy-sync.sh dev  --proveedores --dry-run
#   infra/legacy-sync.sh test --proveedores
#   infra/legacy-sync.sh prod --proveedores --force
#   infra/legacy-sync.sh dev  --cotizaciones --dry-run
#   infra/legacy-sync.sh test --cotizaciones
#   infra/legacy-sync.sh prod --cotizaciones --force
#   infra/legacy-sync.sh dev  --pedidos --dry-run
#   infra/legacy-sync.sh test --pedidos
#   infra/legacy-sync.sh prod --pedidos --force
#   infra/legacy-sync.sh dev  --albaranes --dry-run
#   infra/legacy-sync.sh test --albaranes
#   infra/legacy-sync.sh prod --albaranes --force
#   infra/legacy-sync.sh dev  --albaranes-compra --dry-run
#   infra/legacy-sync.sh test --albaranes-compra
#   infra/legacy-sync.sh prod --albaranes-compra --force
#   infra/legacy-sync.sh dev  --facturas --dry-run
#   infra/legacy-sync.sh test --facturas
#   infra/legacy-sync.sh prod --facturas --force
#   infra/legacy-sync.sh dev  --pagos --dry-run
#   infra/legacy-sync.sh test --pagos
#   infra/legacy-sync.sh prod --pagos --force
#   infra/legacy-sync.sh dev  --fuse-clientes --dry-run       # preview real, rollback, no escribe nada
#   infra/legacy-sync.sh test --fuse-clientes
#   infra/legacy-sync.sh prod --fuse-clientes --force
#   infra/legacy-sync.sh dev  --fuse-proveedores --dry-run
#   infra/legacy-sync.sh test --fuse-proveedores
#   infra/legacy-sync.sh prod --fuse-proveedores --force
#   infra/legacy-sync.sh dev  --fix-productos-referencia --dry-run   # preview real, rollback
#   infra/legacy-sync.sh test --fix-productos-referencia
#   infra/legacy-sync.sh prod --fix-productos-referencia --force
#
# Requiere: misma clave SSH para los 3 entornos (ver memory/reference_ssh_dev.md),
# ~/.ssh/atlanticerp/atlanticerp-key.pem. Tenant fijo `atlantic` — único tenant real hoy.
#
# Cada corrida queda en docs/legacy-sync-logs/<env>-<timestamp>.log (gitignored — son logs
# operativos con datos reales de negocio, nunca se commitean) para poder comparar corridas entre
# ambientes (ej. "Extraídos" de test contra el número ya conocido de dev) sin tener que volver a
# correr nada.
#
# ServerAliveInterval/ServerAliveCountMax agregado 2026-08-21 — un corte de red silencioso entre
# el operador y el EC2 (sin FIN/RST) dejaba la conexión SSH en estado half-open, colgada
# indefinidamente esperando datos aunque el `docker compose exec` remoto ya hubiera terminado o
# desaparecido. `set -euo pipefail` ya propaga el exit code de `ssh` a través del `| tee` (no hacía
# falta agregar nada ahí), pero sin keepalive `ssh` nunca detectaba la conexión muerta para poder
# fallar. Con esto, una conexión colgada corta sola a los ~90s (3 x 30s) en vez de quedar pegada
# sin límite. Encontrado en la primera corrida real de Cotizaciones (SCRUM-757, ~810K filas).
#
# Causa raíz real encontrada 2026-08-21, tras varias corridas fallidas de Cotizaciones (~810K
# filas): NO es un problema de red/SSH — `sudo dmesg -T` confirmó un OOM kill real del kernel sobre
# el proceso `php` (`Killed process ... anon-rss:508956kB`) en dev.atlanticerp.ai, un EC2 t3.small de 2GB
# total con `free -h` mostrando apenas ~94MB libres en reposo (5 contenedores ya usando ~1.5GB).
# `CotizacionExtractor::extract()` (`App\Modules\Legacy\Extractors\CotizacionExtractor.php`) hace
# dos `DB::connection('legacy_icg')->select()` SIN chunking/cursor — materializa las 94.823
# cabeceras + 715.530 líneas enteras en memoria antes de mapear nada, sobre una caja sin margen
# para eso. Se probaron varias formas de desacoplar la ejecución de la conexión SSH del operador
# (`docker compose exec -d`, `{ …; } &` dentro del contenedor, `nohup` a nivel del host EC2) — cada
# una resolvía el síntoma de "conexión SSH cortada" pero el proceso remoto seguía muriendo solo,
# sin marca de red, porque el problema real es memoria del host, no la conexión. **La solución de
# fondo es código** (paginar/streamear la extracción en el Extractor, o acotar por rango de fecha),
# fuera de alcance de este script — ver hallazgo completo en Jira SCRUM-757 y
# `docs/architecture/servicios-fase4-diseno.md`/`migracion-icg-diseno.md` §13.3. El
# `ServerAliveInterval` de arriba sigue siendo correcto y útil (SÍ hay cortes de red reales,
# aparte de esto) — se mantiene la corrida síncrona simple con reintento, sin la complejidad de
# background/polling que no ataca la causa real.
set -euo pipefail

ENV="${1:-}"
shift || true

SSH_KEY="$HOME/.ssh/atlanticerp/atlanticerp-key.pem"
TENANT="atlantic"
REMOTE_REPO="/var/www/backend"

case "$ENV" in
    dev)
        HOST="dev.atlanticerp.ai"
        COMPOSE_FILE="infra/docker-compose.qa.yml"
        ;;
    test)
        HOST="test.atlanticerp.ai"
        COMPOSE_FILE="infra/docker-compose.qa.yml"
        ;;
    prod)
        HOST="atlanticerp.ai"
        COMPOSE_FILE="infra/docker-compose.prod.yml"
        ;;
    *)
        echo "Uso: $0 <dev|test|prod> [--fotos|--clientes|--proveedores|--cotizaciones|--pedidos|--albaranes|--albaranes-compra|--facturas|--pagos|--fuse-clientes|--fuse-proveedores|--fix-productos-referencia] [--dry-run] [--limit=N] [--force]" >&2
        exit 1
        ;;
esac

DRY_RUN_FLAG=""
LIMIT_FLAG=""
FOTOS=false
CLIENTES=false
PROVEEDORES=false
COTIZACIONES=false
PEDIDOS=false
ALBARANES=false
ALBARANES_COMPRA=false
FACTURAS=false
PAGOS=false
FUSE_CLIENTES=false
FUSE_PROVEEDORES=false
FIX_PRODUCTOS_REFERENCIA=false
FORCE=false
for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN_FLAG="--dry-run" ;;
        --fotos) FOTOS=true ;;
        --clientes) CLIENTES=true ;;
        --proveedores) PROVEEDORES=true ;;
        --cotizaciones) COTIZACIONES=true ;;
        --pedidos) PEDIDOS=true ;;
        --albaranes) ALBARANES=true ;;
        --albaranes-compra) ALBARANES_COMPRA=true ;;
        --facturas) FACTURAS=true ;;
        --pagos) PAGOS=true ;;
        --fuse-clientes) FUSE_CLIENTES=true ;;
        --fuse-proveedores) FUSE_PROVEEDORES=true ;;
        --fix-productos-referencia) FIX_PRODUCTOS_REFERENCIA=true ;;
        --limit=*) LIMIT_FLAG="$arg" ;;
        --force) FORCE=true ;;
        *)
            echo "Flag desconocido: $arg" >&2
            exit 1
            ;;
    esac
done

# Mutuamente excluyentes entre sí — una corrida es de una sola entidad a la vez. Contar cuántas
# banderas de entidad están activas en vez de comparar pares (no repetir el gap encontrado por
# Pre-QA en SCRUM-756: cada entidad nueva solo se excluía contra --fotos, no contra las demás).
ENTITY_FLAGS_ACTIVE=0
[[ "$FOTOS" == true ]] && ENTITY_FLAGS_ACTIVE=$((ENTITY_FLAGS_ACTIVE + 1))
[[ "$CLIENTES" == true ]] && ENTITY_FLAGS_ACTIVE=$((ENTITY_FLAGS_ACTIVE + 1))
[[ "$PROVEEDORES" == true ]] && ENTITY_FLAGS_ACTIVE=$((ENTITY_FLAGS_ACTIVE + 1))
[[ "$COTIZACIONES" == true ]] && ENTITY_FLAGS_ACTIVE=$((ENTITY_FLAGS_ACTIVE + 1))
[[ "$PEDIDOS" == true ]] && ENTITY_FLAGS_ACTIVE=$((ENTITY_FLAGS_ACTIVE + 1))
[[ "$ALBARANES" == true ]] && ENTITY_FLAGS_ACTIVE=$((ENTITY_FLAGS_ACTIVE + 1))
[[ "$ALBARANES_COMPRA" == true ]] && ENTITY_FLAGS_ACTIVE=$((ENTITY_FLAGS_ACTIVE + 1))
[[ "$FACTURAS" == true ]] && ENTITY_FLAGS_ACTIVE=$((ENTITY_FLAGS_ACTIVE + 1))
[[ "$PAGOS" == true ]] && ENTITY_FLAGS_ACTIVE=$((ENTITY_FLAGS_ACTIVE + 1))
[[ "$FUSE_CLIENTES" == true ]] && ENTITY_FLAGS_ACTIVE=$((ENTITY_FLAGS_ACTIVE + 1))
[[ "$FUSE_PROVEEDORES" == true ]] && ENTITY_FLAGS_ACTIVE=$((ENTITY_FLAGS_ACTIVE + 1))
[[ "$FIX_PRODUCTOS_REFERENCIA" == true ]] && ENTITY_FLAGS_ACTIVE=$((ENTITY_FLAGS_ACTIVE + 1))
if [[ "$ENTITY_FLAGS_ACTIVE" -gt 1 ]]; then
    echo "ERROR: --fotos, --clientes, --proveedores, --cotizaciones, --pedidos, --albaranes, --albaranes-compra, --facturas, --pagos, --fuse-clientes, --fuse-proveedores y --fix-productos-referencia son mutuamente excluyentes (una corrida es de una sola entidad)." >&2
    exit 1
fi

ARTISAN_COMMAND="legacy:sync-productos"
if [[ "$FOTOS" == true ]]; then
    ARTISAN_COMMAND="legacy:sync-productos-fotos"
elif [[ "$CLIENTES" == true ]]; then
    ARTISAN_COMMAND="legacy:sync-clientes"
elif [[ "$PROVEEDORES" == true ]]; then
    ARTISAN_COMMAND="legacy:sync-proveedores"
elif [[ "$COTIZACIONES" == true ]]; then
    ARTISAN_COMMAND="legacy:sync-cotizaciones"
elif [[ "$PEDIDOS" == true ]]; then
    ARTISAN_COMMAND="legacy:sync-pedidos"
elif [[ "$ALBARANES" == true ]]; then
    ARTISAN_COMMAND="legacy:sync-albaranes"
elif [[ "$ALBARANES_COMPRA" == true ]]; then
    ARTISAN_COMMAND="legacy:sync-albaranes-compra"
elif [[ "$FACTURAS" == true ]]; then
    ARTISAN_COMMAND="legacy:sync-facturas"
elif [[ "$PAGOS" == true ]]; then
    ARTISAN_COMMAND="legacy:sync-pagos"
elif [[ "$FUSE_CLIENTES" == true ]]; then
    ARTISAN_COMMAND="legacy:fuse-clientes"
elif [[ "$FUSE_PROVEEDORES" == true ]]; then
    ARTISAN_COMMAND="legacy:fuse-proveedores"
elif [[ "$FIX_PRODUCTOS_REFERENCIA" == true ]]; then
    ARTISAN_COMMAND="legacy:fix-productos-referencia"
fi

# --limit solo tiene sentido para el comando de fotos (no existe en legacy:sync-productos,
# legacy:sync-clientes, legacy:sync-proveedores, legacy:sync-cotizaciones, legacy:sync-pedidos,
# legacy:sync-albaranes, legacy:sync-albaranes-compra, legacy:sync-facturas, legacy:sync-pagos,
# legacy:fuse-clientes, legacy:fuse-proveedores ni legacy:fix-productos-referencia).
if [[ -n "$LIMIT_FLAG" && "$FOTOS" != true ]]; then
    echo "ERROR: --limit solo aplica junto con --fotos." >&2
    exit 1
fi

# Nunca una corrida real contra prod por accidente — --force explícito o no corre.
if [[ "$ENV" == "prod" && -z "$DRY_RUN_FLAG" && "$FORCE" != true ]]; then
    echo "ERROR: corrida real (sin --dry-run) contra prod requiere --force explícito." >&2
    exit 1
fi

if [[ ! -f "$SSH_KEY" ]]; then
    echo "ERROR: no se encontró la clave SSH en $SSH_KEY" >&2
    exit 1
fi

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
LOG_DIR="$SCRIPT_DIR/docs/legacy-sync-logs"
mkdir -p "$LOG_DIR"
TIMESTAMP="$(date -u +%Y%m%dT%H%M%SZ)"
LOG_SUFFIX="$ENV"
if [[ "$FOTOS" == true ]]; then
    LOG_SUFFIX="${ENV}-fotos"
elif [[ "$CLIENTES" == true ]]; then
    LOG_SUFFIX="${ENV}-clientes"
elif [[ "$PROVEEDORES" == true ]]; then
    LOG_SUFFIX="${ENV}-proveedores"
elif [[ "$COTIZACIONES" == true ]]; then
    LOG_SUFFIX="${ENV}-cotizaciones"
elif [[ "$PEDIDOS" == true ]]; then
    LOG_SUFFIX="${ENV}-pedidos"
elif [[ "$ALBARANES" == true ]]; then
    LOG_SUFFIX="${ENV}-albaranes"
elif [[ "$FACTURAS" == true ]]; then
    LOG_SUFFIX="${ENV}-facturas"
elif [[ "$PAGOS" == true ]]; then
    LOG_SUFFIX="${ENV}-pagos"
elif [[ "$FUSE_CLIENTES" == true ]]; then
    LOG_SUFFIX="${ENV}-fuse-clientes"
elif [[ "$FUSE_PROVEEDORES" == true ]]; then
    LOG_SUFFIX="${ENV}-fuse-proveedores"
elif [[ "$FIX_PRODUCTOS_REFERENCIA" == true ]]; then
    LOG_SUFFIX="${ENV}-fix-productos-referencia"
fi
LOG_FILE="$LOG_DIR/${LOG_SUFFIX}-${TIMESTAMP}.log"

echo "legacy-sync.sh: ambiente=$ENV host=$HOST comando=$ARTISAN_COMMAND dry_run=${DRY_RUN_FLAG:-no} limit=${LIMIT_FLAG:-sin límite} log=$LOG_FILE"
echo "---"

SSH_OPTS=(-i "$SSH_KEY" -o StrictHostKeyChecking=no -o ConnectTimeout=15 -o ServerAliveInterval=30 -o ServerAliveCountMax=3)

# Reintento automático (hasta 3 intentos) agregado 2026-08-21 — cubre cortes de red reales
# (confirmados, ver ServerAliveInterval arriba). NO ataca la causa de memoria (ver docblock del
# encabezado) — para eso hace falta un fix de código en el Extractor de la entidad, fuera de
# alcance de este script. Seguro reintentar desde cero: cada entidad hace su
# `StagingLoader::load()` en una única `DB::transaction()` (§13.3 del diseño), una corrida
# interrumpida no deja nada comprometido en `legacy.*`.
MAX_ATTEMPTS=3
ATTEMPT=0
while true; do
    ATTEMPT=$((ATTEMPT + 1))
    set +e
    ssh "${SSH_OPTS[@]}" "ubuntu@${HOST}" \
        "cd ${REMOTE_REPO} && docker compose -f ${COMPOSE_FILE} exec -T laravel php artisan ${ARTISAN_COMMAND} --tenant=${TENANT} ${DRY_RUN_FLAG} ${LIMIT_FLAG} < /dev/null" \
        < /dev/null | tee "$LOG_FILE"
    SSH_EXIT=${PIPESTATUS[0]}
    set -e
    if [[ "$SSH_EXIT" -eq 0 ]]; then
        break
    fi
    if [[ "$ATTEMPT" -ge "$MAX_ATTEMPTS" ]]; then
        echo "ERROR: $MAX_ATTEMPTS intentos fallidos (última salida ssh=$SSH_EXIT) — revisar conectividad y/o memoria del host a mano." >&2
        exit 1
    fi
    echo "legacy-sync.sh: intento $ATTEMPT falló (ssh exit=$SSH_EXIT) — reintentando (nada quedó comprometido)..." >&2
    sleep 5
done

echo "---"
echo "legacy-sync.sh: log guardado en $LOG_FILE"

# Nota — camino a "nadie necesita estar acá corriendo esto a mano" de verdad: registrar
# `legacy:sync-productos` en el Laravel Scheduler (routes/console.php, ->weekly()->environments('dev'),
# ver §8.1 del diseño) corre el comando DENTRO del contenedor de horizon/scheduler, sin SSH externo
# de por medio — este script sigue siendo útil para corridas puntuales/manuales (como esta, o
# validar test antes de una promoción), pero deja de ser el único camino.
