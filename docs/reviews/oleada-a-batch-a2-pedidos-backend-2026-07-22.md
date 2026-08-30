# Senior Review — Oleada A / Batch A2 (SCRUM-329, "Pedidos backend")

**Fecha:** 2026-07-22
**Alcance:** `git diff d0ddae6..HEAD` en `atlanticerp-backend`, branch `dev` (no pusheado) — 6 commits
(`2cb6ecd`, `9d29b95`, `4872190`, `2e95810`, `f327681`, `7bdf7c8`). Motor completo del tablero
Kanban de Pedidos de Bodega (7 etapas), sin frontend (Batch A3, no revisado acá). Se construye
sobre Batch A1 (fundación: `Order`/`OrderItem`, creación automática desde cotización aprobada,
`Warehouse`/`ProductWarehouseStock`/`AdjustmentRequest`/`KardexService`).

> Nota: el prompt de este review referencia
> `docs/reviews/oleada-a-batch-a1-bodega-pedidos-fundacion-2026-07-22.md` como precedente de Batch
> A1 — ese archivo **no existe** en el repo (verificado, `docs/reviews/` solo tenía 3 archivos
> previos, ninguno de Bodega). El precedente real que sí encontré y usé como referencia es
> `AdjustmentRequestController::approve()`/`reject()` (líneas 164-227), que trae en su propio
> docblock la cita textual del hallazgo CRÍTICO de Pre-QA 2026-07-21 sobre exactamente esta misma
> clase de bug — ver Blocker 1.

## Veredicto: 🔴 Requiere cambios

Dos blockers de integridad de datos (concurrencia + Kardex multi-bodega), uno de permisos
granulares en rutas nuevas, y varias sugerencias no bloqueantes. El diseño de **Back-allocation**
(REQ-322) y **Familia de Entregas** (REQ-319) están bien implementados y verificados — ver
"Aprobado" abajo.

---

## 🔴 Blocker 1 — Falta el patrón lock+recheck-dentro-de-la-transacción en TODAS las transiciones de `Order`, más grave en `dispatch()` (doble decremento de Kardex)

Batch A1 tuvo el mismo bug exacto un día antes (2026-07-21, Pre-QA CRÍTICO) en
`AdjustmentRequestController::approve()`/`reject()` — la corrección documentada ahí es explícita:

> "el fetch + chequeo de `isPending()` vivía ANTES de abrir la transacción, sin lock — 5 approve
> concurrentes sobre la misma línea aplicaban el ajuste 3 veces (stock triplicado). El lock
> (`lockForUpdate()`) y el chequeo de estado ahora viven DENTRO de la misma transacción."
> — `app/Modules/Bodega/Http/Controllers/AdjustmentRequestController.php:167-172`

Este batch (A2) **reintroduce el mismo anti-patrón sistemáticamente**. Todos los métodos que
mutan `Order.stage` verifican la precondición ANTES de abrir `DB::transaction()`, usando el
`$order` cargado por el controller (`Order::find($id)`, sin lock), y **nunca vuelven a
verificar el estado adentro de la transacción** — ni siquiera cuando sí toman `lockForUpdate()`
sobre las filas de `OrderItem`:

- `app/Modules/Bodega/Services/PickingService.php:35-56` (`assignPicker`), `:62-75`
  (`startPicking`) — sin lock de ningún tipo.
- `app/Modules/Bodega/Services/PickingService.php:120-191` (`completePicking`) — sí bloquea
  `OrderItem` (línea 139), pero nunca re-verifica `$order->stage === EN_PICKING` después de
  obtener el lock; solo lo chequea una vez, antes de la transacción (línea 120).
- `app/Modules/Bodega/Services/InventoryReviewService.php:58-141` (`resolve`) — mismo patrón:
  lock de `OrderItem` (línea 92), sin recheck de `$review->stage` post-lock.
- `app/Modules/Bodega/Services/DeliveryService.php:59-131` (`registerDelivery`) — mismo patrón,
  lock de `OrderItem` (línea 80), sin recheck de `$order->stage` post-lock.
- `app/Modules/Bodega/Services/DeliveryService.php:179-209` (`assignCourier`), `:219-247`
  (`dispatch`/`dispatchOne`), `:315-336` (`registerSignedGuide`) — **sin ningún lock**, ni de
  `Order` ni de `OrderItem`.

**El caso más grave es `dispatch()` (líneas 219-273):** no bloquea absolutamente nada antes de
`registerKardexExits()` (línea 265), que llama a `KardexService::registrar()` por cada
`order_item`. Dos requests `POST .../dispatch` para el mismo pedido, casi simultáneos (doble
click real, o reintento de red del frontend sin idempotency key) —escenario explícitamente
pedido en el brief de este review ("dos despachos simultáneos")—:

1. Ambos pasan el check `$order->stage !== STAGE_POR_DESPACHAR` (línea 221) contra el mismo
   `$order` ya cargado, antes de que ninguno haya escrito nada.
2. Ambos entran a `DB::transaction()` y llaman `dispatchOne()` → `registerKardexExits()` →
   `KardexService::registrar()`, que sí toma `lockForUpdate()` sobre `ProductWarehouseStock`
   (`KardexService.php:50-55`) — pero eso solo SERIALIZA los dos decrementos, no los previene.
3. La primera transacción decrementa el stock y transiciona a `DESPACHADO`. Al commitear, la
   segunda (que esperaba el lock de `ProductWarehouseStock`) sigue adelante sin volver a mirar
   `$order->stage` — **decrementa el stock una SEGUNDA vez** por la misma mercancía, y vuelve a
   escribir `order_stage_history` + dispara `OrderDispatched` otra vez.

Resultado: `product_warehouse_stock.quantity` queda con el doble de lo realmente despachado
restado — exactamente el tipo de corrupción silenciosa que `KardexService`'s propio docblock dice
que nunca debería poder pasar ("para que el Kardex sea siempre un reflejo fiel de la realidad").
No hay guardas contra esto en ningún nivel (ni `KardexService::registrar()` valida saldo negativo,
`KardexService.php:57-60`).

Confirmé además que **no hay ningún test** — ni siquiera del tipo "secuencial" (ver Blocker 1b) —
que ejercite un doble `dispatch()`. `DeliveryFlowTest.php` no tiene ningún caso de doble-despacho.

### Blocker 1b — Los tests de "doble submit" existentes prueban secuencial, no concurrencia real, y eso es engañoso

`OrderPickingFlowTest::test_iniciar_picking_dos_veces_seguidas_la_segunda_falla` (línea 131) e
`InventoryReviewFlowTest::test_resolver_dos_veces_la_segunda_falla` (línea 144) están comentados
como cobertura de "doble submit", pero ambos hacen dos llamadas **HTTP secuenciales dentro del
mismo test síncrono** — la primera termina y commitea completamente antes de que la segunda
siquiera arranque. Como el controller vuelve a hacer `Order::find($id)` en cada request, la
segunda llamada ve el estado YA actualizado y devuelve 422 correctamente — pero eso no prueba
nada sobre el escenario real de concurrencia (dos requests que llegan casi al mismo tiempo,
ambos leyendo el estado viejo antes de que cualquiera de los dos commitee), que es justamente el
que reventó en Batch A1. Dan una falsa sensación de que el caso ya está cubierto.

**Fix recomendado** (mismo patrón ya usado y documentado en `AdjustmentRequestController`): en
cada método de servicio que transicione `Order.stage`, mover el fetch+check DENTRO de
`DB::transaction()`, con `Order::lockForUpdate()->findOrFail($order->id)` (o, como mínimo,
recargar `$order` con lock antes de leer `stage`) y volver a lanzar `OrderTransitionException` si
el estado ya cambió. Es más urgente en `dispatch()`/`dispatchOne()` que en el resto, porque ahí no
hay NINGÚN lock hoy, ni siquiera el incidental de `OrderItem`.

---

## 🔴 Blocker 2 — `resolvePrimaryWarehouseId()` no reparte por bodega; puede dejar stock negativo en una bodega mientras otra queda con remanente fantasma

`StockReservationService::resolvePrimaryWarehouseId()` (líneas 71-84) elige "la bodega con MÁS
stock, desempate por id más bajo", usando el `ProductWarehouseStock.quantity` crudo — que **no**
se decrementa hasta el despacho real (Kardex). El problema: esa elección es completamente
independiente de qué otros pedidos "en vuelo" (en_picking/packing/por_despachar) ya están
apuntando, en el papel, a la MISMA bodega — porque el back-allocation (`reallocateProduct()`,
línea 91-136) calcula el cupo `qty_reserved` contra el TOTAL sumado de todas las bodegas
(`$totalOnHand = $stockRows->sum('quantity')`, línea 95), no por bodega individual.

**Escenario concreto (determinístico, no necesita concurrencia real — solo dos pickings
consecutivos del mismo producto antes de que el primero despache):**

- Producto X: Bodega A tiene 10 unidades, Bodega B tiene 5 (total 15).
- Pedido 1 pide 10, completa picking primero → `resolvePrimaryWarehouseId()` devuelve Bodega A
  (10 > 5). Reserva 10 vía back-allocation (cabe en el total de 15). `warehouse_id` = A.
- Pedido 2 pide 5, completa picking después, **antes de que Pedido 1 despache** (Packing/Por
  despachar/Despachado son etapas separadas con pasos manuales entre medio — normal que tome
  horas). `ProductWarehouseStock` de Bodega A sigue mostrando 10 (nadie la decrementó todavía,
  eso solo pasa en `dispatch()`). `resolvePrimaryWarehouseId()` vuelve a devolver Bodega A (10 >
  5) — **no Bodega B**, aunque conceptualmente Bodega B es la que "le queda" a Pedido 2.
  `warehouse_id` = A también.
- Pedido 1 despacha: Bodega A baja de 10 a 0. Pedido 2 despacha: Bodega A intenta bajar de 0 a
  -5. `KardexService::registrar()` no tiene ninguna guarda contra saldo negativo
  (`KardexService.php:57-60`) — el movimiento se crea igual, con `saldo_resultante = -5`. Bodega
  B se queda con sus 5 unidades intactas, nunca tocadas, aunque en el sistema aparecen como
  "consumidas" (el total reservado ya llegó a 15/15).

Esto rompe la invariante que el propio `KardexService` declara como su razón de ser ("que cada
línea cuadre matemáticamente... reflejo fiel de la realidad"). No es un edge case exótico: pasa
con cualquier producto repartido en más de una bodega física con pedidos consecutivos razonablemente
cercanos en el tiempo — un escenario de negocio completamente normal para Atlantic
(`Warehouse` ya modela 7 bodegas físicas reales, ver `WarehouseControllerTest`).

`KardexDispatchIntegrationTest` solo cubre el caso de una sola bodega con stock (`Bodega Central`,
línea 52-53) — no hay ningún test con 2+ bodegas para este flujo, así que el defecto pasa
completamente inadvertido por la suite actual.

**Esto es un gap de diseño, no solo de código** — el fix probablemente requiere que
`StockReservationService` trackee reservas por bodega (no solo el agregado global), o que
`resolvePrimaryWarehouseId()` reciba/calcule un "ya comprometido" por bodega antes de elegir.
Dado que el criterio "más stock, bodega con más" fue una decisión de Luis vía Arquitecto (según el
brief), recomiendo escalar esto al Arquitecto para decidir el fix correcto en vez de que Backend
Dev lo resuelva unilateralmente — encaja con la regla del proyecto "si un dev detecta que la tarea
requiere cambiar el diseño aprobado → escala al Arquitecto".

---

## 🟡 Permisos en rutas nuevas — `routes/bodega.php` solo gatea por `bodega.read`/`bodega.write`/`bodega.approve` genérico; falta granularidad de rol en varios endpoints sensibles

El brief pidió atención especial acá porque A1 no agregó rutas nuevas y A2 sí. Encontré que el
dev SÍ aplica gates de rol específicos donde el ticket lo pide explícitamente y lo documenta bien:

- `PickingService::assignPicker()` → `BodegaRoles::CAN_OPERATE` (Lider+Asistente), con test
  (`test_asignar_picker_con_rol_no_autorizado_retorna_403`).
- `DeliveryService::assignCourier()` → solo `LIDER_BODEGA`, con test.
- `WarehouseAssistantMappingController::store()` → Lider o `superadmin.all`, con docblock
  explícito de por qué excluye a los Asistentes aunque tengan `bodega.write` — este es el patrón
  correcto y bien razonado.

Pero los siguientes endpoints **no tienen ninguna restricción de rol más allá del permiso
genérico `bodega.write`** — cualquier usuario de Bodega con ese permiso (que, a juzgar por el
patrón de "Asistentes que sí tienen bodega.write" citado arriba, probablemente incluye Picker y
Repartidor también) puede invocarlos, incluso sobre pedidos que no le pertenecen:

- `OrderPickingController::startPicking/updatePickingSheet/completePicking/resolveInventoryReview`
  (`PickingService.php`/`InventoryReviewService.php`) — sin chequeo de que el actor sea
  `order->picker_id`, ni siquiera de rol (`CAN_OPERATE`). Un Repartidor con `bodega.write` podría,
  en teoría, completar la Hoja de Picking de un pedido ajeno.
- `OrderDeliveryController::registerDelivery/dispatch/registerSignedGuide`
  (`DeliveryService.php`) — mismo problema.

No hay ningún test que verifique rechazo por rol incorrecto en estos 7 endpoints (busqué en los 5
archivos de test de Batch A2 — el único 403 de rol cubierto es `assign-picker` y
`assign-courier`).

No tengo el spec/HU original a mano para confirmar si esto es una omisión real o una decisión
consciente ("cualquier rol de Bodega puede operar cualquier paso del flujo, solo asignar picker y
repartidor está restringido"). Dado el patrón cuidadoso que el mismo dev aplicó en los 3 casos de
arriba, sospecho que es una omisión — pero lo marco como 🟡 (no bloqueante) en vez de 🔴 porque no
tengo certeza del criterio de negocio exacto. Recomiendo que el PM confirme contra el ticket antes
de Pre-QA — si la respuesta es "sí, hace falta restringir", esto se vuelve bloqueante para Pre-QA
sobre esos 7 endpoints.

---

## 🟢 Aprobado — Back-allocation (REQ-322)

`StockReservationService.php` completo, verificado línea por línea. Es back-allocation real, no
una aproximación:

- Prioridad por `committed_delivery_date` asc, empate por `created_at` asc, empate final por `id`
  (líneas 106-125) — coincide con RN1/RN2.
- Cada llamada a `reallocateProduct()` (línea 86) RECALCULA desde cero la asignación completa de
  TODOS los pedidos en `COMPETING_STAGES` para ese producto, no solo agrega/resta al margen — un
  pedido urgente que entra después SÍ le quita reserva a uno menos urgente ya asignado. Confirmado
  con test (`test_pedido_urgente_recupera_reserva_ya_otorgada_a_uno_menos_urgente`).
- `qty_reserved` del pedido que pierde queda en un estado consistente: se pone a 0 (o al remanente
  que le toque) dentro de la MISMA transacción que otorga la reserva al pedido ganador — no hay
  ventana donde ambos muestren un valor "reservando" el mismo stock.
- Fix correcto del bug de Postgres `FOR UPDATE` + `sum()`: lock-then-sum-en-PHP (líneas 88-95,
  comentario explícito).
- El pedido que pierde reserva **no recibe notificación proactiva** — el brief pedía verificar
  esto explícitamente. Confirmé (grep de `reallocat`/`qty_reserved` en todo el módulo) que no hay
  ningún evento/notificación disparado desde `reallocateProduct()`. Esto coincide con el propio
  docblock del service ("`qty_reserved` es puramente informativo/orientador... el asistente se
  entera cuando ve que le falta stock") — es una decisión de diseño documentada, no un descuido, y
  el número SÍ es visible en tiempo real en el board (`OrderBoardService.php:113`). Lo dejo como
  sugerencia no bloqueante: sería una mejora real avisarle proactivamente al picker/asistente
  cuando su reserva baja a mitad de camino, para que no lo descubra recién en la Hoja de Picking.

---

## 🟢 Aprobado — Familia de Entregas (REQ-319)

`FamilyService.php` es el único punto de escritura de `family_id`/`sequence_in_family`/
`total_in_family`, confirmado por grep — los 3 disparadores (picking parcial en
`PickingService::createInventoryReviewCard()`, revisión mixta en
`InventoryReviewService::splitFoundItemsIntoNewOrder()`, entrega parcial en
`DeliveryService::createFaltanteCard()`) crean la tarjeta hija con `family_id` seteado en el
`Order::create()` (nunca en un `update()` posterior — el docblock de `FamilyService::attach()`
explica correctamente por qué: el índice único parcial `orders_pipeline_card_root_uq` rechazaría
el INSERT si `family_id` quedara en NULL aunque sea por un instante dentro de la misma
transacción) y después llaman a `$this->family->attach($anchor, $child)`, que resecuencia toda la
familia. Ningún flujo reimplementa el conteo por su cuenta. `completionBadge()` (RN2, "todos
Pendiente hasta que todos lleguen a Por despachar") tiene test explícito.

---

## 🟢 Aprobado — fix de `order_number` compartido en familia (`2cb6ecd`)

Migración `2026_07_22_100008_scope_order_number_uniqueness_to_family_root.php` revisada: mismo
patrón que el índice parcial ya usado para `orders_pipeline_card_root_uq` en A1
(`WHERE family_id IS NULL`), con `down()` correcto y un índice no-único adicional para que el
buscador siga siendo rápido sobre tarjetas hijas. Consistente con HU-335.

---

## 🟢 Aprobado (con la observación del Blocker 2 arriba) — `warehouse_id` en Kardex (`7bdf7c8`)

- La migración (`2026_07_22_100009_...`) es correcta: `nullable`, FK con nombre explícito, índice.
- `registerKardexExits()` (`DeliveryService.php:283-312`) cumple el criterio de "nunca bloquear el
  flujo principal": si falta `warehouse_id`, loggea `Log::error` con contexto completo y hace
  `continue` — no lanza excepción. Verificado con test
  (`test_despachar_sin_warehouse_id_no_revienta_solo_loggea_y_sigue`) que confirma tanto el log
  como que el despacho sigue devolviendo 200.
- La heurística en sí (bodega con más stock) tiene el defecto real descrito en el Blocker 2 — no
  la marco como razonable sin reservas.

---

## 🟢 `invoice_ready` gate (punto 5 del brief)

Confirmado en la capa de servicio correcta: `DeliveryService::blockingReasonsForDispatch()`
(líneas 250-261), consumida por `dispatch()` antes de abrir la transacción — no es un chequeo que
viva solo en el controller. Tests `test_despachar_sin_factura_retorna_422` y
`test_despachar_arrastra_hermano_listo_pero_no_al_que_le_falta_factura` cubren el caso individual
y el de familia.

---

## 🟡 Suggestions no bloqueantes

1. **`qty_reserved` no se limpia al salir de `COMPETING_STAGES`.** Cuando un pedido pasa a
   `DESPACHADO`, su `OrderItem` deja de aparecer en el query de `reallocateProduct()` (ya no matchea
   `whereHas('order', stage in COMPETING_STAGES)`) pero su columna `qty_reserved` queda con el
   último valor que tenía, sin resetear a 0. Es inofensivo hoy (nada vuelve a leer `qty_reserved`
   de un pedido despachado), pero es dato stale que puede confundir a futuro si alguien lo expone
   en un reporte histórico. `StockReservationService.php:86-137`.
2. **`DeliveryService::registerDelivery()` — la observación general del pedido solo se loggea, no
   se persiste** (líneas 107-115) — el propio comentario del dev señala que es una decisión
   pendiente de confirmar con el Arquitecto sobre dónde debería vivir. Correcto dejarlo así por
   ahora, pero es una pérdida real de dato de negocio si nadie hace seguimiento — sugiero que quede
   anotado explícitamente en el backlog para no perderlo de vista.
3. **`OrderBoardService::applyFilters()` — filtro `cliente` usa `where('customer_name', $cliente)`
   exacto** (línea 156), inconsistente con `search` que sí usa `ilike` parcial (línea 138). Si el
   frontend (Batch A3) arma este filtro desde una lista desplegable de nombres exactos no es
   problema; si lo arma desde un input libre, va a fallar silenciosamente (0 resultados) por
   mayúsculas/espacios. Vale la pena confirmarlo con Frontend Dev antes de A3.
4. **`resolvePrimaryWarehouseId()` no considera `ProductWarehouseStock` con `quantity <= 0` de
   forma explícita en el mensaje de error** — devuelve `null` silenciosamente (línea 73-75, 77-83),
   lo cual es correcto funcionalmente (ver Blocker de "nunca bloquear"), pero no genera ningún log
   en ese caso (a diferencia de cuando falta al momento de despachar). Si un producto se queda sin
   stock en TODAS las bodegas justo al completar picking, no queda ningún rastro de por qué
   `warehouse_id` terminó en null. Sugerencia menor: loggear también acá.

---

## Verificación ejecutada

- **Rama:** `dev` confirmado (`git branch --show-current`), sin pushear.
- **Tests:** `infra/test.sh` corrido de punta a punta (drop+recreate de schemas de tenant incluido)
  → **1132/1132 tests, 4619 assertions, OK** — coincide exactamente con lo reportado por Backend
  Dev. Confirmé también que el script `infra/test.sh` sigue funcionando correctamente tras el
  cambio de `f327681` (invoca `vendor/bin/phpunit` directo con `-d memory_limit=512M` en vez de
  `artisan test`, para que el override de memoria aplique al proceso real que corre los tests, no
  al padre) — sin errores de invocación, `.env.testing` se sigue aplicando por encima de lo horneado
  por `env_file` (verificado indirectamente: la suite completa, incluyendo los tests de Compras que
  motivaron el cambio de memory_limit, corrió sin "Allowed memory size exhausted").
- **PHPStan:** `vendor/bin/phpstan analyse` con `memory_limit=1G` → **`[OK] No errors`** — coincide
  con lo reportado.

---

## Siguiente paso

No pasa a Pre-QA todavía. PM reasigna a Backend Dev:
1. Blocker 1 — agregar lock+recheck dentro de la transacción en las 8 transiciones de `Order`
   listadas, empezando por `dispatch()`/`dispatchOne()` (el más grave).
2. Blocker 2 — escalar al Arquitecto para decidir el fix de `resolvePrimaryWarehouseId()`
   (reserva por bodega vs. otro mecanismo) antes de implementar.
3. Confirmar con PM/spec si los 7 endpoints sin gate de rol granular son una omisión o decisión
   consciente; si es omisión, aplicar el mismo patrón que `assignPicker`/`assignCourier`.

Tras la mitigación, vuelve a Senior Review (no directo a Pre-QA) dado que Blocker 1 toca el
service layer completo del batch.

---

## Re-revisión (2026-07-22, commit `cab5a27`)

**Alcance:** `git show cab5a27` completo — `app/Modules/Bodega/Services/Concerns/ChangesOrderStage.php`,
`PickingService.php`, `DeliveryService.php`, `InventoryReviewService.php`,
`StockReservationService.php` (leídos en su versión completa actual, no solo el diff), más
`tests/Feature/Bodega/Concerns/RunsConcurrentBodegaRequests.php` y los 3 archivos de
`tests/Feature/Bodega/Concurrency/`. Nota de precedente: el prompt de esta re-revisión referencia
`docs/reviews/oleada-a-batch-a1-bodega-pedidos-fundacion-2026-07-22.md` — ese archivo **no existe**
en el repo (confirmado, `docs/reviews/` solo tiene el archivo de este propio Batch A2 y 3 más
ajenos a Bodega). Esto ya lo había señalado la primera pasada de este mismo review (ver nota al
inicio del archivo) — el precedente real de rigor/tono que sí existe y usé es la propia primera
pasada de este documento, más el docblock de `AdjustmentRequestController::approve()`/`reject()`
que ambas pasadas citan como el bug real ya corregido en Batch A1.

### Blocker 1 (+ 1b) — verificado, corregido correctamente

Confirmé, leyendo el código completo (no el diff) de las 4 clases, que las **8 transiciones**
listadas en la primera pasada ahora abren con `$locked = $this->lockOrder($order->id)`
(`ChangesOrderStage.php:33-43`, `SELECT ... FOR UPDATE` sobre `Order`) y vuelven a validar
`stage`/`order_type` contra esa copia bloqueada, nunca contra el `$order` recibido como parámetro
(cargado sin lock por el controller):

1. `PickingService::assignPicker()` — `PickingService.php:57-60`.
2. `PickingService::startPicking()` — `PickingService.php:73-76`.
3. `PickingService::updatePickingSheet()` — `PickingService.php:97-100`.
4. `PickingService::completePicking()` — `PickingService.php:142-145`.
5. `InventoryReviewService::resolve()` — `InventoryReviewService.php:80-86` (revalida tanto
   `order_type` como `stage`, ambos podían haber cambiado).
6. `DeliveryService::registerDelivery()` — `DeliveryService.php:71-74`.
7. `DeliveryService::assignCourier()` — `DeliveryService.php:203-206`, y además cada hermano de
   familia se re-bloquea y revalida individualmente antes de tocarlo (`:218-221`), en vez de
   confiar en la copia sin lock que devuelve `family->members()`.
8. `DeliveryService::dispatch()`/`dispatchOne()` — `DeliveryService.php:249-252`, el caso más
   grave del hallazgo original. Cada hermano de familia también se re-bloquea y revalida
   (`:270-273`) antes de despacharlo junto.
9. `DeliveryService::registerSignedGuide()` — `DeliveryService.php:357-360`.

(Cuento 9 porque la primera pasada agrupó `assignCourier`+`dispatch`+`registerSignedGuide` como "3"
dentro de la lista de 8 líneas citadas — el punto es el mismo: cobertura completa, ninguna
transición quedó afuera.)

**Tracé el escenario exacto del hallazgo** (dos `POST .../dispatch` casi simultáneos sobre el
mismo pedido) contra el código actual:

1. Request 1 entra a `dispatch()`, abre transacción, `lockOrder()` toma el lock de fila de
   Postgres sobre `Order` — Request 2 (misma fila) queda bloqueada esperando ese lock en su propio
   `lockOrder()`, **antes** de llegar a `blockingReasonsForDispatch()` o a `dispatchOne()`.
2. Request 1 continúa: `blockingReasonsForDispatch()` pasa, `dispatchOne()` llama
   `registerKardexExits()` → `KardexService::registrar()` (decrementa `product_warehouse_stock`),
   `transitionStage()` pasa `Order.stage` a `DESPACHADO`, se recalculan reservas. Transacción
   commitea.
3. Request 2 obtiene el lock recién liberado, `lockOrder()` devuelve la copia **fresca** de
   `Order` — `stage` ya es `DESPACHADO`, no `POR_DESPACHAR` — la condición de
   `DeliveryService.php:250` falla y lanza `OrderTransitionException` (422), sin tocar Kardex.

Esto es exactamente lo que exige el fix: la segunda request ve el estado post-commit de la
primera, no una copia stale. Confirmé con mis propios ojos que no hay ningún camino en `dispatch()`
que lea `stage` antes de `lockOrder()` — el único acceso a `$order` (parámetro) antes del lock es
`$order->id` para pasarlo a `lockOrder()`, nunca `$order->stage`.

**Blocker 1b:** los 3 tests nuevos en `tests/Feature/Bodega/Concurrency/` sí exercitan concurrencia
real, no secuencial dressed-up. Verifiqué el mecanismo en
`RunsConcurrentBodegaRequests::runConcurrently()` (`tests/Feature/Bodega/Concerns/RunsConcurrentBodegaRequests.php:44-111`):
cada snippet corre en un **proceso PHP separado** (`Symfony\Process`, no un hilo ni una coroutine
dentro del mismo proceso PHPUnit), cada uno bootea su propio Laravel (`bootstrap/app.php`) y por lo
tanto abre su **propia conexión real a Postgres** — la única forma de conseguir aislamiento MVCC
real (dos transacciones que no se ven entre sí hasta el commit) que un solo proceso PHPUnit
síncrono no puede simular, tal como señala el propio docblock del trait citando el precedente de
Batch A1. El barrier de archivos (`ready-N` / `go`) maximiza que ambos procesos lleguen al
`lockForUpdate()` real casi al mismo tiempo, aunque — correctamente señalado en el docblock — la
corrección no depende de un timing perfecto: el lock de Postgres serializa sin importar el orden
exacto. `$connectionsToTransact = []` en las 3 clases de test desactiva el wrapping transaccional
de `RefreshDatabase`, así que el fixture del test padre SÍ queda comiteado y visible para los 2
procesos hijos — y cada clase limpia lo que crea en `tearDown()` (verifiqué las 3, sin fugas: cada
una borra `OrderStageHistory`/`OrderItem`/`Order` — y `DispatchConcurrencyTest` además borra
`InventoryMovement`/`OrderDocument`/`ProductWarehouseStock`/`CatalogProduct` — por `order_id`/
`catalog_product_id` explícito).

Las 3 aserciones de negocio son las correctas para probar el bug real: exactamente 1 de los 2
resultados es `OK` y el otro es `REJECTED:...` (no 0, no 2), y exactamente **una** fila de
`order_stage_history` hacia el estado destino (no una por request) — esto último es justo lo que
habría fallado con el código viejo (dos transiciones exitosas → dos filas de historial). En
`DispatchConcurrencyTest` la aserción de negocio central es aún más directa: exactamente 1 fila en
`InventoryMovement` y `product_warehouse_stock.quantity` en `15` (20-5), nunca `10` (20-10, el
doble decremento que motivó el Blocker 1 original).

Además, los tests secuenciales viejos (`OrderPickingFlowTest::test_iniciar_picking_dos_veces_seguidas_la_segunda_falla`,
`InventoryReviewFlowTest::test_resolver_dos_veces_la_segunda_falla`) **no se borraron ni se
reetiquetaron como si fueran cobertura de concurrencia** — sus docblocks ahora dicen explícitamente
"NO es una prueba de concurrencia real" y apuntan al test de concurrencia real correspondiente
(`OrderPickingFlowTest.php:127-138`, `InventoryReviewFlowTest.php` línea equivalente). Es la
honestidad exacta que pedía el hallazgo 1b — antes daban falsa confianza, ahora están rotulados
correctamente y siguen siendo útiles como prueba del estado final de 2 llamadas secuenciales
reales (que también es un caso de negocio legítimo, solo que no el de concurrencia).

**Veredicto Blocker 1/1b: corregido correctamente y verificado de forma independiente — no solo
confié en el mensaje del commit.**

### Blocker 2 — verificado, corregido correctamente

Leí `StockReservationService.php` completo. El fix reemplaza "la bodega con más stock" (agregado
global, ciego a compromisos de otros pedidos) por un reparto **consciente de bodega**, en 2 fases
dentro de la misma transacción que ya bloqueaba `product_warehouse_stock`:

- **Fase 1 — congelar** (`StockReservationService.php:131-152`): ítems cuyo picking ya es un hecho
  físico (`Order.stage` en `FROZEN_STAGES` = Packing/Por despachar) mantienen su `warehouse_id` tal
  cual está, y su `qty_picked` se resta del remanente de ESA bodega específica —
  `$remainingByWarehouse[$frozenWarehouseId] -= $committed` (línea 145) — antes de repartir nada
  entre los competidores todavía "en plan".
- **Fase 2 — repartir** (`:154-178`): los ítems todavía mutables (En picking, sin picking físico
  consumado) compiten en orden de prioridad (RN1/RN2, sin cambios respecto a la primera pasada)
  contra el remanente que dejó la fase 1, con `bestFitAllocation()` (`:188-208`) — la bodega con
  MÁS remanente que alcance a cubrir (total o parcial) la necesidad.

**Tracé el escenario exacto del Blocker 2** (Bodega A=10, Bodega B=5, Pedido 1 pide 10 y completa
picking primero, Pedido 2 pide 5 y completa picking después, antes de que Pedido 1 despache) contra
este código:

1. Pedido 1 completa picking: en ese momento su propio `Order.stage` todavía es `EN_PICKING` (la
   primera llamada a `reallocateForProducts()` en `PickingService.php:161` ocurre ANTES de
   `transitionStage()` a `PACKING`, línea 189) — su ítem entra a la Fase 2 como mutable,
   `bestFitAllocation` con remanente crudo `{A:10, B:5}` elige A (10>5), `warehouse_id=A`,
   `qty_reserved=10`. Tras `qty_picked` actualizado y la transición a `PACKING`, la SEGUNDA llamada
   a `reallocateForProducts()` (línea 209, con el pedido ya en `PACKING`) congela ese ítem en Fase
   1: `remainingByWarehouse[A] = 10 - 10 = 0`.
2. Pedido 2 empieza y completa su propio picking (llamada independiente, nueva transacción): la
   query de `stockRows` (`StockReservationService.php:87-90`) relee `product_warehouse_stock` desde
   cero — sigue en `{A:10, B:5}` crudo, porque Kardex todavía no decrementó nada (eso solo pasa en
   `dispatch()`). Fase 1 de ESTA llamada procesa el ítem YA CONGELADO de Pedido 1 (su `Order.stage`
   sigue siendo `PACKING`): `remainingByWarehouse[A] = 10 - 10 = 0` otra vez (recalculado, no
   acumulado — correcto, es idempotente). Fase 2 procesa el ítem mutable de Pedido 2 (necesita 5):
   `bestFitAllocation` contra `{A:0, B:5}` — A no califica (`0 > 0` es falso), B sí — **asigna
   Bodega B**, no A. Exactamente el resultado correcto.
3. Verifiqué que esto es precisamente lo que cubre el nuevo test
   `KardexDispatchIntegrationTest::test_dos_pedidos_consecutivos_en_2_bodegas_no_dejan_stock_negativo`
   (leí el archivo completo, no until el nombre) — asserts `item2->warehouse_id === $bodegaB->id`
   ANTES de despachar nada (la aserción que prueba el fix de asignación en sí, independiente del
   resultado final de stock), y luego, tras despachar ambos pedidos, `stockA === 0` y `stockB ===
   0` (nunca negativo, nunca con remanente fantasma) más los 2 `InventoryMovement` apuntando cada
   uno a su bodega correcta.

El límite de diseño que la clase documenta explícitamente (`StockReservationService.php:37-48`,
"un `OrderItem` sigue apuntando a UNA sola bodega, no se reparte una línea entre 2+ bodegas") es una
decisión de alcance razonable y bien señalizada, no un descuido — coincide con lo que el Arquitecto
aprobó según la nota del propio commit.

**Casos límite que revisé además de lo pedido, sin encontrar problemas:**
- **Producto sin ninguna fila de `product_warehouse_stock`:** `$stockRows` vacío →
  `$remainingByWarehouse = []`. En Fase 1, un ítem congelado con `warehouse_id` que no aparece en
  el mapa usa `?? 0` (línea 145) y el remanente de esa bodega queda en negativo dentro del array
  PHP en memoria (nunca se persiste como negativo en `product_warehouse_stock`, solo se usa para
  decidir reparto) — inofensivo. En Fase 2, `bestFitAllocation()` con el mapa vacío o solo con
  entradas ≤0 devuelve `[null, 0]` (línea 203-204 tras el loop, porque `$bestRemaining` arranca en 0
  y la condición es estrictamente `>`) — el ítem queda sin bodega asignada, mismo criterio de
  "nunca bloquear" que ya usa el resto del código (Blocker de la primera pasada). No genera
  excepción.
- **`catalog_product_id` null:** nunca llega a `reallocateProduct()` — todos los call sites
  (`PickingService.php:160`, `DeliveryService.php:135/306`, `StockReservationService.php:69`)
  filtran con `->filter()` (elimina `null`/`0`/falsy) antes de pasar la lista a
  `reallocateForProducts()`. Un ítem "personalizado" (sin producto de catálogo) simplemente nunca
  entra a la lógica de reserva — correcto, no hay nada que reservar para un ítem sin SKU.
- **Remanente negativo forzando una asignación inválida:** imposible por construcción —
  `bestFitAllocation()` solo elige una bodega si `remaining > $bestRemaining` con `$bestRemaining`
  inicial en `0`, así que un remanente negativo (caso de arriba) nunca puede ganar la comparación
  ni producir un `grant` positivo.

**Veredicto Blocker 2: corregido correctamente, escenario exacto verificado paso a paso, casos
límite adicionales revisados sin hallazgos.**

### 🟡 Hallazgo nuevo (no bloqueante) — orden de adquisición de locks inconsistente entre `Order`/`OrderItem` propios y `ProductWarehouseStock`/`OrderItem` ajenos: riesgo real de deadlock (no de corrupción)

El brief de esta re-revisión pidió explícitamente evaluar riesgo de deadlock por el orden en que
cada service bloquea `Order`, `OrderItem` y `ProductWarehouseStock` — encontré una inconsistencia
real:

- **`DeliveryService::registerDelivery()`** bloquea primero sus propios `OrderItem`
  (`DeliveryService.php:89`, dentro del `foreach` que arranca en la línea 87) y **recién después**
  llama a `reservations->reallocateForProducts()` (línea 136), que internamente bloquea
  `ProductWarehouseStock` primero (`StockReservationService.php:87-90`) y luego `OrderItem` de
  *todos* los pedidos competidores por ese producto, ordenados por id (`:99-105`) — lo que puede
  incluir `OrderItem` de otros pedidos, no solo el propio.
- **`InventoryReviewService::resolve()`** tiene el mismo orden: bloquea sus propios `OrderItem`
  primero (`InventoryReviewService.php:102`, dentro del `foreach` de decisiones) y llama a
  `reservations->reserveForOrder()` después (líneas 127/139).
- **`PickingService::completePicking()`** hace lo inverso: llama a
  `reservations->reallocateForProducts()` **primero** (`PickingService.php:161` — bloquea
  `ProductWarehouseStock` y luego `OrderItem` de todos los competidores) y **recién después**
  bloquea explícitamente sus propios `OrderItem` en el `foreach` (línea 165) — aunque para ese
  momento ya están bloqueados como parte del conjunto "competidores" de la llamada anterior, así
  que no es un lock nuevo, es sencillamente el mismo orden PWS→OrderItem.
- **`DeliveryService::dispatch()`/`dispatchOne()`** también sigue el orden PWS→OrderItem: la
  primera fila de `product_warehouse_stock` que se toca es dentro de
  `KardexService::registrar()` (`KardexService.php:50-52`, llamado desde
  `registerKardexExits()` en `DeliveryService.php:300`), **antes** de la llamada a
  `reallocateForProducts()` (línea 307) que bloquea `OrderItem` de otros competidores.

Esto da 2 grupos con orden de lock invertido para los mismos 2 tipos de recurso
(`ProductWarehouseStock` vs. `OrderItem` de OTRO pedido): {`registerDelivery`, `resolve`} bloquean
`OrderItem` propio antes que `ProductWarehouseStock`; {`completePicking`, `dispatch`/`dispatchOne`}
bloquean `ProductWarehouseStock` antes que `OrderItem` ajeno. Un clásico patrón A-B / B-A entre
transacciones concurrentes de 2 pedidos DISTINTOS que compiten por el mismo producto:

- Transacción 1 (`completePicking` de Pedido A): bloquea `Order A` → bloquea
  `product_warehouse_stock` del producto compartido → intenta bloquear el `OrderItem` de Pedido B
  (que también compite por ese producto y sigue en `COMPETING_STAGES`) — si Pedido B ya está
  bloqueado por la Transacción 2, esta llamada queda esperando.
- Transacción 2 (`registerDelivery` de Pedido B, concurrente): bloquea `Order B` → bloquea el
  `OrderItem` propio de Pedido B (línea 89, antes de reasignar) → intenta bloquear
  `product_warehouse_stock` del mismo producto (dentro de `reallocateForProducts()`, línea 136) —
  si la Transacción 1 ya lo tiene, esta también queda esperando.

Resultado: Transacción 1 espera un recurso que tiene la Transacción 2, y la Transacción 2 espera un
recurso que tiene la Transacción 1 — deadlock real, detectable por Postgres
(`deadlock_timeout`, default 1s) y NO por ningún mecanismo propio del código. Es un escenario de
negocio plausible, no exótico: 2 miembros de Bodega completando picking y registrando entrega
respectivamente, casi al mismo tiempo, para 2 pedidos distintos que comparten un producto — el
propio Batch A2 modela "familia" y "back-allocation" precisamente porque este tipo de solapamiento
es el caso normal de un almacén con varios asistentes trabajando en paralelo.

**Por qué NO lo marco como bloqueante (🔴) igual que los 2 anteriores:** a diferencia de los
Blockers 1/2 originales, esto **no corrompe datos silenciosamente** — Postgres aborta una de las 2
transacciones con un error de "deadlock detected", esa transacción hace rollback completo (nada
queda a medio escribir) y el usuario ve un 500 que puede resolver reintentando el clic. Es un bug
real de UX/disponibilidad bajo concurrencia, no de integridad — clasificación distinta a los 2
hallazgos que motivaron esta ronda.

**Fix sugerido, de bajo riesgo:** Laravel ya reconoce esta clase de error
(`Illuminate\Database\Connection::causedByDeadlock()`, que matchea los SQLSTATE `40001`/`40P01` de
Postgres) y `DB::transaction($callback, $attempts)` reintenta automáticamente el closure completo
hasta `$attempts` veces si detecta un deadlock — sin necesidad de tocar la lógica de negocio. Hoy
ninguno de los `DB::transaction(...)` de `PickingService`, `DeliveryService`,
`InventoryReviewService` ni `StockReservationService` pasa `$attempts` (confirmé con `grep`, los 10
call sites del módulo Bodega usan la firma de 1 argumento, default `$attempts = 1`, sin retry).
Pasar `$attempts: 3` (o el valor que el Arquitecto prefiera) en los métodos que transicionan
`Order.stage` convertiría este deadlock ocasional en un reintento transparente en vez de un 500 —
fix acotado, sin cambiar el orden de locks ni el diseño de back-allocation. Alternativa más
invasiva (no recomendada para este batch): unificar el orden de adquisición en las 4 clases para
que siempre sea `ProductWarehouseStock` → `OrderItem` ajeno → `OrderItem` propio, pero eso toca el
orden de operaciones de negocio en `registerDelivery()`/`resolve()` (hoy validan y marcan sus
propios ítems ANTES de recalcular reservas, lo cual tiene sentido de negocio por sí mismo) y es un
cambio de mayor superficie para un problema que el retry resuelve igual de bien.

No encontré ningún test (ni en la suite existente ni en los 3 nuevos de concurrencia) que ejercite
2 pedidos DISTINTOS compitiendo por el mismo producto de forma concurrente — los 3 tests nuevos
prueban 2 requests sobre el **mismo** pedido, que es exactamente el escenario de los Blockers
1/1b originales, pero no el de este hallazgo nuevo. Recomiendo un test de concurrencia adicional
(mismo mecanismo de `RunsConcurrentBodegaRequests`, pero con 2 `Order` distintos compitiendo por 1
producto) si se decide invertir en el fix de retry, para probarlo antes/después.

### Verificación ejecutada (independiente, no confié en el reporte de Backend Dev)

- **Rama:** `dev` confirmado (`git branch --show-current` → `dev`), `git status` limpio, sin
  pushear (`ahead of origin/dev by 7 commits`).
- **`git show cab5a27 --stat`** y `git log --oneline -10` corridos directamente para confirmar el
  alcance real del commit antes de leer nada.
- **Tests — suite completa:** `infra/test.sh` (no `docker compose exec` directo) →
  **1136 tests, 4653 assertions, OK**. Nota: el número de tests subió de 1132 (primera pasada) a
  1136 — coincide con los 4 tests nuevos de este commit (3 de concurrencia + 1 de
  `KardexDispatchIntegrationTest`), ya contando que `InventoryReviewFlowTest`/`OrderPickingFlowTest`
  no agregaron casos nuevos, solo re-documentaron los existentes.
- **Tests — filtro Bodega:** `infra/test.sh --filter=Bodega` → **100 tests, 458 assertions, OK**,
  incluyendo los 3 tests de concurrencia real y el nuevo test de 2 bodegas de
  `KardexDispatchIntegrationTest`, corridos de verdad (no solo leídos) — confirmé que no quedaron
  saltados ni marcados `skipped`.
- **PHPStan:** `docker compose -f infra/docker-compose.yml exec -T laravel php -d memory_limit=-1 vendor/bin/phpstan analyse --memory-limit=1G < /dev/null`
  → **`[OK] No errors`** (335 archivos analizados, Level 8).
- **Lectura completa** (no solo diff) de las 5 clases de servicio/concern tocadas y de los 4
  archivos de test nuevos/modificados de concurrencia, línea por línea, para trazar los 2
  escenarios pedidos por el brief a mano, sin asumir que el código hace lo que dice el docblock.

### Estado del hallazgo 🟡 de permisos (primera pasada)

Sigue abierto, sin cambios en este commit — confirmado que es intencional (el brief de esta
re-revisión aclaró que no se atendió a propósito, pendiente de decisión PM/spec). No lo trato como
regresión; sigue como estaba: no bloqueante, pendiente de confirmación de PM antes de Pre-QA sobre
si los 7 endpoints deben ganar el mismo gate de rol que `assignPicker`/`assignCourier`.

### Veredicto: 🟢 Aprobado

Blocker 1 (+1b) y Blocker 2 verificados de forma independiente, corregidos correctamente, con
tests reales (no simulados) que efectivamente fallarían si el fix se revirtiera — confirmé esto
último mentalmente para cada test (ej. sin `lockOrder()`, `DispatchConcurrencyTest` vería 2 `OK` y
2 `InventoryMovement`, no 1). Suite completa (1136/1136) y PHPStan Level 8 (`No errors`) corridos
por mí, no tomados del reporte de Backend Dev. Un hallazgo nuevo 🟡 no bloqueante (riesgo de
deadlock por orden de locks inconsistente entre servicios) queda documentado arriba con fix
sugerido de bajo riesgo (retry de Laravel), a criterio del PM si se agenda ahora o como ticket de
seguimiento — no impide continuar. El hallazgo 🟡 de permisos de la primera pasada sigue abierto
sin cambios, tal como se esperaba.

**Lista para Pre-QA.** El PM puede mover Batch A2 de `Dev Testing` a `QA` una vez Pre-QA corra su
checklist completo sobre el flujo de Pedidos — recomiendo que Pre-QA incluya explícitamente el
escenario "2 pedidos distintos, mismo producto, picking/entrega casi simultáneos" dado el hallazgo
🟡 de deadlock de arriba, aunque no bloquee el pase.
