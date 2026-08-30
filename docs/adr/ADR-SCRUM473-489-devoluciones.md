# ADR — Bloque B6: Devoluciones (SCRUM-473→489, REQ-403→419)

**Status:** Approved (Luis, 2026-07-26)
**Fecha:** 2026-07-26

## Contexto

17 tickets para el ciclo completo de devolución de cliente: bandeja, creación (obligatoria a
partir de una Guía de Entrega real de un Pedido ya despachado), carga de documento firmado,
confirmación de recepción física (que suma al inventario y "notifica" a Administración &
Contabilidad — módulo que no existe todavía, mismo criterio de stub que `Order.invoice_ready`),
rechazo (potestad exclusiva de Bodega, sin aprobación de Mark — a diferencia de todos los demás
flujos de rechazo del módulo), y finalización simulada (nota de crédito).

Depende de Batch A3 (Guía de Entrega de Pedidos), ya completo — `OrderDocumentService` ya genera
ese PDF (`generateGuiaEntrega()`, vista `bodega.guia-entrega-pdf`) y REQ-407 pide reutilizar
exactamente ese documento, no reconstruirlo.

## Decisión — modelo de datos

Nuevo submódulo dentro de `App\Modules\Bodega` (mismo módulo que `Order`, no uno nuevo — Bodega es
quien controla todo el ciclo salvo el paso de nota de crédito, que es un stub).

```
customer_returns (schema {tenant}_bodega)
  id, return_number (folio DEV-AAAA-NNNN, ReturnFolioService — mismo patrón lockForUpdate que
    OrderFolioService/ZonaLibreFolioService)
  order_id (FK orders, NOT NULL) — la guía de entrega origen (REQ-414)
  status: pendiente | esperando_nota_credito | finalizado | rechazada
    (el sub-paso "sin firma" vs "con firma, lista para confirmar recepción" — ambos "Pendiente" en
    la UI, REQ-413 — se deriva de signed_document_path IS NULL, no es un status aparte; mismo
    criterio que Order.invoice_ready siendo un flag independiente del stage)
  contact_name, contact_phone (REQ-416, quien trae físicamente el producto)
  signed_document_path, signed_document_uploaded_at (nullable, REQ-408)
  received_by (FK users, nullable), received_at (nullable), destination_warehouse_id (FK
    bodega.warehouses, nullable) — REQ-409
  credit_note_notified_at (nullable) — sellado automático al confirmar recepción (REQ-410 RN1),
    simula el aviso a Administración, no hay canal real todavía
  finalized_at (nullable) — REQ-412
  rejected_by (FK users, nullable), rejected_at, rejection_reason (nullable) — REQ-411
  created_by (FK users), created_at, updated_at

customer_return_lines (schema {tenant}_bodega)
  id, customer_return_id (FK)
  order_item_id (FK order_items, NOT NULL) — ata cada línea a lo realmente entregado (REQ-417 RN2:
    tope = order_item.qty_delivered)
  reference_snapshot, description_snapshot (copiado de catalog_product al crear, mismo criterio
    que OrderItem)
  qty_requested (int) — cantidad que se dice devolver
  qty_received (int, nullable) — cantidad real confirmada en recepción física (REQ-409 RN1: puede
    diferir de qty_requested)
  reason (string) — catálogo fijo: danado_defectuoso | no_corresponde | cliente_cambio_opinion |
    excedente | error_instalacion | otra (con texto libre embebido "otra:<texto>", mismo patrón
    que OrderItem::setShortageReason())
```

**Por qué no una tabla de historial aparte (`ReturnStageHistory` al estilo `OrderStageHistory`):**
REQ-405 pide un historial cronológico, pero los pasos son fijos y cada uno tiene como mucho una
ocurrencia (nunca se repite "cargar documento firmado" dos veces) — los timestamps ya en la fila
(`created_at`, `signed_document_uploaded_at`, `received_at`, `credit_note_notified_at`,
`finalized_at`/`rejected_at`) reconstruyen el historial completo sin una tabla append-only. Si en
el futuro se necesita reabrir/reintentar un paso, se reconsidera.

## Decisión — alcance del buscador de guía de entrega (confirmado por Luis 2026-07-26)

El buscador de REQ-414 incluye `Order` en stage `por_despachar`, `despachado` o `entregado`
(`Order::STAGE_POR_DESPACHAR`, `STAGE_DESPACHADO`, `STAGE_ENTREGADO`) — no solo los ya despachados
físicamente, también los que ya tienen guía generada aunque todavía no salieron de Bodega.

## Servicios

- `ReturnFolioService::next()` — folio atómico, mismo patrón que `OrderFolioService`.
- `CustomerReturnService`:
  - `create()` (REQ-419): valida ≥1 producto, contacto completo, `qty_requested <=
    order_item.qty_delivered` por línea → crea en `pendiente`.
  - `uploadSignedDocument()` (REQ-408): sube a S3 (mismo `CircuitBreaker`+disk que
    `OrderDocumentService`), setea `signed_document_path`/`_uploaded_at`.
  - `confirmReception()` (REQ-409/410, transacción): setea `qty_received` por línea,
    `destination_warehouse_id`, `received_at`; **incrementa** `ProductWarehouseStock.quantity` por
    línea (producto resuelto vía `order_item.catalog_product_id`); setea
    `credit_note_notified_at = now()`; `status = esperando_nota_credito`.
  - `reject()` (REQ-411): solo permitido antes de `confirmReception()` (mismo punto del flujo,
    "dentro de Confirmar recepción física" — botón de rechazo vive en ese mismo modal); doble
    confirmación es responsabilidad del frontend (RN2), backend solo exige `rejection_reason`
    no vacío; NO toca inventario, NO setea `credit_note_notified_at`.
  - `finalize()` (REQ-412): solo desde `esperando_nota_credito` → `finalizado`. Gateado por el
    permiso estándar de escritura de Bodega (`bodega.write`) — no existe todavía un rol real de
    Administración & Contabilidad, mismo criterio de stub que `Order.invoice_ready` (aprobado por
    Luis en su momento).
- `CustomerReturnDocumentService` (nuevo, hermano de `OrderDocumentService`):
  - `generateFormulario()` (REQ-406): PDF nuevo, vista `bodega.devolucion-formulario-pdf`, mismo
    estilo institucional que guía de entrega/factura; si `signed_document_path` no es null, el
    espacio de firma muestra el nombre de `contact_name` en cursiva.
  - Para REQ-407 ("ver guía de entrega original") **no se genera nada nuevo** — se reutiliza
    `OrderDocumentService::generateGuiaEntrega($return->order, $actor)` tal cual.

## Endpoints (borrador)

```
GET    /bodega/returns                          bandeja + filtros (chip=status)
GET    /bodega/returns/search-orders?q=          REQ-414 (busca Order despachado/entregado)
POST   /bodega/returns                           REQ-419
GET    /bodega/returns/{id}                      detalle (REQ-475/405)
POST   /bodega/returns/{id}/signed-document       REQ-408 (multipart)
GET    /bodega/returns/{id}/form                  REQ-406 (PDF)
GET    /bodega/returns/{id}/delivery-guide        REQ-407 (reusa PDF de Order)
POST   /bodega/returns/{id}/confirm-reception     REQ-409/410
POST   /bodega/returns/{id}/reject                REQ-411
POST   /bodega/returns/{id}/finalize              REQ-412
```

Permiso en todas: `bodega.read`/`bodega.write` estándar (todas las tickets dicen "Visible para
todos los perfiles de Bodega" — sin permiso angosto nuevo, a diferencia de Zona Libre que sí
necesitó uno para el aprobador de Compras).

## Evento de dominio

`bodega.return.received` (`Bodega\Events\ReturnReceived`) al confirmar recepción — deja la puerta
abierta a un listener real de Administración & Contabilidad el día que ese módulo exista, mismo
criterio que el resto del proyecto (interfaz lista, Kafka/notificación real diferida).
