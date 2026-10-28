# Pre-QA — Bloque B3 "Zona Libre de Colón" (2026-07-24)

**Tickets:** SCRUM-433→441 (REQ-363→371, Nueva Orden Zona Libre — 3C) y SCRUM-442→445
(REQ-372→375, Bandeja Órdenes Zona Libre — 3D).
**Commits revisados:** `atlanticerp-backend` `e2e1b83` + `1f72ce4` (ya pusheados). `atlanticerp-frontend`
`1bf08b4` (batch) + `ec6511d` (fix de esta sesión de Pre-QA, **local, sin pushear todavía**).
**Precede a esta sesión:** Visual Reviewer, pasada limpia (`docs/visual-review/bloque-b3-zona-libre-2026-07-24.md`
en `atlanticerp-frontend`, 0 CRÍTICOs).
**Método:** app real, Docker local (`infra-*`) + Vite dev server (`localhost:5173`). Backend:
`infra/test.sh --filter=ZonaLibre` (22 tests) + `curl` directo contra la API real con JWT de
`almacen@atlantic.com.pa` (lider_bodega) y `gerencia2@atlantic.com.pa` (lider_compras,
Lidercompras) para los caminos de ruptura que el feature-test no puede ejercitar en el entorno real
(rutas HTTP method-not-allowed, 403 en runtime). Frontend: `npx vitest run` (18 tests, incl. 2
nuevos de esta sesión) + `npx playwright test e2e/preqa-bloque-b3-zona-libre.spec.ts` (4 specs,
promovido a permanente en `atlanticerp-frontend/e2e/`) contra la app real. Fixtures sembradas a mano vía
tinker (la DB local se comparte con `infra/test.sh`, que la resetea — se resembró después de cada
corrida de tests backend, ver nota de memoria `feedback_local_test_db_no_es_fresh`): productos
`CatalogProduct` 16/17 (proveedor Zona Libre, con stock en 2 bodegas) y 4 `BodegaZonaLibreRequest`
(pendiente single-producto, pendiente multi-producto, aprobada, rechazada con motivo real).

## Paso 0 — permisos angostos y valores paramétricos

- `compras.zona-libre.approve` (aprobar/rechazar) es un grant individual vía `extra_permissions`
  a `gerencia2@atlantic.com.pa` (Lidercompras), **ya presente en `SpecialPermissionSeeder.php`**
  — no hace falta grant manual. Confirmado que Lidercompras tiene también `role_key = lider_compras`
  (`BusinessRoleUserSeeder`), que es el rol que usa `remind()` para resolver la audiencia del
  recordatorio — hoy ambos mecanismos apuntan a la misma persona, camino feliz funciona sin
  fricción. Ver nota ACEPTABLE abajo — riesgo de desincronización si el rol cambia de manos, no es
  un hardcode y sigue el mismo patrón ya aprobado del proyecto (`compras.settings.configure`, ver
  ADR-SCRUM433-445 sección "Permisos"), así que no lo trato como bloqueante.
- No hay umbral/margen/porcentaje de negocio en este batch — no aplica el check de tabla
  paramétrica.

## Checklist — criterio por criterio (camino feliz / camino de ruptura probado)

| Ticket | Camino feliz | Camino de ruptura probado |
|---|---|---|
| SCRUM-433 proveedor fijo | Nombre real visible, sin selector | Confirmado 0 `<select>`/combobox de proveedor en el DOM; banner de aviso siempre visible (no solo aplicado silenciosamente) |
| SCRUM-434 bloqueo cantidad 0 | Buscador + tabla completas (8 columnas) | Cantidad 0 → no agrega, aviso inline. **Cantidad fraccionaria (1.5) → NO estaba bloqueada client-side, solo server-side (422 genérico) — CORREGIDO esta sesión, ver hallazgo abajo** |
| SCRUM-435 sincronía disponible↔carrito | Agregar quita de disponibles | Quitar del carrito lo devuelve a disponibles, sin recargar — confirmado en vivo (Playwright) |
| SCRUM-436 carrito/editar/totales | Editar cantidad válida recalcula | Editar a 0 → rechaza, mantiene cantidad anterior. **Editar a fraccionario (1.5) → mismo gap que arriba, mismo fix** |
| SCRUM-437 ver bodegas | Modal con desglose real | Código: `warehouseRows()` filtra `quantity > 0` server-side — nunca fabrica bodegas sin stock. Confirmado con 2 bodegas reales |
| SCRUM-438 detalles de envío | Terrestre default, editable | Modalidad: `<select disabled>` — genuinamente bloqueada (no solo visualmente gris), confirmado en código y en DOM real |
| SCRUM-439 guardar orden | Folio + estado inicial correcto | Carrito vacío → panel completo ni se renderiza (doble bloqueo, botón tampoco existe). Folio: consecutivo confirmado con 2 solicitudes seguidas vía curl real (3, 4) además del feature test |
| SCRUM-440 ciclo de vida | Aprobar genera PurchaseOrder normal | **RN3 (Bodega no edita orden enviada): confirmado con `php artisan route:list` — CERO rutas PUT/PATCH/DELETE para `zona-libre/requests`, cualquier intento devuelve 405 real (probado con curl+JWT real de Bodega, incluso sobre una ya aprobada). PERMISOS: `approve`/`reject` de Bodega vía API real → 403 confirmado (no solo oculto en UI); Lidercompras vía API real → 200, genera `PurchaseOrder` real (`modality=zona_libre`)** |
| SCRUM-441 panel confirmación | Folio + navegación | "Crear una nueva" resetea sin navegar — confirmado en vivo, formulario limpio, sin residuo del carrito anterior |
| SCRUM-442 bandeja/tabla | 8 columnas correctas | Multi-producto → "2 productos" (no el nombre), confirmado con fixture real de 2 líneas |
| SCRUM-443 chips filtro | Filtrado por status | Mutuamente excluyentes confirmado en vivo: cada chip reemplaza al anterior, sin combinación — server-side (`?status=`) |
| SCRUM-444 acción por estado | 3 variantes correctas | Aprobada → sin botón, solo texto "Sigue el flujo normal de Compras"; Rechazada → "Ver motivo" con el texto EXACTO sembrado ("Ya existe una orden similar en camino, esperar a que llegue antes de duplicar."), no un genérico |
| SCRUM-445 recordar | Envía notificación | Solo visible en pendiente (confirmado ausente en aprobada/rechazada). RN1: `remind()` resuelve la audiencia por `Role::where('key','lider_compras')`, **sin ningún email hardcodeado en el controller** — confirmado por lectura de código |

## CRÍTICO
Ninguno.

## MEDIO — corregido en esta sesión (regla dura: fix chico → se arregla y se re-corre el checklist completo)

**Cantidad fraccionaria no bloqueada client-side (SCRUM-434/436).** `addToCart()` y `confirmEdit()`
en `BodegaNuevaOrdenZonaLibrePage.tsx` solo rechazaban `qty <= 0`, no valores no-enteros. Escribir
"1.5" en el input de cantidad (disponible en la tabla de productos o al editar una línea del
carrito) pasaba la validación del frontend sin aviso, quedaba en el carrito, y solo fallaba al
guardar la orden completa — con un mensaje **genérico** ("hubo un error") que no indicaba cuál
línea ni por qué, dejando al usuario sin poder saber qué corregir. El backend sí lo rechazaba
correctamente (422, regla `integer` de Laravel) — el gap era puramente de feedback en runtime,
exactamente el tipo de camino de ruptura que este rol existe para encontrar (input inválido con
rechazo silencioso o tardío, ver Paso 3 de `pre-qa.md`).

**Fix aplicado:** `Number.isInteger(qty)` agregado a ambas validaciones (agregar y editar),
reusando los mensajes de error ya existentes (`products.errors.zeroQuantity` /
`lines.errors.invalidQuantity`, sin i18n nuevo) + `step={1}` en los 2 inputs de cantidad. Tests
nuevos agregados a `BodegaNuevaOrdenZonaLibrePage.test.tsx` (2 casos). **Checklist completo
re-corrido tras el fix — pasada limpia** (backend 22/22, frontend unit 18/18, Playwright e2e 4/4).
Commit `ec6511d` en `atlanticerp-frontend`, rama `dev`, **local, sin pushear** — junto con `1bf08b4`
(el batch original), pendiente del push coordinado que hace el hilo principal.

## ACEPTABLE (no bloquea)

- `compras.zona-libre.approve` es un grant por persona (Lidercompras), no por rol — ver Paso 0. Riesgo
  latente si el rol `lider_compras` cambia de titular (quien reciba el recordatorio vía
  `remind()` no necesariamente tendría el permiso de aprobar), pero sigue el mismo patrón ya
  aprobado del proyecto y está correctamente seedeado — no es el hardcode de email que el encargo
  pedía verificar explícitamente (no lo hay, confirmado por lectura de código).
- Numeración de Zona Libre (`ZonaLibreFolioCounter`) es una secuencia propia, separada de
  `PurchaseOrder`/`Order` — un "#3" de Zona Libre y un "#3" de una orden de Compras normal pueden
  coexistir. Coherente con el patrón ya establecido del proyecto (`QuoteFolioService`/
  `OrderFolioService`, un contador por tipo de documento) y con la aclaración literal del propio
  criterio RN2 ("no reinicia ni se repite — probalo con más de una orden seguida"), que es
  exactamente lo que se probó y confirmó. No lo reporto como hallazgo.

## Lo que sí funciona (confirmado en vivo, no solo por lectura de código)

Los 13 tickets del batch, incluyendo explícitamente los 3 caminos de ruptura más sensibles del
lote: RN3 (Bodega no puede editar/eliminar una solicitud ya enviada — ni siquiera existe la ruta,
405 real), el gate de permisos aprobar/rechazar exclusivo de Compras (403 real para Bodega, 200
real para Lidercompras), y el recordatorio resuelto dinámicamente por rol sin ningún hardcode. Folio
consecutivo sin colisiones. Sincronía disponible↔carrito bidireccional. Recalculo de totales
correcto. Modal "Ver bodegas" sin inventar stock. Chips de filtro mutuamente excluyentes
server-side. Las 3 variantes de acción por estado con el motivo real del rechazo.

## Veredicto

**Pasada limpia — listo para pasar a QA.** Un hallazgo MEDIO encontrado y corregido en la misma
sesión, checklist completo re-corrido tras el fix sin nuevos hallazgos (regla dura cumplida, loop
cerrado). Pendiente únicamente el push de `atlanticerp-frontend` (`1bf08b4` + `ec6511d`) a `dev` antes de
que QA (qa) pueda probar en `dev.atlanticerp.ai` — acción coordinada por el hilo principal, no
ejecutada acá.

**⚠️ Este archivo vive en `atlanticerp-backend/docs/`, que está completo en `.gitignore` (ver memoria
`reference_docs_gitignored_atlanticerp_backend`) — queda sin commitear, por convención ya establecida en
el proyecto para esta carpeta.**
