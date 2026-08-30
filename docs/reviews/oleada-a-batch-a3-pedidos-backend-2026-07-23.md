# Senior Review — Oleada A / Batch A3, mitad backend (SCRUM-329, "Pedidos: tablero frontend")

**Fecha:** 2026-07-23
**Alcance:** `git diff dcde64d..945752a` en `atlanticerp-backend`, branch `dev` (no pusheado) — 8 commits
(`763ebe8`, `c729c3f`, `b249a58`, `f3ad374`, `904a16a`, `5959264`, `6b0a47f`, `945752a`). 5 tickets de
backend nuevo/parcial para Batch A3 (los otros 11 de los 16 tickets del batch ya tenían backend
completo desde Batch A2 y solo necesitaban frontend, fuera de este alcance) más un fix chico de
`945752a` encontrado por el pase de Frontend Dev en paralelo. Precedente de tono/rigor:
`docs/reviews/oleada-a-batch-a2-pedidos-backend-2026-07-22.md` (leído completo antes de este review).

## Veredicto: 🔴 Requiere cambios

Un blocker de performance (N+1 real, confirmado empíricamente, no solo leído) en el endpoint más
usado del módulo — el tablero Kanban de Pedidos. El resto del batch (export a Excel, consolidado de
picking, extracción de `WarehouseStockService`, placeholder de Factura, fix de serialización de
`945752a`) está bien implementado, verificado línea por línea y con tests reales. Ningún hallazgo de
integridad de datos ni de seguridad esta vez — a diferencia de Batch A2, este batch es
mayoritariamente de solo lectura.

---

## 🔴 Blocker — `eta_proveedor` en `OrderBoardService::cardPayload()` introduce N+1 real (2 queries extra por cada pedido "sin stock") en `board()`, sin batching pese a que el mismo PR sí batchea el caso análogo

`OrderBoardService.php:232` (dentro de `cardPayload()`, ahora llamada desde `board()` para **cada**
pedido de la lista, no solo desde `detail()` para un pedido individual):

```php
'eta_proveedor' => $order->isAwaitingStock() ? $this->supplierEta($order) : null,
```

`supplierEta()` (líneas 253-270) hace, por cada pedido para el que `isAwaitingStock()` es `true`
(`order_type === FALTANTE && stage === ASIGNADO`, `Order.php:183-186` — exactamente los pedidos del
chip "Sin stock", `applyChip()` línea 188):

1. `$order->loadMissing('items')` — `items` **no** está en el eager-load de `baseQuery()` (línea
   139: `with(['assistant', 'picker', 'courier', 'pipelineCard.owner', 'pipelineCard.salesProject'])`,
   sin `items`) → 1 query nueva por pedido.
2. Una query a `PurchaseOrder` (`whereHas('lines', ...)` + `orderBy` + `value()`) → otra query por
   pedido.

**Verificado empíricamente, no solo leído en el código:** escribí un test temporal
(`tests/Feature/Bodega/ZZTempN1CheckTest.php`, corrido con `infra/test.sh --filter`, borrado antes de
terminar este review — no queda en el repo) que crea 15 pedidos `Faltante`+`Asignado` (cada uno con
su propia `PurchaseOrder`/`PurchaseOrderLine` real) y llama a `GET /api/bodega/orders` con
`DB::enableQueryLog()`. Resultado real:

```
Orders creados (sin stock): 15
Total de queries para board(): 33
```

1 query base de `orders` + 15×(1 `order_items` + 1 `purchase_orders`) + 2 `SET search_path` = 33.
Exactamente el patrón `2N+1` que predice la lectura del código — confirmado con datos reales, no
una suposición.

**Por qué es un blocker y no una sugerencia:** `board()` es el endpoint que carga el tablero Kanban
completo — explícitamente **no paginado** ("el tablero Kanban necesita todas las tarjetas activas de
una vez", docblock línea 34-36) — y se recarga en cada visita/refresh de la pantalla principal de
Bodega. "Sin stock" no es un edge case: tiene su propio chip de filtro dedicado
(`applyChip()`, `chip=sin_stock`) precisamente porque es una categoría de negocio real y esperada,
que puede acumular fácilmente varias decenas de pedidos durante un problema de abastecimiento — el
escenario de negocio para el que Bodega más necesita mirar el tablero seguido. El costo crece sin
límite con `N` y sin ningún batching ni caché.

Lo que hace este hallazgo más claro todavía: **el mismo PR sí resuelve el caso análogo
correctamente.** `WarehouseStockService::breakdownForProducts()` (SCRUM-403, este mismo batch) existe
*justamente* para evitar que el frontend dispare "N requests, una por línea" al abrir "Ver bodegas"
(docblock `WarehouseStockService.php:31-33`) — agrupa con `whereIn` en una sola query. El mismo
principio no se aplicó al ETA del proveedor, que termina haciendo el N+1 equivalente **del lado del
backend**, en el propio endpoint que expone a todos los pedidos a la vez.

**Fix sugerido** (mismo patrón que `WarehouseStockService`, sin cambiar el contrato JSON):

1. En `board()`, antes de mapear a `cardPayload()`: eager-cargar `items` en los pedidos con
   `isAwaitingStock()` (`$orders->load('items')` filtrado, o agregar `items` al `with()` de
   `baseQuery()` si el costo de cargarlo siempre es aceptable — a evaluar).
2. Reemplazar la query de `PurchaseOrder` por pedido por una sola query batched: recolectar todos
   los `catalog_product_id` de todos los pedidos `isAwaitingStock()` del board, hacer **una** query
   a `PurchaseOrder`/`PurchaseOrderLine` con `whereIn('catalog_product_id', $allProductIds)`
   agrupando el `MIN(estimated_arrival_date)` por producto (similar a como
   `WarehouseStockService::breakdownForProducts()` agrupa por producto con una sola `whereIn`), y
   mapear el resultado de vuelta a cada pedido en memoria.
3. `detail()` (un solo pedido) puede seguir usando `supplierEta()` tal cual — el problema es
   específico de `board()`/`cardPayload()` en lista, no del caso individual.

No hay ningún test (ni de los 4 nuevos ni de los existentes) que ejercite el board con más de 1
pedido "sin stock" a la vez, así que el N+1 no se hizo visible en la suite — recomiendo agregar un
test de conteo de queries (`DB::enableQueryLog()`/`assertLessThan`) junto con el fix, para que esta
clase de regresión quede cubierta a futuro y no dependa de que un reviewer la reproduzca a mano.

---

## 🟢 Aprobado — `OrderExportService`/`OrderExportController` (SCRUM-390, REQ-320)

Leído completo. Ambos métodos (`pickingSheetXlsx()`, `itemDetailXlsx()`) leen exactamente los mismos
campos que sus gemelos JSON (`OrderPickingController::pickingSheet()` y los `items` de
`OrderBoardService::detail()`, confirmado comparando ambos) — no hay una segunda fuente de verdad
para los números, tal como promete el docblock.

- **Streaming/memoria:** no es streaming progresivo real — `buildXlsx()` arma el `Spreadsheet` en
  memoria, lo escribe a un archivo temporal (`tempnam`), lo relee entero a un string
  (`file_get_contents`) y lo entrega en un solo `echo` dentro del closure de
  `response()->streamDownload()` (`OrderExportController.php:44-53`). Es decir: el nombre de la clase
  ("stream") es sobre el transporte HTTP (headers antes de enviar el body), no sobre construcción
  incremental fila-por-fila — todo el archivo vive en memoria PHP antes de mandarse. No es un bug
  hoy: no hay ningún límite de artículos por pedido en el código (confirmé con grep, no hay
  `MAX_ITEMS` ni similar), pero en la práctica un pedido de Atlantic son decenas de líneas, no
  miles — el volumen real no compromete memoria. Lo marco como sugerencia no bloqueante: si algún
  día se permite un pedido con cientos/miles de líneas, esto debería reescribirse para escribir el
  `Xlsx` directo a `php://output` (soportado por PhpSpreadsheet) en vez de por archivo temporal +
  buffer completo en memoria.
- **Cleanup del temp file:** correcto — `@unlink($tmpPath)` vive en un `finally`
  (`OrderExportService.php:117-119`), se borra incluso si `save()`/`file_get_contents()` lanzan.
- **Nombre de archivo / seguridad:** `hoja-picking-{$order->order_number}.xlsx` interpola
  `order_number` directo. Verifiqué que **no** es texto libre de usuario pese a que el comentario del
  propio código lo advierte como riesgo defensivo (línea 87-88, "sin validación que impida otros
  caracteres a futuro") — `order_number` se genera 100% server-side vía
  `OrderFolioService::next()` (`sprintf('PED-%d-%04d', ...)`, formato fijo `PED-AAAA-NNNN`, nunca
  proviene de input de usuario, ver `OrderFolioService.php:34`). No hay vector de path
  traversal/header injection hoy; el comentario del dev es honesto sobre ser defensivo, no describe
  una vulnerabilidad real actual. El sanitizado del nombre de hoja de Excel (`preg_replace` de
  `: \ / ? * [ ]`, límite 31 caracteres) es correcto igual, y barato de mantener.
- **Tests** (`OrderExportControllerTest.php`): cubren ambos endpoints, verifican headers
  (`content-type`, `content-disposition`), leen el `.xlsx` de vuelta con `IOFactory` (no solo
  "status 200", validan contenido real: referencia, cantidades) y cubren 404 + 403 (falta de
  permiso). Únicamente el 403 solo se prueba contra `pickingSheet()`, no contra `itemDetail()` —
  gap menor de cobertura, no de código (misma ruta/middleware), no bloqueante.

---

## 🟢 Aprobado (con la observación de RN2 abajo) — `PickingConsolidationService` (SCRUM-391, REQ-391)

`consolidatedForPicker()` es puramente de lectura — confirmé con grep en todo el archivo que no hay
ningún `save()`/`update()`/`create()` sobre `Order`/`OrderItem`, cumpliendo RN2 al pie de la letra.
RN1 (solo `STAGE_EN_PICKING`, excluye `picking_pendiente`) está bien implementada
(`PickingConsolidationService.php:45`) y cubierta con test explícito
(`test_pedidos_en_picking_pendiente_no_se_incluyen`). La consolidación por
`catalog_product_id`/`item:{id}` para ítems personalizados es correcta y evita que dos ítems
"custom" de pedidos distintos se mezclen sin base real para agruparlos — cubierto con test.

**Sobre el criterio de la suma (`qty_requested` total, no lo restante por pickear):** confirmé en la
memoria del proyecto que esto fue un juicio explícito del Backend Dev, documentado como decisión
tomada, no una omisión — razonamiento: RN2 describe esto como "vista de consulta/impresión", que
leo como una vista de *planificación previa* (cuánto hay que ir a buscar en total, agregado entre
pedidos, antes de empezar) más que una vista de *progreso en tiempo real* (cuánto falta ahora
mismo). Bajo esa lectura, sumar `qty_requested` es razonable y defendible. Dejo esto como 🟡 no
bloqueante para que PM confirme con Luis antes de Pre-QA: si un picker consulta este consolidado a
mitad de camino (ya pickeó parcialmente uno de los pedidos vía `updatePickingSheet()`), va a ver el
total original pedido, no lo que realmente le queda por recolectar — puede sobre-estimar el trabajo
pendiente. No es un bug de código, es una pregunta de producto sobre cuándo se espera que se use
esta pantalla (¿antes de empezar a pickear, o también a mitad de camino?).

**Tests** (`PickingConsolidationTest.php`): 4 casos, incluyendo el 422 por `picker_id` faltante.
Ningún test de 403 (permiso), mismo gap menor que arriba.

---

## 🟢 Aprobado — extracción de `WarehouseStockService` (SCRUM-403, REQ-333)

Comparé `git diff` del método viejo de `AdjustmentRequestController::productWarehouseStock()` contra
`WarehouseStockService::breakdownForProduct()`/`breakdownForProducts()` completos:

- **Mismo contrato de respuesta:** `{por_servir: int, warehouses: [{warehouse_id, name, quantity}]}`
  — idéntico shape, mismo orden (`Warehouse::orderBy('id')`), mismo fallback a `0` si no hay fila de
  stock para una bodega.
- **Mismo cálculo:** `porServirMap()` ya devolvía `int` (cast dentro del propio
  `InventoryKpiService::porServirMap()`, línea 115, `.map(fn ($v) => (int) $v)`) — el `(int)` extra
  en el código nuevo es redundante pero no cambia el valor.
- **Sin regresión:** el test existente de este endpoint (`AdjustmentRequestControllerTest.php`,
  cubre `productWarehouseStock`) sigue pasando dentro de la suite completa (1157/1157, ver abajo) —
  no se tocó ni se necesitó tocar.
- **`breakdownForProducts()` (nuevo, para el endpoint agregado por pedido) SÍ batchea
  correctamente:** una sola query `whereIn('catalog_product_id', $productIds)` + `groupBy` en
  memoria, no N queries por producto — este es justamente el patrón que faltó aplicar al ETA de
  proveedor (ver Blocker arriba).
- **Test de paridad explícito:** `OrderWarehouseBreakdownTest::test_desglose_por_pedido_coincide_con_el_desglose_por_producto_individual`
  llama a ambos endpoints con el mismo producto y compara byte a byte (`por_servir`, `warehouses`)
  — la forma correcta de probar que una extracción no cambió el comportamiento del caller original.

---

## 🟢 Aprobado — placeholder pasivo de Factura (SCRUM-401, REQ-331)

`OrderInvoiceController::show()` leído completo (40 líneas) — confirmado cero lógica de precios,
cero generación de PDF. Expone únicamente `order_id`, `order_number`, `invoice_ready` (ya existente
en el modelo desde REQ-327/328) y un `status_message` de texto fijo según ese booleano. El docblock
documenta correctamente por qué el alcance se redujo (decisión explícita de Luis, mismo criterio que
`OrderDocumentService`/REQ-330) y remite al mismo patrón ya usado ahí.

Test (`OrderInvoiceControllerTest.php`) verifica explícitamente lo negativo, no solo lo positivo:
`assertArrayNotHasKey('subtotal'/'itbms'/'total', ...)` — exactamente el tipo de assertion que este
proyecto necesita para un descope de alcance (evita que alguien reintroduzca cálculo de precios sin
que el test lo note). Bien pensado.

---

## 🟢 Aprobado — fix de serialización en `OrderBoardService::detail()` (`945752a`)

Encontrado por Frontend Dev en paralelo, no por Backend Dev — el modal "Ver detalle" (REQ-332) pedía
`customer_contact`/`delivery_address`/`factory_reference` que ya existían en el modelo pero nunca se
serializaban. Verifiqué:

- `customer_contact`/`delivery_address` están en `$fillable` de `Order`
  (`Order.php:100`) desde REQ-308 — el fix solo agrega la lectura al payload, no toca escritura.
- `factory_reference` sale de `CatalogProduct` (sprint2/REQ-131, columna ya existente) vía
  `$product?->factory_reference` — `null`-safe para ítems personalizados sin producto de catálogo,
  correcto.
- Test nuevo (`test_ver_detalle_expone_contacto_direccion_y_referencia_de_fabrica`) verifica los 3
  campos con datos reales, no solo la ausencia de excepción.

Cambio quirúrgico, de solo-lectura, sin tocar ninguna otra ruta del `array_merge` en `detail()`.

---

## Rutas nuevas — `routes/bodega.php`

Verificado el orden de registro explícitamente (el brief pedía chequear esto por el bug clásico de
Laravel):

```
GET  /orders                          (index)
GET  /orders/assistant-load           ← segmento fijo, ANTES de "/{id}"
GET  /orders/consolidated-picking     ← segmento fijo, ANTES de "/{id}"  (SCRUM-391)
GET  /orders/{id}                     (show)
...
GET  /orders/{id}/picking-sheet/export   (SCRUM-390)
GET  /orders/{id}/items/export           (SCRUM-390)
...
GET  /orders/{id}/warehouse-breakdown    (SCRUM-403)
GET  /orders/{id}/invoice                (SCRUM-401)
```

`consolidated-picking` y `assistant-load` son de un solo segmento, igual que `/{id}` — si se
registraran después, Laravel matchearía `"consolidated-picking"` como si fuera el `{id}` numérico
(nunca llegaría a fallar por tipo, Laravel no valida que `{id}` sea numérico salvo que se declare
explícitamente con `where()`, que no es el caso acá). Confirmado que el dev los puso ANTES, con un
comentario explícito en el código (`routes/bodega.php:37-38`) explicando exactamente este motivo —
correcto. Las rutas `/{id}/warehouse-breakdown` y `/{id}/invoice`, al tener 2 segmentos, no compiten
con ninguna otra ruta de 2 segmentos existente — no hay ambigüedad ahí.

**Permisos:** todos los endpoints nuevos son `GET` y están gateados con `permission:bodega.read`,
consistente con el resto de endpoints de solo lectura del módulo (`picking-sheet`, `warehouse-stock`
individual, etc.) — ningún endpoint de escritura se agregó en este batch. No aplica el hallazgo 🟡 de
permisos granulares de Batch A2 (ese era sobre endpoints de escritura).

---

## Verificación ejecutada

- **Rama:** `dev` confirmado (`git branch --show-current`), `git status` limpio, `ahead of
  origin/dev by 8 commits`, sin pushear.
- **Lectura completa** (no solo diff) de `OrderExportService.php`, `OrderExportController.php`,
  `PickingConsolidationService.php`, `WarehouseStockService.php`, `OrderInvoiceController.php`, el
  `OrderBoardService.php` completo (no solo el diff), `AdjustmentRequestController.php` (diff +
  método completo), `OrderBoardController.php`, `OrderPickingController.php`, `routes/bodega.php`
  completo, y los 5 archivos de test (4 nuevos + `OrderBoardControllerTest.php` con sus 2 tests
  nuevos).
- **N+1 del ETA de proveedor:** verificado empíricamente con un test temporal
  (`DB::enableQueryLog()` sobre `GET /api/bodega/orders` con 15 pedidos "sin stock" reales, cada uno
  con su propia `PurchaseOrder`/`PurchaseOrderLine`) — 33 queries totales, patrón `2N+1` confirmado.
  Test borrado antes de cerrar este review, no queda en el repo (`git status` limpio al final).
- **Extracción de `WarehouseStockService`:** comparado contra el código viejo vía `git diff`, más el
  test de paridad explícito del propio batch (`test_desglose_por_pedido_coincide_con_el_desglose_por_producto_individual`).
- **Tests — suite completa:** `infra/test.sh` (nunca `docker compose exec` directo) →
  **1157 tests, 4741 assertions, OK** — coincide exactamente con lo reportado por Backend Dev
  (1141→1157, +16 tests: 4 archivos nuevos + 2 nuevos en `OrderBoardControllerTest`).
- **PHPStan:** `docker compose -f infra/docker-compose.yml exec -T laravel php -d memory_limit=-1
  vendor/bin/phpstan analyse --memory-limit=1G < /dev/null` → **`[OK] No errors`** (340 archivos,
  Level 8) — coincide con lo reportado.
- **`composer.json`/`composer.lock`:** confirmé que no cambiaron en este diff — `phpoffice/phpspreadsheet`
  ya era dependencia del proyecto (usada hasta ahora solo para lectura en
  `Compras\Services\InvoiceSpreadsheetExtractor`), no se instaló nada nuevo sin aprobación.

---

## Siguiente paso

No pasa a Pre-QA todavía. PM reasigna a Backend Dev:

1. **Blocker** — batchear el cálculo de `eta_proveedor` en `OrderBoardService::board()` (ver fix
   sugerido arriba: eager-load de `items` + una sola query `whereIn` a `PurchaseOrder`/
   `PurchaseOrderLine` en vez de una por pedido), y agregar un test de conteo de queries para que la
   regresión quede cubierta.
2. Confirmar con Luis/PM el criterio de `PickingConsolidationService` (`qty_requested` total vs.
   restante por pickear) — no bloqueante, pero mejor decidido antes de que QA lo vea y lo interprete
   como bug.
3. Sugerencias no bloqueantes (a discreción del PM si se agendan): streaming real del export a Excel
   si algún día se levanta el límite implícito de artículos por pedido; agregar test 403 a
   `itemDetail()`/`OrderInvoiceController`/`OrderWarehouseBreakdownTest`/`PickingConsolidationTest`
   (mismo middleware ya probado en otro lado, gap solo de cobertura explícita por archivo).

Tras el fix del Blocker, vuelve a Senior Review (alcance acotado: solo `OrderBoardService::board()`/
`cardPayload()` y su test nuevo) — no hace falta repetir la revisión completa del batch.

---

## Re-revisión (2026-07-23, commit 79d6467)

**Alcance:** `git show 79d6467` (`fix(bodega): batch supplier ETA lookup on the board instead of
one query per card`) — 2 archivos: `OrderBoardService.php` (+92/-6) y
`OrderBoardControllerTest.php` (+63). No confío en el mensaje del commit — se verificó el fix de
forma independiente, leyendo el diff completo, el archivo completo resultante, y corriendo la
suite yo mismo.

### Trazado del fix

`board()` (`OrderBoardService.php:40-72`) ahora, después de traer `$orders`:

1. Filtra la subcolección `isAwaitingStock()` (línea 60) — exactamente el mismo criterio de negocio
   que ya decidía cuándo pintar el chip "Sin stock" (`Order.php:183-186`), no un criterio nuevo.
2. `$awaitingStock->loadMissing('items')` (línea 61) — un solo `whereIn` sobre esa subcolección, no
   una consulta por pedido. Confirmado que el tipo de `$orders` se corrigió a
   `Illuminate\Database\Eloquent\Collection` (línea 46, antes solo `Collection` del import genérico
   de `Illuminate\Support\Collection`) — necesario porque `loadMissing()` no existe en la Collection
   base; sin este ajuste PHPStan Level 8 hubiera fallado. Verifiqué que `->filter()` sobre una
   `Eloquent\Collection` preserva la subclase (no degrada a `Support\Collection`), así que
   `loadMissing()` sigue disponible en `$awaitingStock`.
3. Recolecta `catalog_product_id` únicos de **todos** los pedidos sin stock del board (línea 62-64)
   y llama `bulkSupplierEtaByProduct($productIds)` (línea 65) — **una vez**, fuera del `map()` que
   arma las tarjetas.
4. `bulkSupplierEtaByProduct()` (líneas 314-342): si `$productIds` está vacío, retorna `[]` sin
   disparar ninguna query (caso "ningún pedido sin stock en este board" — no paga overhead). Si no,
   hace **exactamente 2 queries**: una `PurchaseOrder::whereHas('lines', whereIn(...))->get()` +
   una eager-load de `lines` (`with(['lines' => fn ($q) => $q->whereIn(...)])`) — el mismo patrón
   `whereIn` + agrupación en memoria que `WarehouseStockService::breakdownForProducts()` ya usaba,
   confirmado comparando ambos métodos lado a lado. El `MIN` por producto se calcula en PHP
   (`if (! isset(...) || $eta < $etaByProduct[$productId])`), no en SQL, pero eso es indiferente al
   conteo de queries y correcto (mismo criterio de "más próxima" que la versión vieja con
   `orderBy()->value()`).
5. `cardPayload($order, $etaByProduct)` (línea 68) recibe el mapa ya resuelto para **cada** tarjeta,
   incluidas las que no están "sin stock" — pero para esas, `'eta_proveedor' => $order
   ->isAwaitingStock() ? $this->supplierEta(...) : null` (línea 250) ni siquiera llama a
   `supplierEta()`, por el operador ternario de corto circuito — cero costo adicional para pedidos
   normales, igual que antes.
6. Dentro de `supplierEta($order, $etaByProduct)` (líneas 280-303): sigue llamando
   `$order->loadMissing('items')` incondicionalmente al entrar (línea 282) — a primera vista parece
   una query redundante por pedido, pero **no lo es**: `items` ya fue cargado en el paso 2 sobre la
   misma instancia de `$order` (la colección `$awaitingStock` contiene las mismas instancias que se
   iteran después en el `map()` de la línea 68, no copias), así que `loadMissing` detecta la
   relación ya resuelta (`relationLoaded('items')`) y no dispara nada. Confirmado con el test de
   conteo (ver abajo) — si esto disparara una query por pedido, el test fallaría.
7. Con `$etaByProduct !== null`, el cálculo de la ETA final es un `array_map` + `min()` en memoria
   sobre los `catalog_product_id` del pedido (líneas 290-293) — cero queries. Si `$etaByProduct` es
   `null` (llamado desde `detail()`), cae exactamente al código viejo sin modificar (líneas 296-302,
   diff confirma que es carácter por carácter idéntico al método pre-fix, solo movido detrás de un
   `if`).

**No encontré ningún query dentro de un loop** en el camino nuevo — las únicas 2 queries de
`PurchaseOrder`/`PurchaseOrderLine` viven dentro de `bulkSupplierEtaByProduct()`, llamada una sola
vez por `board()`, fuera de cualquier `map()`/`foreach` sobre pedidos. Tampoco until encontré un N+1
reintroducido en otro punto del archivo — `assistantLoad()` y `baseQuery()` no se tocaron.

**Detalle menor, no bloqueante:** `cardPayload()` (línea 206-212) quedó con un docblock huérfano —
la línea 206 (`/** @return array<string, mixed> */`, el comentario original antes del fix) no se
borró cuando se agregó el nuevo docblock completo (líneas 207-211) justo debajo. PHP/PHPStan solo
asocian el docblock inmediatamente anterior a la función (el nuevo, correcto), así que no es un bug
funcional ni afecta el análisis estático — confirmado que PHPStan sigue en `[OK] No errors`. Es
cosmético: un `git diff` futuro sobre ese método va a arrastrar una línea muerta. 🟡 sugerencia,
prescindible.

### Test de regresión — `test_eta_proveedor_no_escala_en_queries_con_mas_pedidos_sin_stock`

Leído completo (`OrderBoardControllerTest.php:227-264`). Confirmo que asegura lo que dice:

- Crea 3 productos distintos, cada uno con su propia `PurchaseOrder`/`PurchaseOrderLine`, y **2**
  pedidos "sin stock" por producto (6 pedidos en total, no 1) — el número importa: si el fix no
  batcheara de verdad, el conteo escalaría con la cantidad de pedidos, no con la cantidad de
  productos, así que usar 6 pedidos contra solo 3 productos aísla bien la variable que se quiere
  probar.
- Cuenta queries filtrando por `str_contains($q['query'], 'purchase_order')` — captura tanto
  `purchase_orders` como `purchase_order_lines` (el eager-load de `lines`), que es exactamente lo
  que dispara `bulkSupplierEtaByProduct()`.
- Asserta `assertSame(2, $purchaseOrderQueries, ...)` — un número fijo, no un `assertLessThan`
  laxo. Esto es más estricto y mejor que lo que yo mismo sugerí en el hallazgo original
  (`assertLessThan`) — un `assertSame(2, ...)` detecta tanto una regresión a N+1 como cualquier
  query de `purchase_order` extra que se agregue por accidente en el futuro.
- **Revertí mentalmente el fix** para confirmar que el test lo atraparía: si `cardPayload()`
  volviera a llamar `supplierEta($order)` sin el mapa (como en el código pre-fix), cada uno de los 6
  pedidos "sin stock" dispararía su propia query a `PurchaseOrder` (whereHas + orderBy + value —
  1 query, sin el `with(['lines'])` separado que suma la segunda query fija de hoy) → 6 queries en
  vez de 2. El `assertSame(2, ...)` fallaría inmediatamente con el mensaje ya armado
  (`"...esperado: 2, fijo"`), sin ambigüedad. Confirmado: el test sí protege contra la regresión
  exacta que motivó el blocker.
- Corrí el test aislado (`infra/test.sh --filter=OrderBoardControllerTest`) — pasa junto con los
  otros 11 tests del archivo (12/12, 47 assertions), incluido el test existente
  `test_tablero_expone_eta_proveedor_en_la_tarjeta_para_pedidos_sin_stock` (single "sin stock" +
  1 pedido normal, verifica el valor real `'2026-08-15'` y `null` para el normal) — el fix no
  rompió el caso base de 1 pedido.

### Camino `detail()` — no roto por el refactor

`detail()` (líneas 110-145) sigue llamando `cardPayload($order)` **sin** segundo argumento (línea
114) → `$etaByProduct` cae a su default `null` → `supplierEta($order, null)` ejecuta exactamente el
código pre-fix (comparé carácter por carácter contra el `git show 79d6467`, el bloque `$eta =
PurchaseOrder::query()...` no cambió una sola línea, solo se movió detrás de `if ($etaByProduct !==
null)`). No hay ningún test que llame específicamente a `GET /orders/{id}` y assert el valor de
`eta_proveedor` en el payload de detalle (gap pre-existente, no introducido por este fix — el
test `test_ver_detalle_expone_items_del_pedido` y
`test_ver_detalle_expone_contacto_direccion_y_referencia_de_fabrica` cubren otros campos del modal
pero no `eta_proveedor` puntualmente). Como el código del camino single-order es textualmente
idéntico al que ya estaba aprobado en el primer pase, no lo considero un hallazgo nuevo — lo dejo
anotado por transparencia, no como bloqueante ni como sugerencia nueva.

### Verificación ejecutada

- **Rama:** `dev`, `git status` limpio, sin archivos de test temporales sueltos, `ahead of
  origin/dev by 9 commits` (no 10 — recuento real de `git log dcde64d..HEAD`: 763ebe8, c729c3f,
  b249a58, f3ad374, 904a16a, 5959264, 6b0a47f, 945752a, 79d6467 = 9 commits, los 8 del primer pase
  + el fix). No afecta el veredicto, solo lo corrijo por precisión.
- **`git diff dcde64d..HEAD --stat`:** confirmado que los únicos archivos con cambios adicionales
  respecto al primer pase son `OrderBoardService.php` (98 líneas totales de diff, antes menos) y
  `OrderBoardControllerTest.php` (130 líneas totales, antes 67) — ningún archivo nuevo fuera de esos
  dos, nada más se tocó. El resto del batch (`OrderExportService`, `PickingConsolidationService`,
  `WarehouseStockService`, `OrderInvoiceController`, rutas) permanece exactamente como en el primer
  pase.
- **`grep` de usos de `cardPayload`/`supplierEta`/`bulkSupplierEtaByProduct`:** confirmado que los 3
  métodos solo se llaman entre sí dentro de `OrderBoardService.php` — ningún otro controller/service
  del módulo depende de su firma, así que agregar el parámetro opcional no rompe ningún otro caller.
- **Suite completa:** `infra/test.sh` → **1158 tests, 4744 assertions, OK** (1157→1158, +1 test
  nuevo, exactamente lo esperado).
- **`OrderBoardControllerTest.php` aislado:** `infra/test.sh --filter=OrderBoardControllerTest` →
  12/12, 47 assertions, incluye el test de conteo de queries y el test pre-existente de ETA en
  tarjeta individual.
- **PHPStan:** `docker compose -f infra/docker-compose.yml exec -T laravel php -d memory_limit=-1
  vendor/bin/phpstan analyse --memory-limit=1G < /dev/null` → **`[OK] No errors`** (340 archivos,
  Level 8).

### Veredicto: 🟢 Aprobado

El blocker original está corregido de forma arquitectónicamente sólida: `board()` precalcula la ETA
de todos los pedidos "sin stock" en 2 queries fijas (`bulkSupplierEtaByProduct()`, mismo patrón ya
validado de `WarehouseStockService::breakdownForProducts()`), sin queries dentro de ningún loop, sin
N+1 reintroducido en ningún otro punto del archivo. El camino `detail()` de un solo pedido queda
textualmente sin tocar. El test nuevo asserta un conteo fijo de queries (no un umbral laxo) y
mentalmente verifiqué que atraparía la regresión exacta que motivó el hallazgo. Único detalle es un
docblock huérfano cosmético en `cardPayload()` (🟡, no bloqueante) y la ausencia de un test que
cubra `eta_proveedor` puntualmente vía `detail()` (gap pre-existente, no introducido acá). Suite
completa 1158/1158 y PHPStan Level 8 limpios, verificados por mí, no solo reportados por el commit.

**Listo para Pre-QA.**
