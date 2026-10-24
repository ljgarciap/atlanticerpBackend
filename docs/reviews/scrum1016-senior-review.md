# Senior Review — SCRUM-1016 (backend): stock inicial de ICG por bodega (Bodega Central)

Commits revisados: `00c0b1bc` (comando + AuditService + 7 tests), `0d2809a8` (CatalogProductLoader + 2 tests), `d8b4072d` (runbook + nota del módulo). Se ignora `9ecd8e0f`.
Revisión de solo lectura: no se ejecutó ninguna prueba (se toma como dato la corrida completa: 4455 en verde, PHPStan 8 limpio).

## Veredicto: APROBADO CON OBSERVACIONES (0 CRÍTICO, 0 ALTO, 3 MEDIO, 6 BAJO)

- Push a `dev` y a `test`: SÍ.
- Dry-run en test: SÍ, ya.
- `--apply` en test: SÍ, después de revisar el dry-run y con la decisión de Luis sobre M1 (productos inactivos). Conviene correrlo en una ventana de poca actividad de Bodega (ver M2).
- Prod: no (según lo decidido); sin cambios de criterio.

## 1. Selección (consulta `analizar()`, `LegacyBackfillStockBodegaCentralCommand.php:146-152`)

Correcta para lo pedido:
- "Ligado a ICG" = `EXISTS (legacy.productos.catalog_product_id = c.id)` (l.148). Es el mismo criterio que usa `lafayette:aislar-catalogo` para restaurar productos ICG, y el único camino que escribe ese vínculo es `CatalogProductLoader` (l.164). Los productos de Lafayette aislados y los promovidos de SCRUM-990 (#12900, #22454) no tienen vínculo, así que no entran. Verificado leyendo el aislador: los "puros a promover" se definen justamente como "sin vínculo ICG".
- `stock_quantity IS NOT NULL AND <> 0` y `NOT EXISTS` fila en cualquier bodega (l.149-150): nulo, 0 y productos con fila en otra bodega o en Central (incluso con cantidad 0) no se siembran. Una sola fila por producto; ninguna suma sobre nada existente.
- No hay forma de dejar fuera un producto que sí corresponde, salvo la decisión de diseño de "con alguna fila, no se toca" (ver B5).
- Los NULL de `stock_quantity` están tratados explícitamente (el `<>` solo ya los excluiría).

### M1 (MEDIO) — Se siembran también productos `is_active = false` y `descatalogado`
La selección no mira `catalog_products.is_active` (ni `legacy.productos.descatalogado`). El loader nunca vuelve a tocar un producto desactivado localmente (SCRUM-838), pero el backfill sí le crea fila en Central. `WarehouseController::show` (listado por bodega, `where quantity > 0`, sin filtro `is_active`) los mostraría en Central y los contaría en las unidades de la bodega, mientras que Compras/Bodega Inventario sí los esconden (`cp.is_active = true`, `BodegaInventoryController.php:88`). Es una decisión de negocio, no un bug de código.
Fix: (a) el dry-run debe reportar cuántos de los seleccionados son inactivos y cuántas unidades (barato: un `FILTER (WHERE NOT c.is_active)`), y (b) Luis decide si se excluyen (`AND c.is_active`) o se siembran. Hacerlo antes del `--apply` en test.

## 2. Escritura

Correcto:
- SQL sin valores no controlados: tablas salen de los modelos, `$bodegaId` es int, la lista de ids es `implode` de enteros casteados (l.211-216, 284). Sin entrada del usuario en el SQL. `--lote` validado 1..5000, `--operador` solo va a metadata (JSON), no al SQL.
- Un solo INSERT por lote, `ON CONFLICT (catalog_product_id, warehouse_id) DO NOTHING` sobre la unique key real (`product_warehouse_stock_unique`). Si el lote inserta menos filas de las esperadas, aborta (l.217-219).
- Una transacción (l.110); ROLLBACK ante cualquier excepción; el evento de auditoría se escribe dentro de la misma transacción (si la auditoría falla, no queda nada).
- Nunca escribe `stock_quantity` ni otra bodega: el INSERT solo lee de `catalog_products`. La verificación lo comprueba (huella de todos los `stock_quantity`, huella de todas las filas previas, Σ nuevas = Σ seleccionados, conteo de filas de Central) y el test de ROLLBACK con un trigger que suma +1 demuestra que la verificación sí detecta un error real.
- Negativos: la columna `quantity` es `integer` sin CHECK; entran tal cual. Trigger `product_warehouse_stock_solo_consulta` (BEFORE INSERT, por fila): se dispara también en el INSERT en bloque; el guard de Eloquent (`saving`) no se ejecuta pero el trigger es la barrera definitiva y además el comando aborta antes si Central es `solo_consulta`. Si Central pasara a `solo_consulta` entre el chequeo y el INSERT, el trigger lanza excepción y hay rollback.
- Dry-run: solo SELECT; sin escrituras. Mensajes de error sin SQL (`MensajeSeguroDeExcepcion`).

### B1 (BAJO) — El INSERT no repite los predicados de selección
`aplicar()` l.214-216 inserta "los ids de la lista" releyendo `c.stock_quantity`; no repite `ligado`/`<>0`/`NOT EXISTS`. Hoy está cubierto porque la selección se recalcula en la misma transacción y la verificación posterior detecta cualquier cambio, pero es defensa en profundidad barata: agregar `AND {$ligado} AND c.stock_quantity <> 0 AND {$sinFila}` al `WHERE` del INSERT (conserva el chequeo de conteo del lote).

### B4 (BAJO) — Sin guard sobre `excluye_disponible`
Si alguien marcara Central como `excluye_disponible`, sembrarla haría que "Disponible" de ~3.300 productos cayera al instante (`InventoryKpiService::disponibleParaVenta` resta lo que hay en bodegas excluidas). Hoy Central no lo está. Fix: abortar en `resolverBodega()` si `excluye_disponible` es true.

## 3. Concurrencia

El filtro "sin ninguna fila" + `ON CONFLICT DO NOTHING` + conteo por lote es suficiente para que NUNCA se corrompa nada; no hace falta lock. Casos razonados:
- Bodega crea la fila de Central entre la selección y el INSERT (Kardex hace `firstOrCreate`): conflicto o diferencia de conteo => aborta limpio.
- Bodega crea una fila en OTRA bodega (no hay conflicto de unique key): el cambio de `stock_quantity` (Kardex lo actualiza en la misma transacción) y la huella de filas previas lo detectan al verificar; si el commit de Bodega llega después de la verificación, el resultado es consistente (Central = saldo ICG, otra bodega = delta, `stock_quantity` = suma).
- Kardex bloquea con `lockForUpdate` por fila; el INSERT espera la fila no confirmada por la unique key; sin riesgo de deadlock con el orden `ORDER BY id`... el INSERT SELECT no tiene orden garantizado, pero el peor caso es un deadlock detectado por Postgres que aborta y revierte (seguro).

### M2 (MEDIO) — Las verificaciones son globales y bajo READ COMMITTED: cualquier actividad de Bodega aborta la corrida
`estado()` (l.284-292) toma la huella de TODO `catalog_products.stock_quantity` (~30K filas), de TODO `product_warehouse_stock` fuera de lo sembrado y el conteo total de Central, en dos instantes distintos. Cualquier movimiento de Kardex, recepción o venta de cualquier producto entre ambas lecturas produce un "verificación fallida" falso. Es seguro (rollback, nada se escribe) pero en test, con QA operando, puede abortar varias veces seguidas.
Fix (cualquiera): (a) acotar las verificaciones a los ids seleccionados (huella de `stock_quantity` de los ids; conteo y Σ de las filas de Central de esos ids; el resto de filas ya está garantizado por la forma del INSERT), o (b) documentar en el runbook que se corra en ventana sin actividad de Bodega y que un abort por "verificación fallida" se resuelve repitiendo el dry-run y el apply. Mínimo aceptable: (b).

## 4. Loader (`CatalogProductLoader.php:168, 179-192`)

Correcto:
- Se crea la fila en la MISMA transacción (`loadOne` envuelve todo en `DB::transaction`), solo en la rama de creación; la rama `updated` y las excluidas no la tocan (test `re_sincronizar_nunca_toca_la_fila`).
- Sin Bodega Central operativa (no existe, renombrada o `solo_consulta`) retorna sin hacer nada; el sync no se rompe.
- Stock `null` o `0` no crea fila; negativo se crea tal cual. `(int) round()` ya viene aplicado en `stock_quantity`.
- Rendimiento del sync incremental: solo afecta a productos NUEVOS con stock <> 0 (el incremental crea pocos). No es un problema.

### B2 (BAJO) — Una consulta de bodega por producto creado y un evento de auditoría por fila
Cada producto creado con stock hace `Warehouse::...->value('id')` (l.186) y el `create()` de `ProductWarehouseStock` dispara `LogsAuditTrail` (un `audit_logs` por fila). En una carga inicial de 30K productos son 30K consultas y 30K eventos extra. Fix: cachear el id en una propiedad de la instancia del loader (resuelto una vez por `load()`), y valorar insertar sin evento por fila (como hace el backfill, que audita en resumen). Además el loader usa `->value('id')` (primera coincidencia) mientras el comando exige exactamente UNA Central; es inconsistente solo si existen dos bodegas con ese nombre (hoy no).
Nota: si el `create()` de la fila fallara, se revierte la creación del producto (queda como error del lote). Es coherente con "misma transacción"; solo queda anotado.

## 5. Auditoría, operador, mensajes, runbook

Correcto: `--apply` sin `--operador` falla sin tocar nada (test); el evento resumen incluye operador, usuario SO, host, entorno, tenant, conteos y huella; se escribe una sola vez dentro de la transacción; la segunda corrida no escribe evento. Constante registrada en `AuditService`. Reporte con positivas/negativas/omitidos/filas actuales de Central. Orden por entorno del runbook coherente (test primero, dev idempotente porque omite productos con fila, prod solo cuando Bodega entre en operación y después de `legacy:backfill-stock-al4`).

### B3 (BAJO) — Runbook sin camino de reversa concreto
Dice "respaldo de `product_warehouse_stock`" sin comando, y la auditoría guarda solo el md5 de los ids (no la lista). Agregar: comando de respaldo (`pg_dump -t <tenant>_bodega.product_warehouse_stock` o `CREATE TABLE ... AS`), y cómo revertir (borrar las filas de Central creadas en esa corrida: `warehouse_id = Central AND created_at >= <hora del evento de auditoría>` y sin `inventory_movements` posteriores del producto). Opcional: guardar en `metadata` los ids sembrados si el volumen lo permite (3.277 ids).

### B5 (BAJO) — Productos omitidos por "ya tiene fila" pero con saldo inconsistente
En test hay 28 filas. Para un producto con fila (p. ej. creada por un Kardex con solo el delta) y `stock_quantity` mayor, el desglose por bodega queda corto frente al total. Es la decisión de diseño de Luis (no tocar lo que Bodega ya gestiona), pero el dry-run podría listar cuántos omitidos tienen `Σ filas <> stock_quantity` (conteo y, con una opción, los ids) para revisión manual.

## 6. Efectos en las vistas

- Totales por producto NO cambian: Compras Inventario (KPIs Total/Disponible/Sin stock/Valor), `BodegaInventoryController::format()` (`stock_total`/`stock_quantity`) y `disponibleParaVenta()` salen de `catalog_products.stock_quantity` menos reservado menos lo que hay en bodegas `excluye_disponible`. Central no es excluida => `Disponible` no cambia. Solo se completa el desglose por bodega (`WarehouseStockService::breakdownForProducts`, `warehouseRows`, `warehouseCounts`, listado de la bodega).
- Doble conteo: revisados `InventoryKpiService`, `WarehouseStockService`, `BodegaInventoryController`, `WarehouseController`, `KardexService`. Ningún reporte suma `stock_quantity` + filas por bodega; Kardex aplica el mismo delta a ambos, lo que mantiene la coherencia. No se encontró riesgo de doble conteo.

### M3 (MEDIO) — Consecuencias operativas no documentadas (no son defectos del código)
1. Los negativos quedan invisibles en el listado por bodega, en el conteo de bodegas y en el filtro por bodega (todos usan `quantity > 0`), mientras `stock_total` del producto sí muestra el negativo; `breakdownForProducts` sí los muestra. Es lo decidido ("tal cual"), pero Bodega verá diferencias entre pantallas.
2. `StockReservationService::reallocateProduct` reparte reservas de pedidos bin por bin sobre las filas de `product_warehouse_stock`: tras el backfill, los pedidos en curso empezarán a asignarse a Central (antes no había nada que asignar). Es el efecto buscado, pero cambia el comportamiento de Bodega en test el mismo día.
3. El listado de Central pasa de 28 a ~3.300 filas. Por la regla de rendimiento (auditoría SCRUM-995), medir `GET /bodega/warehouses/{Central}` con y sin `search` en dev (que ya tiene 3.368 filas) antes de promover; no se ejecutó nada aquí.
Fix: agregarlo al runbook y avisar a Bodega/QA antes del apply en test.

## 7. Tests

Prueban comportamiento real sobre BD real (Postgres, tenant real, trigger real). Cubren: positivos/negativos, 0/nulo, fila en otra bodega, fila en Central, sin vínculo ICG, `stock_quantity` intacto, auditoría, idempotencia, dry-run sin cambios, operador obligatorio, Central solo_consulta / inexistente, ROLLBACK por verificación, lotes chicos; loader: creación (+, -, 0), redondeo, no tocar en re-sync.
Mutación: quitar `NOT EXISTS` rompe 2 tests (el de selección y el de idempotencia, porque el 2º apply abortaría por conteo en vez de "Nada que sembrar"); quitar `EXISTS ligado` rompe 1 (la aserción de `sinIcg`). Creíble; la protección de Lafayette descansa en una sola aserción: conviene un caso explícito de producto con `external_source`.

### B6 (BAJO) — Casos que faltan
- Producto ligado a ICG con `descatalogado = true` y/o `is_active = false` (fija la decisión de M1).
- Producto con fila en Central en 0 (omitido).
- Dos corridas concurrentes / fila que aparece entre análisis y escritura (el camino "el lote insertó N de M" no se ejercita).
- Trigger de `solo_consulta` disparándose en el INSERT en bloque (Central pasa a `solo_consulta` tras `resolverBodega`).
- Loader: sin Bodega Central, y Central `solo_consulta` => no crea fila ni rompe el sync.
- Decimales: `stock_quantity` es `integer`, no aplica en el comando; el redondeo del loader sí está cubierto (42.7 => 43).

## Resumen de acciones
Antes del apply en test: decidir M1 (agregar conteo de inactivos al dry-run), documentar M2/M3 y B3 en el runbook. Pueden ir después (mismo ticket o seguimiento): B1, B2, B4, B5, B6.
