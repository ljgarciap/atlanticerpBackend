# Senior Review — Batch B1, "Home de Bodega" (SCRUM-363→374), backend

**Fecha:** 2026-07-23
**Alcance:** `git diff 3f63e77..ab3b249` + `25df57f` en `atlanticerp-backend`, branch `dev` (no
pusheado) — 6 commits: `a8d4031` (umbrales configurables en `BodegaSettingsService`), `5d1a6e9`
(extracción de `applyUrgentScope()`), `fcd5633` (`BodegaRoles::ALL`), `cde93b3` (endpoint Home),
`ab3b249` (tests), `25df57f` (fix de permiso `bodega.read` en `GET /api/compras/orders/{id}`).
Precedente de tono/rigor: `docs/reviews/oleada-a-batch-a3-pedidos-backend-2026-07-23.md` (leído
antes de este review). Contra-revisado el review paralelo de frontend ya publicado
(`docs/reviews/batch-b1-home-bodega-frontend-2026-07-23.md`) para no duplicar hallazgos ya
cerrados del lado de contrato de API.

## Veredicto: 🟢 Aprobado

Sin blockers. Código, tests y verificación en vivo (query log real, no solo lectura de código)
sostienen las 4 interpretaciones que el propio Backend Dev señaló como asunciones del batch. 1
hallazgo no bloqueante (deuda pre-existente que este batch compunda, no que introduce) anotado en
Suggestions.

---

## Resultado real de verificación (corrido yo mismo)

**`infra/test.sh` (suite completa):**
```
PHPUnit 11.5.55 — 1195 tests, 4891 assertions
OK
```
Incluye `infra/test.sh --filter=BodegaHomeControllerTest` (22 tests, 80 assertions) y
`infra/test.sh --filter=PurchaseOrderLogisticsTest` (20 tests, 65 assertions, incluye los 2 tests
nuevos del fix de permiso) corridos también de forma aislada.

**`vendor/bin/phpstan analyse` (Level 8):** `[OK] No errors` — sin baseline que esconda deuda (no
existe `phpstan-baseline.neon` en el repo).

**N+1 — confirmado con `DB::enableQueryLog()` en vivo (`tinker`, tenant `illuminations`), no solo
leído:**
- `summary(scope=team)` contra datos ya sembrados en `dev`: **33 queries**.
- Mismo endpoint tras sembrar +15 órdenes en `ASIGNADO` (con su `order_stage_history`) y +15
  órdenes de compra en tránsito (cada una con su producto y línea, para ejercitar `porRecibir()`):
  **37 queries** — un delta de solo 4 sobre 30 filas nuevas, no proporcional al tamaño del dataset.
  Confirma que no hay ningún loop con query por fila (`porRecibir()`, `mayorRotacion()`,
  `articulosCriticos()`, `pendientesPickingNoIniciado()`, `pendientesGuiaSinSubir()` usan todos
  batching real vía `with()`/`whereIn()`/queries agregadas — el mismo tipo de bug que rompió el
  batch anterior de este épico, ver referencia de `eta_proveedor`, **no se repitió acá**).
- El costo fijo de 33-37 queries por sí es alto para un endpoint de "Home" (7 paneles + 2 llamadas
  a `applyUrgentScope()` independientes — `despachosUrgentes()` y `estadoPedidos()` corren cada
  una su propia query en vez de reusar el mismo resultado — y `estadoPedidos()` hace 7 queries
  bounded, una por etapa de `Order::STAGE_ORDER`), pero es **acotado** (no escala con volumen de
  datos) — no lo considero blocker, ver Suggestion S1.

---

## Foco de revisión — resultado punto por punto

### 1. Fix de `CheckPermission` — retrocompatibilidad confirmada, no solo leída

`string $required` → `string ...$required` (variádico). Confirmé con
`grep -rn "middleware('permission:" routes/` (191 ocurrencias) que **la única ruta** en todo el
repo que pasa más de un permiso separado por coma es la nueva
(`routes/compras.php:50` — `permission:compras.read,bodega.read`); las 190 restantes siguen
pasando un solo string. Con un solo argumento, `string ...$required` puebla `$required` como
`['permiso']` — mismo comportamiento exacto que antes (`in_array($required, $permissions, true)`
vs. el nuevo `foreach` que corta en el primer match) para el caso de 1 elemento. No hay forma de
que Laravel infle o recorte permisos de una ruta existente por este cambio: el parseo de
middleware (`name:param1,param2`) es mecánica estándar de Laravel, no algo que este diff toque.

Tests que ejercitan el camino OR explícitamente:
`tests/Feature/Compras/PurchaseOrderLogisticsTest.php` — `test_bodega_read_puede_ver_detalle_de_orden_sin_compras_read`
(200 con solo `bodega.read`) y `test_sin_compras_read_ni_bodega_read_detalle_de_orden_retorna_403`
(403 sin ninguno de los dos) — ambos verdes. La suite completa (1195 tests) ejercita las 190 rutas
de un solo permiso de forma indirecta y no mostró ninguna regresión.

### 2. Las 4 asunciones documentadas del batch — verificadas contra el código, no dadas por buenas

- **"Descuadre de inventario" = `AdjustmentRequestLine::ESTADO_PENDIENTE`** — razonable: es el
  único concepto real en el modelo de datos que corresponde a "Bodega reportó una discrepancia y
  espera resolución". No hay un estado "rechazado" intermedio que debiera sumarse — una línea
  rechazada ya está resuelta (no es "pendiente" de nada), correctamente excluida.
- **"Mío" = `assistant_id`/`picker_id`/`courier_id`** — cubre los 3 roles operativos reales
  (Asistente, Picker, Repartidor). Para Líder de Bodega: confirmé que `assistant_id` se puebla
  desde `WarehouseAssistantMappingController`/`OrderService` (mapeo vendedor→asistente), un
  mecanismo que no restringe por rol a nivel de código — en teoría nada impide que un `Lider` sea
  configurado como el "asistente" de un mapeo. Pero esto es el mismo mapeo pre-existente que ya usa
  todo `OrderBoardService`, no algo que este batch cambie — la premisa "el Jefe legítimamente ve
  ceros en Mío" es consistente con el resto del sistema, no una interpretación aislada de Home. No
  lo marco como hallazgo de este batch.
- **`BodegaRoles::ALL` separado de `RoleModuleVisibility`** — el razonamiento (Gerencia tiene
  visibilidad transversal del módulo pero no es "del equipo operativo") es sólido y ya está
  validado por un test real (`test_team_retorna_los_5_perfiles_reales_de_bodega_para_el_jefe`
  cuenta exactamente 5 personas de los 4 roles de negocio, sin incluir cuentas de Gerencia
  sembradas en el mismo tenant). No encontré ningún lugar donde debiera haberse usado
  `RoleModuleVisibility` en su lugar: el propio `resolveTargetIds()`/`isBodegaTeamMember()` usan
  `BodegaRoles::ALL` consistentemente para el guard de `owner_id`, y `canViewTeamV2()` (que sí usa
  `RoleModuleVisibility` bajo el capó) se reserva correctamente para la pregunta distinta de "¿el
  actor puede ver Equipo?".
- **"Estado de pedidos" — 6 etapas "hoy", Entregado "7 días"** — confirmé en el código
  (`estadoPedidos()`, `BodegaHomeService.php:412-427`) que el `$since` sí cambia por etapa
  (`subDays(7)->startOfDay()` solo para `STAGE_ENTREGADO`, `startOfDay()` de hoy para las otras 6),
  no es un docblock desalineado del comportamiento real. Dos tests dedicados lo confirman en
  runtime: `test_estado_de_pedidos_cuenta_asignados_que_entraron_hoy` (excluye una entrada de hace
  2 días) y `test_estado_de_pedidos_entregado_usa_ventana_de_una_semana` (incluye día 3, excluye
  día 10) — ambos verdes.

### 3. Scope own/team/owner_id — guard de membresía real, implementado en 2 lugares consistentes

`resolveTargetIds()` (paneles con scope) y `eventsTodayCount()` (calendario) implementan el mismo
guard: `owner_id` fuera de `isBodegaTeamMember()` cae al alcance propio del actor, nunca al del
objetivo pedido — exactamente el patrón que costó un hallazgo CRÍTICO de Pre-QA en
`OutlookCalendarController` (2026-07-21, ver memoria del proyecto). Confirmado con test real
(`test_summary_owner_id_fuera_del_equipo_cae_al_alcance_propio`, usa el usuario
`management@illuminations.test` como `owner_id` inválido y verifica que el Jefe ve su propio
conteo, no el de Gerencia) — no es solo el patrón repetido en un comentario, está verificado en
runtime. La ruta nueva de calendario (`routes/bodega.php`, `GET /bodega/calendar`) reusa
`OutlookCalendarController::teamEmails()` genérico por `RoleModuleVisibility(module=bodega)` —
distinto criterio de "equipo" (incluye Gerencia si tiene visibilidad del módulo) que el usado en
`BodegaHomeService` (`BodegaRoles::ALL`), pero es el mismo controller/criterio que ya se usa para
Ventas & Diseño y Compras — no es una inconsistencia nueva de este batch, es el contrato ya
aprobado de ese endpoint compartido.

### 4. Umbrales configurables — sin hardcode, confirmado en el patrón y en la tabla real

Los 4 valores nuevos (`home_pendientes_picking_horas`, `home_pendientes_guia_dias`,
`home_rotacion_ventana_dias`, `home_rotacion_top_n`) siguen el mismo patrón ya aprobado de
`urgenteWindowDays()`: constante solo para el **nombre de la key** y el **default de bootstrap**,
valor real leído de `bodega_settings` (tabla genérica clave/valor, sin migración nueva necesaria —
`intSetting()` crea la fila con el default en el primer acceso). Ningún `const`/número hardcodeado
en el flujo de negocio (`pendientesPickingNoIniciado()`, `pendientesGuiaSinSubir()`,
`mayorRotacion()` leen los 4 valores vía `BodegaSettingsService`, nunca inline). Confirmado en
runtime: `test_pendientes_umbral_de_picking_es_configurable_via_bodega_settings` cambia la fila a
mano en la tabla y verifica que el umbral efectivo cambia.

**Gap no introducido por este batch, pero compuesto por él (ver Suggestion S2):** no existe ningún
`BodegaSettingsController`/vista de administración — ni para los 4 valores nuevos ni para
`urgenteWindowDays` (agregado en un batch anterior, REQ-310). La tabla es paramétrica y editable
por SQL/tinker, pero no hay CRUD de superadmin todavía, que es lo que exige la regla dura del
proyecto ("con CRUD y vista de administración para que superadmin lo ajuste sin deploy"). No lo
trato como blocker de este batch específico porque el gap ya existía antes de estas 6 commits y el
batch no lo empeora estructuralmente (mismo mecanismo, mismo hueco) — pero con 5 valores ya en la
tabla y cero forma de editarlos sin SQL directo, el CRUD ya no es opcional.

### 5. N+1 / performance — ver sección de verificación en vivo arriba

Sin N+1 real. El único patrón de "muchas queries fijas" es `estadoPedidos()` (7 queries, una por
etapa de `Order::STAGE_ORDER`) más una query duplicada de `applyUrgentScope()` (se llama una vez
en `despachosUrgentes()` y otra vez, independiente, en `estadoPedidos()` para `urgentes_count`) —
ambos acotados (no escalan con el volumen de pedidos), no bloqueante, ver Suggestion S1.

---

## 🟡 Suggestions (no bloqueantes)

**S1 — `estadoPedidos()` podría colapsar 7 queries (una por etapa) en una sola agregada
(`GROUP BY to_stage`), y reusar el resultado de `despachosUrgentes()` en vez de que
`urgentes_count` dispare su propia query de `applyUrgentScope()`.** No es N+1 (no escala con datos)
pero es el costo fijo más alto del endpoint — si "Home" se vuelve una pantalla de refresco
frecuente (polling), vale la pena revisitar antes de que se note en producción.

**S2 — Abrir un ticket para `BodegaSettingsController` (CRUD + vista de superadmin) que cubra los
5 valores ya en `bodega_settings`** (`home_urgente_window_days` más los 4 nuevos de este batch).
El mecanismo de almacenamiento ya es correcto (paramétrico, no hardcode) — falta solo la interfaz
de edición sin deploy que la regla dura del proyecto exige. Recomiendo escalarlo a PM como ticket
de deuda técnica explícito, no dejarlo flotando sin dueño.

---

## Siguiente paso

Sin blockers — puede pasar a Pre-QA. Recomiendo que Pre-QA registre S2 (CRUD de
`bodega_settings`) como hallazgo de seguimiento explícito con Luis (no bloqueante para este batch,
pero ya son 5 valores sin interfaz de administración) y que confirme, del lado de negocio, si el
Jefe de Bodega alguna vez debe aparecer con datos propios en "Mío" (punto 2) — hoy es
estructuralmente imposible salvo que se lo configure como "asistente" de un mapeo, caso no
cubierto por ningún test.
