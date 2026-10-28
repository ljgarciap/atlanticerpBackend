# ADR: Bloque B3 — Zona Libre de Colón (SCRUM-433→445, REQ-363→375)

**Status:** Approved — aprobado por Luis 2026-07-24 (backend ya implementado y pusheado, commit `e2e1b83`)
**Fecha:** 2026-07-24
**Autor:** Arquitecto

## Contexto

Bodega necesita generar órdenes de compra directamente a un único proveedor fijo ("Zona Libre de
Colón"), que luego Compras (Lidercompras) aprueba o rechaza. Al aprobarse, la orden debe seguir
exactamente el mismo flujo que cualquier otra orden de Compras (RN1 de REQ-370) — pago, logística,
ingreso. 13 tickets: formulario de creación (3C), bandeja de seguimiento (3D).

Investigación de código (confirmada archivo por archivo, no asumida):

- `PurchaseOrder` (`app/Modules/Compras/Models/PurchaseOrder.php`) es un pipeline lineal de 7
  estados (`por_aprobar` → ... → `recibido`), sin estado `rechazada` y sin gate de
  aprobación genérico — `store()` ya crea todo en `por_aprobar` sin pedirle permiso a nadie.
  El único "approve" que existe (`PurchaseOrderController::approve()`) es específico de la
  aprobación de Aprobador para flete aéreo (`requires_mark_approval`) — **no es reusable** para
  "Lidercompras aprueba una orden de Zona Libre" sin acoplar semánticas que no tienen relación.
- **Precedente directo ya construido**: `ReplacementRequest` + `ReplacementRequestController`
  (Sustitutos con IA, Oleada 2). Modelo satélite con su propio `status`
  (`pendiente`/`pendiente_gerencia`/`aprobada`/`rechazada`), `requested_by`, `approved_by`,
  `generated_order_id` (FK nullable a `PurchaseOrder`). Su `generateOrder()` crea un
  `PurchaseOrder::create([...'status' => STATUS_POR_APROBAR...])` **completamente normal**, que
  de ahí en más es indistinguible de cualquier otra orden — exactamente el comportamiento que
  pide RN1 de REQ-370. Este bloque copia esa forma, no inventa una nueva.
- `Provider` (`app/Modules/Compras/Models/Provider.php`) — **no existe ningún proveedor "Zona
  Libre de Colón" sembrado** (confirmado, `grep` sin resultados). Hay que crearlo.
- `CatalogProduct` (`app/Modules/VentasDiseno/Models/CatalogProduct.php:48,69,91`) **ya tiene
  `provider_id`** (`factory_reference` es único dentro de `provider_id`) — REQ-434 ("productos del
  proveedor") es un filtro directo sobre una columna que ya existe, sin necesidad de nada nuevo.
- Frontend: `OrderLinesEditor` (`atlanticerp-frontend/src/components/compras/OrderLinesEditor.tsx`) ya es
  un componente reusable (props `providerId`/`draft`/`onChange`) — el carrito de líneas (REQ-366)
  lo reusa tal cual. El selector de proveedor de `NewPurchaseOrderPage.tsx` NO se reusa (acá el
  proveedor viene fijo, sin selector — RN de REQ-363).
- Permisos: creación → `bodega.write` (ya existe, mismo que usa Solicitud de Ajuste). Decisión de
  Lidercompras → permiso nuevo y angosto `compras.zona-libre.approve` (no reusar `compras.approve`, que
  hoy está acoplado a la aprobación de Aprobador de flete aéreo — mezclarlos generaría confusión futura
  sobre qué significa cada uno). Vía migración de tenant, mismo patrón que
  `2026_07_20_100005_add_compras_settings_configure_permission_to_catalog.php`.

## Decisión

**Aclaración de ubicación de módulo (resuelta 2026-07-24, tras pregunta de PM):** `AdjustmentRequest`
(Bodega) y `ReplacementRequest` (Compras) son dos precedentes válidos pero apuntan a criterios
distintos — `AdjustmentRequest` se aprueba DENTRO de Bodega (`permission:bodega.approve`, confirmado
en `routes/bodega.php`), mientras que `ReplacementRequest` se resuelve con autoridad de Compras y
termina en un `PurchaseOrder` (propiedad de Compras). El criterio real para decidir el módulo no es
"quién lo crea" sino "quién tiene la autoridad de resolución + quién es dueño del artefacto final".
Acá la autoridad de aprobar/rechazar es de Lidercompras (Compras) y el artefacto final es un
`PurchaseOrder` — mismo criterio que `ReplacementRequest`. **Confirmado: `Compras\Models`, no
`Bodega\Models`.**

### 1. Modelo nuevo: `App\Modules\Compras\Models\BodegaZonaLibreRequest`

Mismo patrón exacto que `ReplacementRequest`:
```
status: pendiente | aprobada | rechazada   (STATUS_* constants)
requested_by, approved_by, rejected_by, rejection_reason
generated_order_id  (FK nullable -> purchase_orders, solo se llena al aprobar)
shipping_type (default 'terrestre'), estimated_arrival_date
order_number  (consecutivo único — ver Nota de numeración abajo)
notes
```
Líneas propias: `BodegaZonaLibreRequestLine` (catalog_product_id, quantity, unit_cost_snapshot) —
mismo criterio que `OrderItem`/`QuoteItem`: snapshot de costo al momento de armar la orden, no una
referencia en vivo a `catalog_products.cost` (que puede cambiar después).

**Nota de numeración (REQ-369 RN2):** buscar el generador de número consecutivo que ya usa
`Order::order_number` o `PurchaseOrder` (si existe una secuencia/función ya centralizada,
reusarla) antes de crear una nueva — Backend Dev confirma esto al implementar, no asumido acá.

### 2. Provider fijo, configurable — nunca hardcodeado

Nuevo registro `Provider` ("Zona Libre de Colón") vía seeder, referenciado por
`compras_settings.zona_libre_provider_id` (nullable FK, mismo patrón que
`mark_approver_user_id` en la misma tabla) — **regla dura del proyecto**: un ID de proveedor fijo
en código sería exactamente el tipo de valor de negocio que debe vivir en tabla paramétrica, no en
una constante. El formulario de Nueva Orden Zona Libre lee este valor, no un ID fijo en el
frontend ni backend.

### 3. Endpoints nuevos

**Bodega** (`routes/bodega.php`, prefijo `zona-libre`, permiso `bodega.write` para escritura,
`bodega.read` para lectura — mismo patrón que `adjustment-requests`):
```
GET  /bodega/zona-libre/requests           — bandeja (3D), soporta filtro por status
POST /bodega/zona-libre/requests           — crear (3C)
GET  /bodega/zona-libre/requests/{id}      — detalle (motivo de rechazo, etc.)
POST /bodega/zona-libre/requests/{id}/remind  — REQ-375, email real a Lidercompras
```

**Compras** (`routes/compras.php`, permiso nuevo `compras.zona-libre.approve`):
```
POST /compras/zona-libre/requests/{id}/approve  — crea el PurchaseOrder real (como generateOrder())
POST /compras/zona-libre/requests/{id}/reject   — requiere motivo, nunca automático
```

### 4. Frontend

- **Nueva Orden Zona Libre** (3C): página nueva (no extiende `NewPurchaseOrderPage.tsx`, que está
  tejido alrededor del selector de proveedor que acá no existe). Reusa `OrderLinesEditor` para el
  carrito. Busca productos vía el mismo endpoint de catálogo que ya filtra por `provider_id`
  (Backend Dev confirma cuál es el endpoint existente antes de crear uno nuevo).
- **Bandeja Zona Libre** (3D): **NO reusa `usePurchaseOrders`/`OrdersPage` como bloque completo**
  (a diferencia de lo que parecía natural a primera vista) — la bandeja debe mostrar solicitudes
  `pendiente`/`rechazada` que **nunca llegan a existir como `PurchaseOrder`** (solo las aprobadas
  generan uno). Necesita un hook nuevo (`useZonaLibreRequests`) contra el endpoint de arriba. Una
  vez aprobada, el detalle puede linkear al `PurchaseOrder` real usando los componentes ya
  existentes de Ver Órdenes, sin duplicar esa UI.
- "Ver bodegas" (REQ-437): mismo modal ya usado en SCRUM-403 (`OrderItemWarehousesModal` o
  equivalente en Inventario) — reuso directo, sin lógica nueva.

## Consecuencias

- No se toca el modelo `PurchaseOrder` ni su máquina de estados — la orden generada es
  indistinguible de cualquier otra, cumpliendo RN1/RN3 de REQ-370 literalmente.
- Nueva tabla + nuevo controller + nuevo permiso, todo siguiendo un patrón ya usado y aprobado en
  este mismo repo (`ReplacementRequest`) — no es un diseño desde cero.
- PM puede desglosar los 13 tickets en paralelo Backend/Frontend una vez Luis apruebe este ADR.
