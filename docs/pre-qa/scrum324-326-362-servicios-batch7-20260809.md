# Pre-QA — Fase 4 Servicios, Batch 7 (final de Fase 2 — Equipo): SCRUM-324/326/362

Fecha: 2026-08-09
Tickets: SCRUM-324 (REQ-261 estadísticas generales), SCRUM-326 (REQ-262 toggle Equipo/Agenda),
SCRUM-362 (REQ-292 captura mensual de comisión + SLA por tipo de ticket).
**SCRUM-321 (REQ-258, Comisión del mes real — total ponderado, $, redistribución por licencia
médica) NO tocado** — confirmado explícitamente fuera de alcance de este batch, ver docblock de
`CommissionCalculationService`.

Entorno: stack local (Docker `infra/docker-compose.yml`, nginx `:8090` + Vite dev server `:5173`
levantado para esta sesión y detenido al terminar), rama `dev` en `atlanticerp-backend`/`atlanticerp-frontend`,
cambios de este batch **no commiteados todavía**. No se corrió contra `dev.atlanticerp.ai`.

## Resultado

- **SCRUM-324 (REQ-261)** — Pasada limpia. 0 CRÍTICO, 0 MEDIO. → `QA`.
- **SCRUM-326 (REQ-262)** — Pasada limpia. 0 CRÍTICO, 0 MEDIO. → `QA`.
- **SCRUM-362 (REQ-292)** — 1 CRÍTICO encontrado y **corregido en el momento** (condición de
  carrera, loop cerrado, re-verificado). 1 CRÍTICO estructural sin resolver (selector de
  período ausente) — **queda en `Dev Testing`**, no transiciona a `QA` todavía. Ver detalle abajo.

## SCRUM-324 (REQ-261) — Estadísticas generales del equipo

### Camino feliz + ruptura ejercitados
- Escenario 1 literal (4 técnicos, 3 activos) — verificado con el roster real (4 técnicos,
  seedeados vía `InternalTechnicianSeeder`); formato exacto `"X de Y registrados"` confirmado
  contra el string i18n `activeTodayValue`.
- RN1 "activos hoy" = estado ≠ "Fuera" — verificado en vivo vía API (`GET .../stats`) a las 20:47
  hora de Panamá (fuera de horario laboral 08:00–18:00): `activos_hoy: 0` de 4 — correcto, no un
  bug de este batch (mismo criterio de REQ-256/Batch 6, ya cubierto por reloj congelado en
  `InternalTechnicianBatch7Test.php::test_stats_fuera_de_horario_nadie_esta_activo`). No fue
  posible ejercitar en vivo el caso "activo dentro de horario" por la hora real de esta sesión —
  cubierto en cambio por los 3 tests automatizados con `Carbon::setTestNow()` (dentro y fuera de
  horario, con y sin visitas), corridos en vivo (`infra/test.sh`, 13/13 verdes).
- RN2 "visitas hoy" suma del equipo — cubierto por test automatizado con un ticket agendado hoy
  para Carlos Vergara (`test_stats_suma_visitas_de_todo_el_equipo`), corrido en vivo.
- RN3 "promedio 1ra visita excluye técnicos sin historial" — hoy siempre `null` porque
  `pct_resuelto_primera_visita` es un placeholder documentado (REQ-211/Batch 15 no existe
  todavía) — UI muestra "—", nunca "0%" ni "NaN%". Confirmado en pantalla y por test
  (`muestra "—" cuando ningún técnico tiene historial`).
- Sin auth / sin permiso `servicios.read` → 401/403 (mismo middleware que el resto del módulo, sin
  cambios propios de este batch).

### Paso 0
Ningún umbral de negocio nuevo, ningún permiso angosto por persona en este ticket — RN1/RN2/RN3
son puramente de cálculo, visibles para todos los roles del módulo (confirmado, sin gap de grant).

## SCRUM-326 (REQ-262) — Toggle Equipo / Agenda equipo

### Camino feliz + ruptura ejercitados
- Escenario 1 literal — cambiar de Equipo a Agenda y volver, sin recarga visible ni pérdida de
  datos. Verificado en vivo con Playwright: al volver a "Equipo" tras pasar por "Agenda equipo",
  "Carlos Vergara" es visible con timeout de 1000ms (sin `waitFor`/loading intermedio) — confirma
  que TanStack Query sirve el dato cacheado de inmediato (stale-while-revalidate), no un
  refetch-then-render.
- RN1 "ambas vistas leen de los mismos datos" — el código de este batch no agrega lógica nueva acá
  (REQ-262 ya estaba resuelto desde Batch 6, confirmado por inspección de
  `InternalTechniciansPage.tsx`: `enabled: view === 'team'` deshabilita el refetch de la lista sin
  invalidar/limpiar el cache existente al cambiar de vista) — Pre-QA solo verifica que sigue
  cumpliendo el criterio, no hay regresión.
- Sin hallazgos.

## SCRUM-362 (REQ-292) — Captura mensual de comisión + SLA por tipo

### Escenarios literales de Jira — los 3 pasan
- Escenario 1 (conversión automática al guardar) — verificado end-to-end (API + UI): 4.7→100%,
  0 incidencias→100%; también verificado en valores boundary exactos (ver abajo).
- Escenario 2 (SLA configurable) — Gerencia (`daniela@illuminations.com.pa`) ajusta `warranty` de
  3→4 vía `PATCH .../sla-settings`, el resto de los tipos no cambia (confirmado con
  `assertJsonPath('data.claim', 2)` en el mismo request).
- Escenario 3 (mes sin capturar → "Pendiente de captura") — verificado en UI (captura de pantalla)
  y con test automatizado.

### RN3 — las 3 fórmulas de conversión en valores boundary exactos
Probado en vivo vía API y UI (no solo con valores "cómodos" alejados del límite):
- Satisfacción exactamente `4.5` → 100% (límite inferior de la banda alta). Exactamente `3.5` →
  60% (límite inferior de la banda media, confirma que la comparación es `>=` no `>`).
- Puntualidad exactamente `1` incidencia → 50% (no 100% ni 0%, confirma `=== 1` explícito).
- Actitud `2.5` → 50% (fórmula lineal `(2.5/5)*100`), `4.5` → 90%.
Los 3 boundary suman evidencia consistente con `CommissionCalculationService` — sin off-by-one.

### RN1/RN5 — `has_bonus_plan` (solo Carlos Vergara) + rol (Aaron/Gerencia/superadmin)
- Botón "Comisión del mes" solo visible en la tarjeta de Carlos Vergara — ausente en Miguel
  Castillo, Pedro Santos, Agustín Rodríguez (los 3 sin plan activo), confirmado en pantalla y con
  Playwright (`getByText('Comisión del mes')` cuenta exactamente 1 en toda la grilla).
- `tecnico_servicios` (Carlos, viendo su propia tarjeta) NO ve el botón aunque su propio plan esté
  activo — el gate es de ROL, no "es el dueño del plan".
- Backend, camino de ruptura primero (antes del camino feliz):
  - `GET`/`PUT .../commission-capture` sobre Miguel Castillo (sin plan) → **422** en ambos casos,
    con `lider_servicios` autenticado (rol correcto, pero técnico sin plan — confirma que el gate
    de elegibilidad es independiente del gate de rol).
  - `PUT .../commission-capture` sobre Carlos como `tecnico_servicios` → **403** (rol sin permiso,
    aunque el técnico sí tenga plan).
  - `GET .../commission-capture` sobre Carlos como `tecnico_servicios` → **403** (la ruta completa
    está detrás de `role:superadmin,lider_servicios,management`, no solo el PUT).

### RN2 — SLA editable solo por Gerencia (no Aaron/`lider_servicios`)
- UI: Aaron ve los 4 campos de SLA deshabilitados (`disabled`, confirmado por Playwright,
  `input[type=number][min=1]:not([max])` × 4) y sin botón "Guardar SLA".
- Backend: `PATCH .../sla-settings` con Aaron → **403**; con `tecnico_servicios` → **403**; con
  Gerencia → 200. Confirma que el gate es literal a "Gerencia" (RN2: "ajustables por Gerencia sin
  intervención de Tecnología"), no al conjunto más amplio de roles que sí pueden capturar/editar
  la comisión en sí (RN5).
- Validación: `warranty=0` y `warranty=-1` → 422 ambos (`min:1` en el `FormRequest` del
  controller), con mensaje de campo específico.

### RN5 — trazabilidad de auditoría en edición
Verificado en vivo con 2 requests reales, no solo por inspección de código:
1. Aaron crea la captura de agosto → `captured_by: Aaron`, `updated_by: null`.
2. Daniela (Gerencia) edita la misma captura → `captured_by` **se mantiene** "Aaron" (no se pisa),
   `updated_by` pasa a "Daniela". Confirmado también contra la tabla directamente
   (`captured_by`/`updated_by` por id de usuario, no solo por nombre de la respuesta JSON).
3. Aaron vuelve a editar 3s después → `updated_at` cambia de verdad (`02:12:15` → `02:12:30`),
   `created_at` no se toca — descarta que el primer chequeo (mismo segundo) fuera un falso
   positivo por precisión de columna `timestamp(0)`.

### Validación de input inválido (Paso 3)
- `satisfaccion_promedio=6` (fuera de escala) → 422, mensaje específico de campo.
- `satisfaccion_promedio=0` → 422 (fuera de la escala 1-5).
- `incidencias_puntualidad=-1` → 422 (`min:0`).
- Payload vacío `{}` → 422, con los 5 campos obligatorios listados individualmente en `errors`.
- Nota técnica, no es un bug: sin header `Accept: application/json` (ej. `curl` a mano sin ese
  header), Laravel devuelve 302 en vez de 422 JSON — comportamiento estándar de FormRequest para
  requests que no piden JSON explícitamente. El cliente Axios compartido
  (`src/api/authApi.ts`) ya manda `Accept: application/json` en todas las requests del frontend
  real, confirmado — cero impacto en la app real, documentado acá para que nadie lo redescubra
  como bug.

### CRÍTICO #1 — condición de carrera en doble-submit de una captura nueva (CORREGIDO)
**Encontrado:** 2 `PUT .../commission-capture` concurrentes para el mismo técnico/período **sin
captura previa** (mismo escenario que "doble clic en Guardar" del Paso 3, o dos pestañas/sesiones
guardando al mismo tiempo) pasan ambos el `where(...)->first()` en `null` (time-of-check-to-
time-of-use), y el segundo `CommissionCapture::create()` choca contra el unique constraint
(`internal_technician_id, year, month`) con `UniqueConstraintViolationException` **sin capturar**
— resultado: **500 Internal Server Error con stack trace completo devuelto al cliente** (incluye
rutas de archivo del servidor, expuesto sin control de errores de producción).

Reproducido con 2 `curl -X PUT` concurrentes (`&` + `wait`) contra el mismo endpoint — confirmado
antes del fix (500 + stack trace en la segunda respuesta) y después del fix (200 en ambas, exactamente
1 fila en la tabla).

**Fix aplicado** (`app/Modules/Servicios/Services/CommissionCaptureService.php::save()`): el
`create()` ahora está envuelto en `try/catch(UniqueConstraintViolationException)` — si otra
request ganó la carrera entre el `SELECT` y el `INSERT`, se relee el registro recién creado por
esa otra request y se trata como edición (mismo resultado final que si las requests hubieran
llegado en cualquier orden secuencial, sin duplicar filas ni perder el `captured_by` del creador
real).

**Re-verificación tras el fix (loop cerrado, checklist completo del ticket vuelto a correr):**
- 3 `PUT` concurrentes (no solo 2) → 3× `HTTP 200`, exactamente 1 fila en `commission_captures`,
  `captured_by` del creador real preservado.
- `infra/test.sh --filter=InternalTechnicianBatch7Test` → 13/13 verde.
- Suite completa de backend (`infra/test.sh`) → **1738/1738 verde** (incluye el 1 fallo real que
  causó una corrida intermedia — ver nota de contaminación abajo, no relacionado al fix).
- PHPStan Level 8 (`--memory-limit=1G`) → sin errores.
- Suite completa de frontend (`npm run test -- --run`) → 1002/1002 verde (sin cambios de frontend
  para este fix, solo confirmación de que nada se rompió).

### CRÍTICO #2 — sin selector de período, el formulario no puede editar "un mes ya cerrado" (RN5) — SIN RESOLVER, requiere decisión de PM/Luis
RN5 dice literalmente: *"un mes ya cerrado (con el mes calendario terminado) puede seguir
editándose si se detecta un error, pero cualquier edición debe quedar con marca de fecha/quién la
hizo, para trazabilidad."*

El backend ya soporta esto — `GET`/`PUT .../commission-capture` aceptan `?year=&month=` /
`{year, month}` arbitrarios en el payload, sin ningún candado que impida escribir sobre un período
ya cerrado (verificado).

**El frontend no expone ningún camino para llegar ahí.** `InternalTechnicianCommissionModal.tsx`
calcula `year`/`month` con `new Date()` del navegador en cada apertura, tanto para el `GET` como
para el `PUT` — no hay selector de mes/año, ni siquiera una **etiqueta visible** que diga qué
período se está viendo/editando (el título del modal es literalmente "Comisión del mes — Carlos
Vergara", sin año ni mes). Confirmado visualmente (captura de pantalla adjunta al comentario de
Jira) e inspeccionando el componente.

**Impacto concreto:** si Aaron/Gerencia abre el modal el día 1 de un mes nuevo para cargar los
datos del mes recién cerrado (el flujo más probable en la práctica, dado que una encuesta de
satisfacción del mes solo se puede promediar una vez el mes terminó), el sistema **silenciosamente
guarda esos valores bajo el mes en curso** (el nuevo), no el mes que el usuario cree estar
capturando — sin ningún error, sin ninguna advertencia. No hay forma de corregirlo después salvo
por API directa (fuera del alcance de un usuario real).

Esto no rompe ninguno de los 3 Escenarios literales de Jira (los 3 asumen "mes en curso" de forma
explícita, especialmente el Escenario 3), pero sí incumple RN5 tal como está escrita, con cero
camino de UI para ejercitarla — mismo patrón que el precedente SCRUM-428 (2026-07-21): "diferencia
entre lo que el ticket pide y lo que existe, no autoclasificar como alcance diferido y seguir de
largo". Por eso este hallazgo **no se autoclasifica, se deja explícito acá y en Jira, y SCRUM-362
queda en `Dev Testing`** hasta que PM/Luis confirme si:
(a) esto es alcance real de REQ-292 y falta un selector de período (fix de UI, no trivial — no es
"una línea"), o
(b) es alcance diferido a un ticket futuro y RN5 se reinterpreta como "el backend no debe bloquear
la escritura de un período cerrado" (ya cumplido) sin exigir un selector en la UI de este batch.

### Otros — Paso 3 general
- Doble clic en "Guardar captura" (sin condición de carrera, mismo click) — el botón se deshabilita
  mientras `saveMutation.isPending` (confirmado en `Button.tsx`, `disabled={disabled || loading}`).
- Checkbox "Licencia médica" — se captura, persiste y se recupera correctamente al reabrir el
  modal (verificado con Playwright: marcar, guardar, cerrar, reabrir, checkbox sigue marcado).
  RN4's redistribución de peso (0% forzado a Puntualidad) es explícitamente de REQ-258/SCRUM-321,
  no de este batch — el valor crudo de `puntualidad_pct` no se ve afectado por la bandera en esta
  respuesta, tal como documenta el docblock de `CommissionCalculationService` — no es un hallazgo.
- Recargar la página a mitad de edición del formulario (con datos sin guardar) — no rompe la app,
  vuelve a un estado limpio (comportamiento estándar de SPA, no persiste el borrador — aceptable,
  no hay ningún criterio que pida guardar borradores).

## Archivos tocados por Pre-QA
- `atlanticerp-backend/app/Modules/Servicios/Services/CommissionCaptureService.php` — fix de la
  condición de carrera (CRÍTICO #1).
- `atlanticerp-frontend/e2e/preqa-scrum324-326-362-servicios-batch7-20260809.spec.ts` (nuevo, promovido
  a permanente — cubre los 3 tickets: gates de rol/elegibilidad, boundary de conversión, SLA
  Gerencia-only, toggle sin pérdida de datos).
- Este archivo.
- Ningún cambio de frontend para el CRÍTICO #2 (selector de período) — pendiente de decisión de
  PM/Luis antes de implementar, ver arriba.

## Veredicto (1ra pasada)
- SCRUM-324 → transicionado a `QA`.
- SCRUM-326 → transicionado a `QA`.
- SCRUM-362 → **permanece en `Dev Testing`**. Condición de carrera corregida y re-verificada
  (loop cerrado). Hallazgo del selector de período documentado en Jira, esperando confirmación de
  PM/Luis sobre alcance antes de transicionar.

---

## Re-check (2da pasada) — 2026-08-09, mismo día

El Arquitecto implementó el selector de período en `InternalTechnicianCommissionModal.tsx`
(chevrons "Mes anterior"/"Mes siguiente" + label `toLocaleDateString`, "Mes siguiente"
deshabilitado en el mes actual, refetch por `?year=&month=` explícitos, reset de campos al
cambiar a un período sin captura). Por la "regla dura" del protocolo de Pre-QA (`pre-qa.md`), un
fix vuelve a correr el checklist COMPLETO del ticket, no solo el punto que falló — se repitió
Paso 1-4 entero para SCRUM-362, no solo el selector.

### Checklist completo re-verificado (sin regresiones)
- **RN3 — 3 fórmulas de conversión en boundary exacto** — repetido vía UI navegando a un período
  pasado (junio 2026: 3.5→60%, 1→50%, 2.5→50%) y contra la respuesta de la API — mismos resultados
  que la 1ra pasada, sin regresión.
- **RN1/RN5 — gate `has_bonus_plan` + rol** — repetido: botón "Comisión del mes" solo en la tarjeta
  de Carlos Vergara (Playwright, conteo exacto 1), ausente para `tecnico_servicios` viendo su
  propia tarjeta. Sin regresión.
- **RN2 — SLA solo-Gerencia** — repetido: Aaron ve los 4 campos deshabilitados, Gerencia puede
  editar. Sin regresión.
- **RN6 — "Pendiente de captura"** — repetido para el mes en curso sin captura. Sin regresión.
- **RN5 — auditoría `captured_by`/`updated_by`** — re-verificado, ahora explícitamente **cruzando
  períodos** (no solo el mes en curso, que era el único caso cubierto en la 1ra pasada): Aaron creó
  la captura de junio 2026 (2 meses atrás) vía API directa, Daniela la editó después — resultado
  `captured_by: "Aaron"` (se mantiene), `updated_by: "Daniela"`. Confirmado también que julio 2026
  (nunca tocado) sigue devolviendo `data: null` y que agosto 2026 (mes en curso, tocado por otra
  parte del checklist) es independiente — sin bleed-through entre columnas de la tabla por
  período. Ver curls de evidencia abajo.
- **Suite completa backend** (`infra/test.sh`, corrida aislada, sin nada más tocando el mismo
  Postgres compartido con dev local — ver nota de proceso abajo) → **1738/1738 verde**, 7078
  assertions.
- **PHPStan Level 8** (`--memory-limit=1G`) → sin errores.
- **Suite completa frontend** (`npm run test -- --run`) → **1004/1004 verde**.
- **`npx tsc --noEmit`** → sin errores.

### Selector de período — verificación específica del fix
- **Navegación hacia atrás sin bleed-through**: desde agosto 2026 (mes actual), retrocediendo a
  julio (sin captura, campos vacíos, "Pendiente de captura") y a junio (con datos sembrados:
  3.5/1/2.5, 60%/50%/50%) — cada período muestra exactamente sus propios datos, sin arrastre del
  período anterior. Playwright: `e2e/preqa-scrum324-326-362-servicios-batch7-20260809.spec.ts`.
- **"Mes siguiente" deshabilitado en el mes actual, y avanza correctamente al volver**:
  confirmado deshabilitado apenas se abre el modal (mes actual); tras retroceder 2 meses, queda
  habilitado y avanza mes a mes correctamente hasta volver a deshabilitarse en el mes actual.
- **Trazabilidad cruzando períodos** — ver RN5 arriba, verificado también desde la UI (el
  formulario muestra el valor editado por Daniela, no el original de Aaron, al navegar a junio).
- **Label coincide con el período mostrado, en ambos locales**: `es` → "agosto de 2026" / "junio de
  2026" (formato real de `toLocaleDateString('es', ...)`, con "de" — la primera versión de este
  chequeo tenía una regex de test equivocada sin "de", corregida); `en` → "August 2026" / "June
  2026" (sin "of", formato real de `toLocaleDateString('en', ...)`). Ambos verificados leyendo el
  DOM renderizado, no asumidos por el código.
- **Intentos de romperlo**:
  - Doble clic rápido en "Mes anterior" — el `period` avanza exactamente 2 meses atrás, no 1 ni 3
    (el updater funcional de `setPeriod` encola correctamente ambos clics).
  - Red lenta (delay de 1.5s inyectado en `commission-capture` vía `page.route`) en un período
    todavía no visitado en esa sesión del modal (cache-miss garantizado, ver nota de
    `staleTime: 30_000` abajo) — mientras la respuesta está en vuelo, el botón "Guardar" no está
    visible/disponible; solo reaparece cuando el fetch resuelve y el período ya cambió realmente.
    Descarta el escenario "guardar sobre el período viejo pensando que ya cambió".
  - Guardar durante un cambio de período en curso — cubierto por el mismo mecanismo: mientras
    `isLoading` es verdadero (fetch del nuevo período en curso), el formulario completo —
    incluido "Guardar" — no se renderiza.

**Nota de proceso, no de producto** — el primer intento de correr esta suite de Playwright en
paralelo con `infra/test.sh` (ambos contra el mismo Postgres que usa `dev` local, ver
`.env.testing`: mismo `DB_DATABASE=atlanticerp`, mismo tenant `illuminations`) produjo fallos falsos
por interferencia cruzada (`migrate:fresh` de un proceso pisando datos que el otro proceso estaba
leyendo/escribiendo en vivo) — no correr ambos a la vez. Corregido corriendo cada suite de forma
aislada. Separado de esto, la primera versión de 3 de los tests nuevos de este re-check tenía
fallos propios de la prueba (regex sin "de" en español; un test de idioma que no cerraba el modal
antes de revertir a español, bloqueando clics de tests posteriores; un test de "red lenta" que sin
querer reutilizaba un período ya cacheado por `staleTime: 30_000`, nunca disparando el fetch real)
— los 3 corregidos, sin relación con el comportamiento de la app.

### Evidencia — trazabilidad cruzando períodos (curl directo, además de la UI)
```
PUT .../internal-technicians/1/commission-capture {"year":2026,"month":6,...} (Aaron)
  → captured_by: "Aaron", updated_by: null
PUT .../internal-technicians/1/commission-capture {"year":2026,"month":6,...} (Daniela)
  → captured_by: "Aaron", updated_by: "Daniela"
GET .../internal-technicians/1/commission-capture?year=2026&month=7 → data: null (nunca tocado)
GET .../internal-technicians/1/commission-capture?year=2026&month=8 → data: null (independiente)
```

## Archivos tocados por el re-check
- `atlanticerp-frontend/e2e/preqa-scrum324-326-362-servicios-batch7-20260809.spec.ts` — 6 tests nuevos
  para el selector de período (navegación, bleed-through, "Mes siguiente" deshabilitado,
  auditoría cruzando períodos, label en ambos locales, intentos de romperlo).
- Ningún cambio de código de producto por parte de Pre-QA en este re-check — el fix del selector
  de período ya lo había implementado el Arquitecto antes de este re-check; el checklist completo
  no encontró regresiones ni hallazgos nuevos.
- Datos de prueba (capturas de comisión de junio/julio/agosto 2026 para Carlos Vergara, creadas
  vía curl/UI durante la verificación) limpiados de la base de dev local al terminar — no quedan
  residuos.

## Veredicto final
- **SCRUM-362 → transicionado a `QA`.** Pasada limpia y concluyente: 0 CRÍTICO, 0 MEDIO. El
  hallazgo estructural de la 1ra pasada (selector de período ausente) está resuelto y
  re-verificado con el checklist completo del ticket, sin regresiones en ningún otro criterio.
