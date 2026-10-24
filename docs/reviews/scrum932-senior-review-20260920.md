# Senior Review — SCRUM-932 (Notas Crédito y Devoluciones — correcciones y mejoras UAT)

Senior Reviewer, 2026-09-20. Diseño: `ADR-SCRUM932-notas-credito-correcciones.md`. Implementación:
Backend Dev (B1-B2) + Frontend Dev (F1-F2).

## Alcance revisado

Backend: `CreditNoteService::invoicesForClient()` (campos de Proyecto/Cotización, query
independiente de Cobros/SCRUM-931 — confirmado que no se colapsó con `PaymentService`) y
`CreditNoteService::show()` (Cotización/Guía de una sola factura, reusando `GuiaEntregaResolver`
tal cual sin cambios). Frontend: `RegistrarNotaCreditoModal.tsx` (filtro cruzado, adaptado a un
`<select>` único en vez de checkboxes) y `DetalleNotaCreditoModal.tsx` (primera vez que abre
modales hijos, Cotización/Guía antes de "Factura de origen", que queda intacto).

## Sin hallazgos nuevos — el bug de clase ya conocido se autocorrigió

Frontend Dev identificó y corrigió por su cuenta, sin que yo tuviera que señalarlo, el mismo tipo de
bug que encontré en Senior Review de SCRUM-931 (`RegistrarCobroModal.tsx`): un `<select>`
controlado cuyo valor elegido deja de estar entre las opciones filtradas queda con estado
desincronizado. Acá lo resolvió con un `useEffect` que limpia `facturaOrigenId` cuando deja de
estar en `visibleFacturas`, con test dedicado. Señal de que el patrón de SCRUM-931 se internalizó
correctamente para el resto de la sesión.

Decisión técnica razonable no 100% explícita en el ADR: Backend Dev omitió un campo `order_id`
redundante en `show()` (ya existe `factura_origen_order_id` con el mismo valor) — correcto, evita
duplicar un dato sin necesidad.

## Gate completo (regla dura del proyecto)

- **Backend:** PHPStan Level 8 limpio sobre `CreditNoteService.php`. `infra/test.sh --filter="CreditNote"` → 45/45 verdes. Suite completa (sin nada corriendo en paralelo) → 3601 tests, 1 fallo (`InvoiceConcurrencyTest`, SCRUM-929, no tocado por este trabajo, confirmado flaky de carga en aislado). Test nuevo con fixtures reales (familia de entregas, cotización, guía, nota de crédito real) — 17 assertions, incluye el caso de factura ya pagada (que este método, a diferencia de Cobros, debe seguir trayendo).
- **Frontend:** `tsc --noEmit` limpio. `npx vitest run` completo — 1917/1917 verdes (7 tests nuevos: filtro cruzado con caso de factura oculta por el filtro, Cotización/Guía con y sin datos).
- Pendiente: Playwright — corre en el paso de Pre-QA (sin mockup adjunto en el ticket, Visual Review no aplica).

## Veredicto

**Aprobado.** Diseño respetado, sin desvíos injustificados. Pasa a Pre-QA.
