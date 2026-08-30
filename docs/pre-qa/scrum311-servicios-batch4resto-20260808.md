# Pre-QA — SCRUM-311 (REQ-248, Adjuntos y Observaciones — Nuevo ticket) — 2026-08-08

Fase 4 — Servicios, resto del Batch 4 (después de REQ-245/246/247 en Batch 3 parte 2). Ya pasó
Senior Review (aprobado, 1 fix real de condición de carrera TOCTOU en el conteo de adjuntos,
commit `0ff3e21`) y Visual Review (aprobado limpio, marcador ya creado). Commits: backend
`3283c8d` + `0ff3e21`, frontend `7542e65`. Entorno: local (Docker `:8090` + Vite `:5173`),
Playwright CLI (`atlanticerp-frontend/e2e/preqa-scrum311-servicios-batch4resto-20260808.spec.ts`,
9 tests, dejado como test permanente — cubre gates de validación y permisos).

Cruzado contra el Excel de especificación (`5__Requerimientos_Servicios.xlsx`, filas REQ-248/
HU-248) además del texto de Jira — sin diferencias, mismas RN1/RN2/Escenario 1/Permisos.

## Fixture sembrado antes de correr (Paso 0 del protocolo)

El Postgres local de dev estaba vacío de `master_clients`/`sub_clients`/`sales_projects`/`tickets`
(0 filas en las 4 tablas) — sin esto no se puede completar el formulario real de "Nuevo ticket"
(Cliente Master→Subcliente→Proyecto es obligatorio desde Batch 3 parte 2). Sembrado vía
`php artisan tenants:artisan "db:seed --class=VentasDisenoDemoSeeder --force"` +
`--class=ServiciosDemoSeeder` antes de correr Playwright. Para el escenario 3 (límite de
cantidad), se bajó `adjuntos_max_archivos` a 2 directamente en `servicios_settings` vía SQL —
revertido a 10 (default) al terminar la suite.

**Nota operativa:** `infra/test.sh`, corrido después de Playwright como sanity check del backend,
vació de nuevo el Postgres compartido con dev local (gotcha ya documentado en
`memory/feedback_local_test_db_no_es_fresh.md`) — se re-sembró con `db:seed --force` completo
tras esa corrida, dejando el entorno local en el mismo estado que antes de empezar.

## Escenarios de ruptura intentados

| # | Escenario de ruptura intentado | Resultado |
|---|---|---|
| 1 | Adjuntar `.exe` (extensión fuera del whitelist) | OK — rechazado client-side ANTES de cualquier request, mensaje visible "Ese tipo de archivo no está permitido.", no se agrega a la lista pendiente. Backend también rechaza con 422 (`test_adjuntar_extension_no_permitida_retorna_422`) si se bypasea el cliente. |
| 2 | Adjuntar archivo de 16MB (> 15MB configurado) | OK — rechazado client-side, mensaje "El archivo supera el máximo de 15 MB.", visible en UI, no se agrega a la lista. |
| 3 | Superar el máximo configurado server-side (bajado a 2), subiendo 3 archivos reales vía el formulario | OK — el cliente (constante hardcoded `MAX_ATTACHMENTS=10`, "fail-fast" documentado en el propio código) deja pasar los 3 sin queja, pero el backend re-valida el límite REAL configurado (2) al momento del submit — el 3er adjunto es rechazado con 422 y el mensaje `"Este ticket ya alcanzó el máximo de 2 archivos adjuntos."` aparece como toast visible, no falla en silencio. El ticket se crea igual (con los 2 primeros adjuntos), consistente con el flujo secuencial documentado en el código (`toast(msg ?? ...)` dentro del `catch` del loop de subida). |
| 4 | Crear ticket SIN observaciones y SIN adjuntos | OK — RN1 confirmado, ambos campos opcionales, el submit no se bloquea, no hay toast de error. |
| 5a | Carlos (`tecnico_servicios`) — botón "Nuevo ticket" | OK — el botón no existe en el DOM para su rol (`canCreateTicket()` en `TicketsPage.tsx`, ya cubierto también en Batch 3 parte 2). |
| 5b | Carlos — POST directo a `/api/servicios/tickets/{id}/attachments` (bypaseando la UI) | OK — 403, confirmado con curl real usando su token JWT real (no solo lectura de código) contra un ticket real creado por Aaron. |
| 6 | Recargar la página a mitad del formulario de "Nuevo ticket" con 1 adjunto ya cargado en memoria | Comportamiento esperado (no bloqueante): el estado del formulario se pierde con el reload (SPA sin persistencia de borrador) — confirmado que la app NO se rompe, la tabla de tickets vuelve a cargar normalmente tras el reload. |
| 7 | Adjuntar el mismo archivo dos veces vía el picker (dos interacciones separadas, mismo archivo) | **Hallazgo no bloqueante** (ver abajo) — el frontend no deduplica, el archivo aparece 2 veces en la lista pendiente y ambas copias se subirían como 2 adjuntos separados. Ninguna RN del ticket prohíbe archivos duplicados, así que no es un incumplimiento de criterio — se documenta como observación de UX. |
| 8 | Abrir un adjunto ya subido y verificar la URL firmada | OK — confirmado con curl real (no solo lectura de código): `X-Amz-Expires=900` (15 minutos exactos), la URL resuelve con `HTTP 200` contra el S3 real (`atlanticerp-dev` bucket), el archivo devuelto es el correcto. |
| 9 | Ver el detalle de un ticket creado ANTES de este batch (campos `observaciones`/`adjuntos` en su estado por-defecto: `NULL`/vacío) | OK — el modal de detalle muestra "Observaciones: —" y "Sin adjuntos" sin romperse, para los 14 tickets demo pre-existentes (todos con `observaciones IS NULL` y 0 adjuntos). |

## Hallazgo no bloqueante (documentado, no requiere fix)

**Selección duplicada del mismo archivo (Escenario 7):** el frontend no compara el archivo nuevo
contra los ya agregados a la lista de "Adjuntos" pendientes — seleccionar el mismo archivo dos
veces (dos aperturas del picker) agrega 2 entradas idénticas, que se subirían como 2 adjuntos
separados al crear el ticket. No viola ninguna RN del ticket (RN2 solo limita cantidad/tamaño, no
prohíbe duplicados) y es un escenario de bajo impacto (dos archivos con el mismo nombre pueden
legítimamente ser fotos distintas de carpetas distintas) — se deja documentado como observación de
UX, no como bloqueante. Si Luis quiere cerrarlo, la solución sería comparar `name+size` contra
`attachments` ya en la lista antes de hacer `push`.

## Lo que sí funciona

- Escenario 1 (REQ-248): 2 fotos adjuntadas al crear el ticket quedan asociadas desde su creación
  (ya cubierto por `test_adjuntar_2_fotos_quedan_asociadas_al_ticket`, re-confirmado en vivo en el
  Escenario 3 de esta pasada).
- RN2 — límite de cantidad y tamaño, ambos configurables server-side vía `ServiciosSettingsService`
  (nunca hardcodeados como constante de negocio — la constante del frontend es explícitamente solo
  un fail-fast, el backend es la autoridad real, confirmado en vivo).
- Condición de carrera (fix de Senior Review, commit `0ff3e21`): `lockForUpdate()` dentro de la
  transacción — no reproducido en esta pasada (ya verificado por Senior Review con el mismo método
  que atrapó SCRUM-722, ver `memory/feedback_preqa_race_condition_evidence_20260805.md`), cobertura
  de PHPUnit para el camino secuencial repetido confirmada (`test_adjuntar_repetido_nunca_supera_el_maximo_configurado`).
- Extensiones permitidas (fotos/videos/PDF/Word) vs. rechazadas — whitelist coherente entre
  frontend (`ACCEPTED_ATTACHMENT_EXTENSIONS`) y backend (`ALLOWED_ATTACHMENT_EXTENSIONS`).
- Permisos: mismos 4 roles que pueden crear tickets pueden adjuntar (`superadmin`,
  `lider_servicios`, `management`, `vendedor_disenador`) — técnico interno y garantías, no.
- URL firmada de 15 minutos, funcional contra S3 real.
- Reload a mitad de formulario no rompe la app (pérdida de borrador es UX esperada, no bloqueante).
- Detalle de tickets pre-existentes (sin observaciones/adjuntos) no se rompe con los campos vacíos.

## Veredicto

**PASA LIMPIO.** 0 hallazgos bloqueantes (CRÍTICO o MEDIO). 1 observación de UX no bloqueante
(duplicado de archivo, ver arriba), no requiere el loop de fix+re-check del protocolo porque no
incumple ningún criterio de aceptación ni regla de negocio documentada. Suite backend
`TicketBatch4Test` 11/11 verde tras la pasada (`infra/test.sh --filter=TicketBatch4Test`).
Suite Playwright 9/9 verde contra el stack local real (login real, S3 real, base de datos real).
Transicionado a `QA` en Jira.
