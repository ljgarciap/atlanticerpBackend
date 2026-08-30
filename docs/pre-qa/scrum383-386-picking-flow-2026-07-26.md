# Pre-QA — Flujo de Picking (SCRUM-383→386, REQ-313/314/315/316/317)

**Fecha:** 2026-07-26
**Alcance:** `atlanticerp-backend` HEAD `231ddd3` (rama `dev`, ya en producción desde hace días — este
batch no toca backend, ya estaba completo). `atlanticerp-frontend` HEAD `346d05c` "feat(bodega): wire
picking flow" (rama `dev`, pusheado, 619/619 vitest + `tsc` limpio antes de empezar).

**Entorno de verificación:** stack Docker local (`infra-laravel-1`, `infra-nginx-1` puerto 8090,
`infra-postgres-1`), `npm run build` corrido de cero al empezar (el `dist/` existente era anterior
al último commit — se detectó y corrigió antes de tocar Playwright). Verificación de API con
`curl` directo contra usuarios reales (no mocks) y verificación de UI con Playwright real contra
el dev server Vite (`localhost:5173`, proxy `/api` → nginx:8090).

## Veredicto: NO PASA — 2 hallazgos CRÍTICOS, 1 hallazgo MEDIO

El camino feliz completo y la mayoría de reglas de negocio están bien implementadas (ver detalle
abajo). Pero encontré dos huecos de autorización/alcance reales al intentar romper el flujo más
allá de los 9 puntos sugeridos, y un hueco de UX real dentro de RN3 de SCRUM-383. **No recomiendo
pasar estos 4 tickets a `QA` hasta que el CRÍTICO #1 (autorización) se corrija — es un problema de
control de acceso, no solo estético.**

---

## Fixtures sembrados para esta pasada (vía tinker, `Order`/`CatalogProduct` reales)

Además del fixture pre-existente `Order #176 "PED-E2E-PICK-01"` (2 ítems, ya tenía su propio
spec `e2e/preqa-scrum383-386-picking-flow.spec.ts` escrito por Frontend Dev), sembré:

| Fixture | order_number | Etapa inicial | Detalle |
|---|---|---|---|
| Order (temporal, borrado al cerrar) | `PED-PREQA-RN3` | asignado | 1 ítem — intento de asignar picker con rol no autorizado |
| Order (temporal, borrado al cerrar) | `PED-PREQA-PARTIAL` | en_picking, picker=Apolonio | 3 ítems, ubicaciones D-01/D-02/D-03 — picking parcial → Revisión de Inventario + edición "Alistada" |
| Order (temporal, borrado al cerrar) | `PED-PREQA-READONLY` | packing, picker=Apolonio | 1 ítem ya completo — modo solo consulta |
| Order (temporal, borrado al cerrar) | `PED-PREQA-COURIER` | picking_pendiente, picker=Apolonio | 1 ítem — verificación de autorización cruzada (courier operando el pedido de otro) |

Todos los fixtures y los `CatalogProduct` de prueba (`PREQA-*`) se borraron al cerrar la sesión
(`docker compose exec laravel php artisan tinker`, ver reporte de la tarea para el script exacto).
Es un entorno Docker local, no `dev.atlanticerp.ai` — no afecta lo que verifica marly.

Test permanente agregado (sigue la convención `preqa-*.spec.ts` + patrón "salta si el fixture no
está sembrado" del resto del proyecto): `atlanticerp-frontend/e2e/preqa-scrum383-386-adversarial.spec.ts`
— 3 tests, documentan tanto lo que pasa como los 2 hallazgos de UI de abajo. Como los fixtures que
usa fueron sembrados a mano y ya se borraron, correrá en modo "skip" hasta que alguien los
re-siembre (mismo patrón ya aceptado por el spec original de Frontend Dev para `Order #176`).

---

## Resultado real de verificación, punto por punto

### 1. Camino feliz completo — PASA

Corrido con `Order #176` vía el spec existente (`preqa-scrum383-386-picking-flow.spec.ts`):
asignar picker → iniciar picking → Hoja de Picking con 2 ítems ordenados por ubicación (A-01 antes
que B-02) → marcar ambos "Recogido" → completar → banner "avanzó a Packing" → tarjeta confirmada en
columna Packing del tablero. Sin errores de consola. Sin columna "Ref. fábrica" (gap de contrato ya
documentado, correcto). Verificado también en DB: `stage=packing`, `qty_picked` = `qty_requested`
en ambos ítems.

### 2. RN1 de SCRUM-383 (picker obligatorio) — PASA

Confirmar el mini-formulario sin elegir picker marca el campo en rojo
(`assign-picker-required-error-176` visible) y NO dispara la mutación (confirmado por el mismo
test, la tarjeta no avanza hasta seleccionar un picker real).

### 3. RN3 de SCRUM-383 (solo Jefe/Asistente pueden asignar) — BACKEND PASA, HALLAZGO MEDIO EN FRONTEND

El backend (`PickingService::assignPicker()`) responde `403` con el mensaje real ("Solo el Jefe de
Bodega o los Asistentes de Bodega pueden asignar el picking.") — verificado con `curl` directo
usando el token real de Apolonio González (Picker). **Esta parte del criterio está bien.**

**Hallazgo MEDIO:** `OrderCardTile.tsx` muestra el botón "Asignar Picker" a CUALQUIER usuario con
`bodega.write` (sin gate de rol client-side), incluidos Picker/Repartidor. Al abrir el
mini-formulario, el selector de picker (`useBodegaHomeTeam`, mismo hook que usa el selector
"Equipo" del Home de Bodega) llega **vacío** para estos roles — `BodegaHomeController::team()`
devuelve `{"data": []}` a propósito para quien no tiene `canViewTeamV2(BODEGA)`, que por diseño
excluye a Picker/Repartidor (ver docblock del propio controller, no es un bug ahí). El resultado
para el usuario real: ve el botón, lo abre, el selector solo tiene el placeholder, y al presionar
"Confirmar" sin elegir nada dispara el error de "campo requerido" (RN1) — **nunca llega a ver el
mensaje real de por qué está bloqueado** (que sí existe y es correcto, solo que la UI jamás lo deja
salir a la superficie para este camino). Es confuso para un Picker/Repartidor real que entre a
Pedidos y pruebe el botón. No bloqueante para el flujo del Jefe/Asistente (que sí ve el selector
poblado), pero sí un hallazgo de UX real que vale la pena corregir: ocultar el botón para roles sin
`CAN_OPERATE`, o al menos no ofrecer un selector vacío sin explicación.

Repro: login como `apolonio.gonzalez@illuminations.com.pa`, ir a Pedidos, abrir "Asignar Picker" en
cualquier tarjeta en Asignado → selector solo con "Selecciona un picker...". Test:
`preqa-scrum383-386-adversarial.spec.ts:36`.

### 4. RN1 de SCRUM-386 (bloqueo si falta marcar "Recogido") — PASA, verificado con 3 ítems

Con fixture de 3 ítems, marcar solo 1 de 3 y presionar "Picking completado" muestra el error
inline ("Falta marcar 'Recogido' en 2 artículos") sin `window.confirm`/`alert`, y el checkbox
vuelve a su estado sin marcar (no queda una marca visual falsa). No avanza la etapa hasta que los 3
estén marcados.

### 5. RN2 de SCRUM-385 (Alistada bloqueada + tope de cantidad) — PASA, en frontend Y backend

"Alistada" arranca bloqueada (sin botón "Editar" no hay input, solo el número + lápiz). Intentar
poner `999` en un ítem con `qty_requested=5` se clampa a `5` en el frontend
(`handleQtyChange`). Repetí el intento **bypaseando el frontend** con un `PATCH` directo a
`/api/bodega/orders/{id}/picking-sheet` con `qty_picked: 999` → el backend también lo rechaza con
`422` ("La cantidad alistada no puede ser negativa ni superar lo pedido."). Mismo resultado
probando `qty_picked: -5`. Doble capa de validación confirmada, sin gaps.

### 6. RN3 de SCRUM-385 (modo solo consulta fuera de "En picking") — CRÍTICO: criterio irreproducible en la UI

El backend está bien (`editable: false` cuando `stage !== en_picking`, confirmado con `GET
.../picking-sheet` directo devolviendo `editable: false` para un pedido en Packing). El problema es
que **no existe ningún botón, link o punto de entrada en toda la SPA que abra `PickingSheetModal`
para un pedido que ya avanzó más allá de "En picking"**:

- `OrderCardTile.tsx` solo llama a `onOpenPickingSheet` desde las etapas `picking_pendiente` y
  `en_picking` — para cualquier otra etapa no terminal (incl. Packing) el botón de la tarjeta es el
  genérico deshabilitado "Próximamente".
- `OrderDetailModal.tsx` no importa `PickingSheetModal` en ningún punto — su "Ver detalle de
  artículos" es una tabla completamente distinta (columnas Ref. fábrica/Disponible/Ver bodegas,
  sin Recogido/Alistada/Encontrado en).
- `OrderStatusPage.tsx` tampoco lo referencia.

En la práctica, la única forma de VER la hoja en modo solo consulta hoy es quedarse con el modal
abierto justo después de completar el propio picking (el `useQuery` se revalida, `editable` pasa a
`false` en la misma sesión) — apenas se cierra el modal o se recarga la página, ya no hay forma de
volver a abrirla. El criterio literal de Jira ("cuando se abre su Hoja de Picking, entonces se abre
en modo de solo consulta") presupone que un usuario puede abrirla — hoy no puede, para ningún
pedido que no acaba de completar él mismo en esa misma sesión de navegador. Esto es un problema
real de uso: Empaque/supervisores/auditoría no tienen forma de revisar qué se recogió realmente en
un pedido ya despachado.

Repro: login como `almacen@illuminations.com.pa` (Jefe de Bodega, con todos los permisos), abrir un
pedido en Packing → sin botón de picking en la tarjeta, sin acceso desde el detalle. Test:
`preqa-scrum383-386-adversarial.spec.ts:156` (asserts la ausencia del modal y confirma vía API
directa que el backend sí tiene `editable: false` listo para servir).

**Severidad: CRÍTICO** — no es un problema de seguridad, pero sí de funcionalidad faltante contra
un escenario de aceptación explícito del ticket (SCRUM-385 Escenario 2), no mencionado en los gaps
de contrato ya conocidos/documentados por Frontend Dev.

### 7. Picking parcial → Revisión de Inventario — PASA

Con el fixture de 3 ítems, reduje "Alistada" del primero de 5→3 (dejando 2 de shortfall), marqué
"Recogido" en los 3, completé — banner correcto: "Picking completado con faltantes — el pedido
avanzó a Packing y se generó una Revisión de Inventario (Pedido #PED-PREQA-PARTIAL)". Confirmado en
DB: la tarjeta original avanzó a `packing` con `qty_picked=3` (cantidad reducida, la que Empaque va
a usar como tope real); se creó una tarjeta nueva tipo `revision_inventario`, mismo `order_number`
(HU-335), en `picking_pendiente`, con el ítem faltante (`qty_requested = 5-3 = 2`), vinculada por
familia a la original.

### 8. Sin iconografía de emoji (regla SCRUM-56) — PASA

Revisé visualmente los 8 estados de la Hoja de Picking, el mini-formulario de asignación y los
banners de resultado (screenshots adjuntos en el reporte de la tarea) — solo iconos SVG del set
propio (`IcoPencil`, `IcoDownload`, `IcoPrinter`, `IcoCheck`, `IcoClose`, `IcoAlertTriangle`). Grep
sobre los strings de i18n (`es`/`en` de `bodega.json`) relacionados a picking: sin glyphs de emoji
embebidos.

### 9. Excel / Imprimir — PASA

"Descargar Excel" dispara una descarga real (`hoja-picking-{order_number}.xlsx`, 6.4KB, firma de
archivo confirmada `Microsoft Excel 2007+` con `file`, no un HTML de error). "Imprimir" invoca
`window.print()` real (interceptado y confirmado con un spy en la página, no revienta ni navega
fuera del modal).

---

## Hallazgo adicional, no pedido explícitamente en los 9 puntos pero encontrado al intentar romper el flujo

### CRÍTICO — Cualquier rol de Bodega con `bodega.write` (incl. Repartidor/Courier) puede iniciar/editar/completar el picking de un pedido ajeno, sin relación con ese pedido

`PickingService::assignPicker()` es el ÚNICO método del flujo que valida `BodegaRoles::CAN_OPERATE`
(solo Jefe/Asistente). `startPicking()`, `updatePickingSheet()` y `completePicking()` **no
verifican el rol del actor ni que el actor sea el `picker_id` asignado al pedido** — solo verifican
el `stage` del pedido. Como la migración
`2026_07_22_100010_grant_bodega_edit_to_operational_roles.php` le da `bodega.write` (`can_edit`
Nivel 3) a **los 3 roles operativos** (`asistente_bodega`, `ayudante_general_bodega` = Picker,
`transporte` = Courier), cualquiera de ellos pasa el gate de la ruta (`permission:bodega.write`) sin
más chequeo.

**Reproducido en vivo con `curl` real** (Gary Arrocha, rol `transporte`/Courier — cuyo trabajo real
es despachar, no hacer picking — actuando sobre un pedido con `picker_id` = Apolonio González, NO
Gary):

```
POST /api/bodega/orders/188/start-picking       (token de Gary) → 200 {"stage":"en_picking"}
PATCH /api/bodega/orders/188/picking-sheet       (token de Gary) → 200 (edita qty_picked de otro)
POST /api/bodega/orders/184/complete-picking     (token de Gary) → 200 (avanza a Packing)
```

Ningún endpoint devolvió `403`. Gary pudo mover a Packing un pedido que ni siquiera tiene asignado
a sí mismo. Esto contradice el espíritu documentado de `BodegaRoles::CAN_OPERATE` (el propio
docblock de la clase dice "Roles que pueden asignar picker y **operar el flujo general de
Bodega**" — dos roles, no cuatro) y del docblock de `PickingService` (que solo defiende el gate de
rol en `assignPicker`, sin justificar por qué el resto del flujo no lo necesita).

**Severidad: CRÍTICO** — no es un problema de datos corruptos (las transiciones de stage y
reservas de stock son consistentes), pero sí un gap de control de acceso real: un Courier puede
operar picking de pedidos ajenos sin ninguna relación operativa con ellos, y ningún Asistente/Jefe
se entera (no hay bloqueo, no hay alerta). Recomiendo escalar a Arquitecto/PM antes de pasar estos
4 tickets a QA — probablemente requiere agregar el mismo chequeo `CAN_OPERATE` (o, más ajustado,
"actor === order.picker_id O actor en CAN_OPERATE") a `startPicking()`, `updatePickingSheet()` y
`completePicking()`.

---

## Resumen de severidades

| # | Hallazgo | Severidad | Bloqueante para pasar a QA |
|---|---|---|---|
| — | Cualquier rol Bodega (incl. Courier) puede operar picking de pedidos ajenos vía API directa | **CRÍTICO** | Sí |
| 6 | Sin punto de entrada en la SPA para reabrir la Hoja de Picking de un pedido ya en Packing+ (criterio SCRUM-385 Escenario 2 irreproducible) | **CRÍTICO** | Sí |
| 3 | Selector de picker vacío y sin explicación para roles no autorizados (Picker/Repartidor), error "campo requerido" engañoso | MEDIO | No, pero corregir antes de cerrar el batch |
| 1,2,4,5,7,8,9 | Camino feliz, RN1×2, RN2 (frontend+backend), picking parcial→revisión, sin emoji, Excel/Imprimir | Sin hallazgos | — |

**Recomendación:** devolver a PM para reasignar los 2 CRÍTICOS a Backend Dev (autorización) y
Frontend Dev (punto de entrada a la Hoja de Picking histórica) antes de pasar SCRUM-383→386 a
`QA`. El hallazgo MEDIO puede ir en el mismo ciclo de corrección o quedar como ticket de
seguimiento explícito, a decisión de Luis.

---

## Segunda pasada — 2026-07-26 (noche): PASA LIMPIO, 10/10

**Alcance:** `atlanticerp-backend` HEAD `1755d5a` (merge de `2386d30`, fix de autorización) / `atlanticerp-frontend`
HEAD `37b183a` (merge de `3cb54db`, fix de entry point + gate de UI), ambos en `dev` local,
`npm run build` corrido de cero antes de empezar. Regla dura del proyecto: esta pasada re-corre el
checklist COMPLETO de 10 puntos, no solo los 3 hallazgos de la primera pasada.

**Veredicto: PASA — sin hallazgos nuevos, ninguno de los 3 hallazgos anteriores sigue presente.**
Recomiendo pasar SCRUM-383→386 a `QA`.

### Qué se verificó y cómo

- **Regresión automatizada:** `infra/test.sh --filter=Picking` → 31/31 (incluye 6 tests nuevos de
  esta ronda: `test_iniciar_picking_con_rol_no_autorizado_retorna_403` +
  `..._es_permitido`, mismo par para `actualizar_picking_sheet` y `completar_picking`).
  `npx vitest run` completo del frontend → 625/625. `npx tsc --noEmit` limpio.
- **Punto 4 (CRÍTICO #1, gate de autorización) — CONFIRMADO CORREGIDO.** `curl` directo con el
  token real de Gary Arrocha (`transporte`/Courier) contra un pedido asignado a Apolonio González
  (otro picker): `start-picking` → 403, `PATCH picking-sheet` → 403, `complete-picking` → 403, los
  3 con el mensaje real ("Solo el Jefe de Bodega, los Asistentes de Bodega o el Picker asignado
  pueden operar el flujo de picking."), no un 500 ni un 200 silencioso.
- **Punto 3 (MEDIO, selector vacío engañoso) — CONFIRMADO CORREGIDO.** Login como Apolonio
  (Picker): el botón "Asignar Picker" ya NO se renderiza en la tarjeta (antes aparecía con selector
  vacío). Regresión verificada: el mismo botón SIGUE visible para Esteban (Jefe de Bodega,
  `view_team=true`). El backend sigue respondiendo 403 real por API directa (defensa en
  profundidad, sin depender solo del gate de UI).
- **Punto 7 (CRÍTICO #2, sin entry point) — CONFIRMADO CORREGIDO.** `OrderDetailModal` ahora
  muestra "Ver hoja de picking" para un pedido en Packing con picker asignado; al abrirla, modo
  solo consulta real (sin checkboxes "Recogido"/"Picking completado", banner "Solo consulta"
  visible). Para un pedido en Packing que NUNCA tuvo picker asignado (fixture sintético forzado en
  BD, el flujo real de negocio no permite llegar a Packing sin pasar por asignación), el botón NO
  aparece — el proxy `order.picker !== null` se comporta como se documentó.
- **Punto 1 (camino feliz) y punto 5 (RN1 SCRUM-386)** — re-verificados de punta a punta con
  Playwright real (`e2e/preqa-scrum383-386-picking-flow.spec.ts`, actualizado al nuevo ID del
  fixture recreado, mismo `order_number` `PED-E2E-PICK-01`): asignar picker → iniciar picking →
  hoja ordenada por ubicación → bloqueo real al faltar 1 de 2 "Recogido" → completar → avanza a
  Packing → reabre desde el nuevo entry point en modo solo lectura. Sin errores de consola.
- **Punto 2 (RN1 SCRUM-383)** — confirmar sin elegir picker marca el campo en rojo, sin mutación
  (mismo spec de arriba).
- **Punto 6 (RN2 SCRUM-385)** — "Alistada" bloqueada hasta "Editar"; clamp client-side confirmado
  (999 → topa a lo pedido); bypass de frontend confirmado con `PATCH` directo: `qty_picked` sobre
  lo pedido → 422 con mensaje claro; `qty_picked` negativo → 422 con mensaje claro **siempre que la
  request mande `Accept: application/json`** (nota de proceso, no hallazgo de producto — un primer
  intento sin ese header devolvió 302 a `http://localhost`, comportamiento default de Laravel para
  `ValidationException` cuando `expectsJson()` es falso; axios/el frontend real siempre manda ese
  header, así que no es alcanzable por un usuario real, solo por un curl armado a mano sin headers).
- **Punto 8 (picking parcial → Revisión de Inventario)** — con 3 ítems, reduje el primero de 3→1,
  marqué "Recogido" en los 3, completé: banner "Picking completado con faltantes... Revisión de
  Inventario" visible, confirmado en UI.
- **Punto 9 (sin emoji)** — grep programático sobre el texto visible del modal completo (rango
  Unicode de emoji/símbolos) vía Playwright: sin coincidencias. Solo iconos SVG (`IcoPencil`,
  `IcoDownload`, `IcoPrinter`, `IcoCheck`, `IcoClose`, `IcoAlertTriangle`).
- **Punto 10 (Excel/Imprimir)** — "Descargar Excel" dispara una descarga real
  (`hoja-picking-PED-PREQA2-E.xlsx`, evento `download` de Playwright confirmado, nombre con
  extensión `.xlsx` real). "Imprimir" invoca `window.print()` real (confirmado con spy en la
  página).

### Fixtures de esta pasada

Sembrados vía `tinker` (`illuminations_bodega.orders`, todos borrados al cerrar la sesión, entorno
Docker local — no afecta lo que verifica marly en `dev.atlanticerp.ai`):

| Fixture | Etapa | Detalle |
|---|---|---|
| `PED-E2E-PICK-01` (recreado) | asignado → ... | 2 ítems (Foco LED E2E / A-01, Lampara colgante E2E / B-02) — mismo fixture del spec original de Frontend Dev, recreado porque `infra/test.sh` resetea el schema de tenant compartido con Docker local |
| `PED-PREQA2-A` | asignado | RN3 — visibilidad del botón "Asignar Picker" |
| `PED-PREQA2-D` | en_picking, picker=Apolonio | 3 ítems (3/A-01, 2/B-02, 4/C-03) — picking parcial + clamp de "Alistada" |
| `PED-PREQA2-E` | en_picking, picker=Apolonio | 1 ítem, qty=5 — bypass de frontend (curl directo), Excel/Imprimir |
| `PED-PREQA2-G` | packing, picker=Apolonio | 1 ítem completo — reentrada en modo solo lectura |
| `PED-PREQA2-H` | packing, picker=NULL (sintético) | confirma ausencia del botón "Ver hoja de picking" |

Test permanente `e2e/preqa-scrum383-386-adversarial.spec.ts` reescrito para reflejar el estado
CORREGIDO (antes documentaba el hallazgo roto) — sigue el mismo patrón "salta si el fixture no
está sembrado" del resto de specs `preqa-*`. `e2e/preqa-scrum383-386-picking-flow.spec.ts`
actualizado al nuevo ID del fixture recreado (mismo `order_number`).

**Estado de git al cierre de esta pasada:** ambos archivos de `e2e/` quedaron modificados/creados
en el working tree, sin commitear — Pre-QA no pushea código, solo verifica; el commit/push de estos
specs queda a criterio de PM/Backend/Frontend Dev al cerrar el ticket.
