# Pre-QA adversarial - Fase 1: copia cruda de CLIENTES y CONTACTOSCLIENTES de ICG

Fecha: 2026-10-13. Repo: atlanticerp-backend, rama dev, commit 1b767ceb (Fase 1 = 695fa618..a5516881 + fix 1b767ceb). La Fase 2 (normalizador) quedo fuera.
Entorno: stack Docker local, extractores falsos (sin ICG real, sin SQL Server, sin ssh, sin push, sin Jira). Se uso un test temporal (borrado al terminar) corrido con `infra/test.sh`, mas pruebas de migracion con `tenants:artisan` en la BD local.

## Veredicto: PASA

Sin hallazgos Criticos, Altos ni Medios. 6 hallazgos Bajos (ninguno bloquea QA; recomendaciones abajo). Las 6 condiciones de seguridad, CA1, CA11 y R2 se cumplen en lo que se puede probar sin ICG real.

## Hallazgos

| # | Sev. | Hallazgo | Evidencia reproducible |
|---|---|---|---|
| 1 | Bajo | Un solo valor malo aborta TODA la pagina de 2.000 filas y el error no dice cual fila ni cual columna (solo SQLSTATE, a proposito). Como la paginacion es ordenada, esa pagina bloquea el avance hasta que alguien la encuentre a mano. Con el esquema real de ICG (largos iguales a los de las migraciones) es poco probable; pasa con datos que no cumplen el esquema. | Fila con `nombrecliente` de 256 caracteres: exit 1, `SQLSTATE 22001`, 0 filas cargadas de esa pagina. Idem entero fuera de rango (22003) y fecha `0000-00-00` (22007). Recomendacion: incluir en el mensaje el rango de la pagina (offset) y las llaves primera/ultima, nunca valores. |
| 2 | Bajo | Texto con UTF-8 invalido, o un decimal INF/NaN, aborta toda la corrida (la fila falla en `RowHasher`, antes de la pagina) con "Malformed UTF-8 characters" o "Inf and NaN cannot be JSON encoded", sin indicar la fila. Sin fuga de valores. (Ya conocido como B4 de la Senior Review.) | Fila con `NOMBREBANCO = "...\xC3\x28"`: exit 1, mensaje sin valores. |
| 3 | Bajo | "Copia cruda" no es exacta: se recortan espacios, `''` y solo-espacios pasan a NULL, y una fecha no parseable (`no-es-fecha`) o un numero no numerico pasan a NULL sin aviso. CA1 dice "valor original"; R2 (CIF y NIF20 crudos) se cumple salvo ese recorte (`' 8 -123- 456 '` queda `'8 -123- 456'`; los NIF con DV de la spec quedan intactos). Ya conocido (B2). Conviene dejarlo escrito en la doc/spec como decision aceptada. | `CIF=' 8 -123- 456 '`, `NIF20="\t7340\n"` -> `'8 -123- 456'` y `'7340'`; `NOMBRECLIENTE='   '` -> NULL; `FECHAMODIFICADO='no-es-fecha'` -> NULL. |
| 4 | Bajo | El contador "Staged (unicos)" cuenta duplicados entre paginas como filas distintas: 2.001 filas de ICG con un CODCLIENTE repetido entre la pagina 1 y la 2 reportan 2.001 unicos, pero la tabla queda con 2.000. Solo cosmetico (la llave en ICG es unica). En contactos (sin PK en ICG) un ID duplicado en la misma pagina se descarta en silencio, gana el ultimo; el sync lo muestra como extraidos 2 / unicos 1 y `verify` lo detecta por conteo. | Reproducido con 2.001 clientes y con 2 contactos de ID 5. |
| 5 | Bajo | Tenant que existe en la tabla de tenants pero sin esquema `{slug}_legacy`: el error de la carga se captura bien, pero luego `recordRun()` intenta escribir en `sync_runs` y revienta con `QueryException` (42P01) sin capturar: salida con traza en vez de mensaje limpio. La traza solo lleva el SQL de `sync_runs` con contadores, sin datos de clientes. Configuracion invalida, no ocurre en operacion normal. | Tenant `ghostpreqa` sin esquemas, `legacy:sync-clientes-raw --tenant=ghostpreqa`. |
| 6 | Bajo (informativo) | `ORDENADEUDO` (mandato SEPA) se sigue copiando junto con las columnas bancarias aprobadas (NUMCUENTA, CODIGOIBAN, CODSWIFT, NOMBREBANCO). La revision de seguridad no lo menciona; conviene que Luis/Cybersecurity lo confirmen por escrito. Los 8 campos de tarjeta/credenciales SI estan excluidos (ver abajo). | Columnas de `clientes_raw`. |

## Lo que sí funciona (probado de verdad)

### 1. Seguridad
- Las 8 columnas excluidas (CVC, PASS, PASSWORDCOMMERCE, NUMTARJETA, FOTOCLIENTE, USUARIO, TIPOTARJETA, TARCADUCIDAD) no existen en `clientes_raw` (consulta a `information_schema`); tampoco VERSION.
- Con un ICG falso que DEVUELVE esas columnas aunque no se pidan (valores `SENTINELA_*`): no aparecen en la fila mapeada ni en la fila guardada, y cambiar CVC no cambia `source_hash`.
- El SQL real del extractor (conexion simulada) no contiene `[CVC]`, `[PASS]`, etc. (cierra la observacion M1 de la Senior Review: el cableado ahora esta probado). El SQL de contactos tampoco pide USUARIO ni PASS.
- Errores de BD con centinelas en NUMCUENTA, CODIGOIBAN, CODSWIFT y NOMBREBANCO: valor demasiado largo (22001), UTF-8 invalido (22021), tipo incorrecto (22P02), UTF-8 invalido en hash, INF y llave nula. En los 6 casos: ni la salida de consola, ni `sync_runs`, ni `storage/logs/laravel.log` (no crecio) contienen el centinela. Solo se imprime SQLSTATE o un mensaje fijo.
- `route:list --json` no menciona `clientes_raw`, `contactos_cliente_raw` ni `clientes-raw`; no hay modelo Eloquent ni controlador (grep en app/routes/config: solo Legacy y AppServiceProvider). Sin `Log::`, `dump` ni `report` en los archivos nuevos.
- `legacy:verify-clientes-raw` con 200 llaves de diferencia: lista como maximo 50 por diferencia y "... y 70 mas"; la salida no contiene valores de columnas (centinelas en nombre y numcuenta, 0 apariciones); no escribe filas ni `sync_runs`.

### 2. Idempotencia y reanudacion
- Dos corridas seguidas: 2da con 0 insertados/modificados y `updated_at` intacto (envejecido a 2020 a proposito: 0 filas con fecha nueva), en 30 filas y en 7.796.
- Cambio de una sola columna (NUMCUENTA de un cliente): exactamente 1 fila modificada, solo esa con `updated_at` nuevo, valor reflejado.
- Corte en la pagina 3 (extractor lanza en offset 4000 de 5.000): `sync_run` = partial, cargados 4.000, errores 1; la corrida siguiente completa 5.000 sin duplicados (5.000 distintos) y queda success. Corte en la primera pagina de contactos con clientes ya confirmados: partial.
- Fila borrada en ICG: el staging la conserva (upsert-only, 30 filas) y `legacy:verify-clientes-raw` sale con exit 1 y la lista como "en staging y NO en ICG (borradas en ICG)"; esta documentado en el comando y en la salida.

### 3. Volumen (CA11)
- 7.796 clientes sinteticos con las 110 columnas a largo maximo (varchar al tope, ntext de ~5 KB con emojis y comillas) + 821 contactos con textos de 255: 3,0 s en total; 33 INSERT; maximo 29.945 parametros por sentencia (limite de Postgres: 65.535); 39 consultas en total; tabla de 16 MB.
- Corrido con `memory_limit=256M`: pico del proceso 167 MB (de los cuales ~120 MB son el propio PHPUnit/framework); loader directo 164,5 MB. El comando sube el limite a 512M (`MemoryLimit::atLeast`), no hubo agotamiento de memoria. Segunda corrida: 2,7 s, 0 cambios.
- Un ntext de 5 MB en una fila se guarda completo.

### 4. Datos hostiles (cada fila en su propia corrida)
- Comillas simples y dobles, `; DROP TABLE x; --`, saltos de linea `\n`/`\r\n`/tab, emojis y banderas: guardados intactos (parametros ligados, sin inyeccion).
- NUL en nombre y en ntext: se elimina el byte y se guarda. Nvarchar de largo exacto 255: OK; 256: rechazo limpio (22001).
- Doble maximo/minimo (1,79e308, 4.9e-324): OK. Fechas 1753-01-01 y 9999-12-31 (extremos de SQL Server): OK. Fecha 0000-00-00: rechazo limpio (22007, hallazgo 1). Entero fuera de rango y CODCLIENTE > int32: 22003 limpio. CODCLIENTE no numerico o nulo y ID de contacto nulo: error de llave con mensaje seguro (solo nombre de columna), sin valores de otras columnas, exit 1.
- CODCLIENTE duplicado en la misma pagina y entre paginas: sin error de ON CONFLICT, gana la ultima fila. ID de contacto duplicado: igual.

### 5. Comandos
- `--dry-run`: extrae y hace upsert real, revierte todo (0 filas, 0 `sync_runs`; con filas existentes y un cambio, no pisa el valor) y rotula "(dry-run, revertido)". Con error dentro del dry-run: exit 1, mensaje seguro, sin quedar transaccion colgada (verificado hasta donde permite la transaccion externa del test).
- `--tenant=noexiste` en sync y en verify: "Tenant 'noexiste' no existe." exit 1.
- verify: exit 0 con "Verificacion OK" cuando coincide; exit 1 con diferencias.

### 6. Aislamiento
- `schedule:list` y `LegacySyncIncrementalAllCommand` no mencionan los comandos raw: el sync nocturno no los encadena.
- Suite `Legacy|ClienteRaw|Almacen` completa (incluye sync-almacenes, sync-clientes y sync-incremental-clientes): 761 tests, 5.680 aserciones, OK, 0 fallos.
- Rollback: `migrate:rollback --path=` de las dos migraciones en el tenant local las elimina (`to_regclass` NULL) sin tocar `legacy.clientes`; `migrate --path=database/migrations/tenant` las recrea con las 114 columnas. Nota: `--step=N` revierte las ultimas N migraciones del lote en orden, que incluirian la de la Fase 2 (`2026_10_14_200001`) antes que las dos de la Fase 1; para revertir solo la Fase 1 usar `--path`.

### 7. Migraciones
- `clientes_raw`: 110 columnas de datos + id, source_hash, created_at, updated_at = 114; PK en `id`; indice unico `legacy_clientes_raw_codcliente_uq` en `codcliente`; NOT NULL solo en id, codcliente y source_hash. `contactos_cliente_raw`: 10 columnas de datos + source_hash + 2 timestamps = 13; PK en `id` (sin surrogate); indice no unico por `codcliente`. Presentes NIF20, DIRECCION2, CODPOSTAL, FAX y OBSERVACIONES (CA1).

## Lo que NO pude probar
- SQL Server real: tipos devueltos por el driver sqlsrv, charset/colacion, `OFFSET/FETCH` contra ICG vivo (altas/bajas durante la corrida), ID duplicado/nulo reales en CONTACTOSCLIENTES, y que la lista de columnas de `INFORMATION_SCHEMA` de ICG coincida con el esquema documentado. Todo con extractores falsos y conexion simulada.
- Memoria y tiempo con filas reales de ICG (los sinteticos son del tamano maximo del esquema, no datos reales).
- Concurrencia: dos corridas simultaneas (el upsert es seguro por `ON CONFLICT`, pero no hay candado; no esta en el scheduler).
- Respaldos (`pg_dump`/snapshots) de las tablas nuevas y la condicion 6 de seguridad (retencion): no son verificables aqui.
- Despliegue en dev/test/prod (fuera de alcance por instruccion).

## Estado del arbol
Test temporal borrado. `git status` de atlanticerp-backend: solo este informe sin commitear. Nota: `infra/test.sh` recrea los esquemas de la BD local compartida (comportamiento habitual del script, no un cambio de esta pasada).
