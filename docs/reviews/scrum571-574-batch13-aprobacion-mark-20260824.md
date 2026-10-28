# Senior Review — Admin&Cont, Notas Crédito Batch 13 (SCRUM-571→574 + SCRUM-787, REQ-494→497)

**Fecha:** 2026-08-24
**Resultado: APROBADO tras reconciliar 2 defectos reales de contrato Backend↔Frontend (ambos
esperables al despachar Backend Dev y Frontend Dev como forks paralelos sobre el mismo ADR, sin
verse entre sí durante la implementación) — gate completo re-corrido después de corregirlos.**

Batch 13 de Notas Crédito y Devoluciones (ver `docs/architecture/admin-contab-batches.md`) —
aprobar/rechazar una nota pendiente exclusivo de Aprobador (REQ-494), ver comprobante de soporte
(REQ-495), ver documento formal de la nota con enlace a la factura relacionada (REQ-496), ver esa
factura relacionada con el mismo formato que cualquier otra (REQ-497) + `parseDateOnly()`
compartido bundleado (SCRUM-787). Ver
`docs/adr/ADR-SCRUM571-574-batch13-aprobacion-mark-comprobante-documento.md` para el diseño
completo, aprobado por Luis antes de dispatchar, incluida la confirmación explícita de que
`register()` debía corregirse para que **toda** Anulación completa quede pendiente de aprobación
de Aprobador sin importar el monto (no solo por superar el umbral, gap real de Batch 11 encontrado al
diseñar este batch).

## Defectos reales encontrados y corregidos en este review

**1. REQ-497 — Frontend Dev usó el campo equivocado para "ver factura relacionada".** El ADR
asumía que `factura_origen_id` de la nota resolvía directo contra `GET /invoices/{order}/detail`
(reusado tal cual, sin endpoint nuevo). Backend Dev, implementando en paralelo sin ver el código de
Frontend Dev, encontró que **no resuelve** — `factura_origen_id` es el PK propio de
`AdminContInvoice`, pero ese endpoint espera el `order_id` de la orden/venta. Backend Dev corrigió
su lado agregando `factura_origen_order_id`/`factura_nueva_order_id` a `show()`, pero Frontend Dev
ya había terminado y commiteado usando `factura_origen_id` en `DetalleNotaCreditoModal.tsx` y
`DocumentoNotaCreditoModal.tsx` — en producción esto hubiera abierto la factura equivocada (o
ninguna) al hacer clic en la factura relacionada, sin ningún error visible. Corregido: los 2
componentes ahora usan `factura_origen_order_id`; los 2 tests de REQ-497 se reescribieron con
`factura_origen_id` (10) y `factura_origen_order_id` (55) deliberadamente distintos, para que una
regresión al campo equivocado los rompa (antes coincidían por casualidad y el test no detectaba
nada).

**2. REQ-495 — el contrato de `GET .../comprobante` no incluía `mime_type`.** Frontend Dev
construyó `VerComprobanteNotaCreditoModal` esperando `mime_type` para decidir vista previa de
imagen vs. archivo genérico (mismo patrón que el comprobante de Cobros, que sí lo tiene). Backend
Dev no lo agregó porque `credit_notes` nunca tuvo columna `mime_type` (a diferencia de
`AdminContPaymentAttachment`, que tiene tabla propia) — sin este campo, el modal siempre trataba
cualquier comprobante como no-imagen (`mime_type?.startsWith('image/') ?? false`), degradando la
vista previa de JPG/PNG sin romper nada visiblemente. Corregido agregando
`AdminContCreditNoteAttachmentService::guessMimeType()` (infiere del extension del storage key,
cerrado sobre el mismo whitelist ya validado al subir: jpg/jpeg/png/pdf) en vez de una migración
nueva solo para esto.

Ninguno de los 2 es un error de "no seguir el ADR" — el ADR mismo tenía la asunción incompleta en
el caso 1, y no cubría el caso 2 en detalle. Ambos son el costo esperable de paralelizar Backend
Dev/Frontend Dev como forks sobre un contrato todavía no verificado en código real — por eso este
review reconcilia explícitamente antes de dar el batch por bueno, no se asume que "cada lado
compiló limpio" es suficiente.

## Cambio de comportamiento confirmado (no defecto, decisión de diseño ya aprobada)

`CreditNoteService::register()` — toda nota de tipo Anulación completa queda
`pendiente_aprobacion` sin importar el monto (antes: solo si `monto > threshold`). Confirmado por
Luis explícitamente antes del dispatch (ver ADR). Test existente de "Anulación completa, monto
bajo, queda aplicada directo" actualizado por Backend Dev para reflejar el nuevo comportamiento
esperado.

## Gate completo (corrido después de las 2 reconciliaciones)

- Backend: PHPStan Level 8 — 0 errores (675 archivos). `infra/test.sh` completo — **2560 tests,
  10370 assertions, OK**.
- Frontend: `npx tsc --noEmit` — limpio. `npm run test -- --run` completo — **131 archivos, 1493
  tests, todos en verde**.

## Siguiente paso

Pre-QA + Visual Review fusionado (sin mockup adjunto en los 4 tickets — corre igual, foco en
criterios de aceptación/negativos) contra el stack local, con foco explícito en:
- El camino "Anulación completa, monto bajo" (cambio de comportamiento de Batch 11).
- RN2 de REQ-494 (bloqueo de aprobación sin comprobante) para los 3 tipos que sí lo exigen, y que
  Devolución de mercancía NUNCA lo exige (ni al registrar ni al aprobar).
- Los 2 defectos reconciliados arriba, ya verificados a nivel de tipos/tests pero no en navegador
  real todavía.
