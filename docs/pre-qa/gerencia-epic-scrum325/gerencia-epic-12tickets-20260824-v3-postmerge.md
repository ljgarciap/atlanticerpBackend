# Pre-QA adversarial — Epic Gerencia (SCRUM-325), TERCERA PASADA — post-merge de `gerencia → dev`

**Fecha:** 2026-08-24 (madrugada, continuación de la sesión del 23-ago)
**Acción tomada:** mergeados `atlanticerp-backend#3` y `atlanticerp-frontend#2` (rama `gerencia → dev`) a
pedido explícito de Luis, tras revisar el diff completo. Deploy manual a `dev.atlanticerp.ai` (CI/CD real
sigue caído por el outage de billing de GitHub Actions) — backend en `01f2542`, frontend con
`dist/` nuevo vía rsync + reload de nginx. Verificado: `dev.atlanticerp.ai` responde 200, backend HEAD
confirmado por SSH.
**Spec:** `atlanticerp-frontend/e2e/preqa-scrum-gerencia-12tickets-20260824-v3-postmerge.spec.ts` —
13/13 verde. (El spec de la segunda pasada, `...20260823-v2...`, quedó con selectores del copy
viejo tras el merge — reemplazado por este, no tiene sentido mantenerlo con locators que ya no
matchean nada.)

**⚠️ No revisado en Jira todavía** — mismo criterio de alcance que las pasadas anteriores (epic de
otro equipo): mergeé/desplegué porque Luis lo pidió explícito, pero no toqué ningún ticket de Jira.
Ver "Siguiente paso" al final.

---

## Qué arregló el merge (confirmado en vivo)

| Hallazgo | Estado |
|---|---|
| **SCRUM-171** — "Ver detalle" de Bodega navegaba a Ventas & Diseño | ✅ **Arreglado.** Ahora navega a `/bodega/home` |
| **MEDIO transversal** — `/gerencia` no redirigía a un rol sin acceso | ✅ **Arreglado.** `RequirePermission` ahora redirige de verdad (confirmado con Vendedor/Diseñador — ya no se ve la pantalla de error) |
| **SCRUM-157/158** — clic en las tarjetas grandes (CxC, Variación margen) sin feedback | ✅ **Arreglado como efecto lateral.** Ahora navegan a `/admin-contab` |
| **SCRUM-156/159/160** — tarjetas no navegaban | ✅ **Arreglado a nivel "navega".** Van a `/admin-contab` o `/ventas-diseno` — sin filtro todavía (ver abajo) |
| **SCRUM-161/162** — "Aprobaciones" era un stub total (`aprobaciones: []` hardcodeado) | ✅ **Ya no es un stub** — hay lógica real (POs, reubicaciones, ajustes, conteos, Zona Libre) |

## Qué sigue roto o quedó incompleto (confirmado en vivo post-deploy)

| Hallazgo | Estado |
|---|---|
| **SCRUM-166** — los 4 indicadores de Salud Ventas&Diseño navegan todos al mismo `/ventas-diseno` sin filtrar | ❌ **Sigue exactamente igual.** "En Final Stage" y "Pipeline total" confirmados navegando al mismo destino |
| **SCRUM-168** — falta el indicador "Bajo stock sin ordenar"; los 4 indicadores de Compras navegan al mismo `/compras` sin filtrar | ❌ **Sigue exactamente igual.** El indicador que pide el Escenario 1 del Gherkin sigue sin existir |
| **SCRUM-159** — filtros Vendedor/Cliente | 🟡 Los `<select>` ya existen (RN1 estructural), pero cada uno solo tiene la opción "Todos los..." — sin lista real, no se puede filtrar por una persona específica todavía |
| **SCRUM-160** — margen del año/mes | 🟡 Etiquetas correctas, pero `margen_anio_pct`/`margen_mes_pct` siguen en `0` fijo — confirmado por la respuesta cruda de `/api/gerencia/home`, coincide con el TODO que dejó el propio autor en el código |
| **SCRUM-156/159/160** navegación | 🟡 Navegan, pero al módulo raíz sin filtro — RN3 pide "detalle filtrado", no solo "navega a algún lado" |
| **SCRUM-161 RN1/RN2** — personalización por usuario ("Aprobador, Gerencia2 o Gerencia5, cada uno ve sus propios tipos autorizados") | ❌ **Gap de diseño, no de bug.** Lo implementado es un único `mark_approver_user_id` global (hoy: Aprobador/`mbekhar`) — todo el que no sea esa persona ve "Solo para aprobadores", confirmado en vivo con Gerencia5. El modelo del ticket (varios aprobadores, uno por tipo de solicitud) no está — sería necesario rediseñar, no es un ajuste menor |
| **SCRUM-162 RN2** — Aprobar/Rechazar sin salir de Gerencia | ❌ **No implementado.** Solo existe "ir al módulo de origen" (navega a la lista, ej. `/compras/ordenes` — tampoco abre el registro puntual como pide RN3 literal) |

**No verificado en vivo:** el camino feliz completo de aprobar/rechazar como Aprobador (`gerencia3@test.com`) — su password ya no es el default, no hay credencial disponible en esta sesión para loguearlo. Confirmado por consulta directa a BD que `mark_approver_user_id = 16` (mbekhar) en `atlantic_compras.settings`.

## SCRUM-165 (Día/Semana/Mes)
Sin cambios — el PR no tocó código de calendario. Sigue limpio (pasada de la sesión anterior).

---

## Resumen — 12 tickets, dónde quedan hoy

- **Arreglados de verdad:** SCRUM-171, y el gate de permisos transversal (afecta a los 12 por igual).
- **Con avance real pero todavía con hallazgos:** SCRUM-156, 157, 158, 159, 160, 161, 162 — ya no son los mismos 6 CRÍTICO de origen, pero ninguno pasa una pasada limpia hoy (ver tabla).
- **Sin ningún cambio:** SCRUM-166, SCRUM-168 — mismos 2 CRÍTICO de las dos pasadas anteriores.
- **Limpio:** SCRUM-165.

**Ningún ticket de los 12 pasa una pasada Pre-QA 100% limpia todavía** — hay menos y más chicos hallazgos que al principio, pero sigue sin ser "listo para QA formal" para ninguno excepto SCRUM-165 (que ya estaba limpio antes del merge).

## Siguiente paso — pendiente de decisión de Luis
No toqué Jira (comentarios ni transiciones) — mismo criterio que las pasadas anteriores. Con este
avance real ya hecho (merge + deploy), tiene más sentido que antes dejar todo esto documentado en
cada ticket para el otro equipo. Si Luis quiere, en la próxima instrucción puedo:
1. Comentar en cada uno de los 9 tickets con hallazgos su estado actualizado (arreglado / parcial / gap de diseño).
2. Transicionar algo puntual si corresponde (ninguno de los 12 califica para `QA` limpio todavía, según el criterio de Pre-QA).
