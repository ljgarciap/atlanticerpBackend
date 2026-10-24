# ADR — SCRUM-931: Cobros — correcciones y mejoras UAT

Arquitecto, 2026-09-20. Ticket: `SCRUM-931` (2 puntos, alcance explícito: solo el modal "Registrar
Cobro" y el detalle de un cobro — no toca cálculo de montos, facturación, ni Estado de Cuenta).
Mismo grupo que SCRUM-929/930/932/933 (desbloqueados por el modelo de facturación de SCRUM-929).

Investigación de código previa (Explore, 2026-09-20) + lectura directa de
`PaymentService::openInvoicesForClient()` y `AdminContPaymentController::presentDetail()`.

## Punto 1 — "Registrar Cobro": Proyecto + # Cotización, filtrado cruzado, factura específica

Hoy `openInvoicesForClient($masterClientId)` (`PaymentService.php:86-113`) trae TODAS las facturas
abiertas del cliente, con filas `{id, numero, monto, saldo_pendiente, abono_obligatorio,
neto_a_cobrar}` — sin `sales_project_id`/`quote_id`. `AdminContInvoice` ya tiene ambas columnas
pobladas en creación (`InvoiceService.php:755`), no hace falta migración.

**Decisión: sin endpoint nuevo de cross-filter.** Se extiende la fila de `openInvoicesForClient()`
con `sales_project_id`, `proyecto` (nombre), `quote_id`, `cotizacion_folio` — el mismo query que ya
corre al elegir Cliente Master. El filtrado cruzado Proyecto↔Cotización (elegir uno filtra el otro,
y ambos acotan la lista a la factura específica) se resuelve **enteramente en el frontend**, derivando
las opciones de Proyecto/Cotización de esa misma lista ya cargada — sin una segunda llamada al
backend por cada combinación. Justificación: el universo relevante ("facturas abiertas de este
cliente") ya viaja completo en una sola respuesta; inventar un endpoint de cascada nuevo (como el
que ya existe para Abonos, `AdminContAbonoController`, pero unidireccional) duplicaría lógica de
filtrado que el cliente puede hacer sobre datos que ya tiene, a costo de una request adicional por
cada cambio de campo.

Selección final (Proyecto + Cotización, o cualquiera de los dos solo) filtra la lista ya cargada a
la(s) factura(s) que matchean — normalmente una sola, pero el frontend no asume cardinalidad 1 (una
familia con varias entregas facturadas por separado bajo la misma cotización es un caso real desde
SCRUM-929).

**Componente de selección cruzada — nuevo, sin precedente 1:1 en el repo.** La cascada de
`RegistrarAbonoModal.tsx` (Sub-cliente→Proyecto→Factura) es unidireccional y de otro par de campos
— no aplica tal cual. SCRUM-933 (Registrar Abonos, mismo batch, en `To Do`) va a pedir el mismo
selector cruzado Proyecto↔Cotización. Se construye como componente/hook independiente
(`useProjectQuoteCrossFilter` o similar) reusable cuando se aborde SCRUM-933, pero **sin forzar la
integración ahora** — el universo de datos de Abonos (cascada por sub-cliente) es distinto al de
Cobros (lista plana de facturas abiertas) y evaluar el fit real le corresponde a esa sesión, no a
esta.

## Punto 2 — Detalle de un Cobro: Cotización, Guía de Entrega, Abonos y Notas de Crédito

### Modelo de datos — confirmado, sin migraciones

- **Abonos:** `AdminContPaymentInvoice` (pivote pago↔factura) ya tiene `abono_id` nullable por
  línea — un cobro con varias facturas puede tener varios abonos distintos, uno por línea. No hay
  FK directa `AdminContAbono.payment_id`; la relación vive en el pivote. `AdminContAbono` no tiene
  folio de negocio propio (solo `id`) — se muestra como "Abono #{id}", igual que Guía de Entrega en
  SCRUM-930 no tuvo folio real y se resolvió con un identificador sintético.
- **Notas de Crédito:** relación **siempre transitiva** vía la factura
  (`AdminContCreditNote.factura_origen_id`) — no existe ni existió nunca una FK directa a
  `AdminContPayment`. Mismo query ya escrito y probado en SCRUM-930
  (`AccountStatementService::movements()`, líneas 189-193):
  `AdminContCreditNote::where('estado','aplicada')->whereIn('factura_origen_id', $invoiceIds)`.
- **Cotización / Guía de Entrega:** mismo patrón que SCRUM-930 — `$invoice->quote_id` (directo,
  sin resolver nada) y `resolveGuiaEntrega($invoice, $order, $orders)` (hoy privado en
  `AccountStatementService`, líneas 353-374).

**Decisión: extraer `resolveGuiaEntrega()` a `App\Modules\AdminCont\Support\GuiaEntregaResolver`**
(mismo criterio que `AgingBucketCalculator` en SCRUM-930) — es el segundo consumidor real de esta
lógica exacta (familia de entregas, `order_id` real del documento vs. el de la factura), no vale la
pena duplicarla una tercera vez si aparece en Cobros/Notas de Crédito/Abonos más adelante.
`AccountStatementService` pasa a depender del resolver extraído, mismo comportamiento.

### Cardinalidad real: plural, no singular

El ticket describe "la Cotización"/"la Guía de Entrega" en singular, pero un cobro cubre `N`
facturas (`payment.invoiceLines`, ya soportado hoy) — cada factura puede tener su propia cotización
y guía. **Se generaliza a listas** (`cotizaciones`, `guias_entrega`, deduplicadas por id) en vez de
asumir una sola — consistente con que el propio ticket ya pide plural explícito para
Abonos/Notas de Crédito por la misma razón estructural. Un cobro típico post-punto-1 de este mismo
ticket cubrirá una sola factura casi siempre (la elección de Proyecto+Cotización aterriza en una),
así que en la práctica la lista mostrará 1 elemento la mayoría de las veces — pero el modelo de
datos no lo garantiza, y el detalle de cobros YA EXISTENTES (creados antes de este fix, con varias
facturas de distinto origen) sí puede tener varias.

### `presentDetail()` — extensión

`facturas` (ya existe, `AdminContPaymentController.php:178-184`) se extiende con `quote_id`,
`cotizacion_folio`, `order_id`, `guia_entrega_numero`, `guia_entrega_order_id` por línea (mismos
campos que ya expone `AccountStatementService::movements()`, mismo criterio de nombres). Se agregan
dos arrays nuevos al payload: `abonos` (ids únicos no nulos de `invoiceLines.abono_id`) y
`notas_credito` (resultado del query transitivo de arriba, con `id`+`numero`).

## Frontend

- `RegistrarCobroModal.tsx`: 2 selects nuevos (Proyecto, # Cotización) después de Cliente Master,
  alimentados por la respuesta ya extendida de `useOpenInvoices` — sin hook nuevo de red, solo
  derivación local (`useMemo`) de opciones y cross-filter.
- `DetalleCobroModal.tsx`: la lista de `facturas` (ya existe, texto plano) pasa a incluir el número
  de factura clickeable (mismo patrón `DocLink` de SCRUM-930, portable tal cual) → abre
  `InvoiceDetailModal` (ya extraído a componente propio en SCRUM-930, reusable directo). Secciones
  nuevas: Cotizaciones (lista, cada una → `QuoteViewerModal`), Guías de Entrega (lista, cada una →
  `GuiaEntregaModal`), Abonos (lista "Abono #{id}", cada uno → `RegistrarAbonoModal` con
  `abonoId={id}` — **ya es un modal de detalle/edición cuando se le pasa un id existente, no hace
  falta crear `DetalleAbonoModal` nuevo**), Notas de Crédito (lista, cada una → `DetalleNotaCreditoModal`,
  mismo componente que ya usa Estado de Cuenta). Sin datos → sin sección, nunca error ni placeholder
  inventado (AC explícito del ticket).

## Resumen de cambios

| Capa | Cambio |
|---|---|
| Backend | `PaymentService::openInvoicesForClient()`: agregar `sales_project_id`/`proyecto`/`quote_id`/`cotizacion_folio` por fila |
| Backend | Extraer `GuiaEntregaResolver` desde `AccountStatementService` (sin cambiar su comportamiento), reusarlo en `presentDetail()` |
| Backend | `presentDetail()`: `facturas` extendida + arrays nuevos `abonos`/`notas_credito` (join transitivo vía `factura_origen_id`, mismo patrón que SCRUM-930) |
| Frontend | `RegistrarCobroModal.tsx`: selects Proyecto/Cotización + cross-filter local, sin request nueva |
| Frontend | `DetalleCobroModal.tsx`: facturas clickeables + 4 secciones nuevas, todas reusando modales ya existentes (`InvoiceDetailModal`, `QuoteViewerModal`, `GuiaEntregaModal`, `RegistrarAbonoModal`, `DetalleNotaCreditoModal`) |
| — | Ningún componente de detalle nuevo — los 5 ya existen y son reusables por id |
| — | Sin migraciones — todos los campos nuevos salen de columnas/relaciones ya existentes |

Nota para SCRUM-933 (cuando se aborde): evaluar si el selector cruzado Proyecto↔Cotización de este
ticket es reusable tal cual para Registrar Abonos, o si la cascada por sub-cliente ya existente ahí
hace más sentido extender en su propio término — decisión de esa sesión, no de esta.
