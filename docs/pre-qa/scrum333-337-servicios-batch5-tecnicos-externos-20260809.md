# Pre-QA — Fase 4 Servicios, Batch 5 (SCRUM-333/334/335/336/337, REQ-263→267) — Técnicos externos

**Fecha:** 2026-08-09
**Entorno:** Docker local (`atlanticerp-backend/infra/docker-compose.yml`) + Vite dev server
(`http://localhost:5173`, proxy `/api` → nginx `http://localhost:8090`, ver `vite.config.ts`).
**NO desplegado a dev.atlanticerp.ai** — este batch todavía no se pusheó.
**Resultado: LIMPIO tras 2 fixes propios (mismo día, mismo pase) — pasa a QA.**

Spec de referencia: 5 tickets de Jira (SCRUM-333→337) + Excel adjunto en SCRUM-333
`5__Requerimientos_Servicios.xlsx` (hojas "Requerimientos Servicios" y "Matriz de Permisos por
Rol") — cruzados fila por fila contra REQ-263→267. Texto de Jira y Excel idénticos para estos 5
REQ, sin discrepancias. Sin mockup específico de Técnicos Externos adjunto en ningún ticket del
batch (solo el mockup genérico de Tickets en tickets anteriores) — no se despachó Visual Reviewer
por separado, las comprobaciones visuales/UX se hicieron dentro de este mismo pase.

Suite Playwright: `atlanticerp-frontend/e2e/preqa-scrum333-337-servicios-batch5-tecnicos-externos-20260809.spec.ts`
(6 tests, corridos contra `http://localhost:5173`) — se conserva como test permanente (cubre el
CRÍTICO #1 de abajo, que ya se rompió una vez, ver `feedback_e2e_permanent_tests_must_self_seed.md`
— nombre único por corrida vía `Date.now()`).

Límite conocido y aceptado de antemano (no es un hallazgo): `ExternalTechnicianService::activeProjectsCount()`
y `assignedProjects()` son stubs que siempre retornan `0`/`[]` porque la única fuente real (ítem
"Subcontratado" de Cotización, REQ-232, Batch 11-12) no existe todavía. El escenario "técnico con 2
proyectos activos, advertencia al inactivar" (REQ-265 Escenario 1) y el contenido real de la tabla
de REQ-267 no se pueden ejercitar end-to-end con datos reales hoy — se verificó el CONTRATO en su
lugar (test unitario de la excepción + 409 con `requires_confirmation`/`active_projects_count`,
componente React con el flujo de confirmación mockeado, ambos ya existían en el código entregado y
pasan). Cuando Batch 11 conecte datos reales, agregar el escenario HTTP con 409 real.

---

## CRÍTICO #1 — `GET /external-technicians/{id}` devolvía `especialidad: null` en vez de "Sin especificar"

REQ-264 RN1: *"especialidad muestra 'Sin especificar' si se deja vacía"*. El endpoint de alta
(`store()` → `summary()`) y el de edición de tarifa (`updateTarifa()` → `summary()`) ya aplicaban
el default correctamente (`$t->especialidad ?? 'Sin especificar'`), pero la ficha
(`show()`, `ExternalTechnicianController.php` línea 75) devolvía el valor crudo de BD sin el
fallback:

```php
'especialidad'       => $technician->especialidad,   // antes: null si está vacío
```

**Impacto real:** el frontend (`ExternalTechnicianDetailModal.tsx`) renderiza
`{technician.especialidad}` directo — con `null`, React no imprime nada, así que la ficha de
cualquier técnico sin especialidad mostraba el campo **en blanco**, mientras el listado (que sí
usa el `summary()` correcto) mostraba "Sin especificar" para el mismo técnico. Inconsistencia
reproducible entre listado y ficha del mismo registro.

**Cómo se reprodujo:** creado un técnico sin especialidad vía API real (`POST
/external-technicians`), confirmado `especialidad: "Sin especificar"` en la respuesta de alta, y
`especialidad: null` en la respuesta de `GET /external-technicians/{id}` inmediatamente después,
para el mismo registro. El test existente (`test_registrar_tecnico_externo`) llamaba a la ficha
pero nunca aserteaba ese campo puntual — por eso pasó sin detectarlo.

**Fix aplicado** (mismo día, este pase): `atlanticerp-backend/app/Modules/Servicios/Http/Controllers/ExternalTechnicianController.php:75`
→ `'especialidad' => $technician->especialidad ?? 'Sin especificar',` (mismo patrón que
`summary()`). Re-verificado vía API directa y vía Playwright (test 1 de la suite nueva, que abre
la ficha recién creada y confirma el texto visible "Sin especificar" en pantalla, no solo en el
JSON).

---

## MEDIO — `vendedor_disenador` tenía acceso de lectura real a Técnicos Externos pese a estar excluido del diseño

RN5 (REQ-263) solo otorga acceso de solo-consulta a "Técnico interno" (`tecnico_servicios`,
`garantias_servicios`), además de Aaron/Gerencia con acceso completo. Ni el texto de Jira ni la
fila "Ver listado de Técnicos Externos" de la hoja "Matriz de Permisos por Rol" del Excel
mencionan a Vendedor/Diseñador — esa fila (y las otras 4 de Técnicos Externos) marca explícitamente
`N/A` en su columna. El propio frontend ya refleja esa exclusión a propósito: `ServiciosNavMenu.tsx`
(`LIMITED_ROLE = 'vendedor_disenador'`) oculta la opción "Técnicos externos" del desplegable
"Técnicos" para este rol.

El backend, sin embargo, gateaba `GET /external-technicians` y `GET /external-technicians/{id}`
con `permission:servicios.read` (el permiso de módulo genérico) en vez de un `role:` explícito
como el resto de restricciones angostas del mismo archivo (`routes/servicios.php`).
`vendedor_disenador` sí tiene `servicios.read` por otras razones legítimas del módulo (crear/ver
tickets), así que aunque el botón de navegación esté oculto, cualquier llamada directa a la API —
o cualquier cambio futuro de UI que reintroduzca el link — le daba acceso real de lectura,
incluyendo el campo `tarifa_dia` (costo/día de cada subcontratista) que el diseño nunca le asignó.

**Verificado en vivo antes del fix:** `GET /api/servicios/external-technicians` con el usuario real
`usuario@example.test` (`vendedor_disenador`) → `200`, listado completo con tarifas
visibles.

**Fix aplicado** (mismo día, este pase): `atlanticerp-backend/routes/servicios.php` — ambas rutas GET de
`external-technicians` pasan de `permission:servicios.read` a
`role:superadmin,lider_servicios,management,tecnico_servicios,garantias_servicios` (mismo
mecanismo `role:` ya usado en el resto del archivo para restricciones más angostas que el permiso
de módulo). Re-verificado en vivo tras el fix:
- `vendedor_disenador` (`usuario@example.test`) → `403` en index y show.
- `vendedor_disenador` conserva `200` en `GET /api/servicios/tickets` (módulo no afectado).
- `tecnico_servicios` (`carlos@atlantic.com.pa`) y `garantias_servicios`
  (`garantias@atlantic.com.pa`) conservan `200` (RN5 — técnico interno solo-consulta).
- `lider_servicios`/`management` conservan `200` y todas las operaciones de escritura intactas.

Se agregaron 2 tests de regresión permanentes a
`atlanticerp-backend/tests/Feature/Servicios/ExternalTechnicianBatch5Test.php`
(`test_vendedor_disenador_no_puede_ver_tecnicos_externos`,
`test_garantias_servicios_puede_listar`).

---

## Observación (no bloqueante, no específica de este batch) — `int $id` sin tipo casteado revienta 500 en vez de 404

`GET /external-technicians/{id}` con un `{id}` no numérico (ej. `/external-technicians/abc`)
devuelve `500` (`TypeError: Argument #2 ($id) must be of type int, string given`) en vez de un
`404` limpio. Confirmado que **no es una regresión de este batch** — `TicketController::show()`
(módulo ya existente, Batch 1) tiene exactamente el mismo patrón `int $id` sin casteo defensivo y
el mismo comportamiento (`GET /servicios/tickets/abc` → 500 también). Es deuda técnica sistémica
del módulo Servicios completo, no algo que este batch introdujo ni algo exigido por ningún
criterio de aceptación de REQ-263→267. No se corrige acá — queda registrado para una futura pasada
de endurecimiento transversal del módulo (decisión de Arquitecto, no de Pre-QA).

---

## Checklist de verificación por ticket

### SCRUM-333 (REQ-263) — Listado y filtros

| Escenario probado | Resultado |
|---|---|
| Camino feliz: cada fila muestra nombre/empresa/especialidad/teléfono/tarifa/proyectos activos/estado (RN1) | OK |
| Chip "Activos" oculta técnicos inactivos (Escenario 1) | OK — API y Playwright |
| Buscador filtra por nombre (RN4) | OK — API y Playwright |
| Buscador + chip combinados (RN4) | OK — `search=PreQA&estado=active` vs `estado=inactive` |
| Buscador con solo espacios en blanco | OK — se trata como "sin filtro" (TrimStrings + `trim() !== ''`), no rompe ni devuelve vacío por error |
| "Proyectos activos" excluye cotizaciones Rechazadas (RN2/Escenario 2) | No ejercitable con datos reales (ver límite conocido arriba) — contrato verificado (siempre 0, consistente en list/ficha) |
| Técnicos inactivos siguen visibles en el listado general (RN3) | OK — solo se ocultan con el chip activo |
| Técnico interno (`tecnico_servicios`) — solo consulta, ve tarifa si visible (RN5) | OK — 200 en listado/ficha, 403 en escritura |
| **`vendedor_disenador` — sin acceso** | **CRÍTICO detectado y corregido (ver MEDIO arriba)** — ahora 403 |
| Rol sin sesión (sin token) | 401 |

### SCRUM-334 (REQ-264) — Registrar nuevo técnico

| Escenario probado | Resultado |
|---|---|
| Alta completa, Aaron, campos obligatorios + tarifa (Escenario 1) | OK |
| Nombre/empresa faltantes → 422 | OK |
| Tarifa faltante → 422 | OK |
| Tarifa negativa → 422 (`min:0`) | OK |
| Email inválido → 422 | OK |
| Nombre solo espacios en blanco → 422 (TrimStrings + `required`) | OK |
| Especialidad/teléfono/email vacíos son opcionales, no bloquean el alta | OK |
| Especialidad vacía → "Sin especificar" en alta | OK |
| **Especialidad vacía → "Sin especificar" en la ficha** | **CRÍTICO detectado y corregido (ver arriba)** |
| Nuevo técnico inicia Activo + tarifa visible (RN2) | OK |
| Tarifa inicial queda como primer punto del historial (RN3) | OK |
| Solo Aaron/Líder Servicios/Gerencia registran (RN4) — `tecnico_servicios` → 403 | OK |
| `management` (Gerencia) también puede registrar | OK |
| Doble submit / doble clic en Guardar | Bloqueado por UI (`Button` con `loading` deshabilita mientras `mutation.isPending`) |

### SCRUM-335 (REQ-265) — Activar / Inactivar

| Escenario probado | Resultado |
|---|---|
| Sin proyectos activos → inactiva directo, sin advertencia (Escenario 2) | OK — API y Playwright |
| Reactivar nunca exige confirmación (Escenario 3) | OK |
| Con proyectos activos → advertencia + confirmación (Escenario 1) | No ejercitable con datos reales (ver límite conocido) — contrato 409 + flujo de confirmación verificados (test unitario backend + componente React con mock de 409) |
| Solo Aaron/Líder Servicios/Gerencia activan/inactivan (RN1) — `tecnico_servicios` → 403 | OK |
| Estado inválido (ni active ni inactive) → 422 | OK |
| Estado faltante en el body → 422 | OK |
| Ficha inexistente (id 999999) → 404 | OK |

### SCRUM-336 (REQ-266) — Tarifa: editar, mostrar/ocultar, historial

| Escenario probado | Resultado |
|---|---|
| Editar tarifa acumula historial con ambos valores y fechas (Escenario 1) | OK — API y Playwright |
| Ocultar tarifa reemplaza el monto por "Sin tarifa asignada" en listado y ficha, sin crear entrada de historial (Escenario 2, RN3) | OK — API y Playwright, historial cuenta exacta (no +1 al togglear) |
| El valor real de tarifa persiste en BD aunque esté oculto (RN3) | OK — verificado directo contra el modelo |
| Cambios de tarifa no son retroactivos (Escenario 3, RN4) | No aplica probar end-to-end (depende de Cotización, no existe) — el modelo de datos ya está diseñado para esto (`COTIZACION_ITEM.costo_referencia` histórico propio, no referencia viva) |
| Solo Aaron/Gerencia editan tarifa o el interruptor (RN1) — `tecnico_servicios` → 403 en API, controles ocultos en UI | OK |
| PATCH con body vacío (`{}`) — no-op, no rompe | OK — devuelve el registro sin cambios |
| Historial es de solo consulta (sin endpoint de edición/borrado de entradas) | OK — no existe ruta para eso |

### SCRUM-337 (REQ-267) — Proyectos asignados y costo/margen

| Escenario probado | Resultado |
|---|---|
| Sin proyectos asignados → mensaje "Aún no tiene proyectos asignados" (RN3) | OK — API y Playwright |
| `puede_ver_costos` gateado por rol (RN2: Aaron/Gerencia sí, técnico interno no) | OK — test existente + verificado en vivo (`lider_servicios`/`management` → `true`, `tecnico_servicios` → `false`) |
| Tabla se actualiza automáticamente desde ítems Subcontratado de Cotización (RN1) | No ejercitable — depende de REQ-232 (Batch 11-12), no existe todavía. Contrato (`proyectos_asignados: []`) verificado |
| Nota de mapeo de roles: Excel dice "Aaron, Aprobador, Gerencia2 y Gerencia5" (RN2); implementación usa `role:superadmin,lider_servicios,management` | Consistente con el mapeo ya usado en el resto del proyecto ("Gerencia" = Aprobador/Gerencia2/Gerencia5, confirmado en la hoja "Matriz de Permisos por Rol" del mismo Excel, fila 5 header). No es un hallazgo — mismo patrón que REQ-264/265/266 |

---

## Regresión — suites completas tras ambos fixes

- Backend: `infra/test.sh` completo → **1703/1703 OK** (incluye los 19 tests de
  `ExternalTechnicianBatch5Test`, 17 originales + 2 nuevos de regresión).
- PHPStan Level 8 (`--memory-limit=1G`) → **0 errores**.
- Frontend: `npm run test` (Vitest) completo → **985/985 OK**.
- Playwright: `preqa-scrum333-337-servicios-batch5-tecnicos-externos-20260809.spec.ts` → **6/6 OK**
  contra el stack local real (Vite + Docker), con los 3 usuarios reales del roster
  (`servicio@atlantic.com.pa`, `carlos@atlantic.com.pa`).

## Archivos tocados por Pre-QA

- `atlanticerp-backend/app/Modules/Servicios/Http/Controllers/ExternalTechnicianController.php` (línea 75 — fix CRÍTICO #1)
- `atlanticerp-backend/routes/servicios.php` (rutas GET de `external-technicians` — fix MEDIO)
- `atlanticerp-backend/tests/Feature/Servicios/ExternalTechnicianBatch5Test.php` (2 tests de regresión nuevos)
- `atlanticerp-frontend/e2e/preqa-scrum333-337-servicios-batch5-tecnicos-externos-20260809.spec.ts` (nuevo, permanente)

## Veredicto

**Pasada limpia tras 2 fixes propios, mismo pase, checklist completo re-corrido después de cada
uno.** Los 5 tickets (SCRUM-333/334/335/336/337) pasan a `QA`. El límite conocido de
`activeProjectsCount()`/`assignedProjects()` (Batch 11 pendiente) no bloquea — es alcance diferido
ya documentado en el propio código entregado, no un gap de este batch.
