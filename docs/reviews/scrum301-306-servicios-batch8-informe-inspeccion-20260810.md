# Senior Review — Fase 4 Servicios, Batch 8: Informe de Inspección (REQ-238→243)

**Fecha:** 2026-08-10
**Tickets:** SCRUM-301 (REQ-238), SCRUM-302 (REQ-239), SCRUM-303 (REQ-240), SCRUM-304 (REQ-241), SCRUM-305 (REQ-242), SCRUM-306 (REQ-243)
**Corrida retroactiva** — la implementación, Pre-QA y Visual Review ya habían corrido en la sesión anterior (cortada antes del cierre); este gate se corrió recién antes de comitear/pushear, sobre el diff completo y ya con los fixes de Pre-QA aplicados. Ver `feedback_protocolo_senior_review_antes_dev_testing` en memoria — mismo tipo de gap ya documentado en batches anteriores de Fase 4 (308/309/310, 311, 318-323, 324-326, 333-337), corregido acá antes de repetirlo una vez más.

## Veredicto: APROBADO — sin hallazgos bloqueantes

## Alcance revisado

Backend: `InspectionReportService`, `InspectionReportFolioService`, `InspectionReportController`,
`SaveInspectionReportRequest`, modelos (`InspectionReport`, `InspectionReportMaterial`,
`InspectionReportPhoto`, `InspectionReportFolioCounter`), 4 migraciones nuevas, rutas en
`routes/servicios.php`, relación `Ticket::inspectionReport()`.

Frontend: `InspectionReportModal.tsx` (nuevo), wiring en `TicketDetailModal.tsx`/
`TicketIndicators.tsx`, contrato en `serviciosApi.ts`/`types/servicios.ts`.

## Arquitectura y consistencia con el resto del módulo

- Folio `INF-AÑO-NNNN` vía `InspectionReportFolioService` — mismo contrato exacto que
  `QuoteFolioService`/`TicketFolioService` (PK simple `year`, `lockForUpdate()` dentro de la
  transacción externa). Correcto.
- `findings JSONB array` con `is_quote_recommendation` asignado server-side desde un catálogo por
  tipo/subtipo — decisión bien fundamentada (reconciliación real contra el Excel/mockup, no
  el placeholder de forma fija que tenía el diseño previo) y documentada tanto en el docblock de
  la migración como en memoria. El frontend nunca reimplementa la matriz RN1-RN3 (`fields()` viene
  siempre del backend) — mismo criterio ya usado para `Ticket::REQUIREMENT_LABELS`.
- Separación de `save()` (PUT, JSON) y `saveArchivoMode()` (POST, multipart) para evitar
  method-spoofing — consistente con el único otro caso de upload del módulo
  (`TicketAttachmentService`, metadata vs. archivo por endpoints separados).
- `withUniqueRetry()` (fix de Pre-QA para el doble-submit) es la solución correcta al problema real
  de Postgres bajo READ COMMITTED (`SELECT ... FOR UPDATE` no bloquea una fila inexistente) — capa
  el error específico de la constraint (`servicios_inspection_reports_ticket_uq`), no un catch
  genérico. El caso residual (archivo huérfano en S3 tras una carrera de doble-submit en modo
  archivo) queda documentado en el docblock como trade-off aceptado, no como bug pendiente.
- Gate `tickets.inspection_report_status` actualizado dentro de la misma transacción que completa
  el informe (`finishUpsert()`) — cierra un gap real que llevaba desde Batch 1 (columna
  denormalizada sin nada que la escribiera). Correcto encontrarlo y corregirlo en este batch.
- `assertCanEdit()` (backend) y `canEditInspectionReport()` (frontend, en `TicketDetailModal.tsx`)
  usan exactamente la misma condición (`ticket.internal_technician_id === user.id` para técnico
  interno) — consistentes entre sí, y el frontend nunca es la única barrera (el backend repite el
  check server-side en las 3 rutas de escritura).
- `REQ-240 RN3/RN4` diferidos a Batch 14 con justificación real (RN1 texto libre es incompatible
  con RN3 kardex, que exige `catalog_product_id`; Batch 14 ni existe todavía) — decisión de Luis
  documentada, no un corte de alcance silencioso.

## Sin iconografía de emoji

Verificado (`grep` sobre componente/i18n nuevos) — cumple la regla de cliente SCRUM-56.

## Deuda ya registrada por Pre-QA (no repetida acá)

- `InspectionReportModal.tsx` sin suite de vitest dedicada (cubierto indirectamente vía
  `TicketDetailModal.test.tsx`/`TicketIndicators.test.tsx` + el e2e permanente).
- Archivo huérfano en S3 en el caso residual de carrera de doble-submit en modo archivo.

## Verificación propia (no solo repetir lo que reportó Pre-QA)

- Leído el diff completo línea por línea (backend + frontend), no solo el resumen del Pre-QA.
- PHPStan Level 8 (`--memory-limit=1G`), PHPUnit completo (`infra/test.sh`), `tsc --noEmit`,
  vitest completo — corridos de nuevo en esta sesión antes de comitear (ver comentario de cierre
  en Jira para el resultado).

## Nota de proceso

Este batch llega a Senior Review DESPUÉS de Pre-QA y Visual Review, invirtiendo el orden que exige
`feedback_protocolo_senior_review_antes_dev_testing` (Senior Review → Dev Testing → Pre-QA). No se
revierte nada — el código es válido y Pre-QA ya encontró y corrigió 2 CRÍTICOS reales con ojos
frescos (no hay evidencia de que el orden invertido haya ocultado algo, ya que Pre-QA sigue siendo
adversarial e independiente de esta revisión). Queda registrado como el mismo patrón de deuda de
proceso ya conocido, no como una corrección retroactiva de código.
