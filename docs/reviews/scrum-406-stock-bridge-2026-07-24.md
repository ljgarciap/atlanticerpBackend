# Senior Review — SCRUM-406 RN2, puente Compras → Bodega (stock release)

**Commit revisado:** `ea8291813a3d67430e2f8896275f1009c1e61b6d` — `feat(bodega): auto-release
orders awaiting stock on PO receipt (SCRUM-406)`, rama `dev`.
**Diseño de referencia:** `docs/adr/ADR-SCRUM406-compras-bodega-stock-bridge.md` (Status: Approved).
**Alcance de esta revisión:** exclusivamente los 6 archivos de `ea82918`. El working tree tiene
cambios sin commitear de una tarea distinta (Bloque B3 "Zona Libre de Colón") que no se tocaron ni
se revisaron acá.

## Veredicto: 🟢 Aprobado — lista para Pre-QA/QA

## Archivos revisados
- `app/Modules/Compras/Events/PurchaseOrderReceived.php` (nuevo)
- `app/Modules/Bodega/Listeners/ReleaseOrdersAwaitingStock.php` (nuevo)
- `app/Modules/Compras/Http/Controllers/PurchaseOrderController.php` (`advance()`)
- `app/Providers/AppServiceProvider.php` (registro del listener)
- `app/Modules/Notifications/Models/NotificationSend.php` (`SOURCE_BODEGA_ORDER_STOCK_RELEASED`)
- `tests/Feature/Bodega/StockReleaseOnPurchaseOrderReceivedTest.php` (nuevo)

## Checklist contra el ADR

1. **Try/catch + `Log::error()` envolviendo TODO `handle()`, mismo patrón que
   `CreateOrderFromApprovedQuote`.** Confirmado — `handle()` delega a un método privado `release()`
   dentro de un único `try/catch(Throwable)`, con el mismo shape de contexto de log
   (`purchase_order_id`/`product_ids`/`tenant`/`exception`/`exception_class`) que el precedente.
   Ningún camino dentro de `release()` puede escapar sin pasar por acá.

2. **No liberación parcial con 2+ productos.** Confirmado, y bien implementada — no solo en el
   caso feliz de un producto. La lógica junta TODOS los ítems faltantes de un pedido (no solo los
   del `product_ids` del evento — deliberado, para poder evaluar cobertura de un pedido cuyo otro
   producto faltante ya tenía stock suficiente desde antes), agrupa por `catalog_product_id` en
   `$required`, y solo libera (`found_at_location = true` en todos los ítems + `order_type =
   pedido`) si **todos** los productos requeridos están cubiertos (`$canCoverAll`). El test
   `test_pedido_con_2_productos_faltantes_no_se_libera_si_solo_llega_uno` ejercita exactamente este
   caso end-to-end y pasa.

3. **FIFO por `updated_at` en vez de `status_changed_at`.** Confirmado que `Order` no tiene esa
   columna — ni en el modelo (`app/Modules/Bodega/Models/Order.php`, solo `@property
   \Carbon\Carbon $updated_at` documentado) ni razón para asumir lo contrario. El criterio es
   razonable dado el ADR: nada más toca la fila de un pedido mientras permanece
   `faltante`+`asignado`, así que `updated_at` sí actúa como proxy fiel de "desde cuándo espera".
   El test de competencia FIFO (`test_stock_insuficiente_para_2_pedidos_libera_solo_el_mas_antiguo_fifo`)
   fuerza `updated_at` del pedido más antiguo 30 minutos atrás vía `DB::table(...)->update()` y
   confirma que gana ese, no el más nuevo.

4. **Evento disparado en el lugar correcto.** Confirmado en
   `PurchaseOrderController::advance()`: se dispara dentro del `if ($next ===
   PurchaseOrder::STATUS_RECIBIDO)`, inmediatamente después de que
   `$this->goodsReceiptConfirmation->confirmPendingForOrder($order, $user->id)` retorna sin
   excepción — que es síncrono y transaccional en sí mismo (`GoodsReceiptConfirmationService`
   envuelve el loop de confirmación en su propio `DB::transaction`, ya commiteado antes de que
   `advance()` continúe). Para cuando el listener consulta `catalog_products.stock_quantity`
   dentro de su propia transacción, el incremento de esta PO ya está persistido — no hay ventana de
   lectura stale. `product_ids` se arma desde `order->lines()` (líneas de ESTA orden), no desde los
   ingresos confirmados — decisión correcta y ya comentada en el código, porque un producto puede
   tener stock suficiente por otra vía sin que esta PO puntual lo haya traído.

5. **Tests — los 4 escenarios que pide el ADR, no solo happy path.** Los 4 métodos de test cubren
   exactamente lo exigido en la sección "Consecuencias": cobertura total (1 producto), cobertura
   parcial con 2 pedidos compitiendo por el mismo stock (FIFO), pedido con 2 productos donde solo
   uno llega (no liberación parcial), y un pedido normal (no "faltante") que nunca debe tocarse —
   este último también valida que no se dispare ninguna notificación
   (`SOURCE_BODEGA_ORDER_STOCK_RELEASED`) para un pedido no afectado. Los tests corren el flujo HTTP
   real (`PATCH /api/compras/orders/{id}/advance` dos veces + `POST /api/compras/goods-receipts`
   en medio) sin `Event::fake()`, ejercitando el listener real registrado en
   `AppServiceProvider` — mismo criterio que el test de referencia
   `PurchaseOrderLifecycleTest`. Corridos localmente vía `infra/test.sh --filter=StockReleaseOnPurchaseOrderReceivedTest`:

   ```
   PHPUnit 11.5.55 by Sebastian Bergmann and contributors.
   ....                                                                4 / 4 (100%)
   OK (4 tests, 34 assertions)
   ```

6. **PHPStan Level 8.** Limpio en los 5 archivos PHP tocados
   (`vendor/bin/phpstan analyse app/Modules/Bodega/Listeners/ReleaseOrdersAwaitingStock.php
   app/Modules/Compras/Events/PurchaseOrderReceived.php
   app/Modules/Compras/Http/Controllers/PurchaseOrderController.php
   app/Providers/AppServiceProvider.php
   app/Modules/Notifications/Models/NotificationSend.php`) → `[OK] No errores`.

7. **Documentación.** `atlanticerp/CLAUDE.md` sección "Eventos de dominio" ya incluye
   `compras.purchase-order.received → Compras\Events\PurchaseOrderReceived` con la referencia al
   ADR y al listener — no quedó pendiente.

## Otros puntos verificados
- Constantes usadas por el listener (`Order::ORDER_TYPE_FALTANTE`, `Order::ORDER_TYPE_PEDIDO`,
  `Order::STAGE_ASIGNADO`, `OrderItem::SHORTAGE_SIN_STOCK`, `OrderItem::pendingQty()`) existen tal
  cual se referencian, sin typos.
- `NotificationService::send()` — la firma (`audience`, `content`, `channels`, `source`,
  `sourceRef`) coincide exactamente con la llamada en `notifyReleased()`.
- `NotificationSend::SOURCE_*` no tiene constraint de base de datos (columna `source` es texto
  libre, mismo patrón que el resto de constantes ya agregadas ahí) — no hace falta migración para
  el valor nuevo.
- Locking: el listener toma `lockForUpdate()` sobre `Order` (ordenado por `updated_at`) y luego
  sobre `CatalogProduct`, todo dentro de un único `DB::transaction`. El orden de adquisición de
  locks es consistente entre invocaciones concurrentes del mismo listener (misma query, mismo
  `orderBy`), así que no veo riesgo de deadlock introducido por este cambio específico.

## 🟡 Sugerencias (no bloqueantes)
- **Ausencia de reserva de stock para pedidos "faltante"** — ya documentada explícitamente como
  limitación conocida y fuera de alcance en el ADR (sección "Limitación conocida"), no es un gap
  nuevo de esta implementación. La dejo anotada acá solo para que quede visible en el historial de
  reviews, no como pendiente de este ticket.
- El docblock de la clase describe `StockReservationService::reserveForOrder()` como el mecanismo
  del flujo normal — vale la pena que quede en el backlog de Bodega como candidato a extender a
  pedidos "faltante" el día que haya presión real de dos pedidos compitiendo seguido (no urgente
  hoy, cero pasos de acción de esta review).

## Notas de proceso
- No se transicionó nada en Jira — corresponde a Pre-QA/PM según el flujo del proyecto.
- Verificación hecha exclusivamente contra `ea82918` vía `git show`; el working tree con los
  cambios de Zona Libre (Bloque B3) permanece intacto, sin revisar ni mencionar más allá de esta
  nota de contexto.
