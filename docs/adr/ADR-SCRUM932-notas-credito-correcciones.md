# ADR — SCRUM-932: Notas Crédito y Devoluciones — correcciones y mejoras UAT

Arquitecto, 2026-09-20. Ticket: `SCRUM-932` (2 puntos, alcance explícito: solo el modal de creación
y el detalle de Notas Crédito y Devoluciones). Mismo grupo que SCRUM-929/930/931/933. Mismo patrón
general que SCRUM-931 (Cobros) — filtro cruzado Proyecto↔Cotización en la creación, documentos
clickeables en el detalle — pero con una diferencia real de fondo confirmada por Explore:
**este módulo NO reusa nada de lo ya construido en SCRUM-931**, tiene su propio endpoint/query
independiente para la lista de facturas. No colapsar ambos en uno solo — son queries con reglas de
negocio distintas (Cobros excluye facturas ya saldadas; Notas de Crédito las necesita, REQ-484
Escenario 2: una factura pagada sigue siendo `factura_origen_id` válido para el excedente).

## Punto 1 — Modal de creación: Proyecto + # Cotización, filtrado cruzado

`CreditNoteService::invoicesForClient()` (`CreditNoteService.php:149-177`) es una query propia,
sin relación con `PaymentService::openInvoicesForClient()` — ya devuelve `order_id` (SCRUM-929)
pero no `sales_project_id`/`proyecto`/`quote_id`/`cotizacion_folio`.

**Decisión: mismo patrón que SCRUM-931, aplicado a este método por separado.** Se extiende
`invoicesForClient()` con los mismos 4 campos, resueltos igual (batch de `SalesProject` por
`sales_project_id`, batch de `Order` por `order_id`, `$order?->linkedQuote()` para la cotización —
**no** `AdminContInvoice.quote_id` directo, para seguir el criterio ya establecido en Cobros/Estado
de Cuenta de resolver la cotización vía el pedido, no vía el campo desnormalizado de la factura).
El filtrado cruzado, igual que en Cobros, se resuelve **enteramente en el frontend** sobre esta
lista ya cargada — sin request nueva, sin tocar `RegisterCreditNoteRequest`/`store()` (que solo
recibe `factura_origen_id`, sin cambios).

**Diferencia real de UI con Cobros:** acá la factura de origen es un `<select>` simple (una sola
factura por nota), no checkboxes múltiples — la combinación Proyecto+Cotización acota las
`<option>` del mismo select existente, no requiere una lista nueva. El modo "devolución precargada"
(`esDevolucion`, la factura ya viene resuelta desde afuera) no pasa por este selector — el filtro
nuevo aplica solo al modo manual, mismo bloque que ya lo separa hoy.

## Punto 2 — Detalle: Cotización y Guía de Entrega antes de "Factura de origen"

`CreditNoteService::show()` (`CreditNoteService.php:589-659`) ya expone `factura_origen_id`/
`factura_origen_numero`/`factura_origen_order_id` — el campo "Factura de origen" existente, que el
ticket pide dejar intacto (sigue abriendo el PDF directo vía `useDownloadInvoicePdf`, no
`InvoiceDetailModal` — patrón distinto al de Cobros, no se toca).

**Decisión: mismo patrón exacto que `AdminContPaymentController::presentDetail()` (SCRUM-931),
adaptado a UNA sola factura en vez de una colección** — la nota de crédito tiene un único
`factura_origen_id`, no una lista. Se agrega `quote_id`/`cotizacion_folio`/`order_id`/
`guia_entrega_numero`/`guia_entrega_order_id` a la respuesta de `show()`, resolviendo `$order =
Order::find($invoice->order_id)`, `$quote = $order?->linkedQuote()`, y
`GuiaEntregaResolver::resolve($invoice, $order, $ordersById)` — el resolver ya es genérico (extraído
en SCRUM-930, reusado en SCRUM-931), no necesita ningún cambio, solo una colección de orders de un
solo elemento para el parámetro `$ordersById` (mismo fallback interno a `Order::find()` si hiciera
falta, ya cubierto).

Caso sin factura resoluble (factura sin `order_id`, o pedido sin proyecto vinculado): `linkedQuote()`
devuelve `null`, los campos quedan `null`, la UI no muestra el link — mismo comportamiento ya
tolerado en Cobros/Estado de Cuenta, no es un caso nuevo a cubrir.

## Frontend

- `RegistrarNotaCreditoModal.tsx`: replicar el bloque de filtro cruzado ya implementado en
  `RegistrarCobroModal.tsx` (SCRUM-931) — mismos 2 `useState`/`useMemo` de opciones/cross-filter,
  adaptado a filtrar las `<option>` del `<select>` de factura existente en vez de una lista de
  checkboxes. Mismo criterio del fix de Senior Review de SCRUM-931 (si la factura seleccionada deja
  de estar entre las visibles por el filtro, la selección se limpia — acá es más simple al ser un
  único `<select>`, cambiar las opciones ya lo resuelve nativamente sin estado adicional que podar).
- `DetalleNotaCreditoModal.tsx`: **primera vez que este componente abre modales hijos** — agregar
  `QuoteViewerModal`/`GuiaEntregaModal` (mismo patrón `DocLink` que ya usa `DetalleCobroModal.tsx`,
  pero como 2 campos individuales dentro del grid existente, no una lista/`DetalleSection`, porque
  acá es una sola cotización/guía, no varias) insertados inmediatamente antes de "Factura de
  origen". El resto del grid (Monto, Subtotal, ITBMS, Estado, Registrado por, etc.) no cambia.

## Resumen de cambios

| Capa | Cambio |
|---|---|
| Backend | `CreditNoteService::invoicesForClient()`: agregar `sales_project_id`/`proyecto`/`quote_id`/`cotizacion_folio` por fila (query independiente de Cobros) |
| Backend | `CreditNoteService::show()`: agregar `quote_id`/`cotizacion_folio`/`order_id`/`guia_entrega_numero`/`guia_entrega_order_id`, reusando `GuiaEntregaResolver::resolve()` tal cual |
| Frontend | `RegistrarNotaCreditoModal.tsx`: selects Proyecto/# Cotización + cross-filter local, acotando el `<select>` de factura existente |
| Frontend | `DetalleNotaCreditoModal.tsx`: Cotización + Guía de Entrega clickeables, antes de "Factura de origen" (que no cambia) |
| — | Sin migraciones, sin componentes de detalle nuevos (`QuoteViewerModal`/`GuiaEntregaModal` ya existen y son reusables) |
| — | Sin tocar `PaymentService`/`RegistrarCobroModal`/`DetalleCobroModal` de SCRUM-931 — son queries y componentes independientes, esto es trabajo nuevo por separado, no una extensión de lo ya hecho |
