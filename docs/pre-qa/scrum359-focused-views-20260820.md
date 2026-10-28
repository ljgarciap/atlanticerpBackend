# Pre-QA — SCRUM-359 (REQ-289), fix del rebote de QA (Qa, 2026-08-20)

Rebote real: "Movimiento de Herramientas" (Kardex) mostraba la barra de navegación completa,
violando RN1 (vista secundaria de apoyo, solo debe tener un control de cerrar/volver, no el menú
de las 5 pestañas). Causa raíz: ambas vistas de apoyo del ticket (Kardex, Informe móvil) vivían
dentro de `AppShell` desde su creación (Batch 13 y Batch 10 respectivamente) — decisión explícita
documentada en el código ("ninguna ruta de esta app se sirve sin chrome") que nunca se reconcilió
con RN1/RN2 de este ticket.

**Fix** (`atlanticerp-frontend` `ffa921e`, desplegado a dev.atlanticerp.ai por `infra/deploy-manual.sh dev`):
nuevo `FocusedViewShell` (sin Sidebar/TopBar) para ambas rutas — `/servicios/tools/kardex` y
`/servicios/tickets/:ticketId/inspection-report/movil`, movidas fuera del grupo de rutas de
`AppShell` en `App.tsx`. `ToolKardexPage` gana su propio botón "Cerrar" (`window.close()` — se
abre en pestaña nueva vía `window.open()`, sin historial propio para "volver"). Informe móvil ya
tenía su propio botón "Volver al ticket" desde su creación — se preserva sin duplicar.

Gate previo (mismo gate que corre `infra/deploy-manual.sh`): tsc limpio, 1330 tests vitest OK,
build OK.

Verificado en vivo contra **dev.atlanticerp.ai real** vía Playwright CLI
(`playwright.dev-remote.config.ts`), spec `e2e/preqa-scrum359-focused-views-20260820.spec.ts` (4
tests, evidencia en `e2e/.tmp/preqa-scrum359-focused-views-20260820/`). Cuenta real (password =
email): `servicio@atlantic.com.pa` (Aaron Leis, lider_servicios).

---

## Veredicto: PASA A QA — pasada limpia, ambos escenarios del ticket verificados sin regresión

## Escenario 1 (RN1) — Kardex sin menú de navegación

- Vía el botón "Movimiento de herramientas" en Insumos y Herramientas (abre pestaña nueva): la
  pestaña nueva no tiene ningún `<nav>` en el DOM, título "Movimiento de herramientas" visible,
  botón "Cerrar" visible. Cero errores de página (test 1).
- Vía URL directa `/servicios/tools/kardex` (sin depender del botón de origen): mismo resultado —
  sin `<nav>`, botón "Cerrar" visible (test 2).

## Escenario 2 (RN2) — Informe móvil sin menú de navegación

- `/servicios/tickets/12/inspection-report/movil` (ticket real RET-2026-0001, `on_site`/informe
  `pending`): sin ningún `<nav>` en el DOM, botón "Volver al ticket" (ya existía, no duplicado)
  visible. Cero errores de página (test 4).

## Regresión — resto de la app intacto

- `/servicios/insumos-herramientas` (pantalla principal): el sidebar sigue montado normalmente
  (`aside nav` presente) — el cambio de ruta no afectó ninguna pantalla fuera de las 2 vistas de
  apoyo (test 3).

## Recomendación al PM

**SCRUM-359 → transicionar a `QA`.** Marcadores de gate creados
(`~/.claude-preqa-markers/SCRUM-359`, `~/.claude-visual-review-markers/SCRUM-359` — Visual Review
N/A, no hay mockup adjunto para este ticket, es un ajuste de layout/routing, no una pantalla
nueva).
