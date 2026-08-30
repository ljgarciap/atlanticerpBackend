# Pre-QA — Bloque B4 "Reubicación entre bodegas" (SCRUM-454/457/458/459)

**Fecha:** 2026-07-24/25
**Reviewer:** Pre-QA (subagente adversarial)
**Alcance:** SCRUM-454 (REQ-384, chip Espacio libre), SCRUM-457 (REQ-387, botón/modal Reubicar),
SCRUM-458 (REQ-388, aprobación de Mark), SCRUM-459 (REQ-389, bandeja de solicitudes).
**Precedido por:** Senior Reviewer (aprobado sin blockers) y Visual Reviewer (pasada limpia,
docs/visual-review/bloque-b4-reubicacion-2026-07-24.md).
**Entorno:** local, Docker Compose (`infra-nginx-1`, puerto 8090) + frontend build estático
(`npm run build` confirmado más nuevo que el código fuente antes de arrancar).
**Herramientas:** `curl` directo contra el backend (bypaseando la UI, para las validaciones
server-side y el gate de Mark) + Playwright CLI contra `http://localhost:5173` (dev server con
proxy `/api` → `:8090`, mismo patrón que el resto de specs en `e2e/`).
**Commits en alcance:** backend `b07b9a9`/`c7b6795`/`29c03a2`, frontend `7b02819`/`f61f5d0`/
`c850007`/`26f6049` — más el fix de esta sesión, frontend `b60e8fb`.

---

## Paso 0 — hallazgo antes de empezar

`ComprasSettings::mark_approver_user_id` estaba **NULL** en este entorno pese a que la migración
`2026_07_20_100009_add_mark_approver_to_compras_settings_table.php` intenta backfillearlo
buscando a `mbekhar@atlantic.com.pa`. Causa raíz: el orden real de deploy es
`migrate` → `tenants:artisan migrate` → `db:seed --force` (ver CLAUDE.md, sección
"Infraestructura AWS" / CI-CD) — en cualquier entorno reprovisionado desde cero, la migración
corre **antes** de que exista el usuario de Mark, así que el `DB::table(...)->where('email', ...)
->first()` de la migración siempre devuelve `null` y el backfill es un no-op silencioso. Esto no
es un bug introducido por Bloque B4 (el mismo mecanismo lo usa `AdjustmentRequestController` desde
SCRUM-206), pero es la primera vez que se confirma en vivo que efectivamente queda en `NULL` tras
un reseed — con el valor en `NULL`, el gate del código (`if ($markApproverId !== null && ...)`) se
salta por completo y **cualquier usuario con permiso `bodega.approve`** (todo el rol `management`:
Daniela, David, Whil, Mark — no solo Mark) puede aprobar/rechazar.

**No lo traté como bloqueante de estos 4 tickets** porque el código del gate en sí es correcto (lo
verifiqué explícitamente más abajo, sección 3) y el problema es de aprovisionamiento, no de este
batch. Para poder probar el resto del checklist seteé el valor a mano en mi entorno local
(`ComprasSettings::current()->update(['mark_approver_user_id' => 6])`), igual que ya hice para
levantar el entorno (esto es dato, no código).

**Escalo al Arquitecto como hallazgo separado, no bloqueante de SCRUM-454/457/458/459:**
recomiendo mover el backfill de la migración a un seeder que corra DESPUÉS de `db:seed` (mismo
patrón que `SpecialPermissionSeeder`), porque tal como está hoy, cualquier reprovisión de
dev.atlanticerp.ai/test.atlanticerp.ai (o un `docker compose down -v` + `up` local) puede dejar el gate de
Mark abierto a todo el rol Gerencia sin que nadie lo note — ni `AdjustmentRequestController` ni
`RelocationRequestController` fallarían de forma visible, simplemente dejarían de exigir que sea
Mark específicamente. **Recomendación operativa inmediata: verificar el valor real de
`mark_approver_user_id` en dev.atlanticerp.ai y test.atlanticerp.ai antes de que QA (marly) pruebe SCRUM-458**
— si está NULL ahí también, su prueba de "solo Mark aprueba" puede dar falso positivo (cualquier
cuenta de Gerencia que use aprobaría igual, sin que el 403 se dispare).

---

## Resultado general

**PASADA LIMPIA — los 4 tickets pasan a QA.** Se encontró 1 hallazgo real (MEDIO, doble-submit),
se corrigió, se re-corrió el checklist COMPLETO (no solo el punto que había fallado), se pusheó a
`dev` (`b60e8fb`). El hallazgo de Paso 0 (mark_approver_user_id) se documenta y escala aparte, sin
bloquear esta transición, porque no es un defecto del código de este batch.

---

## MEDIO — doble clic en "Solicitar reubicación" creaba 2-3 solicitudes duplicadas (CORREGIDO)

**Cómo se rompió:** dos clics disparados en el mismo tick de evento (`dispatchEvent` sincrónico,
más fiel a un doble-clic real que dos `.click()` de Playwright, que esperan a que el botón vuelva
a estar "stable" entre uno y otro) — el botón usa `disabled={loading}`, pero `loading` viene de
`create.isPending` de React Query, que solo se refleja en el **siguiente render**. Ambos clics
sincrónicos ven `isPending === false` y ambos llaman `create.mutate(...)`. Confirmado contra el
backend real: 3 solicitudes con el mismo `motivo` tras una corrida, 3 más en una repetición — no
era flaky, era reproducible al 100%.

**Fix aplicado** (`atlanticerp-frontend/src/pages/BodegasPage.tsx`, `RelocateModal`): guardia con
`useRef` (`submittingRef`) en vez de depender de `create.isPending` dentro del closure de
`submit()` — un ref no depende del ciclo de render, así que bloquea el segundo clic de inmediato,
sin importar cuántos lleguen en el mismo tick. Se resetea en `onError` para permitir reintentar
tras un fallo real.

**Verificación:** re-corrido 2 veces tras el fix, cada corrida generó exactamente 1 solicitud (no
2 ni 3). El test quedó como assertion automática contra el backend real (no solo "la UI no rompió
visualmente"), ver `e2e/preqa-bloque-b4-reubicacion.spec.ts` — promovido a permanente (no es un
smoke test descartable, es la regresión de un bug real encontrado en esta sesión).

**PHPStan:** no aplica (fix 100% frontend). **TypeScript:** `npx tsc --noEmit` limpio. **Unit
tests:** `npx vitest run` — 546/546 pasan (el único "fallo" fue Vitest recogiendo mi carpeta
temporal `e2e-tmp/`, no relacionado, ya eliminada). **Build:** `npm run build` limpio, servido por
nginx antes de la re-corrida completa del checklist.

---

## Checklist completo — qué se intentó romper y qué pasó

### SCRUM-457 (REQ-387) — Botón Reubicar

- **RN2, cantidad 0/negativa/texto/decimal** → 422 con mensaje claro en los 4 casos (`curl`
  directo, bypaseando el modal). El validador Laravel (`integer`, `min:1`) cubre los 4.
- **RN2, motivo vacío / solo espacios** → 422 en ambos — Laravel `TrimStrings` normaliza `"   "`
  a `""` antes de `required`, así que el server-side realmente exige contenido, no solo
  "no-vacío-literal".
- **RN2, sin bodega destino** → 422.
- **RN1, destino == origen vía API directa** (bypaseando el filtro del selector) → 422,
  `different:origin_warehouse_id` de Laravel lo bloquea aunque alguien manipule el request a mano.
- **RN1, UI** — selector de destino en Bodega Central confirmado SIN "Bodega Central" entre las
  `<option>` (Playwright, lectura de DOM real, no screenshot).
- **Reubicar HACIA una bodega `modo_detalle=pendiente`** (Bodega Zona Libre como destino, no
  origen) — el ADR y el código NO restringen esto, y de hecho el Visual Reviewer ya lo usó como
  caso feliz de regresión. Confirmé por `curl` que crea la solicitud (201) y que aprobarla no
  rompe nada (ver más abajo) — **es comportamiento intencional, no un gap**: el mockup nunca
  planteó Zona Libre como problema de destino, solo de origen (no hay ubicación física que
  "liberar" al recibir ahí).
- **Reubicar DESDE una bodega `modo_detalle=pendiente`** (guard ya agregado por el fix visual de
  la corrida anterior, commit `29c03a2`) — reconfirmado con `curl`: 422,
  "No se puede reubicar producto desde una bodega sin ubicación física confirmada."

### SCRUM-458 (REQ-388) — Aprobación de Mark

- **Usuario de Bodega sin `bodega.approve`** (`almacen@atlantic.com.pa`, lider_bodega)
  intenta aprobar/rechazar por API directa → 403 del middleware `permission:bodega.approve`,
  nunca llega al controller.
- **Usuario de Gerencia CON `bodega.approve` pero que NO es Mark** (`daniela@atlantic.com.pa`,
  mismo rol `management` que Mark) intenta aprobar/rechazar por API directa → 403,
  "Solo Mark puede aprobar o rechazar esta solicitud." — el gate específico de persona (no de rol)
  funciona correctamente **una vez configurado** (ver Paso 0 arriba: en este entorno tuve que
  setearlo yo mismo, estaba en NULL).
- **Escenario 1 (Central→Obarrio, 2u)** — Mark aprueba, verificado directo en base de datos:
  Central pasó de 50→48 (-2), Obarrio de 5→7 (+2). Exacto, sin efectos secundarios.
- **Escenario 2 (rechazo)** — Mark rechaza, motivo persistido, inventario sin cambios en ninguna
  bodega (no se llamó Kardex).
- **Concurrencia — 2 approves simultáneos sobre la misma solicitud** (2 `curl` en paralelo,
  `wait`): una devolvió 200, la otra 422 "Esta solicitud ya fue resuelta." Verificado en BD que el
  ajuste se aplicó **una sola vez** (no duplicado) — el lock (`lockForUpdate()` + chequeo de
  `isPending()` dentro de la transacción) funciona igual que el fix ya validado en
  `AdjustmentRequestController`.
- **Approve/reject sobre solicitud ya resuelta** (aprobada→approve de nuevo, aprobada→reject,
  rechazada→reject de nuevo, rechazada→approve) → 422 en los 4 casos.
- **Approve/reject sobre id inexistente** → 404.

### SCRUM-459 (REQ-389) — Bandeja de solicitudes

- **RN1, rechazar sin motivo** → 422 vía API directa (`{}` y `motivo_rechazo` ausente); en UI, el
  modal de rechazo bloquea con el mensaje de validación y no llama al backend.
- **RN2, fila ya resuelta sin acciones** — confirmado en Playwright: chips "Aprobadas" y
  "Rechazadas" no muestran ningún botón "Aprobar"/"Rechazar" (`toHaveCount(0)`), solo texto ("Ver
  motivo" o "—").
- **Escenario 1, reset de filtro** — cambiar a "Rechazadas", cerrar el modal (botón X), reabrir:
  vuelve a "Todas" (confirmado por clase CSS real `bg-primary`, no solo visual).

### SCRUM-454 (REQ-384) — Chip Espacio libre

- **Exclusividad con "Todos"** — confirmado, un solo chip activo a la vez.
- **Bodega con ubicaciones ocupadas + libres mezcladas** (Bodega Central) — el chip muestra
  únicamente la libre.
- **Bodega con TODAS las ubicaciones ocupadas** (Showroom Obarrio, fixture sembrada: 1 sola
  location, ocupada) — estado vacío claro ("No hay espacio libre disponible en esta bodega."), no
  rompe ni queda en blanco.
- **Bodega SIN ninguna `WarehouseLocation` registrada** (Merma, id 11 — 0 locations reales, sin
  necesidad de fixture) — mismo estado vacío claro, no crashea.

### Otros casos de ruptura

- **Recarga de página a mitad de flujo** (modal Reubicar parcialmente lleno, F5) — el modal se
  desmonta sin dejar estado huérfano, la página vuelve a cargar normal.
- **Doble clic** — ver sección MEDIO arriba (encontrado y corregido).

---

## Lo que sí funciona

- Las 3 capas de validación de RN2 (cliente, servidor, tipos) sin huecos.
- RN1 (destino ≠ origen) blindado en las 2 capas (selector + validador server-side).
- Gate "solo Mark" funciona correctamente a nivel de código — el hallazgo de Paso 0 es de dato de
  entorno, no de lógica.
- Fix de concurrencia (mismo patrón que `AdjustmentRequestController`) replicado correctamente,
  probado con carga real en paralelo, sin duplicar el ajuste de inventario.
- Los 4 estados de "ya resuelta" (approve→approve, approve→reject, reject→reject, reject→approve)
  devuelven 422 consistentemente.
- Reset de filtro de la bandeja al reabrir, funciona de verdad (no solo por diseño de React, sino
  verificado en vivo).
- Chip Espacio libre maneja los 3 casos de datos (mixto, todo ocupado, sin ubicaciones) sin
  romperse.
- Guard de origen `modo_detalle=pendiente` (fix de la corrida de Visual Review anterior)
  reconfirmado.
- Destino `modo_detalle=pendiente` permitido intencionalmente, sin efectos adversos al aprobar.

## Pendiente para el Arquitecto (no bloqueante de estos 4 tickets)

- **`mark_approver_user_id` puede quedar NULL tras cualquier reprovisión desde cero** (migración
  corre antes que el seeder de usuarios) — recomendado mover el backfill a un seeder post-`db:seed`
  (mismo patrón que `SpecialPermissionSeeder`). Afecta también a `AdjustmentRequestController` y
  los approvals de `PurchaseOrderController`/`PurchaseOrderPaymentController` (mismo mecanismo),
  no es exclusivo de Bloque B4. Verificar el valor real en dev.atlanticerp.ai/test.atlanticerp.ai antes de que
  QA pruebe SCRUM-458.

---

## Fixtures de esta sesión

Producto `PREQA-B4-001` (id 346) + stock en Bodega Central/Showroom Obarrio + `WarehouseLocation`
en Central/Obarrio + ~9 `RelocationRequest` de prueba — **todo limpiado al cierre** (incl.
`InventoryMovement` generados por los approves de prueba), verificado con
`CatalogProduct::where('reference','PREQA-B4-001')->exists()` devolviendo `false` y
`RelocationRequest::where('catalog_product_id',346)->count()` devolviendo `0`.
`ComprasSettings::mark_approver_user_id` quedó **seteado a 6 (Mark)** intencionalmente — no se
revirtió a NULL, porque revertirlo dejaría el entorno local en el mismo estado roto que causó el
hallazgo de Paso 0.
