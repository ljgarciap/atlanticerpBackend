# ADR — SCRUM-930: Estado de Cuenta — correcciones y mejoras UAT

Arquitecto, 2026-09-20. Ticket: `SCRUM-930` (6 puntos, todos dentro de la pantalla/detalle de Estado
de Cuenta — alcance explícito del ticket: no toca Facturación/Movimientos/Comisiones/Tesorería/
Reportes salvo la ubicación en el menú). Precedente reciente en el mismo módulo:
`ADR-SCRUM929-facturacion-parcial-o-completa.md`, `ADR-SCRUM877-notas-credito-estado-de-cuenta.md`.

Investigación de código previa (Explore, 2026-09-20) confirmó el estado real de cada uno de los 6
puntos — dos de ellos parten de una premisa desactualizada, el resto sí requiere trabajo real.

## Punto 1 — "Guía de Entrega" muestra "Sí" en vez de un número

`AccountStatementService::movements()` (`AccountStatementService.php:188-198`) hoy solo verifica
existencia (`->exists()`) de un `OrderDocument` tipo `guia_entrega` — **el sistema no tiene folio de
negocio para la guía**, `OrderDocument` no tiene columna de folio y ninguna migración la agrega en
ningún módulo. El ejemplo del ticket (`#PED-2026-0075-G`) es, de hecho, el `order_number` del pedido
con un sufijo `-G` — no un folio propio.

**Decisión:** no crear folio nuevo (cambio de modelo de datos innecesario para esto). Cambiar la
consulta de `->exists()` a `->first()` (o `->pluck('order_id')->first()`) sobre los mismos
candidatos (`$order->id` para `tipo=parcial`, `familyMemberIds()` para `completo`) para obtener el
`order_id` real que tiene el documento, resolver su `order_number`, y exponer
`guia_entrega_numero = "{$order->order_number}-G"` + `guia_entrega_order_id` (para el modal, punto 4).
Sin este `order_id` específico no se puede enlazar la guía correcta cuando la familia tiene más de
una tarjeta.

## Punto 2 — Número de cuenta enmascarado

**Ya resuelto** por SCRUM-857 (número completo en el bloque de pago) y SCRUM-928 (2026-09-18,
eliminó el fallback `****XXXX` — sin número completo cargado, el campo es `null`/"—", nunca
asteriscos). Backend (`InvoiceService::paymentBlock()`) y frontend (`EstadoCuentaPage.tsx:281-282`)
ya imprimen el valor tal cual, sin transformación. **Este punto no requiere código** — el ticket
describe un estado anterior al 2026-09-18. Pre-QA lo verifica como regresión, no como fix nuevo.

## Punto 3 — Columna "Antigüedad de cartera"

`InvoiceService::agingBuckets()` (línea 1192) ya existe y usa los mismos 4 rangos, pero **no es
reusable tal cual**: bucketea desde la fecha de **vencimiento** (para el panel global de Inicio/
Facturación) y es un cálculo global, no filtrable por cliente/proyecto. El ticket pide bucketear
desde la fecha de **creación** de la factura — criterio distinto, no una variación de parámetro.
Además `agingBuckets()` tiene un bug conocido y sin ticket propio (`max(0,...)` mete facturas no
vencidas en "0-30") — fuera del alcance declarado de SCRUM-930, no se toca.

**Decisión:** extraer un helper puro `AgingBucketCalculator::bucketFor(Carbon $referenceDate): string`
(rangos 0-30/31-60/61-90/+91, sin conocimiento de vencimiento vs. creación) del que `agingBuckets()`
pasa a depender (llamándolo con la fecha de vencimiento, sin cambiar su comportamiento ni su bug
conocido) y que `AccountStatementService::movements()` llama por fila con `$invoice->created_at`.
Columna se agrega inmediatamente después de `saldo` en el payload y en la tabla; para filas que no
son factura (nota de crédito, cotización, guía) el campo va `null` → la UI muestra "—".

## Punto 4 — Documentos clickeables (Factura / Nota Crédito / Cotización / Guía)

Los 4 modales de detalle **ya existen y son reusables por id simple**:
`DetalleNotaCreditoModal` (`{notaId, onClose}`), `QuoteViewerModal` (`{quoteId, onClose}`),
`GuiaEntregaModal` (`{orderId, onClose}`) — los tres importables tal cual. El de Factura
(`InvoiceDetailModal`) es hoy un componente inline dentro de `FacturacionPage.tsx:872-1053`, no
extraído a archivo propio.

**El bloqueante real no es de UI, es de payload:** `AccountStatementMovement` (`adminContab.ts:804`)
no trae ningún id (`invoice_id`, `nota_id`, `quote_id`) — solo strings de presentación. Sin eso no
hay nada que enlazar, ni siquiera la factura.

**Decisión:**
- Backend: agregar `invoice_id`, `nota_id`, `quote_id` (ya existen como columnas/relaciones directas
  — `AdminContInvoice.id`, `AdminContCreditNote.id`, `AdminContInvoice.quote_id`) y
  `guia_entrega_order_id` (punto 1) a cada fila de `movements()`. Es aditivo al payload, no rompe
  nada existente.
- Frontend: extraer `InvoiceDetailModal` de `FacturacionPage.tsx` a
  `src/components/admin-contab/InvoiceDetailModal.tsx` (mismas props `{orderId, invoiceId, onClose}`
  que ya tiene) para poder importarlo también desde `EstadoCuentaPage`. `FacturacionPage` pasa a
  importarlo en vez de definirlo inline — sin cambio de comportamiento ahí.
- `EstadoCuentaPage`: cada celda de documento pasa de texto plano a botón/link que abre el modal
  correspondiente con el id de esa fila, mismo patrón en los 4 casos.

## Puntos 5 y 6 — Sidebar + botón "Status de Pedidos" (tensión real entre ambos)

Hoy Estado de Cuenta es una **vista popup** (`FocusedViewShell`, sin sidebar/menú, abierta con
`window.open()` desde un botón dentro de `FacturacionPage`), con un botón "Cerrar Ventana" propio de
ese patrón. El punto 6 del ticket pide un botón nuevo "al lado izquierdo de Cerrar Ventana" — asume
que ese botón sigue existiendo. El punto 5 pide agregarla al "menú lateral principal" entre Inicio y
Facturación — un ítem de sidebar normalmente navega dentro del `AppShell`, donde "Cerrar Ventana" no
tiene sentido (no hay ventana que cerrar, es la pantalla misma).

**Decisión propuesta (para confirmar con Luis, es la única de las 6 que no se resuelve sola):**
NO convertir Estado de Cuenta en una página de `AppShell` — mantiene su patrón popup actual sin
tocarlo. El ítem nuevo de sidebar dispara el mismo `window.open()` que ya usa el botón de
`FacturacionPage` (misma función, sin cliente preseleccionado — el popup abre con el selector de
cliente/proyecto vacío, a confirmar en implementación si ese estado vacío ya está soportado). Esto
resuelve el punto 5 literalmente (aparece en el menú lateral, en el orden pedido) sin invalidar el
punto 6 (el botón "Cerrar Ventana" sigue existiendo porque sigue siendo la misma ventana emergente).
Gate: mismo permiso/rol que ya usan Inicio y Facturación en el sidebar
(`ADMIN_CONTAB_FULL_ACCESS_ROLES` / `admin_contab.view`) — no hace falta permiso nuevo.

Alternativa descartada: convertir la pantalla a ruta normal de `AppShell` — exigiría rediseñar el
selector de cliente/proyecto como estado inicial de una pantalla persistente (hoy vive en el flujo
popup) y decidir qué reemplaza a "Cerrar Ventana", más alcance del que pide el ticket.

Botón "Status de Pedidos": mismo patrón ya usado en `FacturacionPage.tsx:217-219` (mismo texto, misma
ruta `/bodega/pedidos/status`, mismo `Button variant="secondary"`) — se agrega a `EstadoCuentaPage`
a la izquierda de "Cerrar Ventana".

## Resumen de cambios

| Capa | Cambio |
|---|---|
| Backend | `AccountStatementService::movements()`: `guia_entrega` bool → `guia_entrega_numero`+`guia_entrega_order_id`; agregar `invoice_id`/`nota_id`/`quote_id` por fila; nueva columna `antiguedad_cartera` (bucket por `created_at`, solo filas de factura) |
| Backend | Extraer `AgingBucketCalculator::bucketFor()`, `agingBuckets()` pasa a usarlo (mismo comportamiento, incluido el bug conocido — no se toca) |
| Frontend | Extraer `InvoiceDetailModal` a componente propio reusable |
| Frontend | `EstadoCuentaPage`: columna antigüedad después de Saldo; 4 tipos de documento clickeables → abren su modal; botón "Status de Pedidos" |
| Frontend | `Sidebar.tsx`: nuevo ítem "Estado de Cuenta" entre Inicio y Facturación, dispara el mismo popup existente, sin cliente preseleccionado |
| — | Punto 2 (cuenta sin máscara): sin cambio de código, ya resuelto por SCRUM-857/928 |

Sin migraciones de base de datos — todos los campos nuevos del payload salen de relaciones/columnas
ya existentes. Sin cambios de permisos.
