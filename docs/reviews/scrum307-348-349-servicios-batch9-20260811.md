# Senior Review — Fase 4 Servicios, Batch 9 (REQ-244/278/279)

**Fecha:** 2026-08-10/11
**Tickets:** SCRUM-307 (REQ-244, Ver informe completado y Ver/Imprimir), SCRUM-348 (REQ-278, Hoja
de Reclamo — captura), SCRUM-349 (REQ-279, Hoja de Reclamo — documento formal)

## Veredicto: APROBADO tras 1 fix — sin hallazgos bloqueantes pendientes

## Alcance revisado

Backend: `InspectionReportPdfService` (nuevo), 2 rutas + método `pdfBlank()` en
`InspectionReportController`, `ClaimSheetService`/`ClaimSheetController`/`ClaimSheetPdfService`/
`ClaimSheetAccessDeniedException`/`ClaimSheetLockedException`/`SaveClaimSheetRequest` (todos
nuevos), modelo `ClaimSheet`, migración `claim_sheets`, relación `Ticket::claimSheet()`, 2 blades
de PDF de Informe + 1 de Hoja de Reclamo, rutas en `routes/servicios.php`.

Frontend: toggle de imprimir en `InspectionReportModal.tsx`, `ClaimSheetModal.tsx` (nuevo),
wiring en `TicketDetailModal.tsx`/`TicketIndicators.tsx`, contrato en `serviciosApi.ts`/
`types/servicios.ts`.

## 🔴 Blocker — encontrado y corregido en esta sesión

**`ClaimSheetService::save()` no tenía el guard de retry ante condición de carrera que
`InspectionReportService` recibió un día antes (Pre-QA, Batch 8).** Bajo READ COMMITTED,
`lockForUpdate()` sobre una fila que todavía no existe no bloquea nada — 2 `PUT` concurrentes
sobre un ticket sin hoja podían ambos ver `null` y la segunda transacción perdía la carrera del
lado de Postgres con `UniqueConstraintViolationException` sin catch, propagándose como 500 con
stack trace expuesto.

**Fix:** mismo patrón `withUniqueRetry()` que `InspectionReportService`, adaptado a que
`ClaimSheet::save()` siempre completa en un solo guardado (sin borrador) — el reintento del
perdedor encuentra la fila ya `completed` y termina en `ClaimSheetLockedException` (409) en vez
de 500. Verificado con 5 `PUT` concurrentes reales (curl `&`+`wait`): 1×200, 4×409 limpios.

**Archivo:** `app/Modules/Servicios/Services/ClaimSheetService.php`

## 🟡 Suggestions aplicadas en la misma sesión (no bloqueantes, corregidas de todos modos)

- `ClaimSheetController` cargaba el ticket con `Ticket::with('ticketProducts')->find()` en vez de
  reusar `TicketService::find()` (que ya eager-carga `internalTechnician`/`salesProject.subClient.
  masterClient`/`attachments`) — cambiado a reusar el helper existente, evita N+1 evitables.
- Placeholder de firma en `ClaimSheetModal.tsx` reusaba la clave i18n de Informe de Inspección —
  se le dio clave propia (`tickets.claimSheet.firmaPendiente`) para no acoplar ambas features.
- Comentario inexacto en `routes/servicios.php` sobre por qué `/pdf/blank` iba antes de `/pdf`
  (ambas son literales, el orden entre ellas no importa) — corregido.

## Arquitectura y consistencia con el resto del módulo

- `InspectionReportPdfService::generateBlank()` reusa `InspectionReportService::fieldsForTicket()`
  (que a su vez usa `STATIC_FIELDS_BY_TIPO`) en vez de duplicar el catálogo de campos — confirmado
  vía inyección de dependencia, no una copia.
- `ClaimSheetService::assertCanEdit()` omite a propósito la rama `garantias_servicios` que sí
  tiene `InspectionReportService` — consistente con el roster más angosto de la ruta de escritura
  (`role:superadmin,lider_servicios,tecnico_servicios`) y con RN6 de SCRUM-348.
- `ClaimSheet` sin `numero`/folio, a diferencia de `InspectionReport` — por diseño aprobado, no
  un olvido.
- Patrón de PDF (dompdf, `Pdf::loadView()->setPaper('letter')`, paleta de marca en el blade)
  idéntico al ya establecido por `TicketPdfService`/`CatalogPdfService`.

## Sin iconografía de emoji

Verificado sobre componentes/i18n nuevos — cumple SCRUM-56.

## Verificación propia

- PHPStan Level 8 (`--memory-limit=1G`), módulo completo: 0 errores (corrido 3 veces — antes y
  después del fix del blocker, y de nuevo tras los fixes de Visual Review).
- PHPUnit `infra/test.sh --filter=Servicios`: 138/138, corrido 2 veces (antes/después del fix).
- `tsc --noEmit` y `vitest run src/components/servicios` (59/59): limpios en cada ronda.
- Leído el diff completo (backend + frontend) línea por línea, no solo el resumen de Backend/
  Frontend Dev.

## Deuda registrada (no bloqueante)

- No hay tests PHPUnit dedicados para `ClaimSheetService`/`ClaimSheetController` (mismo patrón de
  deuda ya registrado en Batch 8 para `InspectionReportModal.tsx` en el frontend) — cubierto en
  runtime por Pre-QA (curl directo + Playwright) pero no por una suite de regresión automatizada
  propia. Evaluar en un batch futuro.
