# Spec — SCRUM-1009: "Corrección de datos" — no certificar el reemplazo mientras la NC de anulación no esté certificada

**Fecha:** 2026-09-30 · **Estado:** DECISIONES D1/D2/D3 TOMADAS por Luis (2026-09-30) — spec lista para construir tras confirmar los puntos marcados "a confirmar" · **Rol:** Analista + Arquitecto
**Origen:** hallazgo CRÍTICO de integridad fiscal del Pre-QA de SCRUM-958. Depende de SCRUM-1008 (fecha de la NC, hecho, en QA) y de SCRUM-1007 (reintento manual, construido y desplegado a dev).

## Decisiones de Luis
- **D1:** una "Corrección de datos" sobre una factura que YA tiene notas de crédito se **bloquea** con mensaje claro (no se intenta acreditar saldos parciales).
- **D2:** si la DGI rechaza la NC de anulación, la factura de **reemplazo se retiene** hasta que la NC certifique, con alerta visible y reintento.
- **D3:** producción sigue con la FE en Apagado; activarla queda BLOQUEADO hasta cerrar este ticket y SCRUM-1008.

## Hechos verificados en el código
- `CreditNoteService::registerCorreccion()` (y `decide()` al aprobar una corrección pendiente) ejecutan `executeCorreccion()` (anulan la original en AtlanticERP y crean el reemplazo) y, ya fuera de la transacción, llaman a `certificarSiCorresponde($note)` y **incondicionalmente** a `certificarFacturaDeReemplazoSiCorresponde($note)` (líneas ~490-491 y ~1024-1025). El segundo despacha `InvoiceCreated` para el reemplazo sin mirar el `fe_estado` de la NC.
- `correccionContext()`/`previewCorreccion()` no consideran las NC previas de la factura: `correctionBreakdown()` recalcula el total desde la cotización.
- `FiscalCertificationService::persistirResultado()` es el único punto donde una NC pasa a `certificado`, por cualquier camino (certificación inicial, reintento manual de SCRUM-1007, polling). Es el punto natural para "liberar" el reemplazo.
- `fe_estado` es un string sin enum en BD: un valor nuevo no requiere migración; el frontend tiene el tipo `FiscalElectronicStatus`.
- Constructor de la NC de anulación en el builder (`paraNotaCredito`): el ítem sale de `credit_notes.subtotal` con la **tasa de la factura original**, pero `pagos[].monto` sale de `credit_notes.monto`. Para una corrección de **fecha** ambos coinciden con el total original; para una corrección de **ITBMS** `monto` es el total *corregido* (`correctionBreakdown`) y el ítem sigue siendo el original: posible descuadre ítem-vs-pagos en el documento fiscal (ver punto B, a verificar en el sandbox).

## Diseño

### A. D1 — bloquear la corrección si la factura ya tiene NC
- En `correccionContext()` (lo usan `previewCorreccion` y `registerCorreccion`): si existe alguna `credit_notes` con `factura_origen_id = factura` y `estado ∈ {aplicada, pendiente_aprobacion}` (excluye las `rechazada` por Aprobador; una NC aplicada en AtlanticERP pero rechazada por la DGI SÍ bloquea, y el usuario debe resolverla primero con "Reintentar certificación" de SCRUM-1007) ⇒ excepción de dominio ⇒ **422** con mensaje: *"Esta factura ya tiene notas de crédito (NC-XXXX). No se puede corregir; resuélvelas primero o acredita el saldo y emite una factura nueva."*
- Tanto el preview como el submit devuelven el mismo error; el modal de "Corrección de datos" lo muestra antes de confirmar (sin llegar a anular nada).
- **A confirmar:** ¿una corrección previa ya ejecutada bloquea a la factura de reemplazo? No: su NC de anulación quedó ligada a la ORIGINAL, y el reemplazo es una factura nueva sin NC.

### B. Monto del documento fiscal de la NC de anulación (a verificar; cambio solo en el builder)
- Para notas con `subtipo_anulacion = correccion`, `FiscalDocumentBuilder::paraNotaCredito` debe acreditar **el total de la factura original**: ítem (subtotal/ITBMS) y `pagos[].monto` derivados de la factura original, no de `credit_notes.monto` (que es el total del reemplazo). Las columnas de AtlanticERP (`monto`, `ajuste_itbms_delta`, listados) NO cambian.
- Verificación: test unitario con una corrección de ITBMS (total original ≠ total corregido) que compara `Σ ítems` con `Σ pagos` y con el total de la original; y una prueba en el sandbox (1 par NC+reemplazo) en el Pre-QA.

### C. D2 — retener el reemplazo hasta que la NC certifique
- **Nuevo estado** `fe_estado = 'en_espera_nc'` ("En espera de la nota de anulación") en la factura de reemplazo. Sin migración. Frontend: nuevo valor en `FiscalElectronicStatus`, etiqueta i18n es/en, estilo propio (ni verde ni rojo: NO es un rechazo), y `accion_fe = null` (no hay botón sobre el reemplazo; se libera solo).
- `certificarFacturaDeReemplazoSiCorresponde($note)` (helper compartido por `registerCorreccion` y `decide`) decide según `$note->fresh()->fe_estado`:
  - `certificado` ⇒ despacha `InvoiceCreated` (certifica el reemplazo, como hoy).
  - `no_aplica` (FE apagada / tipo deshabilitado) ⇒ despacha `InvoiceCreated` (el listener lo deja `no_aplica`; sin cambios visibles).
  - cualquier otro (`rechazado`, `error_configuracion`, `pendiente`) ⇒ **no despacha**; deja el reemplazo en `en_espera_nc`.
- **Liberación:** en `persistirResultado()`, cuando una NC con `subtipo_anulacion = correccion` y `factura_nueva_id` pasa a `certificado`, y el reemplazo está en `en_espera_nc`, se libera con un *compare-and-set* atómico (`UPDATE … SET fe_estado = NULL WHERE id = ? AND fe_estado = 'en_espera_nc'`, si afecta 1 fila ⇒ despacha `InvoiceCreated`). Idempotente: dos caminos simultáneos (polling y reintento manual) certifican el reemplazo una sola vez.
- **Visibilidad:** en el detalle de la NC de corrección, mientras no esté certificada, un aviso: *"La factura original figura anulada en AtlanticERP pero sigue vigente ante la DGI hasta que esta nota se certifique. La factura de reemplazo se emitirá automáticamente al certificarse."* (solo Aprobador/superadmin ven además el botón de reintento de SCRUM-1007). En el detalle del reemplazo, el badge "En espera de la nota de anulación".
- **Riesgo residual (decisión de Luis pendiente, fuera de este ticket):** si la NC de anulación nunca certifica, el original queda anulado en AtlanticERP y vigente en la DGI y el reemplazo retenido. No hay "revertir corrección"; se propone un ticket aparte si ocurre.

## Fuera de alcance
- Revertir una corrección en AtlanticERP; corregir facturas con NC previas (D1 las bloquea); cambios de numeración; el reemplazo de una factura parcial ya tiene el mismo tratamiento (hereda `order_id`/`tipo`).

## Criterios de aceptación
1. Corrección sobre una factura con NC `aplicada`/`pendiente_aprobacion` ⇒ 422 con el mensaje, en preview y en submit; nada se anula. Con NC `rechazada` (Aprobador) sí procede.
2. Con la NC de anulación rechazada por la DGI, el reemplazo NO se certifica y queda `en_espera_nc`; nunca hay dos facturas vigentes ante la DGI por el mismo hecho (verificado en sandbox con un par).
3. Al certificarse la NC (inicial, reintento manual o polling) el reemplazo se certifica **una sola vez**, automáticamente; test de concurrencia del compare-and-set.
4. FE apagada: comportamiento idéntico al actual (reemplazo `no_aplica`).
5. Aprobación diferida (`decide()`): misma retención y liberación.
6. El documento fiscal de la NC de una corrección de ITBMS cuadra ítems vs pagos y acredita el total de la original (test unitario + 1 par en sandbox).
7. UI: badge "En espera de la nota de anulación" y aviso en el detalle de la NC de corrección; i18n es/en; sin emojis.
8. PHPStan L8, suite completa y vitest verdes; Senior Review independiente; Pre-QA en dev que repita el **paso 16 completo** de SCRUM-958 (incluida la aprobación que certifica el reemplazo) con tope de documentos acordado antes con Luis.

**Esfuerzo:** ~2-2,5 días (backend 1,5: bloqueo + retención/liberación + builder; frontend 0,5-0,75; pruebas y sandbox 0,25). **Riesgo:** alto por tocar el flujo fiscal de facturación; mitigado con el compare-and-set, tests de los tres desenlaces (NC certificada, NC rechazada, FE apagada) y el Pre-QA en sandbox. **Orden:** 1008 (en QA) → 1007 (desplegado, Pre-QA pendiente) → 1009.
