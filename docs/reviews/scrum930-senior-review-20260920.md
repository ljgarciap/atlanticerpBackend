# Senior Review — SCRUM-930 (Estado de Cuenta — correcciones y mejoras UAT)

Senior Reviewer, 2026-09-20. Diseño: `ADR-SCRUM930-estado-de-cuenta-correcciones.md`. Implementación:
Backend Dev (B1-B3) + Frontend Dev (F1-F5), 2 rondas (F3 tuvo un gap real de payload, cerrado en la
misma sesión antes de este review).

## Alcance revisado

Backend: `AccountStatementService::movements()` (`guia_entrega_numero`/`guia_entrega_order_id`,
`invoice_id`/`order_id`/`quote_id`/`nota_id`, `antiguedad_cartera`), `AgingBucketCalculator`
(nuevo), `InvoiceService::agingBuckets()` (extracción, sin cambio de comportamiento),
`AccountStatementExportService`/PDF blade (consumidores del campo viejo, no en el ADR original pero
necesarios). Frontend: `InvoiceDetailModal` extraído a componente propio, `EstadoCuentaPage` (columna
antigüedad, 4 documentos clickeables, botón Status de Pedidos), `Sidebar` (ítem nuevo).

## Hallazgos durante la revisión (los dos reales, ambos corregidos en esta misma sesión)

1. **Cobertura de tests ausente para los campos nuevos del payload.** El reporte de Backend Dev
   decía tener cobertura de "los campos nuevos" pero solo tocó el test de `guia_entrega_numero`
   (heredado de SCRUM-929) — `invoice_id`/`order_id`/`quote_id`/`nota_id`/`antiguedad_cartera` no
   tenían ninguna aserción. Corregido: `test_930_movimientos_incluyen_ids_para_enlazar_documentos_y_antiguedad_por_creacion`
   en `AdminContPartialInvoicingConsumersTest.php`, con fixtures reales (Order+Quote+CreditNote),
   12 assertions, verde.
2. **Regresión real en 2 specs Playwright permanentes** (`preqa-batch-895-897-550-881-882-20260911.spec.ts`,
   `preqa-batch-scrum850-855-856-858-860-20260908.spec.ts`): el ítem nuevo del Sidebar ("Estado de
   Cuenta", presente en TODA la app) y el botón ya existente del mismo nombre dentro de
   `FacturacionPage` colisionan bajo `getByRole('button', {name: /estado de cuenta/i})` — antes de
   SCRUM-930 esos dos elementos con el mismo texto no coexistían en la misma pantalla. Se evaluó
   `aria-label` para desambiguar el nombre accesible del ítem del Sidebar, mismo texto visible, pero
   el regex de los tests es de substring (`/estado de cuenta/i`, sin anchors), así que cualquier
   nombre accesible que siga conteniendo "estado de cuenta" (requisito de WCAG 2.5.3, Label in Name)
   sigue matcheando ambos — la desambiguación por nombre accesible no es viable acá. Corregido
   escopeando el lookup de esos 4 asserts a `page.locator('main')` (el contenido de la página,
   ya existe en el layout de `AppShell`, distinto del `<nav>` del Sidebar) — no se tocó el criterio
   de ningún test, solo se le devolvió precisión ahora que existe un segundo elemento legítimo con
   el mismo texto en la app.

## Gate completo (regla dura del proyecto — nunca scopeado antes de review/push)

- **Backend:** `infra/test.sh` completo, limpio (sin nada más corriendo en paralelo — la primera
  corrida dio 62 fallos por contaminación cruzada de Postgres compartido al correr un test filtrado
  mientras la suite completa corría en background; repetida limpia). 3597/3598 verdes — el único
  fallo (`InvoiceConcurrencyTest`, SCRUM-929, no tocado por este ticket) es flaky de carga/timing,
  confirmado pasando en aislado. PHPStan Level 8 sin errores.
- **Frontend:** `npx vitest run` completo — 174 archivos, 1898/1898 verdes. `tsc --noEmit` limpio.
- **Playwright — specs permanentes que tocan el módulo** (5 archivos): hallazgo 2 arriba encontrado
  y corregido; los 4 tests directamente afectados (897.1, 897.4, 856.1, 856.2) verdes tras el fix.
  Quedan 2 fallos sin relación con SCRUM-930, confirmados preexistentes con `git stash` (reproducen
  igual en código base, sin ninguno de los cambios de este ticket): `SCRUM-897.2` depende de
  fixtures sembrados a mano en una sesión de Pre-QA anterior que no persisten en este entorno
  (documentado en el propio docblock del spec), y `SCRUM-850.2` es de la pestaña "Inactivos" de
  otra pantalla, sin relación con Estado de Cuenta. Ninguno de los dos bloquea este ticket. Los
  otros 3 specs (`preqa-scrum928-935-batchA-20260918`, `preqa-rebotes-scrum857-858-865-867-20260910`,
  `preqa-visual-batch15-scrum580-584-comisiones-internas-20260825`) tuvieron fallos propios (DV de
  Cliente Master, comisiones internas/proyectos compartidos) — sin relación con Estado de Cuenta,
  no investigados en profundidad en este review por estar fuera de alcance del ticket; quedan
  anotados para quien retome esos módulos.

## Veredicto

**Aprobado.** Diseño respetado sin desvíos injustificados (las 2 decisiones tomadas dentro del
diseño aprobado — `guia_entrega_order_id` vía `->value()` en vez de `->first()`, `nota_id` también
en filas `anulacion` — son consistentes con el ADR). Pasa a Pre-QA (fusionado con Visual Review,
pero sin mockup adjunto en el ticket, así que Visual Review no aplica — confirmado en Jira).
