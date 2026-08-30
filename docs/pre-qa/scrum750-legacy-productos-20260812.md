# Pre-QA — SCRUM-750 (Migración ICG→AtlanticERP, épica SCRUM-749 — piloto de Catálogo)

**Fecha:** 2026-08-12
**Commit verificado:** `8d56def` — "feat(legacy): add ICG Productos extraction pipeline
(SCRUM-750)" (commiteado localmente en `dev`, **no pusheado a origin todavía**)
**Entorno:** stack local (`infra/docker-compose.yml`), Postgres local, tenant `illuminations`.
**Nada de SQL Server real** — la Tarea 6 (corrida real contra el gateway VPN) es explícitamente
fuera de este alcance, confirmado con Luis/Arquitecto en el prompt de activación.

## Particularidad del ticket
Sin UI, sin ambiente vivo (dev.atlanticerp.ai) — es un comando Artisan
(`legacy:sync-productos`) que corre Extractor→Loader-A(staging)→Mapper→Loader-B(catalog_products).
El "romper la app real" acá fue correr el comando REAL end-to-end contra Postgres local, con
`ProductoExtractorContract` fakeado por escenario (patrón `FakeProductoExtractor` ya usado por los
tests, reimplementado ad-hoc vía un comando Artisan temporal — `PreQaScrum750Scratch` — creado,
usado y **borrado** al terminar, nunca commiteado). La suite ya estaba verde (39/39 Legacy,
1889/1889 completa, PHPStan Level 8 limpio) al llegar — el valor de esta pasada es todo lo que esos
tests NO ejercitan corriendo el binario real.

## Escenarios de ruptura intentados

| # | Escenario | Resultado |
|---|---|---|
| 1 | `--dry-run` corrido dos veces seguidas | ✅ `legacy.sync_runs` se queda en 0 filas ambas veces; `legacy.productos`/`producto_precios` SÍ se actualizan (staging es upsert real incluso en rehearsal) |
| 2 | Artículo sin precio público (IDTARIFAV=1 ausente) | ✅ Excluido por el Mapper con motivo explícito, no bloquea el resto del batch, `SyncRun.status=success` con `excluidos=1` |
| 3 | Artículo marcado `descatalogado=true` que igual llega al extractor (no debería pasar del SQL real, pero se simuló que sí llega) | ✅ Excluido en el Mapper ("descatalogado en ICG"), no se carga, no rompe el resto del batch |
| 4 | `reference` que YA existe en `catalog_products` pero el `legacy.productos` de ese artículo NUNCA fue linkeado por este pipeline (sembrado a mano, simulando un producto cargado por otra vía) | ✅ Excluido con motivo "no cargada por este pipeline previamente — nunca se pisa"; verificado en Postgres que `price_full`/`description` del registro preexistente NO se tocaron |
| 5 | Mismo extracto corrido dos veces (80 artículos fake) | ✅ 0 duplicados en `legacy.productos`/`producto_precios`/`producto_costos` (mismo `id` interno en la segunda corrida) y en `catalog_products` (mismo `id`, `updated_at` avanza — confirma acción `updated`, no un segundo `created` silencioso) |
| 6 | `--tenant=no-existe` | ✅ `Tenant 'no-existe' no existe.`, exit code 1, nada tocado — sin stack trace |
| 7 | Extractor lanza excepción (simulando fallo de conexión real) | ✅ Mensaje limpio (`Error al extraer de ICG: ...`), exit code 1, `SyncRun` registrado con `status=failed`, `errores=1` — en `--dry-run` NO se registra nada (confirmado ambos casos) |
| 8 | `REFPROVEEDOR` no numérico (`'ABC-NOPE'`) y `REFPROVEEDOR` null | ✅ Ambos casos: `cost` queda `null` en `catalog_products`, el resto del mapeo (precio, descripción) se completa normal, no se excluye el artículo por esto |
| 9 | Volumen — 80 artículos fake de una corrida | ✅ ~0.6s (incluye overhead de `docker exec`) para la 2ª corrida (ruta `updated`); sin señales de N+1 clásico en `ProductoMapper` (usa `whereIn`+`keyBy`/`groupBy` en batch, no por artículo) — ver hallazgo MEDIO más abajo sobre `CatalogProductLoader` |
| 10 | **Descripción de 300 caracteres** (`catalog_products.description` es `varchar(255) NOT NULL`) — no está en la lista mínima del prompt, lo agregué yo | 🔴 **CRÍTICO real, encontrado y corregido en el momento** — ver abajo |

## Hallazgo real (encontrado, corregido, re-verificado — loop cerrado)

**CRÍTICO — una excepción en staging/mapeo/carga (no solo en la extracción) escapaba sin capturar,
crasheaba el comando con stack trace crudo, y NUNCA quedaba registrada en `legacy.sync_runs`.**

`LegacySyncProductosCommand::runPipeline()` solo envolvía en `try/catch` el paso de
`$this->extractor->extract()`. Todo lo que viene después —
`ProductoStagingLoader::load()`/`ProductoMapper::buildPayloads()`/`CatalogProductLoader::load()`—
corría sin ninguna red de contención propia del comando. Reproducido con un artículo cuya
`descripcion` supera los 255 caracteres de la columna destino (`legacy.productos.descripcion` es
`varchar(255)`, y el `upsert()` de `ProductoStagingLoader` hace un solo INSERT masivo para TODO el
batch): Postgres rechaza el `INSERT` completo con `SQLSTATE[22001]` — y esto tumbaba TODO el batch,
incluyendo artículos perfectamente válidos en la misma corrida (probé con 1 artículo roto + 1 sano:
ninguno de los dos se guardaba). Peor: como el `try/catch` no cubría este tramo, la excepción subía
sin control, el comando terminaba con exit code distinto de 1 controlado (stack trace de Laravel
con +7 frames de vendor) y **`legacy.sync_runs` se quedaba sin ninguna fila** — exactamente el
vacío de monitoreo #5 que esta misma tabla existe para resolver (`docs/architecture` / diseño §11),
sin resolver para cualquier falla que no sea "no puedo conectar a ICG".

Con datos reales de ICG (73,010 filas históricas, calidad de dato sin confirmar todavía —
la propia Tarea 6 lo dice) es plausible que aparezca al menos una fila con un campo fuera de rango
en algún momento de una corrida real. Sin este fix, esa corrida real habría fallado en silencio
para el propósito de monitoreo — nadie se habría enterado por `legacy.sync_runs` de que la corrida
de tal día nunca completó.

**Fix aplicado** (mismo archivo, mismo patrón que el `try/catch` ya existente para la extracción —
no toca diseño ni modelo de datos): se agregó un segundo `try/catch` alrededor de
staging+mapeo+carga (extraído a un método privado `stageMapAndLoad()`), que en caso de excepción
imprime un mensaje legible (`Error al stagear/mapear/cargar productos: ...`, sin dump crudo de
stack trace) y, si NO es `--dry-run`, registra igual una fila en `legacy.sync_runs` con
`status=failed`/`errores=1` — mismo criterio que ya usaba el catch de la extracción.

Re-verificado tras el fix: el mismo escenario (descripción de 300 chars) ahora termina con exit
code 1, mensaje limpio, y una fila `failed` real en `legacy.sync_runs`. Re-corrí el checklist
COMPLETO (los 10 escenarios de arriba) después del fix — sigue limpio — más la suite automatizada
completa (`infra/test.sh`: **1889/1889 verde**, específicamente `--filter=Legacy`: **39/39 verde**)
y PHPStan Level 8 (`vendor/bin/phpstan analyse --memory-limit=1G`: **sin errores**).

## Lo que sí funciona
- Idempotencia real del pipeline completo (staging + carga a `catalog_products`) confirmada
  corriendo el comando real dos veces con el mismo extracto — no solo a nivel de test unitario.
- Criterio conservador de `CatalogProductLoader` (nunca pisa un `catalog_products` no cargado por
  este pipeline) confirmado contra Postgres real, no solo en el Feature test.
- `--dry-run` nunca escribe en `catalog_products` ni en `legacy.sync_runs`, en corridas normales y
  en corridas que fallan.
- Manejo de `REFPROVEEDOR` ambiguo (no numérico / null) — `cost` queda `null` sin bloquear el resto
  del mapeo, tal como documenta el propio código.
- Gate de `--tenant` inexistente — mensaje claro, exit code 1, nada tocado.
- Registro de fallos de EXTRACCIÓN en `legacy.sync_runs` (`status=failed`) — funcionaba desde
  antes del fix de esta pasada.
- Sin N+1 clásico en `ProductoMapper` (fetch batch por `whereIn`, no por artículo).

## Hallazgo NO bloqueante — para que el Arquitecto lo tenga presente antes de la Tarea 6

**MEDIO / observación de diseño — `CatalogProductLoader::load()` hace 1 transacción + ~3
queries por artículo (no hay batch real en el Loader-B).** Con 80 artículos fake la 2ª corrida
completa tardó ~0.6s (mayormente overhead de `docker exec`), así que para el volumen del piloto
esto no es un problema de performance real. Pero si Tarea 6 termina cargando de una sola corrida un
subconjunto bastante más grande que unas pocas docenas/cientos de artículos activos (el diseño no
fija un número), el patrón actual escala linealmente en roundtrips a la DB, no en memoria/CPU. No
lo toqué — es una decisión de diseño (paralelizar/batchear el Loader-B) que le corresponde al
Arquitecto decidir si vale la pena antes o después de ver el volumen real de artículos activos de
ICG. Lo dejo documentado, no bloqueante para este batch.

**Criterio sobre `PriceTypeMap` (pedido explícito del prompt de activación) — NO es un
umbral/margen de negocio hardcodeado, no debería ir a tabla paramétrica.** `PriceTypeMap::MAP`
(`IDTARIFAV` de ICG → `price_type` de AtlanticERP) es un cruce ESTRUCTURAL entre el esquema de datos
propio de ICG (los IDs numéricos de sus 5 tarifas, fijos en su base de datos) y el vocabulario
interno de AtlanticERP — no es un porcentaje, margen, ni divisor configurable por el negocio (como sí lo
es, por ejemplo, un margen mínimo de venta). Es del mismo tipo que cualquier otro cruce
código-externo→enum-interno ya presente en otras integraciones del proyecto: cambia solo si ICG
cambia su propio esquema de tarifas (un evento técnico, no una decisión operativa de Illuminations
que alguien deba poder ajustar sin deploy), y exponerlo como tabla editable por superadmin
introduciría el riesgo real de que alguien lo reconfigure mal y silenciosamente rompa el mapeo de
precios de toda una corrida, sin ningún síntoma inmediato. Mi criterio: dejarlo como constante de
código, tal como está.

**Paso 0 del protocolo (permisos angostos)** — no aplica a este ticket: `legacy:sync-productos` es
un comando Artisan de consola, sin ruta HTTP ni gate de permiso — no hay "solo X puede correrlo"
en los criterios de aceptación.

## Estado del working tree al cerrar esta pasada
- **Modificado:** `app/Console/Commands/LegacySyncProductosCommand.php` (el fix del CRÍTICO de
  arriba — 27 líneas agregadas, sin tocar el resto del pipeline). **Sin commitear** — queda en el
  working tree para que el Arquitecto lo revise antes de decidir si se amasa en el mismo commit de
  SCRUM-750 o va aparte.
- El comando scratch `app/Console/Commands/PreQaScrum750Scratch.php`, usado solo para ejercitar
  escenarios de ruptura contra Postgres real, fue **creado y borrado** en esta misma pasada — no
  queda en el working tree, nunca se commiteó.
- Ningún dato de prueba quedó en la base local: `infra/test.sh` (corrido dos veces en esta pasada,
  para las suites completas post-fix) recrea los schemas de tenant desde cero, así que
  `legacy.*`/`catalog_products` locales terminaron vacíos igual que al empezar.
- No se tocó Jira más allá del comentario de esta pasada (ver abajo). No se hizo `git push`, `ssh`,
  ni nada contra AWS/SQL Server real.

## Veredicto

**BLOQUEADO → CORREGIDO EN EL MOMENTO → LIMPIO.**

Se encontró 1 hallazgo CRÍTICO real (excepciones de staging/mapeo/carga sin capturar, corrida real
sin registrar en `legacy.sync_runs`), se corrigió en el mismo archivo con el mismo patrón ya
existente, se re-corrió el checklist completo (10 escenarios) más la suite automatizada completa
(1889/1889) y PHPStan (limpio) — pasada limpia y concluyente. El fix queda sin commitear en el
working tree para revisión del Arquitecto antes de decidir cómo se integra al commit de SCRUM-750.
No transicioné el ticket en Jira — eso le corresponde al Arquitecto tras leer este resultado.
