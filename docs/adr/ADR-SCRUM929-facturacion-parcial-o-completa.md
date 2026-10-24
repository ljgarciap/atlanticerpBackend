# ADR — SCRUM-929: Facturar por entrega parcial o por pedido completo

Arquitecto, 2026-09-18. Spec: `docs/specs/scrum-928-935-correcciones-uat-admincont-analista-spec.md`
(decisiones D-1…D-4 cerradas con Luis el mismo día). **Supera parcialmente** el ADR
`ADR-SCRUM875-876-factura-unica-por-pedido.md`: se invalidan su §2 (una factura activa por pedido),
la decisión C-7 (`monto = card->amount` siempre) y el índice `invoices_order_id_active_unique`.
Se conservan la identidad de pedido = tarjeta raíz (`Order::familyRootId()`), el helper
`AdminContInvoice::forOrder()` y el split de familia de SCRUM-875.

## Problema

SCRUM-876 corrigió el saldo triplicado facturando UNA vez por el total. La operación real de los
proyectos con entrega parcial necesita lo contrario como opción: facturar cada entrega con lo que
realmente salió de Bodega, o facturar todo junto — a elección del usuario, sin cobros duplicados.

## Decisión

### 1. Modelo: la factura conoce su entrega y su tipo

Migración aditiva sobre `admin_contab.invoices`:
- `family_root_id` (bigint, indexed) — pedido al que pertenece. Backfill = `order_id` actual.
- `tipo` (`parcial` | `completo`, default `completo`) — el backfill deja TODAS las facturas
  existentes como `completo`: para pedidos ya facturados desaparecen solas las opciones parciales.
- `order_id` vuelve a significar **la entrega** (tarjeta) para `tipo=parcial`; para `completo` sigue
  siendo la raíz. `forOrder()` se extiende: resuelve por `family_root_id` (todas las facturas del
  pedido) — sus call sites actuales (§3 del ADR previo) se revisan uno por uno.
- Tabla nueva `invoice_lines` (invoice_id, `quote_item_id`, order_item_id nullable, descripcion,
  cantidad, precio_unitario, subtotal) — snapshot de lo facturado. Fuente para PDF, mapper de
  facturación electrónica y trazabilidad. `quote_item_id` es la llave del "cuánto ya se facturó".
- **Enmienda por spike B0 — `bodega.order_items.quote_item_id` (nullable, indexed).** Hoy NO hay
  ningún vínculo `OrderItem`→`QuoteItem` (solo `catalog_product_id`, nullable para ítems
  personalizados y repetible entre partes de una misma cotización → mapeo ambiguo). Se agrega y se
  llena en **los 4 sitios que crean `OrderItem`** en Bodega: `OrderService::createItemsFromQuote()`
  (origen, desde el `QuoteItem`) y los 3 que derivan ítems de otro ítem —
  `PickingService::createInventoryReviewCard()`, `DeliveryService::createFaltanteCard()` e
  `InventoryReviewService::splitFoundItemsIntoNewOrder()` (este último además borra el ítem
  original y lo recrea en la tarjeta nueva). Los 3 derivados **hoy tampoco copian `unit_price`** →
  queda null; se corrige de paso, copiando ambos campos del ítem padre. Backfill best-effort con comando propio (mismo patrón que
  `bodega:backfill-order-item-prices`, SCRUM-786). **Degradación segura:** un pedido con algún ítem
  sin vínculo solo admite facturación `completo` (monto = `card->amount`), nunca parcial.
- **Índices: el índice único parcial `invoices_order_id_active_unique (order_id) WHERE anulada_at IS NULL`
  se REEMPLAZA por `invoices_order_id_tipo_active_unique (order_id, tipo) WHERE anulada_at IS NULL`**
  (migración `2026_09_18_110200_scope_invoice_order_id_unique_by_tipo`; decisión del Arquitecto 2026-09-18, ver
  "Resultado de B2", punto 1). El índice viejo admitía una sola factura activa por `order_id` sea del tipo que
  sea; una `completo` cuelga de la raíz y la raíz es también la 1ª entrega, que casi siempre se factura antes
  como `parcial` (`order_id` = raíz) — con el índice viejo el "pedido completo por el resto" (D-2) reventaba en
  la BD. El nuevo sigue garantizando a lo sumo UNA `parcial` activa por entrega y UNA `completo` activa por
  `order_id`, y es más débil que el anterior (no puede fallar al crearse sobre datos existentes). (El borrador
  original de este ADR ya había descartado un índice único nuevo sobre `family_root_id`: NO puede crearse,
  dev/test conservan facturas duplicadas huérfanas de antes de SCRUM-876 (C-8).) El invariante `completo` ↔
  `parcial` de OTRAS tarjetas del pedido (Σ ≤ total, tope por ítem, un solo `completo` por pedido) no es
  expresable en un índice → lo garantiza el guard transaccional (§3), no la BD.
- `invoice_lines` lleva importes **desglosados** (`subtotal` base, `itbms`, `total`) e
  `itbms_rate_id` nullable: MSL/Digifact exigen ITBMS por línea y `order_items.itbms_rate_id` no
  tiene todavía ningún escritor, así que B2 usa la tasa efectiva de la cotización.
- `invoices.family_root_id` es NOT NULL; un hook `creating` de `AdminContInvoice` lo resuelve
  (`Order::familyRootId()`) porque ~22 archivos crean facturas.

### 2. Cálculo de monto (suma exacta = total de cotización)

- **Cantidad facturable de una entrega = `qty_delivered`** de sus `OrderItem` (corregido tras
  revisar `DeliveryService`; no `qty_picked` ni `qty_requested`). `registerDelivery()` fija
  `qty_delivered` (tope `qty_picked`) en la transición Packing → `POR_DESPACHAR`, y la diferencia
  `picked − delivered` viaja en una tarjeta de Faltante de la misma familia, que se factura como
  otra entrega cuando llegue a su propio `POR_DESPACHAR`. Idem el remanente de una Revisión de
  Inventario (`qty_requested − qty_picked`).
- **Parcial:** por cada `OrderItem` con `qty_delivered > 0`, vía `quote_item_id`:
  `subtotal_línea = lineTotal(quoteItem) × qty_entrega / qty_cotizada` (descuentos ya incluidos en
  `lineTotal`). ITBMS con la tasa efectiva de la cotización (`quote->itbms() / quote->netTotal()`);
  Backend debe verificar en B2 cómo interactúa con `order_items.itbms_rate_id` (migración del
  17-sep) antes de fijar la fórmula. Redondeo a 2 decimales por línea.
- **Residual:** solo cuando la factura **cierra la última cantidad sin facturar de todos los
  ítems** del pedido, `monto = total_cotización − Σ facturas activas`; así Σ = total al centavo
  (D-2) sin cobrar nunca mercadería que no salió (si el remanente nunca se despacha, no hay
  residual que cobrar).
- **Completo:** líneas = cantidad cotizada − cantidad ya facturada, por `quote_item_id`, sin
  importar si ya se alistó (se factura el pedido). Si no hay parciales previas, es el total.
- Topes: `Σ activas + nuevo ≤ total_cotización` (tolerancia 0.005) **y** por ítem
  `Σ cantidad facturada ≤ cantidad cotizada` (cubre el caso legado en que cada tarjeta nació con
  todos los ítems, SCRUM-909 sin asignación).
- Verificado: `card->amount = quote->grandTotal() = netTotal + itbms` (QuoteService:521) — es el
  total con ITBMS y coincide con `fiscalBreakdown()['total']`.

### 3. Guard anti-duplicado (regla crítica)

`confirm()` y el nuevo `confirmCompleto()` toman `Order::where('id', $rootId)->lockForUpdate()` sobre
la fila **raíz** (existente — serializa a los concurrentes; no depende de bloquear filas que aún no
existen, ver lección `lockForUpdate` vs phantom inserts), y dentro de la transacción validan:
1. no existe factura `completo` activa en el pedido;
2. la entrega objetivo no tiene factura `parcial` activa;
3. `Σ activas + monto_nuevo <= total` (tolerancia 0.005 = medio centavo, épsilon numérico del redondeo a 2
   decimales, no un umbral de negocio);
4. tope por ítem: `Σ cantidad facturada por quote_item_id + la de esta factura <= cantidad cotizada`;
5. la entrega alcanzó una etapa facturable (D-3, sin cambio).

Implementado en B2 (`Billing\InvoiceBillingPlanner`, llamado por `InvoiceService::confirm()`): la decisión se
toma re-leyendo todo el contexto del pedido **dentro** de la transacción y **después** de tomar el lock.
Cualquier violación → `InvoicePreviewException`. La UI oculta opciones, pero **el rechazo real es
del backend**: un request manipulado no puede duplicar el cobro.
Anular una factura (`anulada_at`) devuelve saldo facturable.

### 4. API — contrato implementado en B2

Todo bajo `/api/admin-contab/invoices` (mismos roles y middleware que hoy). Los importes son números
JSON (`321` es `321.00`). Todo mensaje de rechazo viaja en español dentro de `missing[]`.

#### 4.1 Modo de facturación (`modo`)

`POST /invoices/preview` y `POST /invoices` conservan el cuerpo actual y agregan un campo opcional:

```json
{ "order_ids": [5], "modo": "entrega" }      // "entrega" | "completo" | ausente/null
```

(El borrador original de este ADR hablaba de `{order_id, modo}`; se mantiene `order_ids` — lista — porque
es lo que ya manda el frontend y permite facturar N pedidos/entregas en un solo "Guardar".)

| `modo` | Qué significa cada id de `order_ids` | Qué se factura | `tipo` resultante |
|---|---|---|---|
| `entrega` | una **entrega** (tarjeta `Order`) | lo que salió de esa entrega: `qty_delivered` por ítem, vía `quote_item_id` | `parcial`, `order_id` = la entrega |
| `completo` | un pedido o **cualquiera de sus entregas** (se normaliza a la raíz, sin duplicados) | el pedido entero; si ya hay parciales, solo lo que falta (cantidad cotizada − facturada, por ítem) | `completo`, `order_id` = la raíz |
| ausente / `null` | igual que `completo` | — | `completo` |

Decisiones de compatibilidad:
- **Default = `completo`**, no `entrega` como decía el borrador: el frontend anterior a SCRUM-929 manda solo
  `order_ids` y debe seguir obteniendo **una factura por el total del pedido**, exactamente como en SCRUM-876,
  también sobre un pedido con varias entregas. Nunca se factura una parcial sin que el cliente la pida.
- En un **pedido de entrega única** (una sola tarjeta) `entrega` y `completo` son la misma factura; `entrega` se
  trata como `completo`.
- Un `modo` distinto de los dos valores → **422** de validación.
- `POST /invoices` sigue devolviendo `201 {invoices, errors}` (si al menos una se creó) o `422 {message, errors}`
  (si ninguna); cada elemento de `errors` es `{order_id, missing: [...]}`.

Mensajes de rechazo del backend (guard §3 — el rechazo es real, no depende de que la UI oculte botones):

| Situación | `missing[0]` |
|---|---|
| ya existe una factura `completo` activa (cualquier modo) | `Este pedido ya tiene una factura generada.` |
| la entrega ya tiene su parcial activa | `Esta entrega ya tiene una factura generada.` |
| la entrega no llegó a "Por despachar" | `Esta entrega todavía no llegó a la etapa "Por despachar" de Bodega.` |
| la entrega no tiene cantidades entregadas | `Esta entrega no tiene cantidades entregadas para facturar.` |
| un ítem excedería lo cotizado | `El ítem "X" excedería lo cotizado (a de b unidades ya facturadas o por facturar).` |
| Σ + monto excede el total | `El monto de esta factura (x) supera el saldo por facturar del pedido (y).` |
| no queda saldo | `No queda saldo por facturar en este pedido.` |
| todo lo cotizado ya está facturado | `Todas las cantidades de la cotización ya están facturadas.` |
| pedido con algún producto sin vínculo a la cotización + `modo=entrega` | `Este pedido solo admite facturación de pedido completo: sus productos no están vinculados a la cotización.` |
| hay facturas previas con líneas de ítems que ya no están en la cotización vigente (se re-versionó, o se borró un `QuoteItem`) | `La cotización del pedido cambió de versión después de facturar parcialmente; el saldo no se puede calcular automáticamente. Contacte a Administración.` (rechaza `completo`; un pedido re-versionado SIN facturas previas se factura normal) |
| datos requeridos (sin cambio) | `N° de Cotización`, `Monto`, `Cliente`, `Ninguna entrega del pedido llegó a la etapa "Por despachar" de Bodega` |

#### 4.2 `POST /invoices/preview`

`200 {results: [...]}`, un elemento por id normalizado. Éxito:

```json
{ "order_id": 6, "ok": true, "order_number": "PED-2026-500008", "cotizacion_folio": "COT-2026-500006",
  "cliente": "…", "monto": 321, "modo": "entrega", "tipo": "parcial",
  "cierra_pedido": false, "restante_despues": 321, "advertencias": [],
  "entregas_del_pedido": [ … ],
  "subtotal": 300, "descuentos": 0, "itbms": 21, "total": 321,
  "items": [ { "descripcion": "Panel LED 60x60", "cantidad": 3, "precio_unitario": 100, "subtotal": 300 } ] }
```
- `order_id` = la entrega (modo `entrega`) o la raíz (modo `completo`). `modo` es el **efectivo** (una entrega
  única con `entrega` vuelve `completo`).
- `monto` = lo que se factura ahora. `subtotal − descuentos + itbms = total = monto` (al centavo, salvo el
  redondeo de presentación del bruto por línea, ver 4.6). `items` son las líneas que se facturarían.
- `cierra_pedido` = esta factura deja facturada toda la cantidad cotizada (y por eso reparte el residual de
  redondeo, §2). `restante_despues` = saldo del pedido tras emitirla.
- **`advertencias`: `string[]`, vacío por defecto.** Solo el cierre puede traerlas: base/ITBMS que difieren de la
  cotización en más de lo que explica el redondeo, o el total de la tarjeta (`card->amount`) que no coincide con la
  cotización recalculada hoy (típico: cambió la tasa de ITBMS o `PricingSettings` después de aprobar). La factura
  se emite igual por `card->amount − Σ previas` (el dinero siempre cuadra con la tarjeta); la UI debe mostrar el
  aviso ANTES de confirmar. La respuesta de `POST /invoices` incluye el mismo campo en cada elemento de
  `invoices[]` (`invoices.0.advertencias`). Además queda un `Log::warning admin_cont.invoice.cierre_con_advertencias`.
- Falla: `{ "order_id": 5, "ok": false, "missing": ["Esta entrega ya tiene una factura generada."] }` — en `ok:false`
  solo viajan esos 3 campos.
- Un preview nunca persiste nada ni toma locks.

#### 4.3 `GET /invoices?tab=&view=plana|agrupado&cliente=&proyecto=&estado=&desde=&hasta=&search=`

Mismos filtros y mismas dos formas (`{rows, total_en_tab}` / `{groups, total_en_tab}`) que hoy. Lo que cambia es
**qué es una fila**:

- **Facturas emitidas: SIEMPRE una fila por factura** (la última de cada par tarjeta+tipo: una anulada y su
  reemplazo son una sola fila), con su propio `monto`, `invoice_id` y `tipo_factura`, **sea cual sea la forma del
  pedido**. `facturacion_por_entrega`/"linkable" solo decide lo que SE PUEDE emitir (`puede_facturar_*`), nunca
  oculta lo ya emitido (p. ej. si tras emitir dos parciales se crea otra cotización con folio en el proyecto, las
  dos parciales siguen listadas con su monto).
- **Filas pendientes** (sin factura): en un pedido con varias entregas y productos vinculados
  (`facturacion_por_entrega = true`), UNA por cada entrega **ya facturable** (`por_despachar` o posterior) sin
  factura propia y no cubierta por un `completo`. En un pedido de entrega única, o que solo admite pedido completo
  (algún producto sin `quote_item_id`, o cotización re-versionada), UNA fila pendiente anclada a la raíz mientras no
  tenga ninguna factura — idéntica a SCRUM-876. Una entrega todavía en Bodega (p. ej. `packing`) no genera fila
  (viaja en `entregas_del_pedido`). Si el pedido ya tiene una factura `completo`, las entregas que ésta cubre NO
  repiten fila (para no duplicar la misma factura N veces en el listado ni en el export): queda la fila de la
  tarjeta a la que cuelga el completo (la raíz).
- **El frontend no debe usar `order_id` como clave única de fila**: la raíz puede tener dos filas (su parcial y el
  completo). La clave es `invoice_id` (o `order_id` + `tipo_factura` en las pendientes).
- **Etiqueta en el export** (Excel/PDF, columna "Entrega / Hito"): `PED-X (n/N)` solo cuando la fila ES una entrega
  (una factura `parcial`, o una entrega pendiente de un pedido facturable por entrega); una factura `completo` —o la
  fila única de un pedido que solo admite completo, p. ej. todo lo legado— es el pedido entero y lleva `PED-X` a secas.

Campos de cada fila (los previos no cambian de nombre ni de tipo):

| Campo | Tipo | Nivel | Notas |
|---|---|---|---|
| `order_id` | int | entrega | tarjeta de la fila; es el id que se manda en `order_ids` con `modo:'entrega'` y en `/{order}/detail` |
| `order_number`, `sales_project_id`, `proyecto`, `master_client_id`, `cliente`, `cotizacion_folio` | — | pedido | sin cambio |
| `monto` | number\|null | entrega | facturada: monto de SU factura; pendiente: lo que se facturaría de esa entrega (pedidos por entrega) o `card->amount` (resto) |
| `estado` | `pendiente-facturar`\|`facturada`\|`anulada` | entrega | sin cambio |
| `numero_factura` | string\|null | entrega | |
| `invoice_id`, `tipo_factura` (`parcial`\|`completo`\|null) | | entrega | **nuevo** |
| `es_incobrable`, `incobrable_pendiente` | bool | entrega | sin cambio |
| `entrega` | `{sequence_in_family, total_in_family, stage, order_type}` | entrega | **nuevo** |
| `entregas_del_pedido[]` | ver 4.5 | pedido | ampliado (campos nuevos al final, los 4 anteriores intactos) |
| `pedido_id` | int | pedido | **nuevo** — id de la raíz |
| `es_entrega_unica` | bool | pedido | **nuevo** — una sola tarjeta |
| `facturacion_por_entrega` | bool | pedido | **nuevo** — se puede ofrecer `modo:'entrega'` (varias entregas y todo vinculado) |
| `monto_total_pedido`, `facturado_pedido`, `restante` | number | pedido | **nuevo** — total de la cotización, Σ facturas activas, saldo. Repetidos en cada fila del pedido |
| `puede_facturar_completo` | bool | pedido | **nuevo** — se puede emitir hoy un `completo` |
| `motivo_completo_no_disponible` | string\|null | pedido | **nuevo** |
| `puede_facturar_entrega` | bool | entrega | **nuevo** — la entrega admite `modo:'entrega'` hoy (ya corrió el plan completo, incluidos topes por ítem) |
| `motivo_entrega_no_disponible` | string\|null | entrega | **nuevo** |

`puede_facturar_*` NO evalúan los datos faltantes (cotización/monto/cliente): esos se informan en el preview,
como hoy. `summary().pendientes_facturar` cuenta lo mismo que las filas `pendiente-facturar` con
`puede_facturar_*` posible: 1 por pedido de entrega única/solo-completo, y 1 por cada entrega facturable sin su
factura en los pedidos por entrega. `pendientes_entrega`, `facturadas_mes` y `monto_facturado_mes` no cambian.

Ejemplo — pedido `PED-2026-500008`, total 963 (9 × 100 + 7 % ITBMS), 3 entregas de 3 unidades; la **2ª** ya está
facturada (parcial F-0002), la 1ª y la 3ª están en "Por despachar" sin factura (`entregas_del_pedido` omitido):

```json
[
  { "order_id": 4, "estado": "pendiente-facturar", "numero_factura": null, "tipo_factura": null, "monto": 321,
    "entrega": { "sequence_in_family": 1, "total_in_family": 3, "stage": "por_despachar", "order_type": "pedido" },
    "pedido_id": 4, "es_entrega_unica": false, "facturacion_por_entrega": true,
    "monto_total_pedido": 963, "facturado_pedido": 321, "restante": 642,
    "puede_facturar_completo": true, "motivo_completo_no_disponible": null,
    "puede_facturar_entrega": true, "motivo_entrega_no_disponible": null },
  { "order_id": 5, "estado": "facturada", "numero_factura": "F-0002", "tipo_factura": "parcial", "monto": 321,
    "pedido_id": 4, "facturacion_por_entrega": true, "restante": 642, "puede_facturar_completo": true,
    "puede_facturar_entrega": false, "motivo_entrega_no_disponible": "Esta entrega ya tiene una factura generada." },
  { "order_id": 6, "estado": "pendiente-facturar", "numero_factura": null, "tipo_factura": null, "monto": 321,
    "pedido_id": 4, "facturacion_por_entrega": true, "restante": 642, "puede_facturar_completo": true,
    "puede_facturar_entrega": true }
]
```

Mismo pedido con la **1ª** (la raíz) facturada en vez de la 2ª: las filas pendientes siguen trayendo
`"puede_facturar_completo": true` (`restante` = 642) — el "pedido completo por el resto" es posible tras la
parcial de la raíz. Al emitirlo (F-0003 por 642, `order_id` = la raíz, `tipo: "completo"`) la raíz pasa a tener
**dos facturas activas con el mismo `order_id`** (la parcial y el completo), y por eso lleva **dos filas** (una por
factura, ambas con `order_id: 4`; se distinguen por `invoice_id`/`tipo_factura` — **el frontend no debe usar
`order_id` como clave única de fila**). Las demás entregas, cubiertas por el completo, no repiten fila.

Tras emitir un `completo` sin parcial previa en la raíz (F-0003 por 963): una sola fila `order_id: 4`, `estado:
"facturada"`, `tipo_factura: "completo"`, `restante: 0`, ambos `puede_*` en `false`, y las 3 entradas de
`entregas_del_pedido` con `factura_numero: "F-0003"`, `cubierta_por_completo: true` en las que no son la raíz.
`entregas_del_pedido[]` de la raíz con parcial + completo muestra su parcial (`factura_tipo: "parcial"`).

#### 4.4 `GET /invoices/pending?sales_project_id=`

Paso 3 del wizard "+ Crear Factura". Devuelve una lista de payloads de preview (misma forma que 4.2, sin `ok`):
una entrada por **entrega** en "Por despachar" y sin factura activa (pedidos por entrega), o por **pedido** sin
ninguna factura activa (resto — forma histórica: `monto` = `card->amount`, `modo:'completo'`, `tipo:'completo'`,
`cierra_pedido:true`). Un pedido con factura `completo` activa no aparece; tampoco una entrega ya facturada o sin
cantidades entregadas.

#### 4.5 `entregas_del_pedido[]` (en filas, previews y detalle)

Una entrada por tarjeta de la familia, por secuencia: `order_id`, `sequence_in_family`, `total_in_family`, `stage`
(los 4 de siempre) más **`order_type`** (`pedido`\|`faltante`\|`revision_inventario`), **`facturable`** (bool:
etapa `por_despachar` o posterior), **`factura_numero`**, **`factura_tipo`**, **`factura_anulada`** (la factura
propia de la tarjeta o, si no tiene, la del `completo` que la cubre) y **`cubierta_por_completo`** (bool).

#### 4.6 `GET /invoices/{order}/detail` y `/pdf`

`{order}` es la **entrega** consultada. La factura mostrada es la propia (parcial, o el `completo` anclado a esa
tarjeta) o, si no tiene, la del `completo` activo que la cubre; con solo parciales ajenas la entrega figura
`pendiente-facturar`. **`?invoice_id=` (opcional, también en `/pdf`)** elige la factura cuando la tarjeta tiene más de una (la raíz con su
parcial y el completo): sin él rige la regla central de 4.9 (parcial vigente de esa tarjeta → completo vigente que la cubre → recién después las anuladas, y a igualdad la más reciente — orden determinista); una factura que no sea de ese pedido → 404; `invoice_id` que no sea entero ≥ 1 → 422 (`integer|min:1`). Campos
nuevos: `pedido_id`, `entrega_id`, `invoice_id`, `tipo_factura`, `concepto`
(`"Entrega 2 de 3"` / `"Pedido completo"` / `null` en facturas legadas). `order_id` = `order_id` de la factura si
existe (raíz en un `completo`), si no la entrega consultada. `monto`/`total_a_pagar` son los de esa factura.
Si la factura tiene `invoice_lines`, `subtotal`/`descuentos`/`itbms`/`total`/`items` salen de ellas: las filas
muestran el importe **bruto** de lo facturado del ítem (antes del descuento de Totales de la cotización), y
`descuentos` es la parte de ese descuento que le tocó a esta factura, de modo que
`subtotal − descuentos + itbms = total`. Las facturas **legadas** (sin líneas) siguen saliendo de la cotización
completa, con el mismo contenido que antes (verificado por el test del HTML del PDF, no por un golden byte a byte). El PDF (`concepto` bajo el folio de la cotización) usa el mismo `detail()`.

#### 4.7 `InvoiceCreated` (evento de dominio)

Contrato sin cambios: `orderId` = `invoice.order_id`. Ahora eso significa **la entrega** en una factura `parcial`
y **la raíz** en una `completo`. Único listener: `Bodega\Listeners\MarkOrderInvoiceReady`, no-op desde SCRUM-876.

#### 4.8 Incobrable por FACTURA (B3a)

`POST /invoices/{order}/mark-uncollectible` y `PUT /invoices/{order}/uncollectible-decision` aceptan un
**`invoice_id` opcional** en el cuerpo (`integer|min:1`, vacío = ausente). `{order}` sigue siendo cualquier
tarjeta del pedido. Reglas:
- con `invoice_id`: se opera sobre esa factura; si no es del pedido → **404**; si está anulada → 422 con el
  mensaje de siempre;
- sin `invoice_id` y **exactamente una** factura activa en el pedido: se usa esa (compatibilidad con el frontend
  actual, sin cambio de comportamiento);
- sin `invoice_id` y **varias** activas → **422** `Este pedido tiene varias facturas activas: indique sobre cuál
  operar con invoice_id.`; sin ninguna activa → 422 como hoy;
- `invoice_id` inválido (`0`, no numérico) → 422 de validación.
El `invoice_id` de cada factura viaja en las filas de `GET /invoices` (`invoice_id`) y en `detail()`.

#### 4.9 Regla central: "la factura de una entrega" (B3a)

`InvoiceService::pickInvoiceForDelivery(Collection $invoicesDeLaFamilia, int $entregaId, bool $incluirAnuladas)`
(estática, sin estado) y `InvoiceService::invoiceForDelivery(Order)`:
1. la factura `parcial` **ACTIVA** anclada a esa entrega;
2. si no hay, el `completo` **ACTIVO** de la familia (cubre el resto del pedido);
3. si no hay, ninguna;
4. solo con `$incluirAnuladas` (históricos), y recién después: la anulada anclada a esa entrega (parcial primero)
   y, por último, el `completo` anulado de la familia.
Es el mismo orden de `detail()`/`entregas_del_pedido` (N5). La usan: `OrderInvoiceController::show()`
(`invoice_ready`/`invoice_numero` de una entrega sin factura ya no muestran la de otra),
`OrderBoardService` (tablero por lote y detalle de una tarjeta, unificados vía
`InvoiceService::deliveryIdsWithActiveInvoice()`), `CreditNoteService` (`itbmsTreatmentForInvoice`,
`devolucionDetail`, cola de devoluciones por generar) y `detail()`. Ningún consumidor debe usar
`forOrder()->first()`, `keyBy('order_id')` ni `inv.order_id = o.id` asumiendo una factura por tarjeta.
`GET /notas-credito/clientes/{id}/facturas` lista cada factura activa como fila propia y agrega
`tipo_factura` y `order_id`. La guía de entrega de Estado de Cuenta es la de SU entrega para una factura
`parcial` y la de cualquier entrega de la familia para un `completo`.

#### 4.10 Corrección de datos sobre facturas con líneas (B3a)

La factura de REEMPLAZO de `CreditNoteService::executeCorreccion()` conserva **`order_id`, `family_root_id` y
`tipo`** de la que reemplaza y copia sus **`invoice_lines`**: una parcial sigue siendo la parcial de SU entrega, un
completo sigue siendo el completo. Los importes salen de ESAS líneas, no de la cotización entera:
- motivo **fecha** (misma tasa): las líneas se copian tal cual → mismo monto → Σ facturas del pedido no se mueve;
- motivo **ITBMS/ambos** (otra tasa): la base de cada línea no cambia, el ITBMS se recalcula por línea y el
  redondeo del ITBMS de la factura entera se reparte sin líneas negativas (`InvoiceLineMath`); `monto` =
  Σ base + ITBMS nuevo. `previewCorreccion()` usa el mismo cálculo (`factura_preview.items` son las líneas
  de la parcial, no toda la cotización).
La original se anula ANTES de crear el reemplazo, dentro de la misma transacción: el índice único `(order_id,
tipo) WHERE anulada_at IS NULL` solo admite uno activo por par (corregir la parcial de la RAÍZ con el completo ya
emitido daba un 500 antes de este cambio). Una factura legada (sin líneas) se reemplaza como siempre, sin líneas.

### 5. Impactos aguas abajo (Senior Review debe verificar cada uno)

| Consumidor | Cambio |
|---|---|
| `ArchitectCommissionService::allProjects()` (**pendiente: B3b**) | el join `orders o … LEFT JOIN invoices` vuelve a fan-out con N facturas → agregar por `sales_project_id` con `SUM`; comisión = suma de comisiones por factura (D-4). |
| `CommissionOrderService` (internas) | igual, por factura sobre monto proporcional. **Pendiente: B3b.** |
| `proposeUncollectible` / `decideUncollectible` | operan por **factura**, no por pedido. **Hecho en B3a** (4.8). |
| `CreditNoteService::invoiceForOrder()` + `facturas` | elegir factura explícita; ya no asume una. **Hecho en B3a** (4.9/4.10). |
| `AccountStatementService::movements()` | 1 fila por factura (natural); guía de la entrega de esa factura. **Hecho en B3a**. |
| Aging/Cartera/`InvoiceBookService`/reportes | correctos sin cambio **si** Σ montos = total; cubierto por test de invariante. |
| Mapper facturación electrónica | construir desde `invoice_lines`, no desde la cotización. |

## Consecuencias

- **Positivo:** modela la operación real; auditable línea a línea; coexisten ambos modos.
- **Riesgo:** dinero + concurrencia + ~8 consumidores. Mitigación: guard transaccional, test de
  invariante de suma (incluye redondeo con 3 entregas e ITBMS), test de concurrencia, revisión
  exhaustiva de call sites (`grep -rn "AdminContInvoice" app/`) en Senior Review.
- **Resultado del spike B0 (Arquitecto, 2026-09-18, lectura de código):**
  1. ✔ (corregido) La cantidad definitiva de una entrega es **`qty_delivered`**, no `qty_picked`.
     `qty_picked` solo se escribe en `PickingService` (exige `EN_PICKING`) y es el *tope*;
     `qty_delivered` se escribe en un único sitio (`DeliveryService.php:140`, `registerDelivery()`,
     Packing → `POR_DESPACHAR`) y nadie más lo modifica después (`CustomerReturnService` solo lo
     lee). Mi primera lectura del spike había dado `qty_picked` por final — era incompleta.
  2. ✘ `OrderItem`↔`QuoteItem` **no** se mapea sin ambigüedad (sin FK; `catalog_product_id`
     nullable y repetible). Resuelto con `order_items.quote_item_id` (ver §1).
  3. ✘ Las tarjetas de Revisión de Inventario nacen sin `unit_price`. Resuelto en el mismo cambio.
  4. ✔ `card->amount` es total con ITBMS. Pendiente para B2: interacción con
     `order_items.itbms_rate_id`.
- **Resultado de B2 (Backend Dev, 2026-09-18) — premisas del diseño que NO se sostuvieron o que se
  resolvieron; el Arquitecto debe revisarlas:**
  1. ✘ → ✔ **RESUELTO 2026-09-18 (Arquitecto): `completo` con una parcial en la RAÍZ no cabía en el índice único.**
     `invoices_order_id_active_unique (order_id) WHERE anulada_at IS NULL` admitía una sola factura activa por
     `order_id`. Una `completo` cuelga de la raíz, y la raíz es la **1ª entrega** — la que casi siempre se
     factura primero — así que "facturo la 1ª entrega y luego el pedido completo por el resto" (el escenario
     emblema de D-2) reventaba con un error de BD. El §1 lo presentaba como virtud ("impide una parcial sobre la
     raíz junto a un completo") pero chocaba con D-2. Mientras tanto B2 lo rechazaba con un mensaje propio.
     **Resolución:** migración `2026_09_18_110200_scope_invoice_order_id_unique_by_tipo` reemplaza el índice por
     `UNIQUE (order_id, tipo) WHERE anulada_at IS NULL` (más débil que el anterior; los datos legados son todos
     `completo` con `order_id` distinto, no puede fallar) y se quitaron los guards que esquivaban el bloqueo
     (`tieneFacturaActivaEn(root)` en `InvoiceBillingPlanner::planCompleto()` y en
     `InvoiceService::completoDisponible()`). El guard transaccional (§3) sigue siendo lo único que impide el
     doble cobro. Consecuencias de lectura: la raíz puede tener parcial + completo con el MISMO `order_id`, así
     que el listado emite una fila por factura (ver 4.3) y `detail/pdf` aceptan `?invoice_id=` (ver 4.6);
     cualquier consumidor que indexe facturas por `order_id` (`keyBy('order_id')`, joins `inv.order_id = o.id`)
     debe tolerar dos filas por tarjeta (B3). El `down()` de la migración puede fallar si ya existen parcial y
     completo activos con el mismo `order_id`.
  2. ✘ **La fórmula del §2 omitía el descuento de TOTALES de la cotización** (`discount_totals_*`, que se resta del
     subtotal general antes del ITBMS). Sin prorratearlo, Σ líneas ≠ `netTotal` y toda la diferencia caía en la
     última factura. Se prorratea por línea con el factor `netTotal / subtotal` (mismo criterio que
     `Quote::costedPortfolioMarginBelowMinimum()`): `subtotal_línea = lineTotal × factor × cantidad / cotizada`.
  3. **ITBMS:** tasa = `ItbmsRateResolver::defaultPercentage()` — la misma fuente que `Quote::itbms()` (equivale a
     "tasa efectiva" sin la división `itbms/netTotal`, que fallaría con `netTotal = 0`). No se usó
     `order_items.itbms_rate_id` (sin escritor ni lector). `invoice_lines.itbms_rate_id` se llena con la tasa
     default (mismo id que `invoices.itbms_rate_id`), en vez de quedar null.
  4. **Residual (revisado tras Senior Review, F6/F7):** solo la factura que cierra TODA la cantidad cotizada toma
     `monto = card->amount − Σ activas`, y reparte el residual por SEPARADO en base e ITBMS (a la última línea):
     lo que le falta a esa factura es `cotización − Σ previas` (base = `round(netTotal,2)`, ITBMS =
     `round(itbms(),2)`, restando las `invoice_lines` de las facturas activas), de modo que Σ base y Σ ITBMS de
     todas las facturas del pedido == los de la cotización al centavo y el total == `card->amount`. En un pedido de
     entrega única (o un completo sin facturas previas) el desglose sale idéntico al de `fiscalBreakdown` (ej.
     3 × 4.50 a 7 %: 13.50 / 0.95 / 14.45, no 13.49 / 0.96 / 14.45). Si `card->amount` no coincide con base + ITBMS
     de la cotización recalculada hoy, se sigue facturando `card->amount` pero el descuadre se absorbe en la base de
     la última línea y sale en `advertencias` (preview y respuesta de confirmar, ver 4.2). También se advierte
     cuando base/ITBMS difieren en más de medio centavo × (líneas de esta factura + líneas de las facturas
     previas activas + 1): con solo las líneas de esta factura, un redondeo legítimo de 2 centavos en 3 líneas
     daría falsas alarmas. Si una factura activa previa no tiene líneas, no se puede repartir por separado: se cae
     al reparto en la base y se advierte. El ajuste se reparte SIN dejar ninguna línea con base o ITBMS negativos
     (`Billing\InvoiceLineMath::repartir()` — precisado 2026-09-19 en Senior Review, "prorrateado" no era el término
     correcto: SECUENCIAL, no proporcional — un ajuste positivo va entero a la línea de mayor valor; uno negativo se
     quita de la línea de mayor valor hasta agotar su margen y solo entonces pasa a la siguiente, en orden
     descendente; si ni así alcanza, queda una advertencia). El `Log::warning` del cierre solo se escribe en
     `confirm()`, nunca al leer (listados, preview, `completoDisponible()`).
  5. **Vínculo:** "linkable" = todo `OrderItem` de la familia tiene `quote_item_id` **y ese `QuoteItem` pertenece a
     la cotización vigente del pedido** (`Order::linkedQuote()`). Una cotización re-versionada tras crear el
     pedido degrada a solo-completo en vez de facturar contra ítems de otra versión — y `linkable` **solo decide lo
     que se puede emitir**, nunca oculta lo ya emitido (F2: una fila por factura, ver 4.3). Si además ya había
     facturas con líneas contra ítems que ya no están en la cotización vigente (re-versionada o `QuoteItem`
     borrado DESPUÉS de facturar), el `completo` se RECHAZA con el mensaje de 4.1 (F1: emitir líneas contra otra
     versión daría líneas de total negativo); un pedido re-versionado SIN facturas previas se factura normal.
  6. **Cantidades fraccionarias:** el pedido de Bodega redondea a entero (`(int) round(quantity)`), la
     cotización no. Una entrega que entrega 3 de una línea cotizada en 2.5 choca con el tope por ítem y se rechaza
     (el pedido completo sigue disponible). Caso raro; se dejó estricto en vez de topar en silencio.
  7. **Default `modo`** = `completo` (el ADR decía `entrega` para entrega única): ver 4.1.
  8. **Concurrencia:** el guard corre dentro de `DB::transaction` con `Order::lockForUpdate()` sobre la raíz y
     **relee todo** (facturas activas, líneas, ítems) después de tomar el lock. Los tests de PHPUnit reproducen
     la secuencia peligrosa en ambos órdenes; la prueba con requests realmente simultáneos es de Pre-QA.
  9. **B2 no puede desplegarse sin B3.** Las facturas parciales cuelgan de la entrega y varios consumidores
     asumen una factura por pedido (`forOrder()->first()`, joins por `order_id = raíz`); con pedidos de una sola
     factura (todo lo anterior a SCRUM-929) nada cambia. Lista archivo:línea en el reporte de B2.
- **Pendientes B3 / decisiones (solo documentados; NO implementados en B2):**
  - **A-3 — RESUELTO en B3a (2026-09-19, ver 4.10).** *(Texto original:)* `CreditNoteService::executeCorreccion()` crea la
    factura de reemplazo sin `tipo` ni líneas: debe conservar `tipo`/`family_root_id`/`invoice_lines` de la que
    reemplaza. Hoy el reemplazo queda `completo` sin líneas y traba el pedido (`completoActivo()` bloquea todo);
    corrigiendo una `parcial` de la raíz con el `completo` ya emitido, choca con el índice `(order_id, tipo)` y da
    un 500.
  - **A-4** — `precio_unitario = round(base / cantidad, 2)` no siempre multiplica a `subtotal` (100.00 / 3 =
    33.33 → 99.99). Confirmar contra el esquema NUC de Digifact/MSL antes de construir el mapper de facturación
    electrónica desde `invoice_lines`.
  - **A-5 (decisión de negocio)** — con un `completo` activo, anular una `parcial` previa "libera" saldo que ya no
    se puede volver a facturar (`planEntrega`/`planCompleto` rechazan por el completo activo): cobra de menos, nunca
    de más. Aclarar si la corrección de una parcial dentro de un pedido ya "completo" debe ir solo por Corrección de
    datos (B3).
  - **A-7 (decisión de negocio)** — `AbonoService::reasociarAlFacturar()` ata TODO abono de nivel Proyecto a la
    PRIMERA factura del proyecto, que ahora puede ser una parcial chica (anticipo 500 sobre una parcial de 321: el
    excedente sale como abono de nivel Cliente y la 2ª y 3ª factura no lo ven). Confirmar con Luis/Daniela si el
    anticipo se reparte por factura o sigue atado a la primera.
  - **Performance** — `AdminContInvoiceController::index()` ejecuta `list()` dos veces por request (`list()` +
    `countInTab()`), y `list()` carga todo el histórico de entregas facturables. QA Performance debe medir
    `GET /invoices` con volumen; candidato a cachear `list()` dentro del request.
  - Consumidores de `forOrder()`/`order_id` (comisiones, incobrable, `CreditNoteService`, `OrderInvoiceController`,
    reportes fiscales): ver la lista archivo:línea del reporte de B2.
- **Resultado de B3a (Backend Dev, 2026-09-19):**
  1. **Regla central única** (4.9) para "la factura de una entrega"; Bodega (tablero por lote y detalle de una
     tarjeta) daban resultados distintos con parciales — ahora comparten `deliveryIdsWithActiveInvoice()`.
  2. **Incobrable por factura** (4.8) y **Corrección de datos con líneas** (4.10) — cierra A-3 (`executeCorreccion`
     dejaba un `completo` sin líneas que trababa el pedido y, sobre la parcial de la raíz con el completo
     emitido, un 500 por el índice).
  3. **Gerencia (`GerenciaHomeService`): verificado, sin cambios de código.** `facturado_*` suma `monto` por factura
     (Σ = total del pedido, sin doble conteo), `facturas_count` cuenta facturas emitidas, y el margen se calcula
     sobre `quote_id` DISTINTOS (una cotización facturada en 3 partes cuenta una vez; test con 25 % vs 17.5 % si
     contara por factura). Salvedad de negocio, no de datos: una parcial chica pondera la cotización ENTERA en
     el margen del período (aproximación de KPI ya existente).
  4. **Premisa que no se sostuvo — corregir el ITBMS de UNA parcial y el cierre del pedido. → FALLA CERRADO
     (decisión del Arquitecto 2026-09-19), decisión de fondo PENDIENTE de Luis/Daniela.** Al cambiar el
     tratamiento de ITBMS de una parcial (Σ facturas ≠ `card->amount` a propósito), la factura que luego CIERRA el
     pedido (o un completo posterior) toma `card->amount − Σ activas`: re-cobra en silencio la diferencia de ITBMS
     (p. ej. corregir la entrega 2 a 3.5 % baja 10.50 y el cierre los vuelve a sumar), anulando en la práctica la
     corrección. **Hasta que se decida, `CreditNoteService` RECHAZA (422, `previewCorreccion` y `registerCorreccion`,
     más una guarda en `executeCorreccion` para notas diferidas) todo cambio de TASA de ITBMS (`itbms`/`ambos`)
     sobre una factura `parcial`** con `MSG_CORRECCION_TASA_PARCIAL`: "La corrección de la tasa de ITBMS sobre una
     factura parcial no está disponible todavía; contacte a Administración." Sigue permitido: la corrección por
     FECHA sobre una parcial (mismo monto) y TODO sobre facturas `completo` (entrega única, o el completo que cubre
     el resto). Preguntas abiertas para Luis/Daniela: (a) si una corrección de ITBMS sobre una parcial debe ajustar
     también el total del pedido contra el que cierra la última factura; (b) o bloquearse mientras el pedido no esté
     cerrado. Tests: `test_b3b_corregir_la_tasa_de_itbms_de_una_parcial_se_rechaza_...` y
     `test_b3b_corregir_la_tasa_de_itbms_de_un_completo_recalcula_desde_sus_lineas`.
  5. Pendientes menores de las re-revisiones: N-1 (sin líneas negativas), N-2 (log solo al emitir), N1
     (el test del lock comprueba el nivel de transacción), N2 (2 ramas de `advertencias` con test), N4, N5, N6
     (`invoice_id` vacío = ausente), N7 (mensaje neutro) y la cota de F6 escrita una sola vez.
- Datos existentes: sin limpieza (misma postura C-8); las facturas duplicadas huérfanas de dev/test
  quedan; QA valida con pedidos **nuevos**.

## Estado
**Parcial (2026-09-19).** B0 (spike) cerrado; **B1a** implementado y aprobado por Senior Review
(`docs/reviews/scrum928-935-batchA-b1a-senior-review-20260918.md`); **B1b** (`family_root_id`/`tipo`/`invoice_lines`)
y **B2** (`InvoiceService`: modos `entrega`/`completo`, guard transaccional, listados por entrega, contrato §4)
implementados en el árbol de trabajo, sin commit; **B3a** (consumidores que corrompían datos: Bodega, incobrable,
notas de crédito, Corrección de datos, Estado de Cuenta; Gerencia verificado) implementado el 2026-09-19 — **B3b**
(libro de facturas, reporte por medio de pago, comisiones, mapper PAC) pendiente. Senior Review de B1b+B2 (2026-09-19, partes A y B): 0 CRÍTICO /
0 ALTO; sus hallazgos (F1–F8) están corregidos en el árbol y el punto 1 de "Resultado de B2" (índice
`(order_id, tipo)`) quedó RESUELTO el 2026-09-18. B3–B5 pendientes (`docs/architecture/scrum-928-935-batches.md`).
Los tests `test_876_*` que afirmaban la regla "una factura por pedido" se reescribieron como `test_929_*` (ver
`AdminContInvoiceControllerTest` y `AdminContPartialInvoicingTest`).

## Estado de B3b (retomado 2026-09-19 con las decisiones de Luis) — **COMPLETO y revisado**

B3b = consumidores de importes derivados. Los 4 puntos (Libro de Facturas/Ventas por medio de pago,
Comisiones, mapper PAC de ítems, ajuste de ITBMS en el guard/cierre) HECHOS y con tests, con las 4
decisiones de Luis ya aplicadas. **Revisión independiente completa** (`docs/reviews/scrum929-b3b-senior-review-20260919.md`,
un revisor en frío/worktree aislado, ciego al razonamiento del Arquitecto): 1 hallazgo ALTO (mismo bug
que B3a-1, `decide()` sin revalidar `anulada_at`) + 2 BAJO, los 3 cerrados el mismo día (ver "Cierre" al
final de ese doc) — incluye un guard nuevo en `correccionContext()` que corta una 2ª corrección pendiente
en el REGISTRO, no solo en la aprobación. Suite completa del backend (3594 tests/15256 assertions) y
PHPStan L8 limpios. B3a también CERRADO (ver "Cierre de la revisión" en `scrum929-b3a-senior-review-20260919.md`).
Sigue **B4/B5** (frontend, e2e, concurrencia real) — B4 en curso, ver `atlanticerp-frontend`.

**Punto 4 — recalcular el total del pedido al corregir el ITBMS de una parcial: HECHO y con tests
(2026-09-19).** Implementa la decisión de Luis (ver "Decisiones de Luis" arriba) — reemplaza por completo el
fail-closed anterior (`MSG_CORRECCION_TASA_PARCIAL`/`assertCorreccionPermitida()`, eliminados).
- Migración `2026_09_19_150000_add_ajuste_itbms_delta_to_admin_contab_invoices_table`: columna
  `ajuste_itbms_delta` (decimal 12,2, default 0) en `admin_contab_invoices`.
- `CreditNoteService::executeCorreccion()`: la factura de REEMPLAZO persiste
  `ajuste_itbms_delta = ajuste_itbms_delta_de_la_original + (nuevo_monto − monto_original)` — se
  acumula a través de correcciones encadenadas; una corrección por FECHA (no cambia la tasa) da
  delta 0 automáticamente (`correctedLines()` copia las líneas tal cual, mismo monto). No depende del
  estado ACTUAL de las líneas (que la corrección ya sobrescribió) — es la única forma de saber
  "cuánto cambió" sin la información pre-corrección.
- `InvoiceBillingPlanner::context()`/`lightContext()`: suman `ajuste_itbms_delta` de las facturas
  ACTIVAS de la familia (`ajusteItbms`) y lo aplican a `BillingContext::total` (antes `card->amount`
  crudo) — así el guard anti-duplicado, `restante()`, el monto del cierre y el listado
  (`monto_total_pedido`/`restante`) quedan consistentes entre sí.
- `InvoiceBillingPlanner::cerrarPedido()`: el objetivo de ITBMS que reconcilia el residual de
  redondeo pasa de `Quote::itbms()` a `Quote::itbms() + ajusteItbms` — sin este segundo ajuste (a
  nivel de LÍNEAS, no de `monto`) la factura que cierra seguía viendo un "descuadre contra la
  cotización" y le devolvía a sus líneas la diferencia que la corrección acababa de quitar,
  recobrándola en silencio pese al ajuste de `total`.
- Test end-to-end en `AdminContPartialInvoicingConsumersTest` (familia de 963, 3×321 @ 7%):
  corregir la entrega 2 a 3.5% (delta −10.50, monto 310.50) YA NO se rechaza; la entrega 3, que
  cierra el pedido, cobra exactamente 321.00 (no 331.50) sin advertencia espuria, y Σ final =
  952.50 (963 − 10.50) — ni se pierde el ajuste ni se recobra en silencio. 612 tests/3301
  assertions en `tests/Feature/AdminCont`, **3584 tests/15190 assertions en la suite COMPLETA del
  backend, todos verdes**, PHPStan L8 limpio.
- Sin resolver todavía (fuera de alcance de este punto): A-4, el código DGI para certificar en FE una
  línea con la tasa "3.5% retención" — el mapper de facturación electrónica (Punto 3) sigue sin poder
  emitir esa línea al PAC.

**Punto 1 — Libro de Facturas / Ventas por medio de pago: HECHO, con tests (2026-09-19).**
- Nuevo `Services/Billing/InvoiceTaxBreakdown::of(invoice, quote)`: base/ITBMS de una factura = Σ `invoice_lines`
  (`subtotal`/`itbms`) si las tiene; si no (legadas) `Quote::netTotal()/itbms()`; si no hay cotización, la división.
- `InvoiceBookService::facturaRows()` y `PaymentMethodSalesReportService::report()` lo usan (`with('itbmsRate',
  'lines')`; solo las facturas sin líneas cargan la cotización). Las filas de NC no cambian (se calculan desde su
  `monto` y la tasa de la factura de origen, independientes de la cotización).
- Tests nuevos en `AdminContPartialInvoicingConsumersTest` (sección "4b"): familia de 3 parciales → Σ base/ITBMS
  del libro == la cotización entera (900/63) y no ×3; parcial + completo sin duplicar; entrega única
  13.50/0.95/14.45 sin cambio; factura corregida por fecha conserva base/ITBMS de SUS líneas (300/21, no la
  cotización entera); Ventas por medio de pago de una parcial cobrada no sobrestima (300/21/321, no 900/63/963);
  NC sobre una parcial sigue saliendo de su propio `monto`/`subtotal`/`itbms`, sin verse afectada por el cambio de
  fuente. `AdminContInvoiceBookControllerTest`/`AdminContPaymentMethodSalesControllerTest` (preexistentes, no
  usan `invoice_lines`) siguen en verde. 118 tests / 971 assertions en los 5 archivos de SCRUM-929, PHPStan L8
  limpio.
  Observación aparte (preexistente, no tocada): un reemplazo LEGADO de "Corrección de datos" con otra tasa muestra en
  el libro la base/ITBMS de la cotización a la tasa por defecto, no la corregida; los reemplazos con líneas ya no
  tienen el problema.
  Pendiente menor, no bloqueante: no se verificó a mano el Excel (`InvoiceBookExportService`/
  `PaymentMethodSalesExportService`) con datos de una familia parcial — los tests de export existentes
  (`test_935_excel_...`) siguen sobre facturas legadas sin `invoice_lines` y ya estaban en verde.

**Punto 2 — Comisiones (`ArchitectCommissionService`, `CommissionOrderService`): HECHO, con tests
(2026-09-19).** Implementa la "Propuesta conservadora" confirmada por Luis (ver "Decisiones de Luis" arriba).
- `rawProjects()`/`rawOrders()`: el `leftJoin` directo contra `invoices` (`inv.order_id = o.id`) se reemplaza
  por un `leftJoinSub` agregando por `family_root_id` (`sum(monto)`/`sum(saldo_aplicado)`, solo activas) — UNA
  fila por pedido/proyecto, viendo las parciales de entregas hijas y sin fan-out cuando hay una parcial Y un
  completo en la raíz (antes duplicaba `monto_proyecto`/`total_pedido` en cualquier suma aguas abajo).
  `factura_numero`/`fecha_factura` (solo display) pasan a ser los de la factura MÁS RECIENTE de la familia
  (antes había una sola candidata posible). `total_nota_credito`/`nota_credito_numeros` (Internas) y
  `fecha_cobro_completo` (ambos servicios) se recalculan contra CUALQUIER factura activa de la familia, no
  solo la que antes era única.
- `estadoFor()` de ambos servicios gana un 4° parámetro (monto TOTAL del pedido/proyecto:
  `montoProyecto`/`totalPedidoCompleto`) y exige `Σ facturado ≥ ese monto` (tolerancia 0.005, épsilon de
  redondeo) ADEMÁS de `cobrado ≥ facturado` antes de generar/estimar la comisión — el "todo o nada" ahora es
  por PEDIDO, no por lo facturado hasta el momento. Cierra el bug real: un proyecto de 963 con solo la
  entrega 1 (321) facturada y cobrada generaba antes la comisión externa completa (96.30 al 10%) y hacía
  retroceder una comisión interna ya "pagada" al emitirse la 2ª factura.
- Tests: unitarios de `estadoFor()` actualizados (4° arg) + 2 nuevos que reproducen el bug exacto (proyecto de
  963/321, externa é interna) en `AdminContArchitectCommissionControllerTest`/
  `AdminContInternalCommissionControllerTest`; 2 tests de integración end-to-end nuevos en
  `AdminContPartialInvoicingConsumersTest` (sección "5b") que facturan/cobran vía la API real (`makePedido`/
  `facturar`) y verifican `allProjects()`/`ordersForVendor()` directamente: con 1/3 cobrado no generan
  comisión y `total_facturado` no se duplica (321, no más); al completar el pedido, `total_facturado` = 963
  exacto y la comisión pasa a generada. 612 tests / 3298 assertions en todo `tests/Feature/AdminCont`,
  PHPStan L8 limpio.

**Punto 3 — Mapper de facturación electrónica (ítems): HECHO y con tests (2026-09-19).**
`App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentItemsBuilder::build(AdminContInvoice):
list<FiscalDocumentItem>` — el builder de ítems que faltaba, único punto de esta capa que lee
`AdminContInvoice`/`invoice_lines`.
- Fuente: `invoice_lines` de la factura si las tiene (una `parcial` solo arma ítems de lo que salió de SU
  entrega, ITBMS por línea vía `ItbmsCodeMapper` con la tasa de CADA línea — nuevas relaciones
  `AdminContInvoiceLine::itbmsRate()`/`::quoteItem()`); si no (legadas, previas a SCRUM-929), los ítems de la
  cotización completa a la tasa ÚNICA de la factura (`itbms_rate_id`).
- `precioUnitario = subtotal / cantidad` recalculado SIN redondear (nunca el `precio_unitario` PERSISTIDO en
  la línea, que sí está redondeado a 2 decimales) — cierra A-4 para este camino: `cantidad × precioUnitario −
  descuento == subtotal` al centavo, verificado end-to-end contra `NucJsonMapper::mapItems()`/`Totals.TotalItem`
  en el test de integración. `descuento` siempre 0 (ya está aplicado dentro de `subtotal`).
- La tasa "3.5 % retención" sigue sin código DGI (`ItbmsCodeMapper` lanza `UnmappableItbmsRateException`,
  sin cambios) — una línea con esa tasa sigue sin ser certificable; probado explícitamente.
- **Alcance deliberadamente acotado a los ÍTEMS** (lo que el batch plan pide: "mapper de facturación
  electrónica desde invoice_lines"), no al `FiscalDocument` completo (emisor/receptor/pagos/referencia,
  selección de PAC) — confirmado 2026-09-19 que ese ensamblado no tiene NINGÚN wiring real en ningún lugar
  del código (cero `new FiscalDocument(...)` fuera de tests de esta capa); es un alcance más amplio, sin
  ticket, fuera de SCRUM-929.
- ⚠️ **Hallazgo de higiene de docs (mismo patrón que el threat model de Epic 11 y el de
  bodega-admincont-fraude):** varios docblocks de esta capa (`FiscalParty`, `FiscalDocumentReference`,
  `ItbmsCodeMapper`, `NucJsonMapper`) citan `docs/architecture/facturacion-electronica-pac-diseno.md`
  como si existiera y estuviera vigente — **ese archivo no existe**, ni en el working tree ni en el
  historial de git de ningún commit. Solo existe `docs/architecture/facturacion-electronica-adapter-
  digifact-notas.md` (61 líneas, alcance parcial). No bloqueante para este punto (el código y sus tests
  no dependen del documento), pero queda como deuda de Tech Writer — no corregido en esta sesión.
- Tests: `tests/Feature/AdminCont/FacturacionElectronica/FiscalDocumentItemsBuilderTest.php` (6 casos:
  parcial vs pedido entero, precio sin redondear, completo normal, legada sin líneas, rechazo de la tasa
  de retención, integración con `NucJsonMapper`). **3590 tests/15218 assertions en la suite COMPLETA del
  backend, todos verdes**, PHPStan L8 limpio.

### Análisis de comisiones (insumo para la decisión; ningún código escrito)

Cómo se calculan HOY:
- **Internas (`CommissionOrderService::rawOrders`)**: una fila por (tarjeta de pipeline aprobada, vendedor). El %
  sale de la tabla escalonada `commission_tiers` aplicada al Σ de `total_pedido` (= `pipeline_cards.amount`, dividido
  entre responsables si es compartido) de los pedidos del vendedor con el mismo `mes_cierre` — NO depende de las
  facturas ni de cuántas haya, así que los escalones son no lineales pero no rompen D-4. La base de cada pedido es
  `total_cobrado` (o `total_facturado` como estimado mientras está `pendiente_cobro`) menos NC aplicadas, dividida
  entre responsables; comisión = base × %. Estado "todo o nada" (`estadoFor`): `pendiente_cobro` mientras
  `cobrado < facturado`; `por_pagar`/`pagado` según el mes de cierre. Los snapshots mensuales
  (`CommissionMonthSnapshot`) congelan `total_pedidos × %` por mes cerrado (también independiente de las facturas).
- **Externas (`ArchitectCommissionService::rawProjects`)**: una fila por proyecto con arquitecto; comisión =
  `monto_proyecto` (= `card->amount`) × % (10 % por defecto o el aprobado por Mark), NO depende de las facturas; las
  facturas solo deciden el estado (`aun_no_generada` mientras `cobrado < facturado`). Hay UNA cuenta de cobro y UNA
  fecha de pago persistidas por proyecto (`ArchitectCommission`, por `pipeline_card_id`).

Qué está roto con varias facturas por pedido (independiente de la decisión de negocio):
- El join `inv.order_id = o.id` con `o.family_id IS NULL` (solo la raíz): (i) no ve las parciales de entregas hijas
  (`total_facturado` queda corto); (ii) con parcial + completo en la raíz devuelve DOS filas por (pedido, vendedor):
  duplica `total_pedido` en el Σ del mes (**infla el % de escalón**) y duplica la comisión externa.

Qué es ambiguo (decisión de negocio, NO improvisada): D-4 dice "comisión por factura sobre su monto proporcional; la
suma por proyecto == la del pedido completo". El MONTO es lineal y la suma coincide sin problema (base = Σ cobrado,
% del mes sobre `card->amount`). El problema es el CUÁNDO:
- *Externas.* La comisión es sobre el proyecto entero y se gestiona con UNA cuenta de cobro/fecha de pago: no admite
  devengarse factura por factura sin cambiar el modelo. Caso concreto: proyecto de 963 (3 entregas), comisión 10 % =
  96.30; si solo la entrega 1 (321) está facturada y cobrada, "cobrado ≥ facturado" ya da `pendiente_factura_arquitecto`
  y el arquitecto podría emitir la cuenta de cobro por los 96.30 completos con 2/3 del proyecto sin cobrar. Antes de
  SCRUM-929 esto no pasaba porque `facturado == monto_proyecto` (una factura por el total).
- *Internas.* Con la regla actual agregada (Σ cobrado vs Σ facturado), tras cobrar la 1ª parcial el pedido pasa a
  `por_pagar`/`pagado` con base parcial y, al emitirse la 2ª factura, vuelve a `pendiente_cobro`: una comisión ya
  "pagada" retrocede.
Propuesta conservadora (a confirmar por Luis, no implementada): agregar las facturas activas por
`family_root_id` (Σ facturado, Σ cobrado, Σ NC; sin fan-out y viendo parciales de hijas y el completo) manteniendo UNA
fila por pedido/proyecto (el frontend depende de esa forma), y — como en el modelo anterior — exigir además, cuando
el pedido tiene alguna factura `parcial`, que Σ facturado ≥ `card->amount` (pedido completamente facturado) antes de
generar/estimar la comisión: así el "todo o nada" del pedido se preserva y Σ por factura == comisión del pedido
completo al terminar. La alternativa literal de D-4 (devengar por factura cobrada) exige decidir cómo se emite la
cuenta de cobro del arquitecto por partes y cómo se liquida la interna por partes.

## Decisiones de Luis (2026-09-19, retomando la sesión detenida)

1. **Comisiones — CONFIRMADA la propuesta conservadora de arriba.** Por pedido completo: agregar por
   `family_root_id`, una fila por pedido/proyecto, y exigir Σ facturado ≥ `card->amount` antes de generar/estimar
   la comisión (externa o interna). Se descarta devengar por factura individual — evita el caso concreto del
   proyecto de 963 pagando 96.30 completos con solo la entrega 1 (321) cobrada.
2. **ITBMS de una parcial — se recalcula el total del pedido automáticamente**, respuesta (a) de las dos preguntas
   abiertas del punto 4 de "Resultado de B3a". Diseño de la mecánica (decisión técnica dentro de este alcance, la
   resuelve el Arquitecto): el guard y el cierre dejan de comparar contra `card->amount` a secas y comparan contra
   un **total ajustado** = `card->amount + Σ deltas de ITBMS de correcciones confirmadas` sobre facturas activas de
   la familia (delta = ITBMS nuevo − ITBMS original de las líneas corregidas, con signo). La factura que cierra el
   pedido (o un `completo` posterior) toma `total_ajustado − Σ activas` en vez de `card->amount − Σ activas`, así
   la corrección deja de recobrarse en silencio. El delta debe quedar persistido y auditable (no recalculado desde
   el estado actual de las líneas, que ya fue sobrescrito por la corrección) — implementar antes de levantar el
   rechazo 422 de `MSG_CORRECCION_TASA_PARCIAL` sobre `parcial`. Sin tocar `card->amount` en sí (sigue siendo el
   monto cotizado/aprobado, fuente de verdad para Gerencia y comisiones).
3. **Abono de proyecto atado a la primera factura — se deja como está.** El excedente sobre una parcial chica sigue
   pasando a saldo del cliente, sin reasociación automática. No requiere cambios de código para B3b.
4. **Retención 3,5 % — paramétrica, se use o no hoy en facturas reales** (Luis no tiene certeza de uso, pero la
   regla es independiente de eso: ver "Nunca" de `../../CLAUDE.md` sobre umbrales/porcentajes de negocio
   hardcodeados). **Verificado 2026-09-19: ya lo es, no hace falta construir nada.** `admin_contab_itbms_rates`
   (SCRUM-635/636, REQ-558/559) es el catálogo completo (7 % general, 3.5 % retención, 0 % exento) con CRUD real
   (`AdminContItbmsRateController`, gateado por `EnsureIsPrimaryApprover`) y pantalla de administración
   (`FiscalConfigPage.tsx`/`ItbmsRateDetailModal.tsx`) — el 3.5 % vive como fila editable, no como constante en
   código. Sigue sin resolver A-4 por sí solo (código DGI para certificar en FE): mientras no haya código DGI
   mapeable, una línea con esta tasa sigue sin ser certificable — eso es un tema de mapeo con el PAC, no de
   parametrización.

