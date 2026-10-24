# Migración ICG — sincronización de stock por delta durante la operación en paralelo

**Date**: 2026-10-06
**Ticket**: SCRUM-1114, épica SCRUM-749 (seguimiento de SCRUM-921 / SCRUM-1016 / SCRUM-940)
**Status**: spec del Analista + diseño del Arquitecto (2026-10-06), diseño APROBADO por Luis el 2026-10-06; desglose del PM al final. Sin código.
**Relacionada**: `legacy-backfill-stock-bodega-central.md` (runbook), `limpieza-previa-lanzamiento.md`, `app/Modules/Legacy/CLAUDE.md`

## Problema
El stock de ICG (almacén AL4 = Bodega Central) se cargó UNA vez en prod el 2026-10-06 (SCRUM-940: 11.000 `stock_quantity`, 3.497 filas en Bodega Central, Σ 78.201) y
después AtlanticERP nunca vuelve a leerlo (`CatalogProductLoader` solo siembra al crear; `stock_al4` no entra al hash del incremental). Mientras ICG siga operando en
paralelo, el saldo de AtlanticERP se desactualiza: si el producto X arranca en 100, ICG mueve 10 y AtlanticERP mueve 5, AtlanticERP mostraría 95 y la realidad es 85.
Solo "refrescar" el dato desde ICG pisaría lo que AtlanticERP movió; hay que importar **el delta de ICG** y sumarlo a lo que AtlanticERP ya tiene.

## Reglas de negocio (decididas por Luis 2026-10-06)
1. **AtlanticERP es la fuente de verdad.** ICG aporta únicamente su variación desde la última lectura.
2. **Sin doble registro:** cada movimiento físico se registra en un solo sistema (los de ICG llegan por delta, los de AtlanticERP quedan solo en AtlanticERP).
3. **El delta es la diferencia neta de AL4, con signo**, sin distinguir el origen (ventas, compras, ajustes, devoluciones). Puede ser positivo o negativo.
4. **Línea base inicial en prod = el valor exacto cargado el 2026-10-06.**
5. **Producto sin fila/NULL en AtlanticERP:** si ICG lo mueve, la fila se crea partiendo de 0 con el delta. **Los negativos se permiten** y se reportan.

## Diseño propuesto (a validar por el Arquitecto)
- **Línea base por producto:** columna nueva `legacy.productos.stock_al4_aplicado` (float nullable) = último AL4 ya reflejado en AtlanticERP. No se reutiliza `stock_al4`: hoy solo se
  refresca cuando cambia el hash de la fila, así que no es confiable como base.
- **Paso nuevo de lectura:** lee AL4 de TODOS los artículos ligados (`catalog_product_id`) en cada corrida, independiente del hash (el hash de catálogo no cambia).
- **Fórmula:** `delta = round(AL4_hoy) − round(stock_al4_aplicado)`. Si `delta ≠ 0`: en UNA transacción por producto se registra un movimiento de kardex con
  `KardexService::registrar()` sobre Bodega Central (el servicio ya aplica el mismo delta firmado a `product_warehouse_stock` y a `catalog_products.stock_quantity`) y se
  actualiza `stock_al4_aplicado = AL4_hoy`. Si la transacción falla, ninguna de las dos cosas cambia (idempotencia: base y movimiento viajan juntos).
- **Movimiento de kardex:** tipo a decidir por el Arquitecto (`Entrada`/`Salida` según el signo, o `Ajuste`), con `referencia_texto` "Ajuste por sincronización ICG",
  `referencia_entidad_tipo = 'legacy.sync_run'`, `referencia_entidad_id` = id de la corrida y `realizado_por = null` (Sistema).
- **Casos:** `AL4_hoy` pasa de NULL a valor → delta sobre base 0 (crea la fila). `AL4_hoy` NULL con base no nula (ICG borró la fila) → se trata como 0 y se reporta.
  Un producto nuevo creado por `CatalogProductLoader` fija `stock_al4_aplicado = stockInicial` al crearse (sin delta en ese momento).
- **Alcance:** solo productos ligados a ICG, activos y no descatalogados (mismo criterio que `legacy:backfill-stock-bodega-central`). Para un producto inactivo/descatalogado
  no se aplica ni se avanza la base (el delta se acumula y se aplica si se reactiva). Lafayette no participa. Aborta si Bodega Central no existe o es `solo_consulta`.
- **Cadencia:** corre dentro de `legacy:sync-incremental-all` (prod 21:00 diario; dev domingo 05:00; test domingo 01:00), después del paso de productos, como proceso del SO aparte.
  También invocable a mano con `--dry-run` por defecto y `--apply`.
- **Circuit breaker y umbrales paramétricos:** tope de productos con delta y de unidades netas/absolutas por corrida. Los umbrales viven en una tabla paramétrica con CRUD y
  vista de superadmin (regla global: nada hardcodeado); si se superan, la corrida no aplica nada y avisa a los superadmins.
- **Informe de divergencia por corrida** (se guarda en `legacy.sync_runs`): productos con delta (positivos/negativos, unidades), productos creados desde 0, productos que
  quedaron en negativo, anomalías sobre el tope, y la verificación `Σ product_warehouse_stock ≠ catalog_products.stock_quantity` (lista de productos que no cuadran).
- **Cutover:** el día que ICG se congela se corre una última vez y se retira el paso del scheduler. Reversa de una corrida: contramovimientos por `referencia_entidad_id`.

## Criterios de aceptación
1. **Línea base:** antes de implementar, consulta de SOLO LECTURA en prod que compara `legacy.productos.stock_al4` contra lo cargado el 2026-10-06 (Σ 78.201 en Central, 11.000
   `stock_quantity`) y documenta las diferencias; la migración que crea `stock_al4_aplicado` la inicializa con el valor exacto cargado, no con un AL4 nuevo.
2. **Ejemplo canónico:** base 100, ICG pasa a 90, AtlanticERP movió −5 antes de la corrida → después de la corrida el stock de AtlanticERP es 85 y `stock_al4_aplicado = 90`.
3. **Delta positivo:** base 100, ICG pasa a 120, AtlanticERP movió −5 → AtlanticERP queda en 115.
4. **Sin cambios en ICG:** delta 0 → no se crea movimiento ni se toca ninguna fila; una segunda corrida inmediata no cambia nada (idempotente).
5. **Atomicidad:** si falla el movimiento de un producto, su `stock_al4_aplicado`, su fila por bodega y su `stock_quantity` quedan sin cambio; el resto de la corrida continúa.
6. **Producto sin fila:** ICG lo mueve de NULL a 7 → se crea la fila de Bodega Central con 7 y un movimiento de kardex.
7. **Negativos:** un delta que deja el stock de AtlanticERP en negativo se aplica y aparece en el informe; no se trunca.
8. **Un solo camino de escritura:** `Σ product_warehouse_stock = stock_quantity` para todo producto tocado por la corrida; el informe lista los que no cuadran sin corregirlos.
9. **Alcance:** no toca Lafayette, productos inactivos/descatalogados ni productos no ligados a ICG.
10. **Circuit breaker:** con un delta que supere los umbrales paramétricos no se aplica nada, se registra el motivo y se avisa a los superadmins; los umbrales se editan desde la vista de superadmin sin deploy.
11. **Rendimiento:** corrida completa medida con el catálogo real (~12.000 artículos) sin superar el presupuesto de memoria del host (t3.small), sin solaparse con las otras entidades del sync.
12. **Auditoría y reversa:** cada corrida deja un evento de auditoría con operador/entorno/conteos y los movimientos son reversibles por id de corrida.
13. **Prueba de regresión:** test con el ejemplo 100/90/−5 y con delta positivo, test de idempotencia y test de que `stock_al4` sigue fuera del hash del incremental.

## Fuera de alcance
- Reconciliar o corregir automáticamente productos donde `Σ por bodega ≠ stock_quantity` (solo se reportan).
- Stock de almacenes de ICG distintos de AL4 y Zona Libre/Lafayette.
- Cualquier cambio a la regla "los negativos de ICG se preservan" (decisión de Luis, ya vigente).

## Diseño del Arquitecto (2026-10-06) — aprobado por Luis
Las 4 decisiones abiertas quedan resueltas así, con la evidencia del código:

1. **Tipo de movimiento = `Ajuste`**, distinguido por `referencia_entidad_tipo = 'legacy.sync_run'` y `referencia_texto = 'Ajuste por sincronización ICG (corrida #N)'`.
   `Entrada`/`Salida` se descartan: `BodegaHomeService::mayorRotacion()` (línea 546) cuenta todo movimiento `Salida` como rotación real, y las ventas de ICG la inflarían.
   Un tipo nuevo se descarta también: `KardexTipo` (`types/bodega.ts:210`) y el filtro de `KardexPage.tsx` lo ignorarían y habría que tocar el frontend. Ningún KPI lee `Ajuste` hoy
   (solo `AdjustmentRequestController` lo crea). Mejora opcional posterior: una insignia "ICG" en el Kardex.
2. **Paso propio, décimo de `legacy:sync-incremental-all`, justo después de `productos`** (comando `legacy:sync-incremental-stock`, `sync_runs.entidad = 'stock'`).
   Dentro del comando de productos se descarta porque su circuit breaker (cambio de catálogo > 20%) es una señal distinta: si se retiene el catálogo, el stock quedaría frenado sin motivo.
   Lee solo `STOCKS WHERE CODALMACEN='AL4' AND TALLA='.' AND COLOR='.'` (una tabla, ~64.000 filas), no vuelve a leer `ARTICULOS`. Debe sumarse `'stock'` a la lista de entidades de
   `LegacyCheckSyncHealthCommand` y a la documentación del orden de dependencia (§14.1 de `migracion-icg-diseno.md`). En prod corre a las 21:00, cuando ICG ya no tiene actividad: la lectura es una foto consistente.
3. **Umbrales y control en tabla paramétrica singleton** `legacy.stock_sync_settings` (mismo patrón que `ComprasSettings`, con CRUD y vista de superadmin; sin deploy):
   `activo` (interruptor, sirve también para el cutover), `max_productos_con_delta_pct`, `max_unidades_abs_por_producto`, `max_unidades_abs_total`. Valores iniciales: se fijan con
   el dry-run real (distribución de deltas), nunca como constantes (a diferencia del `CHANGE_THRESHOLD = 0.20` de productos, que no se replica). En prod nace con `activo = false`.
4. **Producto inactivo o descatalogado:** no se aplica ni se avanza la base. Para un producto elegible con base NULL que NO tiene filas por bodega ni `stock_quantity` (nunca se cargó),
   la primera corrida lo trata como siembra (stock = AL4, base = AL4, sin delta). Si ya tiene stock en AtlanticERP, queda retenido y reportado para decisión humana: nunca se suma a ciegas.

### Ajustes a la spec por este diseño
- **Alertas (criterio 10):** una corrida que supera un umbral queda `held_for_review` en `legacy.sync_runs` y se avisa por `LegacySyncAlertService` (hoy log a CloudWatch; el gap de SNS ya está documentado
  y sigue pendiente) y se muestra en el dashboard de migración. No se reutiliza `SecurityAnomalyDetected` (es de Seguridad Agéntica). Forzar una corrida retenida: `--force` manual, como en productos.
- **Primera corrida en prod supervisada:** `--dry-run` manual, revisar el informe, `--apply` manual con operador, y recién entonces `activo = true` para el scheduler.
- **Resumen de la corrida:** `legacy.sync_runs` solo tiene contadores; se agrega una columna `resumen` JSONB nullable (productos con delta, unidades +/−, creados desde 0, en negativo, retenidos, no cuadran).
- **Línea base inicial (criterio 1):** la migración la inicializa con `round(stock_al4)`. La consulta de solo lectura debe revisar los productos cuyo `legacy.productos.updated_at` es posterior a la carga (01:42 del 2026-10-06):
  para esos, el valor cargado se reconstruye con `saldo_inicial` del primer movimiento de Kardex posterior a la carga o, sin movimientos, con la cantidad actual de Bodega Central.
- **Concurrencia:** cada producto va en su propia transacción; `KardexService` ya toma `lockForUpdate` sobre la fila de Central, y la base se actualiza en esa misma transacción.

### Riesgos aceptados
- Mientras SCRUM-1114 no esté desplegado, la deriva crece cada día que ICG opera (hoy son horas desde la carga). No hay efecto destructivo, solo desactualización.
- El stock de AtlanticERP puede quedar negativo por un delta de ICG (decisión de Luis); aparece en el informe, no se corrige solo.
- La alerta sigue siendo un log hasta que exista el cliente SNS.

## Alertas y corrida no ejecutada (validado 2026-10-06, a pedido de Luis — reemplaza el punto "Alertas" de los ajustes de arriba)
**Canales que existen hoy (verificado en el código):**
- **Correo: SÍ.** SMTP (M365 en prod, Mailpit en dev/test) con precedente directo: `NotifySuperadminsOfAnomaly` envía `SecurityAnomalyMail` a todos los superadmins activos. También existe el módulo `Notifications`.
- **Teléfono (SMS/WhatsApp): NO desde la app.** No hay SDK ni proveedor de SMS/WhatsApp; los "whatsapp" del código son campos de contacto. SNS puede enviar SMS a un número suscrito, pero hoy `atlanticerp-alerts`
  solo tiene correos, la app no tiene `sns:Publish`, y no está verificado que SNS entregue SMS a números de Panamá ni su costo. Queda como tarea de evaluación del AWS Architect (fase 2), no como requisito.
- **Hoy `LegacySyncAlertService` solo escribe al log**, así que ni un `held_for_review` ni una corrida saltada avisan a nadie.

**Diseño en tres capas:**
1. **Correo en `LegacySyncAlertService`** (aplica al paso de stock y, al ser servicio compartido, a las 9 entidades existentes): destinatarios = superadmins activos + lista adicional en `legacy.stock_sync_settings`
   (`correos_alerta`); un solo correo por entidad y por slot (sin spam); cubre `held_for_review`, `failed`/errores, corrida saltada y "sync de stock desactivado en prod más de 48 h después de la primera corrida".
   Cambiar el comportamiento de las 9 entidades existentes (de log a correo) es una decisión incluida en este diseño; si Luis prefiere limitarlo a stock, se acota en la tarea T9.
2. **Health-check de la entidad `stock`:** se suma a `LegacyCheckSyncHealthCommand::ENTITIES`; si no hay corrida en la ventana esperada, alerta por correo.
3. **Detector independiente del scheduler (la capa que cubre "la migración no se llevó a cabo"):** el health-check de la capa 2 corre dentro del mismo scheduler; si el contenedor `scheduler` o `horizon` está caído, ni la corrida ni su chequeo ocurren y nadie se entera.
   Se agrega un latido: al terminar `legacy:sync-incremental-all` con éxito se publica la métrica `AtlanticERP/LegacySyncLastSuccess` (precedente: `infra/scripts/ship-wal.sh` ya hace `put-metric-data` al namespace `AtlanticERP`)
   y una alarma de CloudWatch con `treat-missing-data=breaching` (prod: sin latido en 26 h; dev/test: 8 días) publica a `atlanticerp-alerts` (3 correos ya suscritos). Quién publica la métrica (host por cron leyendo `legacy.sync_runs`, o la app) lo decide el AWS Architect según el permiso IAM mínimo.

## Desglose del PM
Roles: BE = Backend Dev, FE = Frontend Dev, DO = DevOps, AWS = AWS Architect, SEC = Cybersecurity, TW = Tech Writer. Todo sobre `dev`; tests: ciclo interno con `infra/test.sh --filter` y gate completo antes de Senior Review y de cualquier push.

| # | Tarea | Rol | Depende de | Aceptación (criterios de la spec) |
|---|---|---|---|---|
| T1 | Consulta de SOLO LECTURA en prod: staging `legacy.productos.stock_al4` vs lo cargado el 2026-10-06; lista de productos con `updated_at` posterior a la carga; Σ Central 78.201 y 11.000 `stock_quantity`. Documentar diferencias. Requiere aprobación de `ssh` de Luis | DO + BE | — | 1 |
| T2 | Migraciones: `legacy.productos.stock_al4_aplicado`, `legacy.sync_runs.resumen` (JSONB), `legacy.stock_sync_settings` (singleton: `activo`, 3 umbrales, `correos_alerta`; seeder con `activo=false` en prod) | BE | Arquitecto (hecho) | 3, 10 |
| T3 | Inicialización de la base (`round(stock_al4)` + regla de reconstrucción de T1) y `CatalogProductLoader` fija la base al crear | BE | T1, T2 | 1, 6 |
| T4 | Extractor `STOCKS` solo AL4 (contrato + fake para tests) | BE | — | 11 |
| T5 | `StockDeltaService`: delta por producto en transacción única vía `KardexService` (`Ajuste`, referencia al sync), casos NULL/negativos/inactivos/retenidos, siembra de elegibles sin stock | BE | T2, T4 | 2-7, 9 |
| T6 | Comando `legacy:sync-incremental-stock` (dry-run por defecto, `--apply`, `--force`, circuit breaker con umbrales de la tabla, `sync_runs.entidad='stock'`, informe `resumen` con verificación Σ bodega = `stock_quantity`), décimo paso de `legacy:sync-incremental-all` y entidad `stock` en el health-check | BE | T5 | 4, 8, 10, 12 |
| T7 | API de superadmin: leer/editar `stock_sync_settings` con auditoría de cambios y último informe | BE | T2 | 10 |
| T8 | Vista de superadmin: ajustes del sync, interruptor, último informe y corridas retenidas | FE | T7 | 10 |
| T9 | Correo en `LegacySyncAlertService` (superadmins + `correos_alerta`, un correo por entidad y slot) con `Mail::fake()` en tests, nunca correos reales | BE | T2 | alertas, capa 1 y 2 |
| T10 | Latido `AtlanticERP/LegacySyncLastSuccess` + alarma CloudWatch (missing data = breaching) → `atlanticerp-alerts`; evaluar SMS por un tema separado de alta severidad (no desplegar sin aprobación) | AWS + DO | T6 | alertas, capa 3 |
| T11 | Revisión de seguridad: endpoint de ajustes solo superadmin, auditoría, contenido de los correos, IAM mínimo del latido (en paralelo al Senior Review) | SEC | T7, T9, T10 | — |
| T12 | Runbook `legacy-sync-stock-delta.md` (primera corrida supervisada, cutover, reversa por id de corrida), actualizar `app/Modules/Legacy/CLAUDE.md` y §14.1 de `migracion-icg-diseno.md` | TW | en paralelo | 12 |
| T13 | Gate: Senior Review, luego Visual Review + Pre-QA fusionados (medición con las ~12.000 filas reales, memoria bajo el límite del host, ejemplo 100/90/−5 contra datos reales de dev), luego QA | Revisores | T1-T12 | 11, 13 |
| T14 | Despliegue: dev → test (corre en su domingo) → prod; primera corrida supervisada (dry-run, revisión, `--apply` manual con operador, `activo=true`) | DO | T13 | 1, 12 |

Camino crítico: T1 → T3 → T5 → T6 → T13 → T14. Paralelos: T2/T4/T7/T9/T12 desde el inicio; T8 tras T7; T10/T11 tras T6. Cada fix de Pre-QA se pushea a `dev`; el push de cada tarea pasa por el gate de `git push` de Luis.

## Resultado de T1 — línea base verificada en prod (2026-10-06 16:26 UTC, solo lectura)
Consulta en transacción `READ ONLY` con `ROLLBACK`, sin escribir nada. La carga (evento de auditoría) fue el 2026-10-06 05:41 UTC (00:41 Panamá).
- Bodega Central (id 6): 3.497 filas, Σ 78.201 (79.687 positivas, −1.486 en 226 productos).
- 11.705 `catalog_products` ligados a ICG: 11.000 con `stock_quantity` (Σ 78.201) y 705 en NULL. Todos tienen `stock_al4` en staging (Σ redondeada 78.201; 705 sin fila en AL4).
- Para los 11.000 activos y no descatalogados, `round(stock_al4)` = `stock_quantity` en TODOS (0 diferencias, diferencia neta 0).
- 0 filas de staging actualizadas después de la carga y **0 movimientos de Kardex** desde la carga (Bodega todavía no opera en prod).
- 0 productos cuyo desglose por bodega no cuadra con `stock_quantity`.
- Última corrida programada de todas las entidades: 2026-10-06 02:00-03:15 UTC, sin errores (productos: 12.352 extraídos, 1 cargado).
Una foto de la línea base (`icg_codarticulo, catalog_product_id, stock_al4, stock_quantity, central_qty`, 11.705 filas) quedó fuera de la BD como evidencia (no commiteada).

**Conclusión:** la línea base es exactamente lo cargado y no necesita reconstrucción compleja hoy.
**Ajuste a T3:** la base inicial NO se copia ciegamente de `stock_al4`: el sync incremental nocturno (21:00 Panamá) refresca `stock_al4` en las filas cuyo hash de catálogo cambia, y eso la movería sobre lo cargado.
La migración debe derivarla de lo que AtlanticERP tiene: para cada producto, `stock_quantity` (o la cantidad de Bodega Central) menos los movimientos de Kardex posteriores a la carga; con 0 movimientos, es `stock_quantity` tal cual.
Se verifica de nuevo en el dry-run de T1 contra el momento del despliegue. Mientras no esté desplegado, el movimiento de Bodega (hoy cero) no contamina la base.

## Revisión independiente (2026-10-06) y correcciones
Informes: `docs/reviews/scrum1114-senior-review-20261006.md` (CAMBIOS REQUERIDOS, 2 bloqueantes) y `docs/security/scrum1114-revision-seguridad-20261006.md` (aprobado con condiciones, 0 críticos).
Corregido en la misma sesión (cada punto con test):
- **B1 reversa reintentable:** idempotente por movimiento (si el contramovimiento ya existe se omite); `revertida` solo cuando todo se revirtió sin error ni productos omitidos (si no, `reversa_parcial`); exige `activo = false`.
- **B2 contadores:** `apply()` acumula los efectos solo después del commit; un fallo al actualizar la base revierte el movimiento y no infla `aplicados`/`negativos` (criterio 5, ahora con test que fuerza el fallo).
- **S1 respuesta parcial de ICG:** nuevo parámetro `min_filas_icg_pct` (80 %): si las filas de AL4 leídas son menos que ese % de las de la última corrida buena, la corrida queda `held_for_review`.
- **S2 sin línea base:** la corrida falla registrada y avisada (no en silencio) y la API responde 422 al intentar activar sin línea base.
- **S3 línea base de productos "nunca cargados":** un producto elegible hoy con stock en ICG y `stock_quantity` nulo queda con base nula para que la primera corrida lo siembre.
- **Seguridad S-02:** topes de validación realistas (50 % / 50.000 por producto / 500.000 en total) y correo a los superadmins por cada cambio del interruptor o de un umbral (quién y desde qué IP).
  **S-03:** correos individuales por destinatario (nadie ve a los demás) y lista de dominios permitidos opcional (`LEGACY_ALERT_ALLOWED_DOMAINS`; vacía = sin restricción — **Luis decide los dominios**).
  **S-04:** la auditoría de los comandos manuales agrega usuario del SO, host y origen ssh, y cada corrida/línea base/reversa lanzada a mano o forzada avisa por correo.
  **S-05:** la deduplicación del aviso se marca solo tras un envío exitoso; el latido publica además `…LastSuccessAgeHours`, que sí sube si la cadena corre pero falla o se retiene cada noche.
  **S-06/07/09:** reversa parcial no marcada como hecha, mensajes de error recortados y aplanados, candado por tenant compartido por sync, línea base y reversa. `horas_aviso_apagado` pasó de constante a parámetro.

### S-01 (Alto) — riesgo ACEPTADO por Luis el 2026-10-06 (decisión (a))
Luis eligió aceptarlo por escrito, apoyado en el circuit breaker, el informe de divergencia, el guard de respuesta parcial y los correos de cada evento manual. Queda como deuda de seguimiento conocida: extender REQ-635 para leer estos movimientos si el riesgo deja de ser aceptable.
También decidió **no restringir por ahora los dominios de `correos_alerta`** (S-03): `LEGACY_ALERT_ALLOWED_DOMAINS` queda vacío; el riesgo se mitiga porque cada cambio de ajustes avisa por correo a los superadmins.

Contexto original de S-01:
Los ajustes del sync (kardex `Ajuste`, `realizado_por` nulo) no pasan por solicitud/aprobación y las reglas de fraude REQ-635 solo leen `AdjustmentRequestLine`: un delta fraudulento concentrado en pocos productos de alto valor no se detectaría.
Opciones: (a) aceptarlo por escrito, compensado con el circuit breaker, el informe y los correos de eventos manuales; (b) extender REQ-635 para leer estos movimientos y alertar (delta de alto valor concentrado, deltas recurrentes sobre un mismo producto).
**Condiciones para encender `activo` en producción: S-01 (aceptado), S-02 y S-05 (corregidos arriba) — cumplidas.**
