# ADR: Batch 13 Notas Crédito y Devoluciones — aprobación de Aprobador, comprobante, documento formal, factura relacionada (SCRUM-571→574 + SCRUM-787)

**Estado:** Propuesto — pendiente de aprobación de Luis antes de dispatchar implementación.
**Épica:** Admin&Cont (SCRUM-331). **Tickets:** SCRUM-571→574 (REQ-494→497), + SCRUM-787 (fix de
timezone, bundleado a pedido de la sesión previa — ver
`memory/project_admincont_batch12_cerrado_batch13_siguiente_20260824.md`).

## Contexto

Batches 10-12 dejaron toda la plumbing de este batch ya provisionada a propósito: columna `estado`
(`aplicada`/`pendiente_aprobacion`/`rechazada`), `aprobado_por`/`fecha_decision`/`motivo_rechazo`,
`comprobante_url`, middleware `mark_only` (ya usado en Facturación para el mismo patrón de
"decisión exclusiva de Aprobador", ver `AdminContInvoiceController::uncollectibleDecision`),
`markApprovalThreshold()`, el flag `puede_aprobar_rechazar` en `show()`, y los botones
"Aprobar"/"Rechazar" en `DetalleNotaCreditoModal.tsx` ya renderizados pero `disabled` con el hint
"próximo batch". Este batch conecta esa acción real y agrega 3 vistas nuevas (comprobante,
documento formal, factura relacionada) que hoy no tienen ningún punto de entrada en la UI.

## Decisión

### 1. Aprobar/Rechazar — REQ-494

`PUT /api/admin-contab/notas-credito/{creditNote}/decision`, `{approve: bool, motivo_rechazo?:
string}`, bajo `Route::middleware('mark_only')->prefix('notas-credito')` — mismo patrón exacto que
`invoices/{order}/uncollectible-decision`, no un mecanismo nuevo.

`CreditNoteService::decide(int $id, bool $approve, ?string $motivoRechazo, User $actor)`:
- Lock de fila en transacción. Si `estado !== pendiente_aprobacion` → `CreditNoteStateException`
  (nueva, mismo patrón que `InvoiceStateException`), 422.
- **RN2 (bloqueo por falta de comprobante):** el predicado "esta nota requiere comprobante" YA
  existe duplicado en `RegisterCreditNoteRequest::rules()` (línea ~57-58): `tipo !==
  devolucion_mercancia && (tipo === anulacion_completa || monto > threshold)` — la devolución NUNCA
  lo exige, sin importar monto (REQ-487 RN1/RN2). Extraer ese predicado a un método reusable (ej.
  `AdminContCreditNote::requiresComprobante(threshold)`) y usarlo acá tal cual — replicar la regla
  a mano en el gate de aprobación sería divergir de la fuente de verdad si algún día cambia.
- **Aprobar:**
  - Si es "Corrección de datos" pendiente (`correccion_pendiente_aprobacion`) → invoca
    `executeCorreccion()`, ya escrita en Batch 12 explícitamente para ser reusada acá (ver su
    docblock) — no reimplementar.
  - Si es un tipo normal (descuento/devolución/anulación completa vía `register()`) → el bloque que
    aplica saldo/movimiento bancario/saldo a favor hoy vive inline dentro de `register()`, gateado
    por `if ($estado === APLICADA)`. Extraerlo a un método privado reusable (ej.
    `applyToBalance(note, facturaOrigen)`) invocado desde `register()` (caso inmediato) y desde acá
    (caso diferido) — el payload (monto/resultado/cuenta_bancaria_salida_id/excedente) ya quedó
    persistido en la nota al crearla, no hay que recalcular nada.
  - Setea `estado=aplicada`, `aprobado_por=actor->id`, `fecha_decision=now()`.
- **Rechazar:** `motivo_rechazo` obligatorio (validar en el Form Request del endpoint, no en el
  service). `estado=rechazada`, mismos `aprobado_por`/`fecha_decision`. RN5 (no genera factura
  nueva ni mueve dinero) es gratis — simplemente no se llama ni `executeCorreccion()` ni
  `applyToBalance()`.

**⚠️ Gap real encontrado en el diseño, no solo código nuevo de Batch 13:** RN1 de REQ-494 exige que
"cualquier Anulación completa" quede pendiente de aprobación sin importar el monto. El `register()`
actual (Batch 11) solo evalúa `monto > threshold`, sin excepción para `tipo ===
anulacion_completa` — una anulación completa de una factura chica ($200, por ejemplo) hoy queda
`aplicada` directo. Esto es un fix a lógica ya en `dev`/`test` desde Batch 11, no solo código nuevo:

```php
$estado = ($monto > $threshold || $tipo === AdminContCreditNote::TIPO_ANULACION_COMPLETA)
    ? AdminContCreditNote::ESTADO_PENDIENTE_APROBACION
    : AdminContCreditNote::ESTADO_APLICADA;
```

Scope acotado: solo aplica a `register()` (tipo "Anulación completa" elegido por el usuario,
subtipo `cancelado`). `registerCorreccion()` (subtipo `correccion`, REQ-490) ya tiene su propia
regla intencional de solo-umbral y queda sin tocar. Senior Review debe confirmar este cambio
explícitamente — no es solo "código nuevo", es un cambio de comportamiento de Batch 11 en
producción/dev. Pre-QA debe re-probar el camino "Anulación completa de monto bajo" además de los
escenarios nuevos de Batch 13.

### 2. Ver comprobante — REQ-495

`GET /notas-credito/{creditNote}/comprobante` — gate de rol estándar (Contabilidad/Asistente/Aprobador/Gerencia),
no `mark_only`. Reusa `AdminContCreditNoteAttachmentService::presignedUrl()` (ya existe). Respuesta:
`{tiene_comprobante: true, url, subido_por, fecha}` o `{tiene_comprobante: false}` (RN2, mensaje
explícito en frontend). `subido_por`/`fecha` reusan `registrado_por`/`fecha` de la nota — el
comprobante solo se sube una vez, al registrar la nota (register/registerCorreccion), no hay flujo
de re-adjuntar; no hace falta una columna de tracking separada.

### 3. Ver documento (Nota de Crédito formal) — REQ-496

Mismo patrón que `InvoicePdfService`/`PaymentReceiptPdfService` (dompdf, logo vía `PdfLogo::dataUri()`
— nunca `<svg>` inline, ver gotcha ya documentado): nuevo `CreditNotePdfService` + blade
`admin-cont.credit-note-pdf`, `GET /notas-credito/{creditNote}/pdf` (gate de rol estándar). RN1: los
mismos datos que ya devuelve `show()` (empresa, cliente, tipo, destino del monto, cuenta de salida,
factura relacionada, motivo, desglose). RN3: el estado real siempre visible en el documento —
"Rechazada" se muestra como tal, nunca aparentando estar aplicada (basta un badge de estado en la
plantilla, no hace falta la marca de agua tipo "ANULADA" de facturas).

RN2 (enlace clicable a la factura relacionada) se resuelve en la vista en pantalla (modal
`DocumentoNotaCreditoModal` en React, reusando el mismo payload de `show()`), no en el PDF estático
— el número de factura ahí es clicable y abre la vista de REQ-497 sin salir del documento. El botón
"Descargar PDF" dentro de ese modal pega al endpoint de arriba como artefacto complementario.

### 4. Ver factura relacionada — REQ-497

RN1 exige el mismo formato exacto que cualquier factura del sistema — no una versión resumida.
Ya existe `GET /invoices/{order}/detail` (Facturación, Batch 4) y el componente React que lo
renderiza. Backend Dev confirma que `factura_origen_id` de la nota resuelve directo contra ese
mismo endpoint (misma tabla `AdminContInvoice`); Frontend Dev reusa el componente de detalle de
factura YA EXISTENTE, abierto en un modal desde Notas Crédito — no se construye una vista nueva ni
resumida.

### 5. SCRUM-787 — `parseDateOnly()` compartido (bundleado, no bloqueante)

Helper compartido (ubicación a criterio de Frontend Dev, ej. `src/utils/dates.ts`) que parsea un
string "date-only" (`"2026-08-24"`) como fecha LOCAL, no UTC — reemplaza el `formatDate()`
duplicado en `HistorialNotasCreditoPanel.tsx`, `DetalleNotaCreditoModal.tsx`,
`HistorialCobrosPanel.tsx` y `DetalleCobroModal.tsx` (Cobros, mismo patrón ya en producción). Se
corre junto con este batch para no abrir un ciclo de gate aparte solo por esto — no bloquea ningún
criterio de aceptación de REQ-494→497.

## Consecuencias

- El cambio de `register()` (punto 1) es la única pieza que toca comportamiento ya desplegado —
  requiere correr la suite completa (`infra/test.sh` + `npm run test`, no scoped) antes de Senior
  Review, y que Pre-QA incluya explícitamente el camino "Anulación completa, monto bajo" en su
  checklist, no solo los 4 REQ nuevos.
- `applyToBalance()` y `requiresComprobante()` quedan como los dos puntos de extracción obligatorios
  para no duplicar lógica entre `register()`/`decide()` y entre el Form Request/`decide()`
  respectivamente (regla global de "buscar código reutilizable antes de crear nuevo").
- Sin dependencias nuevas de infraestructura — todo el mecanismo (mark_only, presigned URL,
  dompdf, detalle de factura) ya existe en el proyecto, este batch solo lo conecta.

## Plan de trabajo

Backend Dev + Frontend Dev en paralelo (fork, comparten este contrato) → Senior Review (con foco
explícito en el cambio de `register()`) + Visual Review/Pre-QA fusionado (suite completa +
Playwright) → push a `dev` → deploy manual → comentario Jira + transición a QA por ticket, mismo
flujo que los 12 batches anteriores de Admin&Cont.
