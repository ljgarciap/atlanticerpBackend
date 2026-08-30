# ADR: Puente de evento Compras→Bodega para liberar tarjetas "Sin stock" (SCRUM-406 RN2)

**Status:** Approved — Backend Dev puede implementar
**Fecha:** 2026-07-24
**Autor:** Arquitecto

## Contexto

SCRUM-406 (REQ-336, "Tarjeta sin stock — fecha estimada de llegada visible en el tablero") define
dos escenarios de aceptación. El Escenario 1 (ETA visible en la tarjeta sin abrir el detalle) ya
está implementado y Pre-QA lo confirmó limpio en vivo (2026-07-24). El Escenario 2 (RN2 — "la
tarjeta debe salir de este estado 'sin stock' automáticamente... cuando Compras marque la orden
correspondiente como recibida") **no existe en ningún lado del código** — Pre-QA lo confirmó
rompiendo el flujo real: avanzó una `PurchaseOrder` real hasta `recibido` con el usuario real de
Compras y la tarjeta de Bodega siguió mostrando "Sin stock" indefinidamente.

Investigación de código (no asumida, confirmada archivo por archivo):

- `PurchaseOrderController::advance()` (`app/Modules/Compras/Http/Controllers/PurchaseOrderController.php:394-424`)
  es donde una PO pasa a `STATUS_RECIBIDO`. Dispara `GoodsReceiptConfirmationService::confirmPendingForOrder()`,
  que sí incrementa `catalog_products.stock_quantity` (vía `confirmLine()`, líneas 46-63) y escribe
  Kardex — pero **no dispara ningún `DomainEvent`**. El propio `advance()` sí dispara uno para el
  caso de liquidación (`OrderLiquidationAssigned`, líneas 373-379) — la ausencia acá es una omisión,
  no una decisión.
- `Order::isAwaitingStock()` (`app/Modules/Bodega/Models/Order.php:183-186`) es una lectura pura de
  dos columnas persistidas: `order_type === ORDER_TYPE_FALTANTE && stage === STAGE_ASIGNADO`.
  `order_type` se setea a `faltante` en `InventoryReviewService::resolve()` (RN2/RN3 de esa
  revisión) y en `DeliveryService::registerDelivery()` — **nada en el código lo revierte jamás**.
- No existe FK entre `OrderItem` y `PurchaseOrderLine`. El matching debe ser por
  `catalog_product_id`, el mismo campo que ya usa `OrderBoardService::supplierEta()`/
  `bulkSupplierEtaByProduct()` (líneas 300-361) para mostrar la ETA — no es un heurístico nuevo, es
  el mismo ya validado por Pre-QA para el Escenario 1.
- `OrderItem` (`app/Modules/Bodega/Models/OrderItem.php`) trackea `qty_requested`/`qty_delivered`
  (`pendingQty()`, línea 135-138) y `shortage_reason`/`found_at_location` — un ítem faltante tiene
  `found_at_location = false` y `shortage_reason = SHORTAGE_SIN_STOCK`.
- Precedente directo de un listener cross-módulo con la misma forma de aislamiento de fallos:
  `Bodega\Listeners\CreateOrderFromApprovedQuote` (`app/Modules/Bodega/Listeners/CreateOrderFromApprovedQuote.php:35-41`)
  envuelve `handle()` en try/catch + `Log::error()` explícitamente, con la decisión documentada de
  que "un fallo del lado de Bodega no puede bloquear ni romper" el flujo del módulo productor.
  `ShouldQueue` fue considerado y rechazado ahí por la misma razón que aplica acá: el criterio de
  aceptación pide que el efecto sea inmediato, no diferido — ningún listener del proyecto usa
  `ShouldQueue` hoy.

## Decisión

### 1. Evento nuevo: `Compras\Events\PurchaseOrderReceived`

Se dispara en `PurchaseOrderController::advance()`, inmediatamente después de que
`confirmPendingForOrder()` complete sin excepción (mismo punto donde hoy falta), síncrono vía
`event()`, mismo patrón que `OrderLiquidationAssigned`.

```php
eventName(): string => 'compras.purchase-order.received'
payload(): array => [
    'purchase_order_id' => int,
    'product_ids'       => int[],  // catalog_product_id de las líneas de esta PO
    'tenant'            => string,
]
```

`product_ids` va en el payload (no se re-consulta desde el listener) para que el listener no
necesite conocer el shape interno de `PurchaseOrderLine` — mismo criterio de acoplamiento mínimo
que ya sigue `DomainEvent`.

### 2. Listener nuevo: `Bodega\Listeners\ReleaseOrdersAwaitingStock`

Registrado en `AppServiceProvider::boot()` junto a los demás (`Event::listen(PurchaseOrderReceived::class, ReleaseOrdersAwaitingStock::class)`).

Lógica (dentro de un try/catch + `Log::error()` que envuelve TODO el `handle()`, igual que
`CreateOrderFromApprovedQuote` — un fallo acá nunca debe afectar la respuesta HTTP de Compras):

1. Buscar `Order::where('order_type', Order::ORDER_TYPE_FALTANTE)->where('stage', Order::STAGE_ASIGNADO)`
   que tengan al menos un `OrderItem` con `catalog_product_id` en `payload['product_ids']` y
   `shortage_reason = OrderItem::SHORTAGE_SIN_STOCK` / `found_at_location = false`.
2. Ordenar los candidatos por `status_changed_at` ascendente (FIFO — el pedido que lleva más tiempo
   esperando se libera primero si el stock alcanza).
3. Para cada candidato, para cada ítem faltante de ese pedido: confirmar que
   `catalog_products.stock_quantity` (ya incrementado por `GoodsReceiptConfirmationService`) cubre
   `pendingQty()` de ese ítem — descontando en memoria lo que ya se "asignó" a candidatos previos en
   esta misma pasada, para no liberar de más si dos pedidos compiten por el mismo stock recién
   llegado. Si el pedido tiene ítems de MÁS de un producto faltante, todos deben quedar cubiertos
   para liberar ese pedido completo (no liberación parcial de un pedido).
4. Ítems cubiertos: `found_at_location = true`. Cuando TODOS los ítems faltantes de un pedido quedan
   cubiertos: `order_type = ORDER_TYPE_PEDIDO` (el pedido vuelve al flujo normal de picking — mismo
   valor que usa `InventoryReviewService::resolve()` para "todo encontrado"). **No se cambia
   `stage`** — el pedido sigue en `asignado`, listo para el flujo normal de picking; el criterio de
   aceptación solo exige que deje de mostrarse "sin stock", no que avance de etapa.
5. Notificar (mismo mecanismo que `notifyShortage()` usa hoy para avisar del faltante — avisar
   ahora de la liberación, buscar si hay un canal de notificación ya existente que reusar en vez de
   crear uno nuevo).

**Limitación conocida, no introducida por este diseño:** no existe sistema de reserva de stock para
pedidos "faltante" (a diferencia del flujo normal, que sí reserva vía `reservations->reserveForOrder()`).
Si dos pedidos compiten por el mismo stock recién llegado, gana el que la query ordena primero
(FIFO por tiempo de espera) — es la misma ausencia de reserva que ya existe en el resto del sistema
para este caso, no un gap nuevo que este diseño deba resolver. Un sistema de reserva completo para
pedidos faltantes sería una feature separada, más grande, fuera de alcance de SCRUM-406.

**Consistencia visual:** el tablero de Bodega usa TanStack Query con `staleTime` global (no hay
capa de websockets en el proyecto) — la tarjeta refleja el cambio en el próximo refetch, no al
instante. Esto es consistente con el resto de actualizaciones de estado del tablero (nada en el
proyecto empuja cambios en tiempo real hoy); no se introduce infraestructura de push para esto.

### 3. Test de Pre-QA ya escrito (falla por diseño hasta que esto se implemente)

`atlanticerp-frontend/e2e/preqa-scrum406-rn2-cross-module.spec.ts` (agregado por Pre-QA, 2026-07-24) —
correr este test es el criterio de "implementado correctamente", no hace falta escribirlo de cero.

## Consecuencias

- Nuevo evento + listener siguen el patrón `DomainEvent` ya establecido — no requiere Kafka ni
  cambios de infraestructura.
- Actualizar la sección "Eventos de dominio" de `atlanticerp/CLAUDE.md` con
  `compras.purchase-order.received → Compras\Events\PurchaseOrderReceived`.
- Backend Dev implementa evento + listener + test unitario del listener (casos: cobertura total,
  cobertura parcial de un producto entre 2 pedidos competidores, pedido con ítems de 2 productos
  distintos donde solo uno llega). Frontend no requiere cambios — ya lee `order_type`/`stage` reales.
