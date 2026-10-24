# Senior Review — SCRUM-931 (Cobros — correcciones y mejoras UAT)

Senior Reviewer, 2026-09-20. Diseño: `ADR-SCRUM931-cobros-correcciones.md`. Implementación: Backend
Dev (B1-B3, retomado por el Arquitecto tras un corte de sesión a mitad de B1 — sin repetir trabajo
ya hecho) + Frontend Dev (F1-F2).

## Alcance revisado

Backend: extracción de `GuiaEntregaResolver` (compartido con `AccountStatementService`, SCRUM-930),
`PaymentService::openInvoicesForClient()` (campos de Proyecto/Cotización), `AdminContPaymentController::presentDetail()`
(facturas extendidas + `abonos`/`notas_credito`). Frontend: `RegistrarCobroModal.tsx` (filtro
cruzado Proyecto↔Cotización), `DetalleCobroModal.tsx` (facturas clickeables + 4 secciones nuevas).

## Hallazgo real durante la revisión (corregido en esta misma sesión)

**Selección de factura no se podaba al ocultarse por el filtro.** `RegistrarCobroModal.tsx`:
el total mostrado (`selectedInvoiceIds` contra `invoices`, la lista completa) y el payload de
`register()` (`Array.from(selectedInvoiceIds)`) nunca se recalculaban contra `visibleInvoices` (la
lista ya acotada por Proyecto/Cotización) — tildar una factura y después cambiar el filtro a una
combinación que la oculta la dejaba "cobrándose" sin checkbox visible en pantalla, sin que el
usuario tuviera forma de notarlo ni destildarla. Corregido con un `useEffect` que poda
`selectedInvoiceIds` a la intersección con `visibleInvoices` cada vez que el filtro cambia — test
nuevo (`RegistrarCobroModal.test.tsx`, "tildar F-0001, filtrar a un Proyecto que la oculta...")
confirma que el total desaparece junto con la factura oculta.

## Gate completo (regla dura del proyecto)

- **Backend:** `infra/test.sh` completo, sin nada más corriendo en paralelo — 3600/3600 verdes.
  PHPStan Level 8 limpio. Test nuevo con fixtures reales (familia de entregas, cotización vinculada,
  guía de entrega, abono, nota de crédito) verifica los 9 campos/arrays nuevos con valores
  concretos, no solo "no vacío" (21 assertions).
- **Frontend:** `tsc --noEmit` limpio. `npx vitest run` completo — 1910/1910 verdes (incluye el test
  del hallazgo de arriba y la cobertura ya agregada por Frontend Dev para el filtro cruzado y las 4
  secciones nuevas del detalle, cada una con y sin datos).
- Pendiente: Playwright — corre en el paso de Pre-QA (fusionado con Visual Review, sin mockup
  adjunto en el ticket, Visual Review no aplica — confirmado en Jira).

## Veredicto

**Aprobado**, con el hallazgo de arriba ya corregido y verificado antes de este veredicto — no
queda como "documentado, sigo con el próximo". Decisiones tomadas dentro del diseño aprobado por
Frontend Dev (estado del cross-filter como dos `useState` en vez de un hook compartido, dedupe por
`Map`) son razonables y no se fuerza la integración con SCRUM-933 antes de tiempo, tal como dejaba
explícito el ADR. Pasa a Pre-QA.
