# ADR — Batch C: Una factura por pedido / split de entregas parciales (SCRUM-875 + SCRUM-876)

Arquitecto, 2026-09-09. Corrección reportada por Gerencia Test. Spec:
`docs/specs/scrum-875-880-correcciones-admincont-analista-spec.md`. Decisiones de negocio
(C-1…C-8) cerradas con Luis en la misma fecha.

## Problema

Cuando un pedido se divide en entregas parciales, cada tarjeta de Bodega que llega a "Por
Despachar" genera su propia línea "pendiente-facturar" y su propia factura — cada una **por el
monto total del pedido** (`pipelineCard.amount`). Un pedido de 3 entregas → saldo de cliente 3× el
real, 3 líneas de comisión, y solo la tarjeta facturada muestra la factura. Además, hoy el split
por N fechas parciales desde Ventas **ni siquiera ocurre** (SCRUM-875): `OrderService` toma solo
la fecha más próxima.

## Decisión

### 1. Identidad de "pedido" = tarjeta raíz de la familia

El índice único parcial `orders_pipeline_card_root_uq ON (pipeline_card_id) WHERE family_id IS
NULL` ya garantiza **1 raíz por `pipeline_card`**. La factura, las comisiones y el estado de cuenta
operan sobre `Order::familyRootId()` (`$this->family_id ?? $this->id`).

### 2. La factura siempre apunta a la raíz (no columna nueva)

`AdminContInvoice.order_id` pasa a contener siempre el id de la **tarjeta raíz** del pedido, no de
la entrega que gatilló la facturación. El índice único parcial existente
(`invoices_order_id_active_unique ON (order_id) WHERE anulada_at IS NULL`) **queda igual** y ahora
significa "1 factura activa por pedido". **Sin migración de schema.**

Descartado: columna `invoices.order_number` (Opción B) — migración + índice nuevo + backfill de
facturas existentes + más call sites, sin beneficio real frente a resolver la raíz en un helper.

Descartado: llave por `sales_project_id` / `pipeline_card_id` (Opción C) — `sales_project_id` puede
ser null, y no quiero acoplar la identidad de "pedido" al índice único de `orders`.

### 3. Helper `AdminContInvoice::forOrder(int|Order $order): Builder`

Resuelve cualquier `order_id` a su raíz antes de consultar. **Todos** los
`AdminContInvoice::where('order_id', ...)` del código pasan por acá. Call sites a migrar (revisar
en Senior Review):
- `InvoiceService`: `confirm()` (guard), `detail()`, `proposeUncollectible()`, `decideUncollectible()`
- `CreditNoteService`: `invoiceForOrder()` (línea ~443)
- `Bodega\OrderInvoiceController`: `show()`, `pdf()`
- `InvoiceBookService::resolveRuc()` (vía `factura_origen?->order_id`)
- Cualquier otro que aparezca en `grep -rn "where('order_id'" app/Modules` sobre `AdminContInvoice`

### 4. Facturación agrupa por familia

`InvoiceService::list()` / `pendingForProject()` / `summary()`:
- Agrupan los `Order` de `FACTURABLE_STAGES` por `familyRootId()`. **Una fila por pedido**,
  representada por la raíz.
- Una familia entra a "pendiente-facturar" cuando **cualquier** miembro está en `POR_DESPACHAR` y
  no hay factura activa en la raíz (RN: "se factura con la 1ª entrega").
- El payload de fila incluye `entregas_del_pedido: [{sequence_in_family, stage, order_id}]`.
- `summary().pendientes_facturar` cuenta familias.

`confirm()`:
- Re-resuelve `$locked` a la raíz (`Order::where('id', $order->familyRootId())->lockForUpdate()`).
- Guard: `AdminContInvoice::forOrder($root)->whereNull('anulada_at')->exists()`.
- `order_id` de la factura = `$root->id`. `monto` = `card->amount` (total del pedido, decisión C-7).
- Dispara `InvoiceCreated(orderId = $root->id)`.

### 5. `Order.invoice_ready` deja de usarse (decisión C-3)

`OrderInvoiceController::show()` calcula el estado en vivo:
`$invoice = AdminContInvoice::forOrder($id)->whereNull('anulada_at')->first(); $invoice_ready = $invoice !== null;`.
Todas las tarjetas de la familia devuelven el mismo `invoice_numero`.

`MarkOrderInvoiceReady` (listener de `InvoiceCreated`) queda como **no-op** — se deja el archivo
con un docblock explicando por qué (no se elimina para no tocar el registro en
`AppServiceProvider::boot()` en este batch; limpieza opcional en un batch posterior). La columna
`orders.invoice_ready` no se dropea (migración de limpieza fuera de alcance) — simplemente deja de
leerse/escribirse.

### 6. Comisiones externas — `ArchitectCommissionService::allProjects()`

El join deja de fan-out por familia:
```sql
LEFT JOIN orders o     ON o.pipeline_card_id = pc.id AND o.family_id IS NULL
LEFT JOIN invoices inv ON inv.order_id = o.id        AND inv.anulada_at IS NULL
```
→ 1 fila de comisión por pedido. **Corrige de paso la comisión externa duplicada de SCRUM-879.**
El `AND inv.anulada_at IS NULL` además tapa un fan-out latente pre-existente (Corrección de datos
deja 2 filas por `order_id`).

Verificar (Backend): `CommissionOrderService` (comisiones internas) — si joina `orders`/`invoices`
por fila, aplicar el mismo filtro.

### 7. SCRUM-875 — split de familia en `OrderService::createFromApprovedQuote()`

Cuando el pedido tiene modo "parcial" con **N ≥ 2** fechas en `deliveryDates`:
- Crear N `Order` dentro de la misma transacción, mismo `order_number` (folio del pedido),
  ordenados por fecha ascendente.
- Tarjeta 1 = raíz (`family_id = null`); tarjetas 2..N con `family_id => $root->familyRootId()`
  **en el `create()`**, nunca en un `update()` posterior (lo rechaza el índice único parcial — ver
  docblock de `FamilyService::attach()`).
- `committed_delivery_date` de cada tarjeta = su fecha parcial.
- Cada tarjeta nace con **todos los ítems** de la cotización (`createItemsFromQuote()` por
  tarjeta), `qty_requested` = cantidad completa (decisión C-2 — el reparto real lo hace el picker).
- `FamilyService::attach()` para resecuenciar; `OrderSplit` + `OrderCreated` por tarjeta (mismo
  patrón que `PickingService::createInventoryReviewCard()`).
- Modo "única", o "parcial" con 1 sola fecha → 1 tarjeta, comportamiento actual sin cambios.

**Open (Backend/Arquitecto, resolver en implementación):** dónde vive el flag "parcial vs única"
(¿columna en `pipeline_cards`? ¿`deliveryDates->count() > 1`?). Si no hay flag explícito y hoy
"parcial" se infiere de tener >1 fecha, el split se dispara con `deliveryDates->count() > 1` sin
más.

## Reconciliaciones

- **Agregados que hoy doble-cuentan pedidos partidos** (`agingBuckets`, `carteraPorCobrar`,
  `cuentasAlDia`, `InvoiceBookService`, `ClientCollectionReportService`,
  `PaymentMethodSalesReportService`, `GerenciaHomeService`, `CashPositionService`): **no requieren
  cambio** — con 1 factura por pedido, el doble conteo desaparece solo. Es una corrección
  colateral, no un riesgo. Sus fixtures de test (pedidos no partidos) siguen verdes.
- **`blockingReasonsForDispatch()`** ya no exige factura (SCRUM-860) — el despacho no está
  bloqueado por esto, un miembro de familia no espera "su" factura.
- **"Status de Pedidos"** (`OrderStatusService`) ya opera a nivel de raíz — sin cambios.
- **Índice único de datos:** el invariante "1 factura activa por familia" lo garantiza el código
  (factura siempre en la raíz), no la BD — mismo criterio que el índice parcial ya existente.

## Datos existentes (decisión C-8)

Sin migración de limpieza. Las facturas duplicadas de dev/test quedan (una en la raíz, otra
huérfana en una hermana). Los saldos inflados de esos clientes de prueba **no** se recalculan — QA
valida con pedidos **nuevos**. Las comisiones externas dobles ya pagadas (Arquitecto 12) quedan en
BD sin movimiento nuevo y salen de la vista al corregir el join (decisión C-4).

## Consecuencias

- **Positivo:** corrige el saldo de cliente, la comisión duplicada y el doble conteo en ~8
  reportes, todo con un cambio de llave + un helper.
- **Riesgo:** un call site de `where('order_id')` sin migrar → factura invisible o guard que no
  dispara. Mitigación: helper central + revisión exhaustiva en Senior Review (lista en §3).
- **Test:** suite backend completa + vitest + spec Playwright permanente nuevo (pedido partido →
  1 factura → todas las tarjetas la muestran → 1 línea de comisión), autosembrado.

## Estado
Implemented: **no** — pendiente de PM breakdown e implementación.
