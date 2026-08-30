# Pre-QA — Fase 4 Servicios, Batch 6: Técnicos internos (SCRUM-318/319/320/322/323)

Fecha: 2026-08-09
Tickets: SCRUM-318 (REQ-255), SCRUM-319 (REQ-256), SCRUM-320 (REQ-257), SCRUM-322 (REQ-259),
SCRUM-323 (REQ-260). **SCRUM-321 (REQ-258) excluido a propósito** — depende de REQ-292/Batch 7,
sin construir todavía; ver comentario de deferral en el ticket.

Entorno: stack local (Docker `infra/docker-compose.yml`, nginx `:8090` + Vite dev server `:5173`),
rama `dev` en `atlanticerp-backend`/`atlanticerp-frontend`, cambios de este batch **no commiteados todavía**.
No se corrió contra `dev.atlanticerp.ai`.

## Resultado

**Pasada limpia — sin hallazgos bloqueantes.** 0 CRÍTICO, 0 MEDIO. Los 5 tickets pasan a `QA`.

## Camino de ruptura ejercitado

### REQ-255 (Vista Equipo) / REQ-256 (estado automático)
- Los 4 estados (Disponible/En ruta/Ocupado/Fuera) verificados en tiempo real contra tickets
  sembrados dinámicamente alrededor de la hora real de ejecución (no horas hardcodeadas —
  necesario porque el cálculo es 100% `Carbon::now()`, sin freeze en producción).
- Ticket **cancelado** el mismo día para un técnico: confirmado que NO cuenta en `visitas_hoy` ni
  afecta su estado (sigue "Disponible").
- Placeholders documentados (`herramientas_asignadas: 0`, `pct_resuelto_primera_visita: null`) se
  ven como "0"/"—" en la tarjeta — nunca "null"/"undefined" ni crash.
- Ningún control de edición manual del estado existe en la UI (RN5) — confirmado por inspección de
  `InternalTechnicianCard.tsx`/`InternalTechnicianDetailModal.tsx`, ningún input/select para
  `estado`.
- 20/20 tests automatizados de `InternalTechnicianBatch6Test.php` corridos en vivo (no solo
  confiados de memoria) — incluyen el límite de horario laboral (`Carbon::setTestNow('20:00')` →
  "Fuera"), que no se puede ejercitar en vivo sin congelar el reloj del proceso PHP-FPM real.

### REQ-257 (Modal "Visitas hoy")
- Orden cronológico verificado con 2 visitas de horas distintas para el mismo técnico.
- Estado vacío ("Sin visitas hoy") verificado para un técnico sin ninguna visita hoy — nunca un
  modal vacío sin explicación.
- `GET .../{id}/visitas-hoy` con id inexistente → 404 JSON limpio, no 500.

### REQ-259 (Registrar técnico)
- **Gate de rol, camino de ruptura primero:** `tecnico_servicios` (Carlos) y `vendedor_disenador`
  (Milena) → 403 real de API (`role:superadmin,lider_servicios` en la ruta) y botón "+ Nuevo
  técnico" ausente del DOM (no solo `display:none`, no está montado). Sin auth → 401.
  `superadmin` y `lider_servicios` → 201, confirmados ambos.
- Validación campo por campo con `Accept: application/json` (nota: sin ese header Laravel
  redirige 302 en vez de devolver 422 JSON — comportamiento estándar de Laravel para requests no-
  AJAX, no un bug; axios en el frontend sí manda el header, confirmado en `src/api/serviciosApi.ts`
  vía el cliente Axios compartido): nombre vacío, nombre solo-espacios (recortado por
  `TrimStrings`), especialidad ausente, especialidad inválida, email con formato inválido → 422 con
  mensaje específico por campo, en los 5 casos.
- **Unicidad de color (RN3):** creados 5 técnicos nuevos de especialidad `warranty` (la misma que
  Miguel Castillo, ya con ámbar) — los primeros 4 recibieron los 4 colores restantes de la paleta
  de 8, sin colisión con ninguno de los 4 técnicos ya seedeados; el 5º (9º técnico total, paleta de
  8 agotada) reutilizó correctamente el color menos usado en vez de fallar o repetir arbitrariamente.
  Limpiado tras la verificación.
- Doble clic en "Guardar" → un solo registro creado (confirmado con Playwright, `clickCount: 2`) —
  el botón se deshabilita mientras la mutación está en curso (`Button` component,
  `disabled={disabled || loading}`).
- **Nota de diseño verificada, no es un hallazgo:** un técnico dado de alta por este formulario
  nace sin `user_id` (no reusa el flujo de `POST /api/users`) — por diseño, siempre muestra estado
  "Fuera" apenas se refresca la Vista Equipo (nunca puede recibir una visita agendada, ver docblock
  de la migración). El `201` de creación responde "Disponible" (cumple RN4 literalmente: "un
  técnico nuevo siempre inicia con Disponible"), pero eso es la respuesta puntual del POST, no el
  estado recalculado que ve la vista un instante después. La UI no muestra nada engañoso — el test
  E2E permanente confirma la transición real (ver abajo).

### REQ-260 (Agenda equipo)
- Vista agrupada por técnico con bloques de color propio, verificado.
- Filtro "Todos" vs. un técnico específico, verificado (oculta correctamente los demás bloques).
- Estado vacío por técnico ("Sin visitas hoy — se puede asignar carga"), verificado.
- **RN5 — acceso de Vendedor/Diseñador:** `milena.e@grupolafayette.com` puede ver Agenda equipo
  (API 200 + UI renderiza) pero el botón "+ Nuevo técnico" permanece ausente — solo-vista real, no
  solo un permiso de lectura que además esconde mal un botón de escritura.

## Camino feliz (verificado, no es el foco pero se confirmó)
- Los 4 técnicos reales seedeados (`InternalTechnicianSeeder`, idempotente, corre en todos los
  entornos) aparecen correctamente con nombre/especialidad/color propios.
- `PHPStan level 8` limpio (`--memory-limit=1G`, sin el flag falla por "Child process error" —
  gotcha ya documentado del proyecto).
- 20/20 tests de `InternalTechnicianBatch6Test.php` en verde, corridos en vivo con `infra/test.sh`.

## Paso 0 — permisos/valores paramétricos (antes de tocar el navegador)
- REQ-259 RN5 menciona un rol puntual ("solo Aaron/Líder de Servicios") pero es un **rol**
  (`lider_servicios`), no un `extra_permissions` angosto por persona — no aplica el patrón de
  `SpecialPermissionSeeder.php`. Confirmado con el usuario real `servicio@atlantic.com.pa`
  (rol `lider_servicios`) ya provisto en el roster — sin gap de grant.
- No hay umbral/margen/porcentaje de negocio en este batch (los 5 tickets, sin contar SCRUM-321
  que sí los tiene y queda diferido).
- Ningún botón de otro ticket sin cablear bloqueó el flujo — REQ-259/260 no dependen de UI de otro
  ticket del mismo batch.
- Dato sembrado: el roster real (4 técnicos) no cubre por sí solo los 4 estados de REQ-256 en
  tiempo real (todos "Disponible" sin tickets) — se sembraron tickets propios vía fixture
  self-seeding en el propio test E2E (ver abajo), no un grant manual que se revierte.

## Regresión de mi propio proceso, corregida en el momento
La primera versión del test E2E permanente sembraba los tickets de prueba a mano vía `tinker`
**fuera** del archivo de test, con horas de reloj real hardcodeadas en el momento de la sesión.
Eso es exactamente el anti-patrón ya documentado en
`feedback_e2e_permanent_tests_must_self_seed.md` — se habría roto en la siguiente corrida (al día
siguiente los tickets sembrados hoy ya no caen en la ventana de "hoy" del backend, y además
`infra/test.sh`, corrido más tarde en la misma sesión para validar el backend, de hecho **borró**
esos tickets manuales como efecto secundario documentado de compartir Postgres con el dev local).
Corregido antes de dar la pasada por limpia: el fixture ahora se siembra en `test.beforeAll()`
dentro del propio spec (offsets relativos a `Carbon::now()` en el momento real de la corrida,
mismo patrón que `preqa-scrum216-217-timeline-race-recheck-20260806.spec.ts`) y se limpia en
`test.afterAll()`. Verificado corriendo el archivo 2 veces seguidas sin acumulación ni colisión.

## Archivos tocados por Pre-QA
- `atlanticerp-frontend/e2e/preqa-scrum318-319-320-322-323-servicios-batch6-tecnicos-internos-20260809.spec.ts`
  (nuevo, promovido a permanente — gate de rol + estado en tiempo real ya se ejercitó una vez)
- Este archivo.
- Ningún archivo de `atlanticerp-backend` o de código fuente de `atlanticerp-frontend` requirió cambios — 0
  hallazgos bloqueantes.

## Veredicto
SCRUM-318, SCRUM-319, SCRUM-320, SCRUM-322, SCRUM-323 → transicionados a `QA`.
SCRUM-321 → sin cambios de estado, comentario de deferral agregado (depende de Batch 7 / REQ-292).
