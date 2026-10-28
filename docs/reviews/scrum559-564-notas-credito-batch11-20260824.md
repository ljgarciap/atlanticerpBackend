# Senior Review — Admin&Cont, Notas Crédito Batch 11 — submit real (SCRUM-559→564, REQ-482→487)

**Fecha:** 2026-08-24
**Resultado: APROBADO. Sin hallazgos funcionales bloqueantes.**

Batch 11 conecta el submit real del formulario de Notas Crédito que Batch 10 dejó armado con el
botón deshabilitado: selección de factura de origen con saldo real (REQ-483), monto/desglose ITBMS
en tiempo real (REQ-485), resolución automática del excedente — aplicado al saldo / devuelto por
banco / saldo a favor (REQ-484), banners de Bodega y de umbral de aprobación configurable (REQ-482),
avisos automáticos de notificación al cliente y reducción de comisión (REQ-486), comprobante de
soporte obligatorio según el caso (REQ-487), y el `POST /admin-contab/notas-credito` real
(`CreditNoteService::register()`). Alcance deliberadamente excluido: el subtipo "Corrección de
datos" sigue de solo lectura, su submit real (revisión previa + factura nueva) es Batch 12 —
`RegisterCreditNoteRequest` lo rechaza con 422.

**Revisión de código** (línea por línea, sin hallazgos funcionales) ya hecha antes de este gate —
ver `app/Modules/AdminCont/Services/CreditNoteService.php`, `RegisterCreditNoteRequest.php`,
`AdminContCreditNoteController.php` (backend) y `RegistrarNotaCreditoModal.tsx` (frontend). Este
documento cubre la verificación en navegador real (Playwright CLI, local) + el gate de suite
completa — ver `docs/pre-qa/scrum559-564-notas-credito-batch11-20260824.md` para el detalle
punto por punto contra los 6 tickets, incluidos los criterios negativos/bloqueantes.

## Puntos técnicos confirmados durante el gate (sin cambios de código)

- El umbral de aprobación ($5,000 default) vive en `fiscal_settings.mark_approval_threshold`
  (Configuración Fiscal, editable sin deploy) — ni backend ni frontend lo hardcodean; ambos lo leen
  del mismo lugar (`AdminContFiscalSettings::current()` / `resumen.mark_approval_threshold`),
  cumple la regla global de no hardcodear umbrales de negocio.
- `RegisterCreditNoteRequest::withValidator()` rechaza el subtipo `correccion` con 422 de forma
  explícita e independiente del frontend (verificado con un POST directo saltando la UI) — el
  frontend nunca deja llegar a ese submit tampoco, doble cobertura.
- `CreditNoteService::invoicesForClient()` lista todas las facturas del cliente (pagadas o no) a
  propósito, distinto de `PaymentService::openInvoicesForClient()` (Cobros sí filtra) — decisión de
  diseño documentada en el propio docblock, verificada en navegador con un cliente con facturas
  mixtas.
- El gate `estado = monto > threshold ? pendiente_aprobacion : aplicada` aplica parejo a los 3 tipos
  de nota (Descuento comercial, Devolución, Anulación completa) — no hay atajo que deje pasar una
  Anulación completa de monto alto sin quedar pendiente de aprobación de Aprobador.

## Gate real (suite completa)

- Backend: PHPStan Level 8 (`--memory-limit=1G`) → `[OK] No errors`. `infra/test.sh` completo →
  **2550/2550 tests, 10315 assertions, OK** (incluye `AdminContCreditNoteControllerTest.php`, 20
  tests nuevos cubriendo `summary`/`itbms-rates`/`itbms-treatment`/`facturas`/`store` — RBAC,
  aplicación automática al saldo, ambas resoluciones de excedente, validaciones de RN2/RN3/RN4,
  gate de comprobante en sus 2 sentidos, y el rechazo 422 de "Corrección de datos").
- Frontend: `npm run test -- run` completo → **126 archivos, 1463/1463 tests, OK** (incluye
  `RegistrarNotaCreditoModal.test.tsx` con 27 tests nuevos/actualizados cubriendo exactamente las RN
  verificadas en navegador real durante este gate — sin discrepancias). `npx tsc --noEmit` → sin
  errores.

Ningún hallazgo bloqueante encontrado en este gate — ver el reporte de Pre-QA/Visual Review para 1
nota de fidelidad visual no bloqueante (label de excedente ausente en el mockup vs implementación).

## Pendiente para Luis (no bloqueante)

Push de `dev` (ambos repos) requiere su aprobación explícita — gate de `git push`, sin excepción.
