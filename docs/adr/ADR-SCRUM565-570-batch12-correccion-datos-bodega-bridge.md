# ADR: Batch 12 Notas Crédito y Devoluciones — submit real de "Corrección de datos" + puente Bodega→Admin&Cont (SCRUM-565→570)

**Status:** Approved — PM puede desglosar, Backend Dev puede implementar
**Fecha:** 2026-08-24
**Autor:** Arquitecto
**Decisión de Luis:** puente Bodega vía query cross-schema directa + llamada explícita a un servicio
de Bodega (no evento de dominio) — confirmado en sesión, ver hilo.

## Contexto

Batch 10/11 dejaron dos huecos deliberados, documentados en su propio código:

1. `RegisterCreditNoteRequest` rechaza con 422 el tipo `correccion` — su submit real (revisión
   previa + vista previa de factura nueva + aprobación diferida >$5,000) es explícitamente Batch 12
   (comentario en `CreditNoteService::register()`, línea ~107).
2. `AdminContCreditNote.devolucion_bodega_id` existe como columna desde Batch 10/11 pero es un
   `int` nullable sin ninguna query real contra Bodega — nadie construyó la cola (REQ-491) ni el
   cierre del círculo. `CustomerReturn::$credit_note_notified_at` (Bodega, SCRUM-473→489) documenta
   en su propio docblock que hoy "simula el aviso a Administración & Contabilidad (módulo que no
   existe todavía)" — ya existe, este batch lo conecta de verdad.

Investigación de código confirmada archivo por archivo (no asumida):

- `AdminContInvoice.order_id` ya referencia el `Order` de Bodega (mismo id que usa
  `CustomerReturn.order_id`) — no hace falta ninguna tabla de mapeo nueva, el join es directo por
  `order_id`.
- `CustomerReturn::STATUS_ESPERANDO_NOTA_CREDITO` es el estado real tras `confirmReception()`
  (`CustomerReturnService.php:293`) — no existe hoy ningún método que lo cierre; `reject()` es el
  único "de salida" que existe (línea 308).
- `AdminContInvoice.anulada_at` existe en el modelo/migración pero **nunca se escribe en ningún
  lado del código** — Batch 11 (`anulacion_completa` subtipo `cancelado`) reduce saldo vía
  `saldo_aplicado`, no marca `anulada_at`. Este batch es el primer escritor real de ese campo.
- No existe ningún método `approve()`/`reject()` de notas pendientes de aprobación todavía — eso es
  Batch 13 (REQ-494, exclusivo Aprobador) explícito en comentario de `CreditNoteService.php:183`. El
  mismo patrón aplica a `correccion`: Batch 12 registra y, si califica, ejecuta de inmediato; si
  queda pendiente, Batch 13 la ejecuta al aprobar — mismo contrato que los otros 3 tipos.
- `AdminContInvoiceFolioService::next()` ya existe y es el mismo folio usado por Facturación
  (Batch 2) — se reusa, no se crea un folio paralelo para "facturas de corrección".
- "Avisos automáticos" (Batch 11, REQ-482) no dispara ninguna notificación real hoy — es banner de
  UI únicamente (confirmado: cero referencias a notificación en
  `AdminContCreditNoteController.php`). Este batch sigue el mismo criterio para "se notifica al
  cliente" (REQ-490 RN3) — banner, no envío real. Si Luis quiere un envío real, es un ticket aparte.

## Decisión

### 1. Puente Bodega→Admin&Cont (REQ-491)

**Lectura de la cola** — nuevo método en `CustomerReturnService` (o un servicio de lectura propio
de Admin&Cont, a criterio de Backend Dev), cross-schema, mismo patrón de precedente que el bridge
Compras↔Bodega (ADR-SCRUM406):

```sql
CustomerReturn::where('status', STATUS_ESPERANDO_NOTA_CREDITO)
  ->whereDoesntHave(-- no tiene ya una AdminContCreditNote con este devolucion_bodega_id --)
  ->join AdminContInvoice on order_id  -- para cliente/master_client_id/numero de factura
```

Cada fila expone: `customer_return_id`, `return_number`, `cliente`/`master_client_id`,
`factura_origen_id` (vía `order_id`→`AdminContInvoice`), productos (`CustomerReturnLine`), fecha de
confirmación. Se mezcla con las notas reales en el mismo endpoint de historial (REQ-492) — filas
"virtuales" con `estado_derivado = 'pendiente_generar_nota'`, sin fila propia en
`admin_contab.credit_notes` hasta que se genera.

**Cierre del círculo** — al registrar una nota con `devolucion_bodega_id` no nulo (flujo ya
prellenado desde Batch 10, "devolución iniciada desde Bodega"), `CreditNoteService::register()`
llama, dentro de la MISMA transacción de Postgres (ambos schemas viven en la misma instancia — ya
es la convención del proyecto, ver `schema: legacy` y Compras↔Bodega), a un método nuevo:

```php
// Bodega\Services\CustomerReturnService
public function finalizeForCreditNote(int $customerReturnId, string $creditNoteNumero, User $actor): void
```

Setea `status = STATUS_FINALIZADO`, `finalized_at = now()`. Llamada síncrona y directa (no
evento/listener) — decisión explícita de Luis: acá el efecto es 1:1 e inmediato al registrar la
nota, a diferencia del bridge de stock (SCRUM-406) que reacciona a un evento de OTRO flujo
(recepción de compra) sin que Compras necesite saber si Bodega tiene algo esperando. Si
`finalizeForCreditNote()` falla, la transacción completa revierte (a diferencia del try/catch de
SCRUM-406) — acá SÍ debe ser atómico: no puede existir una nota de crédito real sin que la
devolución que la origina quede cerrada, ni viceversa.

Una vez generada la nota, la fila deja de aparecer en la cola (RN3 de REQ-491) — automático, ya que
la query de arriba excluye devoluciones con nota ya vinculada.

### 2. Submit real de "Corrección de datos" (REQ-488, 489, 490)

Nuevo método en `CreditNoteService`, `registerCorreccion()` (paralelo a `register()`, no lo
extiende — el flujo es fiscal, distinto de aplicar saldo/excedente de los otros 3 tipos):

1. **Validaciones (REQ-488):** cliente, factura de origen, motivo, y — específico de este tipo —
   `nuevo_tratamiento_itbms_rate_id` o fecha corregida (según qué corrige la nota), ya validados en
   el form request existente desde Batch 10 (`motivo_correccion`,
   `nuevo_tratamiento_itbms_rate_id`) — reusar, no rehacer.
2. **Revisión previa (REQ-489):** endpoint de solo-lectura que arma las tarjetas (factura a anular,
   proyecto, cotización, Guía de Entrega vía `order()`, nota a generar, motivo, corrección) +
   `GET .../correccion/preview` que devuelve el documento de factura nueva reusando el MISMO
   renderer que Facturación (`InvoiceService`/vista de factura — buscar el componente compartido de
   documento fiscal, no duplicar markup) con los productos de la factura original sin cambios y
   el ITBMS/fecha ya corregidos. Nada se persiste en este paso (RN1).
3. **Umbral y ejecución diferida (REQ-490):** al confirmar,
   - monto ≤ `markApprovalThreshold()` (mismo singleton reusado desde Batch 11, sin duplicar): se
     ejecuta TODO de inmediato dentro de la transacción — `anulada_at = now()` en la factura
     original, nueva `AdminContInvoice` vía `AdminContInvoiceFolioService::next()` con los mismos
     `order_id`/`quote_id`/productos/monto de la original salvo el dato corregido, `estado =
     APLICADA` en la nota.
   - monto > threshold: se persiste la nota con `estado = PENDIENTE_APROBACION` y TODO el payload
     necesario para ejecutar después (mismo shape que el paso anterior, guardado en la nota — no en
     una tabla temporal aparte), **sin** tocar `anulada_at` ni generar folio todavía (RN1/RN2). La
     ejecución real al aprobar es Batch 13 — dejar la lógica de "ejecutar corrección" en un método
     privado/protegido reusable (`executeCorreccion(AdminContCreditNote $note)`) para que Batch 13
     solo tenga que invocarlo desde su `approve()`, sin reimplementar nada.
   - Rechazo (Batch 13): no toca la factura original en absoluto — ya es el comportamiento por
     default de no haber ejecutado nada todavía (RN4), no requiere código nuevo en este batch más
     allá de dejar el estado `RECHAZADA` disponible (ya existe en el modelo).

### 3. Historial combinado + filtros (REQ-492) y modal de detalle (REQ-493)

**Corrección sobre el borrador anterior de este ADR:** verificado en código — no existe ningún
endpoint `index`/`show` en `AdminContCreditNoteController` (solo `summary`, `itbmsRates`,
`itbmsTreatment`, `facturas`, `store`), y `NotasCreditoPage.tsx` solo tiene un placeholder de texto
(`t('notasCredito.historialPendiente')`) donde va la tabla. REQ-492/493 son endpoints y pantalla
**nuevos de cero**, no una extensión de algo ya construido — dimensionar el ticket de Frontend Dev
en consecuencia (tabla completa + filtros + modal, no un ajuste).

- **Backend:** `GET /notas-credito` (paginado, filtros `search`/`cliente`/`tipo`/`estado`) — arma la
  lista combinando `AdminContCreditNote` real + las filas virtuales de la cola de Bodega (punto 1),
  cada fila con un `estado` derivado (`aplicada`/`pendiente_aprobacion`/`pendiente_generar_nota`/
  `rechazada`, RN2 de REQ-492). `GET /notas-credito/{id}` — detalle; si `devolucion_bodega_id` no es
  null, adjunta el payload de trazabilidad ya expuesto por `CustomerReturnService::historyPayload()`
  (reusar, no reconstruir).
- **Frontend:** tabla nueva en `NotasCreditoPage.tsx` (reemplaza el placeholder), barra de filtros,
  modal de detalle nuevo. Botones "Aprobar"/"Rechazar" se renderizan cuando
  `estado = PENDIENTE_APROBACION` (RN4) pero quedan sin acción real conectada todavía — mismo
  criterio que Batch 10 dejó "Registrar nota" deshabilitado hasta Batch 11.

## Addendum — Backend Dev, 2026-08-24 (confirmado por Arquitecto)

Implementación completa, gate corrido: PHPStan `--memory-limit=1G` limpio, `infra/test.sh` completo
2550 tests / 10315 assertions OK. Desvíos reales del diseño original, todos confirmados en código:

1. `tipo` de "Corrección de datos" es `anulacion_completa` + `subtipo_anulacion=correccion` (schema
   ya existente desde Batch 10) — el borrador original de este ADR asumía un `tipo='correccion'`
   propio, incorrecto. Frontend Dev notificado y corrigiendo.
2. `GET /notas-credito` sin paginación (`{data:[...]}` sin `meta`) — consistente con
   `InvoiceService::list()`, que tampoco pagina.
3. Endpoint adicional `GET /notas-credito/devoluciones/{customerReturnId}` — las rutas propias de
   Bodega exigen `permission:bodega.read`, que los roles de Admin&Cont no tienen; necesario para el
   detalle de una fila de cola antes de precargar el registro.
4. **Invariante de schema relajado** (`admin_contab.invoices`, migración
   `2026_08_24_100002_relax_order_id_unique_on_admin_contab_invoices_table.php`): el `unique(order_id)`
   original de Batch 2 nunca contempló que una "Corrección de datos" deja 2 filas para el mismo
   `Order` (la anulada + su reemplazo). Reemplazado por índice único PARCIAL
   (`WHERE anulada_at IS NULL`) — preserva "a lo sumo una factura ACTIVA por Order", permite que la
   anulada conviva con la nueva. Todos los call sites que asumían una sola fila por `order_id`
   auditados y corregidos (`InvoiceService::confirm/proposeUncollectible/decideUncollectible/detail/list`,
   `CreditNoteService`) — `detail()`/`list()` usan `latest('created_at')`/`orderBy('id')->keyBy()`
   deliberadamente para que la fila vigente gane, con comentario inline explicando por qué. **Arquitecto
   revisó y aprueba** — down-migration correcta, invariante real preservado, sin regresión en la
   suite completa.
5. `InvoiceService::fiscalBreakdown()` cambiada de `private` a `public static` (solo visibilidad) para
   reusar en la vista previa de corrección.

## Consecuencias

- Primer escritor real de `AdminContInvoice.anulada_at` — verificar que ningún query existente que
  hoy asume "nunca hay anuladas" (reportes, Estado de Cuenta) se rompa al empezar a aparecer una.
  Correr suite completa de Facturación/Estado de Cuenta en el gate, no solo Notas Crédito.
- Nuevo método público en `Bodega\Services\CustomerReturnService` invocado desde `AdminCont` — cruza
  el límite de módulo con una llamada de servicio directa (no HTTP interno, mismo patrón que el
  resto del monolito modular). Documentar en la sección "Eventos de dominio" de `atlanticerp/CLAUDE.md`
  que este SÍ es cross-module síncrono, no event-driven, para no confundirlo con el resto de
  entradas de esa tabla.
- Actualizar `atlanticerp/CLAUDE.md` (schema `admin_contab`/`servicios`/eventos) y
  `docs/architecture/admin-contab-batches.md` al cerrar el batch, como siempre.
