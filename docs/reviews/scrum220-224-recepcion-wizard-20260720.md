# Senior Review + Pre-QA — REQ-145/157/158/159/160 + wizard de Ingreso de Mercancía

**Fecha:** 2026-07-19/20
**Alcance:** cierre de REQ-157-161 (SCRUM-220-224) más el hallazgo real descubierto en el camino —
el wizard de Ingreso de Mercancía (REQ-162-167, SCRUM-225-230) tenía backend completo desde el
19-jul pero **cero frontend**, así que esos 6 tickets no eran testeables pese a estar en Dev
Testing. Se construyó el wizard completo en el mismo batch.

## Qué se construyó

### Backend
1. **`PurchaseOrder::receptionSummary()`/`receivedQuantitiesByProduct()`/`receivedQuantitiesForOrders()`**
   (REQ-145) — única fuente de verdad del cálculo "Recepción" (Pendiente/Recibido parcial/Recibido
   completo), por línea y consolidada. Cuenta movimientos de `PurchaseOrderReceiptLine` sin
   importar `confirmed_at` (a diferencia de Inventario, donde sí importa) — coincide con la RN
   explícita del ticket. Batch-friendly (`receivedQuantitiesForOrders()`) para no generar N+1 en
   listados; `PurchaseOrderController::index()` la usa así.
2. **`PurchaseOrder::showsGoodsReceiptLink()`** (REQ-157) — visible desde "En aduana" (secuencia
   internacional) o "En tránsito local" (proveedor local, que salta aduana); un
   `pending_remainder_status` (entrega parcial) manda sobre el `status` real mientras esté en una
   etapa anterior.
3. **`PurchaseOrderController::formatSummary()`/`formatDetail()`** — expone `reception_status`,
   `shows_goods_receipt_link`, `pending_remainder_status` (orden) y `received_quantity`/
   `reception_status` (por línea). Un solo método consumido por Ver Órdenes Y Logística (ambas
   pantallas reusan `index()`/`formatSummary()`, no hay controller separado) — satisface REQ-160
   por diseño, no hizo falta ningún mecanismo de sincronización nuevo.
4. **`GoodsReceiptController::index()`** (REQ-159) — le faltaba buscar por producto (RN: "buscable
   por proveedor, orden o producto"), solo buscaba por orden/proveedor. Corregido.
5. Consolidación de la fórmula de margen (`ReplacementRequestController`) ya cerrada en el batch
   anterior (SCRUM-237) — no se tocó de nuevo acá.

### Frontend (no existía nada de esto antes de este batch)
1. **`GoodsReceiptWizardPage.tsx`** — wizard de 2 pasos (orden vinculada → líneas + costeo),
   mismo patrón "gates condicionales" que `NewPurchaseOrderPage.tsx`. Cubre REQ-162 (buscador
   acotado a órdenes no Recibidas, precarga de productos esperados con cantidad completa),
   REQ-163 (agregar/quitar líneas, buscador de "agregar de vuelta" acotado a lo esperado),
   REQ-164 (desglose de costo condicionado al origen del proveedor: nota de ITBMS para local, 4
   inputs de totales para no-local), REQ-165 (modal de "estado de lo pendiente" cuando el backend
   devuelve `requires_pending_status`), REQ-166 (pantalla de éxito con los 3 accesos del ticket) y
   REQ-167 (mismo componente en modo corrección vía `:id/editar`, bloqueado si `editable: false`).
2. **`GoodsReceiptsPage.tsx`** (REQ-159) — listado + detalle + "Corregir este ingreso".
3. **`ReceptionBadge`** (componente compartido) — mismo badge/texto en Ver Órdenes, detalle de
   orden y Logística, para no arriesgar 2 estilos distintos del mismo dato (mismo espíritu que la
   nota técnica del ticket sobre "una sola implementación").
4. Rutas nuevas (`/compras/ingresos`, `/compras/ingresos/nuevo`, `/compras/ingresos/:id/editar`) +
   ítem de Sidebar — mismo patrón de permisos que el resto de Compras (`compras.read`/`compras.edit`,
   confirmado contra las rutas reales del backend, no asumido).

## Alcance NO cubierto en este batch (decisión explícita, no descuido)

- **REQ-161** (adjuntar factura con detección automática por IA) — el propio Excel de
  requerimientos lo marca "CRÍTICO PARA TECNOLOGÍA": necesita la misma capacidad de OCR/IA que
  SCRUM-211 ya construyó para confirmación de proveedor, no se debe construir 2 veces por
  separado. Queda pendiente de diseño de AI Architect + revisión de Cybersecurity antes de
  implementar el Job — SCRUM-224 se queda en `In Progress`, no se fuerza a Dev Testing.
- El mecanismo de adjuntar-manual (sin IA) tampoco se agregó: no existe ningún endpoint de subida
  de archivo para `goods-receipts` hoy (`invoice_storage_key`/`invoice_original_filename` son
  campos de texto plano sin mecanismo de carga real) — construirlo a medias sin la detección real
  sería trabajo descartable una vez que se diseñe la capacidad de IA real.

## Verificación

- Backend: 989/989 (`infra/test.sh`), PHPStan Level 8 limpio. 9 tests nuevos en
  `PurchaseOrderLogisticsTest` (Recepción por línea/consolidada, casos pendiente/parcial/completo,
  líneas "Por ingresar" sin confirmar cuentan igual, enlace visible/oculto en aduana/tránsito
  local/proveedor local/remanente pendiente) + 3 en `GoodsReceiptControllerTest` (búsqueda por
  producto, `editable` según estado, permiso negativo).
- Frontend: 379/379 (`npx vitest run`), `tsc --noEmit` limpio. 9 tests nuevos: wizard completo
  (precarga de productos, desglose no-local enviado al guardar, nota ITBMS para local, modal de
  entrega parcial con reenvío del remainder status, modo corrección con precarga y bloqueo si no
  editable) + 3 en `GoodsReceiptsPage` (listado, corregir, bloqueado) + 3 en `LogisticsPage`
  (badge de Recepción, enlace condicional, navegación con la orden preseleccionada).
- Verificado además con `tinker` contra Docker local (costTotal/marginPercent, sync de familia —
  batch anterior) y contra la suite automatizada de este batch, que ejercita cada escenario de
  aceptación de REQ-145/157/159/162-167 con datos reales de Postgres (no solo mocks del frontend).

Listo para pasar a QA en cuanto se pushee — con la salvedad de SCRUM-224 (REQ-161), que sigue
bloqueada por el diseño de IA pendiente.
