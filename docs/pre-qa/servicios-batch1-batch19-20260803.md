# Pre-QA — Fase 4 Servicios, Batch 1 (SCRUM-279→284, 312) + Batch 19 (SCRUM-357→360)

**Fecha:** 2026-08-03
**Entorno:** Docker local (`atlanticerp-backend/infra/docker-compose.yml`), nginx en `http://localhost:8090`
sirviendo el build de producción del frontend. **NO desplegado a dev.atlanticerp.ai** — nada de este
trabajo se pusheó todavía.
**Resultado: BLOQUEADO — 2 hallazgos CRÍTICOS, 1 MENOR.** No pasa a QA.

Spec de referencia: tickets de Jira (SCRUM-279→284, 312, 357→360) + Excel adjunto
`5__Requerimientos_Servicios.xlsx` (hojas "Requerimientos Servicios" y "Matriz de Permisos por
Rol") — cruzados fila por fila contra REQ-216→221, REQ-249, REQ-287→290; sin discrepancias entre
Jira y Excel en estos REQs específicos.

Suite Playwright: `atlanticerp-frontend/e2e/preqa-scrum279-284-312-357-360-servicios-batch1-20260803.spec.ts`
(17 tests, corridos contra `http://localhost:8090`) — se conserva como test permanente por incluir
un gate crítico de estado y un crash de pantalla completa (ver regla `feedback_e2e_no_desechar`).

---

## CRÍTICO #1 — REQ-218 / REQ-221: mismatch de ruta rompe TODO cambio de estado desde la UI real

**El corazón del batch (REQ-218) es inalcanzable desde la aplicación real.**

- Frontend (`atlanticerp-frontend/src/api/serviciosApi.ts:17`):
  `api.patch<Ticket>(\`/servicios/tickets/${id}/status\`, { estado })`
- Backend (`atlanticerp-backend/routes/servicios.php:20`):
  `Route::patch('/{id}/estado', [TicketController::class, 'updateStage'])`

El frontend llama `PATCH /api/servicios/tickets/{id}/status`; el backend solo registra
`PATCH /api/servicios/tickets/{id}/estado`. Toda llamada real desde la UI devuelve **404**.

Confirmado en 2 capas:
1. `curl` directo: `/status` → 404. `/estado` → 200 (con el mismo payload, mismo usuario).
2. Playwright contra la app real (test 1 y 2 de la suite): cambiar el select de Estado en la
   tabla, o soltar una tarjeta en el Tablero, dispara la request a `/status`, recibe 404, y el
   frontend cae al mensaje genérico `"No se pudo cambiar el estado del ticket"` (nunca el mensaje
   específico de RN4, porque el catch solo distingue el caso 422).

**Impacto:** el gate de RN1-RN7 de REQ-218 está **perfectamente implementado en el backend**
(confirmado exhaustivamente vía API directa contra `/estado`, ver tests 13-16 — Escenarios 1, 4, 5,
6, RN6, RN7 con doble rol, todos correctos) pero es 100% inalcanzable desde el select de la tabla o
el arrastre del tablero — ni el camino feliz ni ningún camino de ruptura de REQ-218/REQ-221 se
puede ejercitar desde la app real hoy. Bloquea Escenario 1, 2, 3, 4, 6 de REQ-218 y Escenario 2, 3
de REQ-221 tal como los define el ticket ("cuando el usuario cambia su estado... desde la tabla" /
"arrastra su tarjeta").

**Cómo reproducir:** login como `servicio@atlantic.com.pa` → Tickets → cambiar cualquier
select de "Estado" en una fila → Network tab: `PATCH /api/servicios/tickets/{id}/status` → 404.

**Fix esperado:** una línea — alinear la ruta en `serviciosApi.ts` (`/status` → `/estado`) o en
`routes/servicios.php` (`/estado` → `/status`); cualquiera de los dos lados, decisión de
Arquitecto/Backend Dev sobre cuál nombre es el canónico del proyecto (ver que el resto del proyecto
usa `estado` como nombre de columna/vocabulario de negocio en español, por consistencia yo
inclinaría a mantener `/estado`).

---

## CRÍTICO #2 — pantalla en blanco: buscar cualquier texto con un ticket de `cliente=null` revienta TODA la tabla

`atlanticerp-frontend/src/pages/servicios/TicketsPage.tsx`, función `matchesSearch()`:

```ts
return ticket.numero.toLowerCase().includes(q)
  || ticket.cliente.toLowerCase().includes(q)   // <- sin verificar null
  || technicianName.includes(q)
```

`cliente` es nullable en el backend (`StoreTicketRequest`: `'cliente' => ['nullable', 'string',
'max:255']`) — consistente con que el endpoint mínimo de REQ-249 (alcance explícito de este batch)
todavía no incluye el buscador de Cliente Master/Subcliente/Proyecto del formulario completo
(REQ-245, batch futuro). Es decir: un ticket sin `cliente` es un estado alcanzable y esperado con
el propio endpoint de este batch, no un caso artificial.

Con al menos un ticket de `cliente=null` en la lista visible, **escribir cualquier carácter** en el
buscador de REQ-217 dispara:

```
TypeError: Cannot read properties of null (reading 'toLowerCase')
```

Sin `ErrorBoundary` que lo capture, React desmonta el árbol completo — la pantalla de Tickets queda
**completamente en blanco** (`document.body` vacío), para **cualquier rol** (Aaron, técnicos,
Gerencia, Vendedor/Diseñador), sin recuperación salvo recargar y evitar el buscador. Confirmado con
`page.on('pageerror')` + screenshot (`e2e/.tmp/preqa-servicios-batch1/15-CRASH-pantalla-en-blanco.png`)
en el test `9b` de la suite.

**Cómo reproducir:** crear un ticket vía `POST /api/servicios/tickets` sin el campo `cliente` (el
propio endpoint de REQ-249 lo permite) → Tickets → escribir una letra en el buscador → pantalla en
blanco.

**Fix esperado:** una línea — `(ticket.cliente ?? '').toLowerCase()` (y lo mismo por seguridad para
`ticket.numero` si puede ser null/undefined en algún escenario, aunque no está definido como
nullable). Recomendación adicional, no bloqueante: un `ErrorBoundary` a nivel de página en
`TicketsPage`/rutas de Servicios como red de seguridad general, para que un error de render futuro
muestre un estado de error en vez de pantalla en blanco.

---

## MENOR / observacional — REQ-220 RN1 vs Escenario 4: contradicción en el REQ mismo (confirmada también en el Excel)

RN1: *"Si el tipo/subtipo del ticket no requiere Informe de Inspección específicamente, se muestra
'No aplica' en esta columna — **esto incluye a los tickets de Reclamos**, que en cambio usan su
propio documento de diagnóstico equivalente, la Hoja de Reclamo..."*

Escenario 4: *"Dado un ticket de tipo Reclamos, ... entonces esta columna **NO muestra 'No
aplica'** — en su lugar se muestra el indicador de Hoja de Reclamo..."*

RN1 dice que Reclamos SÍ cae dentro del grupo que muestra "No aplica" en esta columna; Escenario 4
dice literalmente lo contrario. Confirmé que esta misma contradicción ya existe en el Excel
adjunto (no es un problema de transcripción a Jira) — es ambigüedad del requerimiento original.

La implementación actual sigue RN1 (Reclamos muestra "No aplica" en Informe de Inspección),
consistente con REQ-249 RN2 — **sin ambigüedad en ese REQ**, mismo batch: *"Reclamos → Informe 'No
aplica', Cotización pendiente de generar"*. La Hoja de Reclamo (REQ-278) es un batch futuro y no
existe todavía como indicador propio — mostrarla "en su lugar" hoy no es construible.

**No bloqueante.** Dejo esto documentado para que PM/Daniela reconcilien la redacción del REQ (cuál
de las dos frases es la intención real) antes de que se implemente REQ-278, para no repetir la
ambigüedad en ese batch.

---

## Checklist completo — qué se probó y pasó limpio

### Paso 0 (permisos/fixtures) — sin hallazgos
- REQ-218 RN7 no es un grant angosto tipo `SpecialPermissionSeeder` (no es "solo X persona") — es
  restricción por rol vía middleware `role:superadmin,lider_servicios` en la ruta, ya sembrado
  correctamente (migraciones `2026_08_02_100003`/`100004`, revisadas). Confirmé además que el
  backend rechaza con 403 a `tecnico_servicios` y `management` vía API directa aunque ambos tengan
  `servicios.edit` general — la restricción angosta se aplica en la ruta como documenta el propio
  código, doble capa correcta (frontend deshabilita + backend 403).
- Sin umbral/margen de negocio hardcodeado en este batch (no aplica, no hay REQ de ese tipo acá).
- 10 tickets sembrados en el entorno cubren los 6 estados del tablero (Reportado/Agendado/En
  sitio/Resuelto/Cerrado/Cancelado) — verificado antes de empezar. Sembré adicionales vía
  `TicketService::create()` real (API) para escenarios puntuales (Escenario 1/4/5/6/RN6 de
  REQ-218), y usé `tinker` UNA vez para forzar `quote_status=rejected` +
  `inspection_report_status=completed` en un ticket de prueba (Escenario 4) — documentado acá
  porque el módulo real de Cotización/Informe todavía no existe (Batch 8-9/11-12), no hay forma de
  llegar a ese estado por la vía real todavía.

### REQ-216 (Tabla) — limpio
- 10 columnas presentes, orden por defecto correcto (Reportado sin agendar primero, luego
  created_at desc).
- Subtipo "—" para Reclamo/Retrofit (sin subtipo).
- Escenario 2 — "Sin tickets para este filtro" confirmado con filtro tipo=retrofit+estado=cancelled
  (combinación sin match), contador "Mostrando 0 de 0 tickets".

### REQ-217 (Filtros) — limpio salvo el CRÍTICO #2 de arriba
- RN5 — confirmado que NO existe fila de chips de tipo redundante (0 botones de chip encontrados).
- RN4 — contador "Mostrando X de Y tickets" se actualiza correctamente.
- RN6 — filtros persisten al alternar Tabla↔Tablero↔Tabla (probado con el select de Tipo, dado que
  el buscador de texto dispara el CRÍTICO #2 — ver nota en el spec).
- El buscador en sí (case-insensitive, "contiene") no se pudo confirmar funcionalmente por el
  CRÍTICO #2 — bloqueado hasta el fix.

### REQ-218 (Gate de cierre) — backend perfecto, inalcanzable desde la UI por el CRÍTICO #1
Probado exhaustivamente vía API directa (`PATCH .../estado`, bypasseando el bug de ruta a
propósito para poder validar la lógica en sí):
- Escenario 1/2 (informe+cotización pending) → 422, mensaje "falta completar el informe de
  inspección" (RN4, prioriza informe sobre cotización cuando ambos fallan, como documenta el
  código).
- Escenario 4 (cotización rechazada sin reemplazo, informe completado) → 422, mensaje "falta
  aprobar la cotización" — RN2 confirmado.
- Escenario 5 (cancelar con ambos pending, y también desde el estado rechazado del Escenario 4) →
  200 sin bloqueo — RN5 confirmado en 2 combinaciones distintas.
- Escenario 6 (Instalación venta previa, ambos "No aplica") → Cerrado sin bloqueo — confirmado.
- RN6 (transición intermedia Reportado→En sitio con ambos pending) → 200 sin gate — confirmado.
- RN7 — 403 para `tecnico_servicios` y `management` vía API directa — confirmado ambos.
- Ruptura adicional (Paso 3): estado inválido fuera del enum → 422; ticket inexistente → 404; sin
  autenticación → 401 — los 3 correctos.

### REQ-219/REQ-220 (Indicadores) — limpio
Confirmado visualmente (screenshot `01-tabla-aaron.png`): "No aplica" para Instalación venta
previa (ambos indicadores); "🔒 Bloqueada" para ticket con informe pending (candado); "Generar
cotización" activo para Reclamo (informe ya "No aplica" → cotización lista); "Aprobada"/"Completado"
para ticket ya resuelto. El flujo de generar/completar en sí es placeholder a propósito (fuera de
alcance, confirmado por Visual Review previo).

### REQ-221 (Tablero) — limpio (mismo bloqueo del CRÍTICO #1 para el arrastre en sí)
- 6 columnas confirmadas incl. Cancelado (RN1).
- El mismo mutationFn roto que el select (línea de código idéntica, `serviciosApi.tickets.changeStatus`)
  — no hace falta simular el drag real con la librería `@hello-pangea/dnd` para confirmar que
  también rompe, es el mismo endpoint.

### REQ-249 (Numeración/creación) — limpio
- Numeración consecutiva confirmada (2 creaciones seguidas, consecutivo +1).
- Derivación de estados: Instalación venta previa → ambos "No aplica"; Reclamo → informe "No
  aplica", cotización "pending" — ambos confirmados.
- Ticket creado siempre en "Reportado", sin técnico, sin fecha agendada — confirmado.
- Ruptura: tipo vacío, subtipo cruzado (installation+warranty_generic), subtipo en claim (no debe
  tener), descripción vacía → los 4 casos devuelven 422 correctamente.
- Año dinámico (RN1/Escenario 4) no se pudo forzar sin cambiar el reloj del sistema — no probado
  directamente, pero el código lee `now()` del servidor (no hardcodea "2026"), revisado en
  `TicketFolioService`.

### REQ-287→290 (Navegación, Batch 19) — limpio
- Aaron: 5 pestañas completas + 2 opciones en el desplegable de Técnicos (internos+externos).
- Neil (`vendedor_disenador`): 3 pestañas (Inicio/Tickets/Técnicos), sin Insumos/Reportes, y el
  desplegable de Técnicos con una sola opción (sin "Técnicos externos") — RN2/RN3 confirmados.
- Felix (`lider_admin_contab`) y Yirena (`lider_compras`): sin ningún enlace "Servicios" en su
  sidebar, y navegación directa por URL a `/servicios/tickets` no expone contenido del módulo (cae
  al layout de su propio módulo) — RN4 confirmado para ambos.
- REQ-289 (menú ausente en vistas secundarias Movimiento de Herramientas/Informe móvil) — **no
  aplica todavía**, esas vistas no existen en ningún batch construido hasta ahora (Batch 14/10,
  futuros) — no es un hallazgo, es alcance no alcanzado todavía.
- REQ-290 (navegación real de router, no archivos HTML sueltos) — confirmado por construcción, todo
  el módulo ya es SPA con React Router.

---

## Conclusión

**BLOQUEADO.** 2 hallazgos CRÍTICOS, ambos de una línea de código, ambos con fix evidente y
acotado, pero ambos rompen funcionalidad central del batch (el gate de REQ-218 completo es
inalcanzable; la pantalla de Tickets completa se cae ante cualquier búsqueda con datos reales de
este mismo batch). Sigo el protocolo de loop: no se pasa a QA, se deja documentado acá y en Jira
para que PM reasigne. Cuando el fix esté hecho, corro el checklist COMPLETO de nuevo (no solo estos
2 puntos) antes de dar pasada limpia.

---

## RE-CHECK — 2026-08-03 (checklist completo, no solo los 2 puntos que fallaron)

**Fix aplicado:** commit `34415bd` (`fix(servicios): fix status-change route mismatch and
null-cliente search crash`) en `dev`, `atlanticerp-frontend`:
- `src/api/serviciosApi.ts` — `changeStatus()` ahora llama `PATCH .../estado` (antes `/status`).
- `src/pages/servicios/TicketsPage.tsx` — `matchesSearch()` ahora usa `(ticket.cliente ?? '')`.
- `src/types/servicios.ts` — `Ticket.cliente` corregido a `string | null` (coincide con el
  contrato real, `cliente` ya era nullable en `StoreTicketRequest` desde este mismo batch).
- Se actualizaron las 2 aserciones e2e de este mismo spec que originalmente confirmaban que los
  bugs reproducían (ahora confirman que el fix se sostiene, conservado como test permanente por
  regla del proyecto) + 1 test unitario nuevo en `TicketsPage.test.tsx`.

**Verificación independiente** (no me limité a confiar en que el dev ya corrió sus propios
tsc/vitest/e2e — los repetí yo mismo, y agregué cobertura que el checklist original nunca
ejercitó):

1. **Bundle servido en `localhost:8090` inspeccionado directamente** — confirmé en el JS
   compilado (`dist/assets/index-*.js`) que `changeStatus` llama literalmente
   `` `/servicios/tickets/${e}/estado` ``, y que ningún otro `/status` del bundle pertenece a
   Servicios (son de MFA, Compras/claims, Bodega/orders, Users/Departments/SecurityLevels —
   módulos no relacionados). `npx tsc --noEmit` limpio, `npx vitest run
   src/pages/servicios/TicketsPage.test.tsx` 12/12, `npx playwright test
   e2e/preqa-scrum279-284-312-357-360-servicios-batch1-20260803.spec.ts` 18/18 — corridos por mí
   en este re-check, no asumidos del reporte del dev.

2. **REQ-218 — camino de RUPTURA ejercitado por primera vez desde la UI real** (el checklist
   original solo pudo probar el bloqueo de RN1-RN7 vía API directa, bypaseando el bug de ruta —
   nunca desde el select). Con la ruta ya corregida, creé un ticket de Garantía fresco (informe +
   cotización `pending` por defecto) y cambié su select de Estado a "Cerrado" desde la Vista Tabla
   real, como Aaron: la request pega a `/estado`, responde **422**, el select hace **rollback**
   al valor anterior (`reported`), y se muestra el mensaje **específico** de RN4 ("No se puede
   cerrar: falta completar el informe de inspección") — nunca el genérico. Esto cierra el hueco de
   cobertura más importante que quedaba: antes de este re-check, el camino de bloqueo de REQ-218
   nunca se había visto funcionar en la aplicación real, solo en el backend aislado.

3. **CRÍTICO #2 — confirmado sin crash, y auditados TODOS los lugares que leen `ticket.cliente`**
   en el frontend (`TicketTable.tsx`, `TicketBoard.tsx`, `TicketsPage.tsx` — no hay más). Creé
   tickets con `cliente=null` reales (vía `POST /api/servicios/tickets` sin el campo, mismo
   camino que describe el hallazgo original) y escribí en el buscador: la tabla se mantiene
   completa, sin `pageerror`, sin pantalla en blanco.

**Hallazgo nuevo — MENOR, no bloqueante:** `TicketTable.tsx:59` (`<div>{ticket.cliente}</div>`) y
`TicketBoard.tsx:52` (`<p>{ticket.cliente}</p>`) renderizan el campo cliente directo, sin
fallback. Con `cliente=null` no crashea (React omite `null` al renderizar), pero deja una línea/div
en blanco en vez de un placeholder como `"—"` — el patrón que sí se usa en el resto del código base
para el mismo tipo de campo nullable (ej. `OrderCardTile.tsx`: `{order.cliente ?? '—'}`).
Confirmado visualmente en Vista Tablero (captura `e2e/.tmp/preqa-recheck/render-cliente-null-board.png`,
gitignored) — la tarjeta del ticket sin cliente muestra un hueco en blanco entre el badge de
tipo y el indicador de cotización. No es una regresión de este fix (el dato ya era nullable en el
backend desde antes del fix, el fix solo corrigió el tipo TS y el buscador) — es un gap que el
checklist original tampoco había atrapado, porque no había tickets `cliente=null` visibles en la
siembra original de 6 tickets. No bloquea este batch; queda documentado para que se agregue el
mismo `?? '—'` en las dos líneas cuando se retome Servicios (fix trivial, mismo patrón que el resto
del código, sin necesidad de spec nueva).

**MENOR previo (REQ-220 RN1 vs Escenario 4) — re-confirmado, sigue igual, sin resolver a
propósito** (código y REQ sin cambios desde la pasada anterior — no le corresponde a este fix
tocarlo). Sigue pendiente de que PM/Daniela reconcilien la redacción antes de REQ-278.

### Conclusión del re-check

**APROBADO — pasa a QA.** Los 2 CRÍTICOS están corregidos y confirmados con cobertura *mayor* a
la del checklist original (el camino de bloqueo de REQ-218 ahora se probó desde la UI real, no
solo vía API). 1 hallazgo MENOR nuevo (render en blanco de cliente vacío, no bloqueante) + 1 MENOR
previo (contradicción REQ-220, sin resolver a propósito) quedan documentados para tratamiento
posterior, ninguno bloquea el batch. Resto del checklist (REQ-216, REQ-219, REQ-249, REQ-287→290)
re-confirmado sin cambios ni regresiones.
