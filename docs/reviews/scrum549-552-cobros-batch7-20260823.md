# Senior Review — Admin&Cont, Cobros Batch 7 (SCRUM-549→552, REQ-472→475)

**Fecha:** 2026-08-23
**Resultado: APROBADO. Sin hallazgos bloqueantes de diseño; 1 bug real encontrado y corregido en
frontend durante Pre-QA/Visual Review propio (ver ese documento).**

Batch 7 de Cobros, último de la sub-área (ver `docs/architecture/admin-contab-batches.md`) — ver
comprobante adjunto (REQ-472), ver/imprimir recibo formal (REQ-473), apertura automática desde
Facturación (REQ-474 — resultó ya implementada de punta a punta desde Batch 5, ver abajo),
confirmación manual bancaria (REQ-475, botón "Marcar como confirmado"). Implementado en un solo
hilo (sin despachar subagentes reales — `feedback_risk_classifier_agent_dispatch_telegram_gap_20260823`
sigue sin aplicarse), Backend y Frontend secuencial, no forkeado.

## Gate real (suite completa)

- Backend: PHPStan Level 8 (app completa, no solo el módulo) → `[OK] No errors`. `infra/test.sh`
  completo → **2503/2503 tests, 10131 assertions, OK** (incluye 9 tests nuevos de
  `AdminContPaymentControllerTest`: attachment con/sin comprobante, PDF válido/404, confirm
  feliz+ledger+saldo favor, confirm sobre ya-confirmado/doble-confirm/inexistente → 422, detalle
  incluye cliente/metodo_pago_label).
- Frontend: `npx vitest run` completo → **123 archivos, 1411/1411 tests, OK** (incluye
  `VerComprobanteModal.test.tsx` nuevo, `DetalleCobroModal.test.tsx` +5 tests, `RegistrarCobroModal.test.tsx`
  +2 tests para REQ-474 RN2). `tsc --noEmit` → sin errores.

## REQ-474 (apertura automática) — sin código nuevo, ya cubierta por Batch 5

Al revisar `CobrosPage.tsx`/`FacturacionPage.tsx` antes de implementar, la apertura automática con
cliente preseleccionado (`master_client_id`+`accion=nuevo-cobro` en la query string, botón
"Registrar cobro" del detalle de factura) ya estaba completa desde Batch 5 (REQ-462 RN2) — mismo
mecanismo, escrito antes de que este ticket existiera formalmente. Único gap real: RN2 ("cliente
inválido → se comporta como manual, sin error visible") no limpiaba `masterClientId` cuando
`master_client_name` volvía `null` — el formulario se abría igual pero con un id inválido "pegado"
en el estado. Corregido en `RegistrarCobroModal.tsx` (el efecto que completa el nombre ahora
también limpia `masterClientId` cuando no hay nombre), cubierto por 2 tests nuevos (Escenario 1
válido / Escenario 2 inválido).

## Bug real encontrado por mis propios tests (no por Pre-QA aparte)

**Los 3 botones nuevos del footer de `DetalleCobroModal` renderizaban (deshabilitados) ANTES de
que `payment` cargara**, porque solo estaban fuera del bloque `{payment && (...)}` con
`disabled={!payment}` — un botón deshabilitado sigue siendo un botón real en el DOM, así que
`fireEvent.click`/un click de usuario real sobre él durante el instante de carga no hace nada, sin
ningún feedback visual de por qué. Los tests de Vitest lo atraparon de inmediato (el `findByText`
resolvía sobre el botón deshabilitado del primer render, no el habilitado post-carga). Corregido:
los 3 botones ("Marcar como confirmado", "Ver comprobante", "Ver recibo") ahora viven dentro de
`{payment && (...)}` — no aparecen en absoluto mientras carga, en vez de aparecer deshabilitados.

## Diseño de los 3 endpoints nuevos

- `GET /payments/{id}/attachment` — reusa `AdminContPaymentAttachmentService` (ya existía desde
  Batch 6 para la subida), se le agrega `presignedUrl()` (TTL 15 min, mismo criterio que
  `TicketAttachmentService`). Devuelve `{data: null}` explícito sin comprobante (RN2 REQ-472),
  nunca 404 — el cobro sí existe, solo no tiene adjunto.
- `GET /payments/{id}/pdf` — `PaymentReceiptPdfService` clona el patrón exacto de
  `InvoicePdfService` (Batch 4 Facturación): mismo `AdminContFiscalSettings`/`AdminContCompanyProfile`
  para los datos de empresa, mismo `PdfLogo::dataUri()` (dompdf no renderiza `<svg>` inline). El
  frontend descarga el PDF (mismo patrón blob que "Ver PDF" de Facturación) en vez de abrir un
  modal con `window.print()` como el mockup estático — se prefirió reusar el flujo ya
  Senior-revisado de Batch 4 en vez de introducir un segundo patrón de "ver documento" en el mismo
  módulo.
- `PUT /payments/{id}/confirm` — aplica dentro de una transacción lo que `PaymentService::register()`
  dejó diferido a propósito en Batch 6 para cobros `esperando_confirmacion` (RN5 de REQ-469):
  `saldo_aplicado` de cada factura cubierta, aplicación de saldo a favor, movimiento en
  `bank_movements`. Genera folio `CONF-XXX` propio (`AdminContPaymentConfirmationFolioCounter`,
  mismo mecanismo de contador atómico que `REC-XXX`/`F-XXXX`) — tabla nueva
  `payment_confirmations`, `unique(payment_id)` porque RN4 dice que la acción no se puede deshacer
  desde la pantalla, así que nunca hay una segunda confirmación real del mismo cobro.
  `PaymentStateException` → 422 si el cobro no existe o ya no está `esperando_confirmacion`.

## Verificación en vivo (local, antes de deploy)

`infra/docker-compose.yml` + `npm run dev` (Vite, proxy `/api` → nginx local `:8090`) — fixture
sembrado vía tinker (MasterClient "PreQA Batch7 Cobros" id 645, factura F-PREQA7 $1,500, cobro
REC-001 por transferencia arrancando `esperando_confirmacion`). 4 escenarios corridos con
Playwright real contra ese entorno (spec ephemeral, no promovida a permanente — mismo criterio que
Batch 6, no guarda ningún gate previamente roto): detalle muestra los 3 botones nuevos, "Ver
comprobante" sin adjunto muestra el mensaje RN2 (no error), "Ver recibo" descarga
`recibo-REC-001.pdf` real, "Marcar como confirmado" pasa el cobro a `confirmado`, oculta el botón,
y deja visible "CONF-001 — confirmado por Contabilidad Test el 23 ago 2026". PDF verificado con
contenido real (RUC vacío es dato del entorno local — `fiscal_settings` sin poblar en esta BD, no
un bug del código; mismo campo que ya usa el PDF de Facturación).
