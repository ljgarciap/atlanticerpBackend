# Senior Review — SCRUM-1114 (sincronización de stock de ICG por delta)

**Fecha**: 2026-10-06
**Revisor**: Senior Reviewer (revisión independiente, sin conocer el razonamiento de quien implementó)
**Alcance**: backend (`atlanticerp-backend`, árbol de trabajo sin commit) y frontend (`atlanticerp-frontend`: StockSyncPanel y su cableado). Revisión estática: no se corrió la suite, migraciones ni nada contra la BD (ya reportadas en verde: 4961 backend, 2584 vitest, PHPStan nivel 8).
**Spec**: `docs/specs/scrum1114-icg-stock-delta-paralelo.md` · **Runbook**: `docs/runbooks/legacy-sync-stock-delta.md`

## Veredicto: CAMBIOS REQUERIDOS

La fórmula, la atomicidad por producto, el CAS de la base y el circuit breaker paramétrico están bien resueltos y bien probados. Hay **2 bloqueantes** (ambos en herramientas de operación, no en el camino feliz) y varias sugerencias, 3 de ellas de prioridad alta antes de encender `activo` en prod.

---

## Lo que está bien (verificado leyendo el código)

- **Fórmula y bordes** (`StockDeltaService::plan`): `round(AL4) - base` sobre enteros; `round()` consistente en loader, baseline y plan (mismo redondeo, sin deriva); base nula vs 0 distinguidos; inactivo/descatalogado no avanza la base; base nula + stock en AtlanticERP queda retenido y reportado (nunca suma a ciegas); producto sin fila en AL4 = 0 y se cuenta en `sin_fila_al4`.
- **Atomicidad**: `apply()` abre una transacción por producto; el movimiento (`KardexService::registrar`, que anida su propia transacción) y `stock_al4_aplicado` van en la misma. `lockForUpdate` sobre `legacy.productos` + CAS de la base (`omitidos_cambiados`) hace segura una corrida concurrente (el orden de locks producto -> fila de stock no tiene ciclo con Bodega, que solo toma la fila de stock).
- **Circuit breaker** con umbrales de `legacy.stock_sync_settings` (nada hardcodeado en el cálculo); `--force` solo aplica tras un motivo ya registrado y deja `forzada` en auditoría.
- **Cadena nocturna** (criterio 3): en `LegacySyncIncrementalStockCommand::ejecutar()` el chequeo de `activo` va ANTES del de baseline, así que con `activo=false` y sin línea base el paso devuelve SUCCESS y la cadena no falla cada noche. `--operador` no salta el requisito de baseline; `--force` sin `--operador` no abre nada si `activo=false`. `legacy:sync-incremental-all` pasa `--dry-run` solo cuando es dry (el flag solo se propaga, no se inyecta).
- **Respuesta vacía de ICG** = fallo, no "todo en 0" (`$al4 === []`); excepción del extractor = fallo registrado.
- **Un fallo de correo nunca rompe la corrida** (`LegacySyncAlertService::notify` captura `Throwable`); dedupe `Cache::add` por entidad+tipo+día; destinatarios = superadmins activos + `correos_alerta`, sin duplicados.
- **Seguridad de API**: rutas `role:superadmin`; `StockSyncSettingsRequest` valida tipos y rangos y NO incluye `baseline_inicializada_at` (el test lo cubre); `StockSyncSettings` usa `LogsAuditTrail` (cada cambio de interruptor/umbral queda auditado).
- **Los productos nuevos** del loader nacen con `stock_al4_aplicado = stock inicial` (o 0); el upsert incremental/full de staging no incluye `stock_al4_aplicado` en las columnas a actualizar, así que la base no se pisa. `stock_al4` sigue fuera del hash (test preexistente `ProductoIncrementalStagingLoaderTest`, ~línea 109, cubre el criterio 13c).
- **Shell** (`legacy-sync-heartbeat.sh`): `$1` de `edad()` sale de una lista fija del propio script, `TENANT` se valida con `case`, solo lee `AWS_DEFAULT_REGION`/`APP_ENV` del `.env`, `</dev/null` en el `docker exec`. Sin inyección.
- **Frontend**: sin emojis (usa los iconos propios), sin umbrales en código, validación espejo de la del backend, 6 tests de comportamiento. El dashboard ya es `role:superadmin` exclusivo, así que el panel no queda en "Cargando..." para otros roles.
- **Rendimiento (análisis estático)**: `plan()` usa `cursor()` y solo retiene en memoria los ítems con delta/base; `$al4` (~64.000 floats) cabe de sobra en el límite de 512M que fija el comando (corre como proceso de SO aparte). `apply()` hace ~7 consultas por producto con delta; aun con las ~11.000 de la primera corrida es del orden de uno o dos minutos. **No medido** (criterio 11 queda para Pre-QA con el catálogo real).

---

## Bloqueantes

### B1. La reversa no es idempotente ni reintentable (`LegacyRevertStockSyncRunCommand.php:128-155`)
El guard "ya revertida" (`resumen['revertida']`, línea 81) se escribe SOLO después de recorrer todos los productos (línea 153), y se escribe aunque haya errores. Dos escenarios concretos:
1. **Caída a mitad de la reversa** (SIGKILL por OOM, deploy, `Ctrl-C`) tras revertir N productos: `revertida` no quedó marcada, el operador vuelve a correr `--apply` y los N productos ya revertidos se revierten **otra vez** (segundo contramovimiento y `stock_al4_aplicado` restado dos veces). Resultado: stock de AtlanticERP y base con signo equivocado en N productos, en un comando cuyo propósito es corregir.
2. **Algún producto falla** (excepción en `registrar`): se marca `revertida` igual y el comando sale con error; reintentar para los que fallaron ya no es posible (`ya fue revertida`). Hay que corregir a mano.

Arreglo propuesto: idempotencia por movimiento, no por corrida. Antes de revertir cada `$mov`, comprobar si ya existe un movimiento `referencia_entidad_tipo='legacy.sync_run_reversa'` con `referencia_entidad_id=$runId` y `catalog_product_id` igual (si existe, omitir y contarlo como ya revertido). Marcar `resumen['revertida']` solo cuando `errores === 0` (o guardar la lista de productos revertidos y permitir reintento). Agregar tests: (a) reversa interrumpida y reanudada no duplica; (b) reintento tras un error revierte solo los pendientes.

### B2. Contadores de `apply()` pueden declarar movimientos que se revirtieron (`StockDeltaService.php:222-233`)
`$out['aplicados']++`, `$out['negativos']++` y `negativos_ids[]` se incrementan DENTRO de la transacción, antes de `$producto->update(...)` (línea 233). Si ese `update` (o el commit) lanza, la transacción se revierte pero los contadores ya subieron, y además `errores++` en el `catch`: el mismo producto cuenta como aplicado y como error. Impacto: `sync_runs.cargados`, el resumen y el correo/pantalla muestran más aplicados/negativos que la realidad (estado falso en el informe que usa el operador para decidir una reversa). Arreglo: acumular en variables locales dentro del closure y volcarlas a `$out` solo después de que `DB::transaction` retorne sin excepción. Test faltante: criterio 5 de la spec (si falla un producto, su base/fila/`stock_quantity` quedan sin cambio y el resto continúa) no tiene ningún test que fuerce una falla; añadir uno (p. ej. fake de `KardexService` que lance para un producto).

> B2 podría clasificarse como Sugerencia por su impacto acotado (solo informe), pero se deja como bloqueante porque el criterio 5 de aceptación está sin cubrir y es la misma corrección.

---

## Sugerencias (ordenadas por prioridad)

### S1 (alta). Una respuesta PARCIAL de ICG no vacía puede pasar el circuit breaker (`LegacySyncIncrementalStockCommand.php:118-123`, `StockDeltaService::plan` líneas 101-104, migración `..._100002` defaults)
Solo se rechaza `[]`. Si STOCKS devuelve, por ejemplo, 80 % de las filas (tabla en reconstrucción, lectura durante un batch en ICG, truncado sin error), los productos ausentes se toman como AL4 = 0 y generan deltas negativos masivos. Con los defaults provisorios (30 % de productos, 2.000 por producto, 30.000 totales) y Σ positiva actual de ~79.700 unidades, un faltante del 20 % (~16.000 unidades, ~2.300 productos) **pasa los tres umbrales y se aplica sola** cuando `activo=true`. El runbook mitiga parcialmente (se afinan umbrales con el dry-run), pero el umbral de porcentaje no distingue "se vendió mucho" de "falta el dato".
Arreglo: guard de sanidad adicional y paramétrico (columna en `stock_sync_settings`): comparar `count($al4)` contra `extraidos` de la última corrida exitosa (caída > X % => held) y/o limitar `sin_fila_al4` nuevo respecto a la corrida previa (hoy se cuenta pero nada lo limita). Test: mapa con el 20 % de filas ausentes retiene la corrida.

### S2 (alta). Con `activo=true` y sin línea base la corrida programada falla en silencio (`LegacySyncIncrementalStockCommand.php:98-102`)
Devuelve FAILURE sin registrar en `sync_runs` ni alertar, así que ni el health-check ni el correo se enteran. Además `StockSyncSettingsController::update` permite poner `activo=true` con `baseline_inicializada_at = null` (la UI solo muestra un aviso). Arreglo: en el controlador/Request rechazar `activo=true` (422) si no hay línea base; y en el comando, si es una corrida programada, usar `fallar()` (registra + `alertFailed`).

### S3 (alta). Baseline: productos "inactivos al momento de la carga" que hoy están activos (`LegacyInitStockBaselineCommand.php:109-125`)
Si `stock_quantity` es null y no hay filas por bodega pero `stock_al4` es no nulo (p. ej. 20), la base se fija en `round(stock_al4)=20` mientras `esperado=0`: el producto se lista como inconsistente (y bloquea `--apply`), y con `--force` se escribe una base de 20 que **nunca importa esas 20 unidades** (el delta sería 0). Lo correcto según el diseño es dejarlo con base nula (el plan lo trata como "desde cero" y lo siembra). En prod no aplica hoy (T1: los 705 sin `stock_quantity` no tienen fila en AL4), pero sí en dev/test o si prod cambia antes del despliegue. Arreglo: si `stock_quantity IS NULL` y no tiene filas, no inicializar (dejar null) y reportarlo en una fila aparte del informe.
Además, la rama "reconstruida" (`refrescada`) siempre cuadra consigo misma (base = esperado por construcción), así que la verificación de consistencia no detecta ahí cambios de `stock_quantity` hechos fuera de Kardex (GoodsReceipt toca la columna directo, según el comentario de `KardexService`). Documentar la limitación en el runbook o restringir el dry-run a mostrar esos casos.

### S4. Datos sensibles potenciales en correos y resumen (`LegacySyncIncrementalStockCommand.php:106,115,125,159,183`; `LegacySyncAlertService`)
`$e->getMessage()` se mete tal cual en el correo (que también va a `correos_alerta`, posiblemente externos) y en `sync_runs.resumen`. Un error del driver sqlsrv hacia ICG suele incluir host/IP del gateway VPN, y una `QueryException` incluye SQL con bindings. Arreglo: recortar a una longitud fija y quitar host/IP/SQL antes de enviar; dejar el mensaje completo solo en el log. (No verificado cómo se ve exactamente el mensaje del driver real.)

### S5. Reversa: detalles de consistencia (`LegacyRevertStockSyncRunCommand.php`)
- Tras revertir, la base vuelve al valor anterior, por lo que **la próxima corrida programada reaplicará el mismo delta** mientras ICG conserve el valor. Es lo correcto para una corrida mala (p. ej. por S1), pero el runbook debe decir explícitamente "apague `activo` antes de revertir", o el efecto se deshace solo.
- `lockForUpdate()` sobre un `update()` (línea 144) no hace nada; el `UPDATE ... base - delta` relativo ya es atómico, quitar el lock engañoso.
- Un producto que partió de base nula queda con base 0 tras la reversa (no null): aceptable, pero documentarlo.
- `hayPosterior` consulta por producto dentro del loop (N consultas); aceptable para una operación manual.

### S6. Alertas: el dedupe se consume antes de enviar (`LegacySyncAlertService::notify`)
`Cache::add` marca "ya avisado" antes de `Mail::send`; si el envío falla, no hay reintento ese día (queda solo en log). Arreglo: `Cache::forget` de la clave en el `catch`. Tampoco hay test de que un fallo de envío libere el dedupe.

### S7. Umbral hardcodeado menor (`LegacyCheckSyncHealthCommand.php`: `STOCK_DISABLED_GRACE_HOURS = 48`)
No es un umbral de negocio, pero la regla global pide parametrizar valores de este tipo; moverlo a `stock_sync_settings` es barato, o aceptarlo explícitamente en el runbook.

### S8. Cobertura de tests y spec
Criterios de la spec sin respaldo directo en tests:
- **5 (atomicidad ante falla de un producto)**: sin test (ver B2).
- **11 (rendimiento ~12.000 artículos / memoria t3.small)**: no medido; queda para Pre-QA.
- **9 (Lafayette)**: no hay test explícito; el alcance se logra por `catalog_product_id` ligado a ICG y no hay código que toque Lafayette, pero conviene un test de "producto sin vínculo ICG" (existe `test_un_producto_no_ligado_a_icg_no_se_toca`; no hay uno específico de Lafayette).
- Criterio 3 de la revisión (activo=false + sin baseline => exit 0 en la cadena): el orden del código es correcto, pero el test visible (`test_la_corrida_programada_no_corre_con_el_interruptor_apagado...`) conviene que fije explícitamente la combinación SIN línea base.
- Reversa: faltan los dos tests de B1.
Cubiertos con test: 2, 3 (spec), 4, 6, 7, 8, 10, 12, 13 (a, b, c).

### S9. Higiene de entrega
- En `atlanticerp-frontend` hay archivos sin seguimiento ajenos a este ticket (`tsconfig*.tsbuildinfo`, `vite.config.js`, `vite.config.d.ts`, `_preqa_dev.config.ts`, `e2e/_preqa_*.spec.ts`, `screenshots/`); en `atlanticerp-backend`, `docs/pre-qa/scrum990-capturas-20261001/`. Commitear solo archivos explícitos (riesgo conocido de arrastrar trabajo ajeno con `git add <dir>`).
- `AppServiceProvider.php`: los dos `use` nuevos quedaron fuera de orden alfabético (cosmético).
- El comentario en `LegacySyncIncrementalAllCommand` ("las 10 entidades como 9 entradas independientes") quedó incoherente tras el reemplazo masivo 9->10 en el texto histórico.

---

## No pude verificar
- Rendimiento y memoria reales con ~12.000 artículos (criterio 11).
- Cómo se comporta `pdo_pgsql` con `EXISTS(...)` como booleano en el host de prod (el código hace `(bool)`; con PHP 8.3 el driver devuelve bool nativo, y los tests pasan, pero no lo probé).
- Existencia/instalación del cron y de las alarmas de CloudWatch del latido (no están en el repo; son acción de DevOps/AWS con aprobación de Luis).
- El texto exacto del mensaje de error del driver sqlsrv hacia ICG (S4).

## Resultado
**CAMBIOS REQUERIDOS**: corregir B1 y B2 (con sus tests) y volver a pasar por Senior Review; S1, S2 y S3 deben resolverse o aceptarse explícitamente por Luis antes de encender `activo` en producción.
