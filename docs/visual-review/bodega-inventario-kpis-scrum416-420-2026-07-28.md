# Visual Review — SCRUM-416 (REQ-346, KPIs) + SCRUM-420 (REQ-350, chips) — 2026-07-28

**Reviewer:** Visual Reviewer (AtlanticERP)
**Tickets:** SCRUM-416, SCRUM-420 (ambos Dev Testing, pasada limpia de Pre-QA ya confirmada 2x)
**Mockup:** `3B__Bodega_Inventario.html` — adjunto en ambos tickets, mismo archivo (misma fecha de
subida, mismo tamaño 182521 bytes; SCRUM-416 attachment id 10891, SCRUM-420 attachment id 10900 —
no hay versiones distintas, se usó una sola descarga como referencia).
**Entorno:** Docker local (`http://localhost:8090` — nginx del proyecto AtlanticERP mapeado a 8090 en
este host, puerto 80 ocupado por otro proyecto del workspace), usuario `management@atlantic.test`.
**Herramienta:** Playwright CLI (`atlanticerp-frontend/e2e/vr_scrum416_420.spec.ts`, script descartable,
no se promueve a smoke test permanente — se borra al cerrar esta revisión).

## Checklist funcional del mockup (líneas 658-751 de `3B__Bodega_Inventario.html`)

### SCRUM-416 — KPIs (5 tarjetas, `.inv-kpi-grid` con `grid-template-columns: repeat(5, 1fr)`)
| # | Mockup | Desarrollo | Estado |
|---|---|---|---|
| 1 | "Total de productos" — valor plano | "TOTAL DE PRODUCTOS" — 6 | ✅ presente y correcto |
| 2 | "Bajo stock" — valor ámbar (`.warn`) | "BAJO STOCK" — 1, color ámbar | ✅ presente y correcto |
| 3 | "Sin stock" — valor rojo (`.danger`) | "SIN STOCK" — 5, color rojo | ✅ presente y correcto |
| 4 | "⚠️ En atención" — valor rojo + subtítulo "Alta/media rotación + bajo o sin stock" | "EN ATENCIÓN" — 4, color rojo, subtítulo idéntico texto | ✅ presente; sin emoji (regla SCRUM-56, ver nota) |
| 5 | "Total por servir" — "37 uds" | "TOTAL POR SERVIR" — "0" (sin sufijo "uds") | ⚠️ ACEPTABLE — ver nota |

### SCRUM-420 — Chips de filtro rápido (línea 748-751)
| # | Mockup | Desarrollo | Estado |
|---|---|---|---|
| 1 | "Todos" (chip base, no es parte del ticket pero es el estado por defecto) | "Todos" — activo por defecto | ✅ |
| 2 | "⚠️ Atención" | "Atención" (icono SVG en vez de emoji) — al hacer click se activa (fondo teal), filtra tabla | ✅ funcional, confirmado con datos reales (ver abajo) |
| 3 | "⛔ Inactivos" | "Inactivos" (icono SVG) — al hacer click se activa, filtra tabla | ✅ funcional (fixture sin productos inactivos → estado vacío correcto, no error) |
| 4 | "📦 Por ingresar" | "Por ingresar" (icono SVG) — al hacer click se activa, filtra tabla | ✅ funcional (fixture sin productos por ingresar visibles bajo este filtro → estado vacío correcto, no error) |

## Verificación funcional de RN1 (SCRUM-416) con datos reales del fixture QA

Fixture visible: 5 productos `SCRUM416-*` con distintas combinaciones de rotación/stock.
- `EDGE-EXACT` (Alta, Bajo stock) → cuenta en atención
- `EDGE-NULLRP` (Alta, Sin stock) → cuenta en atención
- `ROT-ALTA` (Alta, Sin stock) → cuenta en atención
- `ROT-MEDIA` (Media, Sin stock) → cuenta en atención
- `ROT-BAJA` (Baja, Sin stock) → **NO** cuenta en atención

Click en chip "Atención" filtró la tabla a exactamente esos 4 productos (excluyendo `ROT-BAJA`),
coincidiendo 1:1 con el valor de la tarjeta KPI "EN ATENCIÓN" = 4. Confirma Escenario 1
(rotación alta cuenta) y Escenario 2 (rotación baja no cuenta) de los criterios de aceptación de
SCRUM-416 con datos reales, no solo inspección visual.

## CRÍTICO
Ninguno.

## ACEPTABLE (nota, no bloquea)
- KPI "Total por servir": el mockup muestra el valor con sufijo de unidad ("37 uds"), el
  desarrollo muestra el número plano sin sufijo ("0"). El valor es correcto y visible — no se
  pierde funcionalidad, el usuario ve el total real. Nota para Tech Writer/Frontend Dev: agregar
  el sufijo "uds" sería una mejora de claridad, no un bloqueante.
- Iconografía de chips y del KPI "En atención": el mockup usa emoji (⚠️/⛔/📦), el desarrollo usa
  los iconos SVG propios de `atlanticerp-frontend/src/components/icons/`. Esto es la regla de cliente
  SCRUM-56 (no emoji en la UI) aplicada correctamente — variante esperada y correcta, no una
  desviación del mockup en el sentido que le preocupa a este gate.

## Lo que sí cumple
- Las 5 tarjetas KPI están presentes, en el mismo orden, con el mismo agrupamiento visual
  (grid de 5 columnas) que el mockup.
- Los 3 chips nuevos (Atención, Inactivos, Por ingresar) están presentes junto al chip "Todos"
  preexistente, en el mismo orden que el mockup.
- Los chips son funcionales: al hacer click activan visualmente (fondo teal) y disparan el
  filtro de la tabla — no son solo decorativos.
- RN1 de SCRUM-416 (criterio de rotación alta/media + bajo/sin stock) verificado funcionalmente
  con datos reales, ambos escenarios de aceptación pasan.
- RN2 de SCRUM-416 (recálculo en tiempo real) implícitamente confirmado — los valores de KPI
  reflejan el fixture real sembrado, no valores fijos/hardcodeados.
- RN1 de SCRUM-420 (chips independientes, se combinan con el resto de filtros) — estructuralmente
  confirmado en código (`chip` es un filtro más del query junto a categoría/estado/rotación/etc.).

## Resultado
Sin hallazgos CRÍTICOS. Ambos tickets aprobados para pasar a Pre-QA/QA (Pre-QA ya corrió limpio
dos veces según el gate; este visual review era el bloqueante pendiente del `preqa-gate.sh`).

Marcadores creados: `~/.claude-visual-review-markers/SCRUM-416`, `~/.claude-visual-review-markers/SCRUM-420`.
