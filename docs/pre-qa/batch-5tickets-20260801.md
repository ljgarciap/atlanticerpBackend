# Pre-QA — Batch de 5 tickets, 2026-08-01

**Fecha:** 2026-08-01
**Reviewer:** Pre-QA
**Alcance:** Senior Review (encontró y corrigió spinner infinito de SCRUM-105) + Visual Review
(encontró y corrigió overflow de tabla en SCRUM-203) ya pasaron. Esta pasada es Pre-QA
adversarial en vivo — Docker local (`localhost:8090`) + Vite dev server (`localhost:5173`),
Playwright real, tinker para fixtures/verificación DB, `curl` directo contra la API para
confirmar enforcement de permisos en el backend (no solo ocultamiento de UI). El batch todavía
no se pusheó a `dev.atlanticerp.ai` — commits solo locales en `dev` de ambos repos, por instrucción
explícita de esta sesión (override de la regla por defecto de "todo fix se pushea a dev").

**Tickets:** SCRUM-718 (REQ complemento — filtros multiselect Clientes), SCRUM-203 (REQ-140 —
tabla Ver Órdenes, columna "Proyecto asignado"), SCRUM-105 (REQ-083 — avance de Pipeline + guard
de cotización duplicada), SCRUM-711 item 2 (menú lateral siempre colapsado al iniciar sesión),
SCRUM-140 (REQ-048/049 — Vista Externa sin Observaciones/pago/Condiciones).

**Test permanente agregado:** `atlanticerp-frontend/e2e/preqa-batch-5tickets-20260801.spec.ts` — 19
tests, cubre los 5 tickets con sus escenarios de ruptura. Corre limpio en 3 corridas completas
consecutivas (~1.3 min cada una). `npx vitest run` (771/771) y `npm run build` también en verde.

---

## Resultado general

**Pasada limpia en los 5 tickets — 0 hallazgos bloqueantes tras el checklist de ruptura
completo.** Un hallazgo inicial de metodología propia (falso positivo en SCRUM-711, detallado
abajo) se descartó tras verificación cruzada, no llegó a ser un hallazgo real del producto.

| Ticket | Resultado |
|---|---|
| SCRUM-718 (filtros multiselect Clientes) | Pasada limpia |
| SCRUM-203 (REQ-140, "Proyecto asignado" multi-proyecto) | Pasada limpia — 1 hallazgo de wording no bloqueante (ver abajo) |
| SCRUM-105 (REQ-083, avance de Pipeline + guard) | Pasada limpia, confirmado en 2 corridas completas |
| SCRUM-711 item 2 (Sidebar colapsado) | Pasada limpia — ver nota de metodología importante abajo |
| SCRUM-140 (REQ-048/049, Vista Externa) | Pasada limpia |

---

## SCRUM-718 — filtros multiselect de Clientes

**Escenarios de ruptura intentados:**
| Escenario | Resultado |
|---|---|
| Seleccionar A + C + "Sin contacto +60 días" simultáneamente | OK — los 3 quedan con la misma clase "activa" (criterio 1/2/5) |
| Deseleccionar UNO de los 3 (A) sin tocar los otros | OK — C y "Sin contacto +60 días" no se auto-limpian (criterio 3) |
| Click en "Todos" con 2+ chips activos | OK — limpia todo, "Todos" vuelve a resaltarse solo |
| Recargar la página a mitad de un filtro activo | Se resetea (no persiste en localStorage) — **no es un hallazgo**, ningún criterio de aceptación pide persistencia entre recargas, solo "hasta deselección manual explícita" |

**Lo que sí funciona:** multiselect real sin exclusividad de "Sin contacto +60 días" (bug viejo
de SCRUM-93 completamente resuelto), estilo visual consistente entre los 5 chips, toggle
simétrico sin race conditions en doble clic rápido (usa `prev =>` updater).

---

## SCRUM-203 — "Ver detalle" con múltiples proyectos en una orden

**Fixtures creadas para esta sesión** (Docker local, tabla `illuminations_compras.purchase_orders`):
- Orden **#221** — 3 líneas: proyecto 290, proyecto 291, 1 línea sin proyecto → 2 proyectos
  DISTINTOS entre sus líneas (el escenario literal del ticket).
- Orden **#222** — 2 líneas: proyecto 292, 1 línea sin proyecto → 1 solo proyecto distinto
  (caso límite de asignación parcial, ver hallazgo abajo).
- Orden **#220** (preexistente) — 1 línea sin proyecto → 0 proyectos.

**Escenarios de ruptura:**
| Escenario | Resultado |
|---|---|
| Orden con 2 proyectos distintos entre líneas (#221) | Muestra un botón "2 proyectos" clickeable → navega a `/compras/ordenes/221`. Nunca un nombre de proyecto suelto/incompleto |
| Orden con 1 proyecto + 1 línea sin asignar (#222) | Muestra el nombre del único proyecto asignado, no "Ver detalle" — ver hallazgo MEDIO abajo |
| Orden sin ningún proyecto asignado (#220) | Muestra "Sin proyecto" — comportamiento correcto, tercer estado no roto |
| Rol sin `compras.*` (designer) — UI | No ve datos reales en `/compras/ordenes` |
| Rol sin `compras.*` (designer) — API directa | **403** confirmado vía `curl` contra `GET /api/compras/orders` — el gate bloquea de verdad en el backend, no es solo un botón escondido en el frontend |
| Rol con `compras.view` (management) — API directa | **200** confirmado |

**MEDIO (no bloqueante) — wording no coincide con el texto literal del ticket:** el criterio de
Jira dice textualmente `entonces dice 'Ver detalle'`. La implementación real muestra `"N
proyectos"` (ej. `"2 proyectos"`) como botón clickeable — cumple 100% el *intent* del criterio
(nunca un nombre único/incompleto cuando hay ambigüedad, siempre lleva al detalle real), pero el
texto no es el literal "Ver detalle". Marco esto como hallazgo de wording, no de funcionalidad —
decisión de si vale la pena alinear el copy exacto queda para Luis/PM, no bloquea el paso a QA.

**MEDIO (no bloqueante) — caso límite de asignación parcial no cubierto por el criterio escrito:**
una orden con 1 línea asignada a un proyecto + 1 línea SIN proyecto (orden #222) se presenta con
el nombre del único proyecto asignado, como si el 100% de la orden le perteneciera. El criterio
de Jira solo habla de "2 proyectos DISTINTOS", no de "asignación parcial + líneas sin proyecto",
así que no es técnicamente un incumplimiento del texto — pero es un caso adyacente que un
usuario real puede interpretar como "toda la orden es de ese proyecto" cuando en realidad una
parte no tiene proyecto asignado. Documentado para que PM decida si amerita ampliar el criterio.

**Lo que sí funciona:** las 13 columnas + Acciones (confirmadas en el spec previo de Visual
Review, no re-verificado línea por línea acá), el fix de `overflow-x-auto` de Visual Review sigue
vigente (botón "Ver" alcanzable), permisos de vista/edición separados (`compras.read` vs
`compras.write`) enforced en el backend.

---

## SCRUM-105 — avance de Pipeline + guard de cotización duplicada

Escenarios (a)/(b)/(c) del ticket, TODOS ejecutados sobre la MISMA tarjeta de Lead creada por
este batch (no un atajo con tarjetas ya existentes), vía flujo real de UI de punta a punta:
crear Lead → setear Cliente Master/Subcliente/Contacto → "Crear cotización" → llenar
Descripción/Arquitecto/Entrega/Partida+Ítem → "Guardar y generar cotización".

| Escenario | Resultado |
|---|---|
| (a) Crear tarjeta en Lead → generar cotización → confirmar avance a Cotización | OK — confirmado por UI (columna Cotización) y por DB (`pipeline_cards.stage = 'quote'`) |
| (c) — probado ANTES de generar cualquier folio: 2do "Crear cotización" sobre la MISMA tarjeta sin generar la 1ra | OK — crea un SEGUNDO borrador sin folio, sin bloquear (el guard solo mira `whereNotNull('folio')`) |
| (b) Generar folio en uno de los borradores → volver a "Crear cotización" sobre la MISMA tarjeta | OK — **422 + `existing_quote_id`** confirmado, frontend redirige a la cotización existente (`replace: true`), **sin spinner infinito** — el bug original que motivó el ticket queda cerrado |
| Confirmación cruzada en DB tras 2 corridas completas del flujo | En cada corrida: exactamente 1 quote con folio por proyecto, N borradores sin folio conviviendo sin conflicto — comportamiento consistente y repetible |

**Detalle técnico verificado en código** (no solo en runtime): el guard
(`QuoteService::create()`) compara por `sales_project_id` + `whereNotNull('folio')`, y
`advanceCardToQuoteStage()` solo avanza si la etapa actual está estrictamente antes de `'quote'`
en `PipelineCard::stageOrder()` — nunca regresiona una tarjeta ya en Propuesta/Aprobado. Descarté
una preocupación teórica inicial (¿el guard se salta si `sales_project_id` es null?) verificando
la migración: `sales_project_id` es `NOT NULL` en el schema, ese camino no es alcanzable.

**Lo que sí funciona:** la nota de arquitectura ya aceptada ("Guardar y generar cotización" en vez
de un botón "Enviar" separado) sigue vigente, no la re-reporto. Redirect usa `existing_quote_id`
tal cual, sin ambigüedad con otros campos de error.

---

## SCRUM-711 item 2 — Sidebar SIEMPRE colapsado al iniciar sesión

**Nota de metodología importante para quien retome este ticket (evita perder tiempo
redescubriéndolo):** un browser context 100% nuevo de Playwright arranca en el sidebar en modo
"riel de íconos" (ancho colapsado) por default — mecanismo previo y ya intencional
(`sidebar-collapsed-migrated-v711` + `setAuth()` fuerza `sidebar-collapsed=true` en TODO login
real, ver `authStore.ts`). En ese modo riel, el código deliberadamente hace `groupCollapsed =
false` siempre (no tiene sentido colapsar por sección si ya se ve solo íconos) — así que TODOS
los ítems aparecen como botones de ícono con `title=label`, **sin que eso implique que el
ACORDEÓN de la sección esté expandido**. Hay que expandir el ANCHO ("Expandir menú") antes de
poder observar el estado real del acordeón.

**Hallazgo descartado en la misma sesión (documentado para que no se repita):** mi primer intento
de probar "expandir → cerrar sesión → volver a loguear → ¿sigue colapsado?" reportó un FALSO
CRÍTICO — parecía que el acordeón conservaba el grupo expandido tras el ciclo completo. Llegué a
escribir un fix (reset de `openGroups`/`openDropdowns` vía `useEffect` atado al cambio de
`user.id`) antes de verificar la causa raíz real. Al aislar la variable (`git stash` del fix +
re-correr el mismo test corregido) confirmé que el código YA estaba correcto — la falla era
metodológica: no había vuelto a expandir el ANCHO tras el segundo login (que `setAuth()` fuerza a
riel en cada login, por diseño), así que estaba viendo el riel de íconos, no el acordeón
expandido. Revertí el fix (no hacía falta) y corregí el test. **Ninguna línea de producto se
tocó** — el hallazgo real fue en mi propio test, no en el código de AtlanticERP.

| Escenario (con la metodología correcta: expandir ANCHO primero) | Resultado |
|---|---|
| Login en contexto limpio, ancho expandido | Ningún submenú desplegado — ni "Pipeline"/"Clientes" (grupo CRM) ni "Seguridad" (grupo Configuración) aparecen antes de un clic explícito |
| Clic en el header "CRM" | Expande SOLO ese grupo — "Pipeline"/"Clientes" visibles, "Seguridad"/"Cotizaciones" (otros grupos) siguen colapsados |
| Ciclo completo: expandir "CRM" → Cerrar sesión (real, confirmado por token/localStorage limpio) → volver a loguear (mismo tab, sin reload forzado por mí) → re-expandir ancho (forzado a riel por diseño en cada login) → ¿el acordeón nace colapsado? | **Sí** — el AC se cumple literalmente, aunque el usuario dejó una sección expandida antes de cerrar sesión |

**Nota de nomenclatura:** "Pipeline" y "Clientes" viven hoy en el grupo **"CRM"**, no "Ventas &
Diseño" (Epic CRM Batch A, SCRUM-673→676, reubicación ya documentada en el propio código) — el
AC original de SCRUM-711 nombra "Ventas & Diseño" pero la sección real donde vive ese submenú
específico cambió de nombre en un batch posterior. No es un hallazgo, solo una aclaración para
que el próximo test/lectura del ticket no se confunda buscando el grupo equivocado.

---

## SCRUM-140 — Vista Externa sin Observaciones/pago/Condiciones

Reusa la cotización con folio generada por el bloque SCRUM-105 (evita rearmar una cotización
completa solo para esto).

| Escenario de ruptura | Resultado |
|---|---|
| Vista Previa vs Vista Externa, cotización con `conditions_text` poblado | Previa: SÍ muestra Condiciones. Externa: Condiciones/Observaciones/instrucciones de pago (cheque/ACH) desaparecen POR COMPLETO — no un contenedor vacío, el bloque entero no se renderiza (`{!external && (<>...</>)}`) |
| `observations`/`conditions_text` null o vacíos | Vista Externa no deja un "—" residual ni un contenedor fantasma donde antes iba ese contenido |
| `conditions_text` MUY largo (5640 caracteres, forzado vía tinker para simular el escenario de paginación) | El documento sigue ocultando Condiciones limpio, sin salto de página vacío ni contenedor huérfano — el `colSpan` condicional (`external ? 3 : 6`) sigue siendo consistente |
| Modo oscuro + Vista Externa, pantalla | Pie de contacto (`companyFooter`, sigue visible en ambas vistas por decisión de producto) legible — `dark:text-slate-500` sobre `dark:bg-slate-900`, contraste aceptable a simple vista |
| Modo oscuro + Vista Externa, impresión emulada (`page.emulateMedia('print')`) | El fix de SCRUM-716 (`@media print { html.dark .quote-doc {...} }`) sigue vigente — fondo blanco forzado, texto oscuro legible, confirmado por `getComputedStyle().backgroundColor === 'rgb(255, 255, 255)'` |

**Nota de investigación previa a la corrida (aclaración, no hallazgo):** el contexto de esta
sesión mencionaba "SCRUM-716 ya tiene un fix conocido para el dark mode del footer" — el fix real
de SCRUM-716 (commit `a1d14b1`) no toca el footer específicamente ni `QuoteDocument.tsx`, toca
`src/index.css` con un override genérico `@media print` para TODO `.quote-doc` en tema oscuro.
Confirmado en vivo que ese override sigue cubriendo el escenario completo (incluida Vista
Externa), así que el comportamiento esperado se cumple aunque la referencia exacta al commit no
era 100% precisa.

**Lo que sí funciona:** paginación real delegada al motor de impresión del navegador (sin
librería de PDF, por diseño ya documentado), gate `!external` aplicado de forma granular por
celda en la tabla de ítems (precio/descuento/total), no solo por bloque.

---

## Test permanente

`atlanticerp-frontend/e2e/preqa-batch-5tickets-20260801.spec.ts` — 19 tests, serial
(`test.describe.configure({ mode: 'serial' })`), `BASE = process.env.PREQA_BASE_URL ??
'http://localhost:5173'`. El describe de SCRUM-140 acepta `PREQA_QUOTE_ID` como env var para
correr independiente de SCRUM-105 en el mismo proceso (reusa cualquier cotización con folio ya
existente). 3 corridas completas consecutivas, 19/19 en verde cada vez (~80s por corrida).

Fixtures de datos dejadas en Docker local (no afectan `dev.atlanticerp.ai`/`test.atlanticerp.ai`):
- `purchase_orders` #221 (multi-proyecto), #222 (parcial-null) — tabla `illuminations_compras`.
- Múltiples tarjetas Lead + cotizaciones `PreQA SCRUM-105 <timestamp>` — quedan como ruido de
  demo, no afectan ningún reporte real (nombres claramente marcados).
- Quote 214: `conditions_text` sobrescrito con texto largo (5640 caracteres) para el test de
  paginación — si alguien reutiliza esta cotización para otra demo, resetear ese campo.

---

## Decisión

**Los 5 tickets → pasan a `QA`.** Ningún hallazgo bloqueante. 2 hallazgos MEDIO no bloqueantes en
SCRUM-203 (wording "Ver detalle" vs "N proyectos"; caso límite de asignación parcial no cubierto
por el criterio literal) — quedan documentados para que PM decida si ameritan un ticket de ajuste
de copy/alcance, no impiden el paso a QA.

**Comentarios sugeridos para Jira (a postear por Luis, no por mí):**

> **SCRUM-718:** Pre-QA — pasada limpia. Multiselect real confirmado (A+C+"Sin contacto +60d"
> simultáneos), deselección parcial no auto-limpia el resto, "Todos" resetea correctamente. Sin
> hallazgos. → QA.

> **SCRUM-203:** Pre-QA — pasada limpia funcionalmente, 2 notas MEDIO no bloqueantes: (1) el
> texto real es "N proyectos" (botón clickeable a detalle), no literalmente "Ver detalle" como
> dice el criterio — mismo intent, distinto copy; (2) una orden con 1 proyecto asignado + líneas
> sin proyecto se muestra con el nombre del proyecto como si fuera 100% de la orden — caso límite
> no cubierto por el criterio escrito, evaluar si amerita ajuste de alcance. Gate de permisos
> Ver=Compras/Gerencia confirmado con 403/200 reales contra el backend. → QA.

> **SCRUM-105:** Pre-QA — pasada limpia, los 3 escenarios de ruptura (avance a Cotización, guard
> de duplicado con redirect sin spinner, 2 borradores sin folio permitidos) confirmados de punta
> a punta por UI real sobre la misma tarjeta, y repetidos en 2 corridas completas con resultado
> consistente. → QA.

> **SCRUM-711:** Pre-QA — pasada limpia. Confirmado el ciclo completo expandir→cerrar
> sesión→volver a loguear→acordeón nace colapsado. Nota: "Pipeline"/"Clientes" viven hoy en el
> grupo "CRM" (Epic CRM Batch A), no "Ventas & Diseño" como decía el AC original — solo
> reubicación de nombre, sin impacto funcional. → QA.

> **SCRUM-140:** Pre-QA — pasada limpia. Observaciones/instrucciones de pago/Condiciones ausentes
> por completo en Vista Externa (sin residuos ni saltos de página vacíos, incluso con texto muy
> largo), pie de contacto legible en pantalla y en impresión (modo oscuro incluido, fix SCRUM-716
> sigue vigente). → QA.

**Transición sugerida:** `Dev Testing` → `QA` para los 5 tickets.
