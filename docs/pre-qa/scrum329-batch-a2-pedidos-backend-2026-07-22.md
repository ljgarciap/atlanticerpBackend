# Pre-QA — SCRUM-329 Batch A2 "Pedidos backend"

Fecha: 2026-07-22
Alcance: motor completo del tablero Kanban de 7 etapas de Pedidos (SCRUM-383→399,
REQ-313→330), back-allocation (REQ-322) y familias de entrega (REQ-319). Sin frontend propio
(Batch A3 lo construye) — probado pegando directo a la API real vía HTTP contra
`http://localhost:8090`.
Branch: `dev` en `atlanticerp-backend`, **no pusheado** (commits locales, ver "Fix aplicado" abajo).
Ya pasó Senior Review dos veces (`docs/reviews/oleada-a-batch-a2-pedidos-backend-2026-07-22.md`,
2 blockers de concurrencia/asignación por bodega corregidos y re-verificados, 1 hallazgo 🟡 de
deadlock corregido con retry, 1 hallazgo 🟡 de permisos abierto sin cambios).

Nota de status: los 13 tickets se dejan en `In Progress` a propósito (mismo criterio que Batch
A1) — este documento + comentario en Jira son el registro del resultado, sin mover status.

## Cómo se probó

Stack Docker local real (`infra/docker-compose.yml`), no solo lectura de código:

1. Login real (`POST /api/auth/login`) con los 5 usuarios reales de Bodega
   (`almacen@`/`almacen2@`/`logistica@`/`auxiliarbodega@test.com`,
   `arrochagary@gmail.com`).
2. Fixtures de pedidos armados vía `artisan tinker` sobre la BD real (mismo shape del modelo,
   sin pipeline_card por simplicidad — no afecta la lógica de Bodega, que es agnóstica de
   cómo nació el pedido una vez creado).
3. Cada transición del Kanban (assign-picker, start-picking, picking-sheet, complete-picking,
   resolve-inventory-review, register-delivery, assign-courier, dispatch, register-signed-guide)
   ejecutada contra el endpoint HTTP real, con verificación de resultado por
   `docker compose exec postgres psql` directo contra `atlantic_bodega.orders`/
   `order_items`/`order_stage_history`/`inventory_movements`/`product_warehouse_stock`, y
   `atlantic_notifications.notification_sends` para REQ-318 RN2.
4. Suite completa (`infra/test.sh`) y PHPStan Level 8 corridos por mí, dos veces (antes y
   después del fix, ver abajo).

Todos los pedidos/productos de prueba (`orders` 79-91 de la primera pasada, 85-89 de la
segunda tras el reset de la suite) fueron creados por esta sesión — quedaron eliminados
automáticamente al correr `infra/test.sh` al final (mismo mecanismo que Batch A1, ver
`memory/feedback_local_test_db_no_es_fresh.md`). Los `extra_permissions` temporales que
otorgué a Asistentebodega2/Asistentebodega/Auxiliarbodega/[persona] (ver Paso 0) también quedaron restaurados a `[]`
por el mismo reset — confirmado con `psql` después de la corrida final.

## Paso 0 — verificación de permisos angostos (🔴 CRÍTICO, no corregido por mí — ver razón)

**El batch completo depende de que "Asistentes de Bodega" (RN3 SCRUM-383), el Picker y el
Repartidor puedan ejecutar acciones de escritura sobre Pedidos — verifiqué contra la BD real,
no contra el código, y NO es así hoy.**

Login real de los 5 usuarios + decodificación del JWT real:

| Usuario | `role_key` | `security_level` (BD) | `bodega.write` en JWT real |
|---|---|---|---|
| Lider Bodega Test (Jefe de Bodega) | `lider_bodega` | 4 (Power User) | ✅ sí |
| Asistentebodega2 [persona] (Asistente) | `asistente_bodega` | 1 (Read Only) | ❌ **no** |
| Asistentebodega [persona] (Asistente) | `asistente_bodega` | 1 (Read Only) | ❌ **no** |
| Auxiliarbodega [persona] (Picker) | `ayudante_general_bodega` | 1 (Read Only) | ❌ **no** |
| [persona] [persona] (Repartidor) | `transporte` | 1 (Read Only) | ❌ **no** |

Causa raíz: `bodega.write` se emite solo cuando `SecurityLevelModulePermission.can_edit = true`
para el módulo `bodega` en el `security_level` del usuario (`User::effectivePermissionsV2()`).
Confirmé contra `atlantic_auth.security_level_module_permissions` que **solo los niveles
4/8/9 tienen `can_edit = true` para `bodega`** — el nivel 1 ("Read Only"), que es el
`default_security_level_id` de los roles `asistente_bodega`/`ayudante_general_bodega`/
`transporte` en `atlantic_auth.roles` (confirmado con `psql`), NO lo tiene.

Confirmé el efecto real con HTTP: Asistentebodega2 (Asistente, security_level 1 sin grant) recibía
`403` en `POST .../assign-picker` — el mismo endpoint que RN3 de SCRUM-383 exige que él SÍ
pueda usar — hasta que le otorgué `extra_permissions: ["bodega.write"]` temporalmente vía SQL
directo (restaurado por `infra/test.sh` al final, no persiste).

**Por qué no lo corregí yo mismo:** esto no es un permiso angosto de una persona puntual (patrón
`SpecialPermissionSeeder`, ej. SCRUM-126/138) — es el `default_security_level_id` de 3 roles de
negocio completos (afecta a Asistentebodega2/Asistentebodega hoy y a cualquier futuro Asistente/Picker/Repartidor
que se contrate), una decisión de nivel de seguridad por defecto que corresponde al
Arquitecto/PM confirmar (¿nivel 4 igual que `lider_bodega`? ¿un nivel intermedio nuevo?), no
algo que un grant SQL ad-hoc resuelva de forma duradera.

**Impacto real si no se corrige antes de desplegar:** con la configuración actual de
seguridad, **solo Liderbodega puede operar CUALQUIER paso del flujo de Pedidos** — Asistentebodega2 y
Asistentebodega (Asistentes) no podrían asignar picker pese a que RN3 se los permite explícitamente,
y Auxiliarbodega/[persona] no podrían completar su propia Hoja de Picking ni registrar su propia entrega.
Esto bloquea el uso real del batch por el equipo completo de Bodega, no un caso límite.

**Recomiendo:** bloqueante para pasar a QA — el PM/Arquitecto debe decidir el
`security_level` correcto para estos 3 roles (vía seeder de roles, no SQL manual) antes de que
QA (qa) intente operar el flujo con una cuenta que no sea Liderbodega.

## 🔴 CRÍTICO encontrado y corregido en vivo — `registerDelivery()` permitía entregar por
encima de `qty_picked = 0`

**RN3 de REQ-323** exige que un artículo sin reservado ni disponible quede "bloqueado en 0,
faltante automático". Reproduje en vivo el caso exacto: un pedido con un ítem que resultó
**totalmente no encontrado** durante el picking (`qty_picked = 0`, ya derivado a su propia
tarjeta de Revisión de Inventario/Faltante por REQ-317) seguía permitiendo, en la tarjeta
ORIGINAL ya en Packing, un `POST .../register-delivery` con `qty_delivered` igual al
`qty_requested` completo (`200 OK`, no `422`) — una entrega fantasma de mercancía que nunca se
recogió, duplicando contra la misma mercancía física que la tarjeta separada ya gestiona.

Causa: `$cap = $item->qty_picked > 0 ? $item->qty_picked : $item->qty_requested;`
(`DeliveryService.php:98`) trataba `qty_picked = 0` como "todavía no pasó por picking" en vez
de "confirmado en cero" — pero por RN1 de `completePicking()` (todos los `order_items` deben
vener marcados), **todo pedido que llega a Packing ya tiene `qty_picked` resuelto para
absolutamente todos sus ítems**, así que la rama de "fallback a `qty_requested`" nunca debía
dispararse a esta altura del flujo.

**Corregido en el momento** (regla dura de Pre-QA): `$cap = $item->qty_picked;`, sin fallback.
Actualicé los 3 fixtures existentes de `DeliveryFlowTest` (construían pedidos directo en
Packing sin pasar por `completePicking()`, por eso nunca ejercitaron el caso real de
`qty_picked = 0` confirmado) y agregué un test de regresión explícito
(`test_registrar_entrega_de_item_con_qty_picked_cero_queda_bloqueado_en_cero`). Re-corrí el
checklist completo después del fix: HTTP real confirmado bloqueado (422), suite completa
**1137/1137 verde** (subió de 1136 con el nuevo test), PHPStan Level 8 **sin errores**.
Commit local `0ed3d89` (`dev`, no pusheado — coordinación de push pendiente con Luis junto a
los 2 commits previos del batch).

## Escenarios probados (camino de ruptura primero)

Cobertura completa de los 13 tickets, con foco en las RN de ruptura explícitas del brief:

- **SCRUM-383 (assign-picker):** RN1 (sin picker → 422), RN2 (picker visible en el pedido),
  RN3 (Asistentebodega2/Asistentebodega sí, Auxiliarbodega no — 403 correcto), picker inválido (id de repartidor) →
  422. PASA (con el permiso temporal del Paso 0).
- **SCRUM-384 (start-picking):** doble-click real (2 requests paralelos) → exactamente 1 `200`
  y 1 `422`, 1 sola fila de historial. PASA.
- **SCRUM-385/386 (Hoja de Picking):** ordenada por ubicación, "Alistada" arranca sin marcar,
  editable solo en En picking (confirmado `editable:false` en Packing), `qty_picked` fuera de
  rango → 422, `complete-picking` sin todos los ítems → 422 explícito. PASA.
- **SCRUM-387/388 (picking parcial → Revisión de Inventario):** las 3 RN (todo encontrado →
  directo a en_picking; nada encontrado → Faltante/Asignado + notificación real a Compras
  (`lider_compras`); mixto → 2 tarjetas, ambas en la misma familia) probadas con 3 pedidos
  distintos, verificado contra BD y contra `notification_sends`. Ubicación obligatoria si
  `found=true` → 422 si falta. PASA.
- **SCRUM-389 (familia):** badge confirmado (`entrega_completa` solo cuando TODOS los
  miembros llegaron a Por despachar o más allá). PASA.
- **SCRUM-392 (back-allocation):** 2 pedidos compitiendo por el mismo producto en 2 bodegas
  (10+5) — el primero en completar picking se queda con la bodega de mayor stock (Central),
  el segundo correctamente recibe la bodega restante (Zona Libre), sin stock fantasma negativo
  al despachar ambos. PASA — confirma en vivo el fix del Blocker 2 de Senior Review.
- **SCRUM-393/394/395 (registrar entrega):** ver el CRÍTICO de arriba (corregido). RN1 (tope
  no supera lo pedido/alistado), RN2 (motivo obligatorio en parcial → 422 si falta), tarjeta
  Faltante hereda datos + vinculada a familia — todo confirmado en vivo. PASA (tras el fix).
- **SCRUM-396 (assign-courier):** RN1 (Asistentebodega2/Asistente → 403, solo Liderbodega), RN2
  (propagación automática al hermano de familia ya en Por despachar sin repartidor propio) —
  confirmado con 2 pedidos reales de la misma familia. PASA.
- **SCRUM-398 (bloqueo de despacho):** sin repartidor → 422 con mensaje propio; sin factura →
  422 con mensaje propio; despacho conjunto de familia con un miembro sin factura → ese
  miembro NO se arrastra (`dispatched_siblings: []`), queda esperando. PASA.
- **SCRUM-399 (guía firmada):** sin archivo → 422; con archivo → avanza automáticamente a
  Entregado sin botón de avance aparte. PASA.
- **Doble-despacho (foco especial del brief):** 2 requests `dispatch` simultáneos sobre el
  MISMO pedido → exactamente 1 `200` + 1 `422`, exactamente 1 movimiento de Kardex, stock
  decrementado una sola vez (verificado con `psql`, no solo por la respuesta HTTP). Confirma
  en vivo, en un escenario fresco, el fix del Blocker 1 original de Senior Review.
- **Deadlock entre 2 pedidos DISTINTOS (foco especial del brief):** `completePicking` de un
  pedido y `registerDelivery` de OTRO pedido, compitiendo por el mismo producto, disparados
  casi simultáneamente — ambos respondieron `200`, sin deadlock real en esta corrida (es
  dependiente de timing, como ya señala el propio docblock de Senior Review), pero sin ningún
  indicio de corrupción: 1 sola transición de etapa por pedido, `product_warehouse_stock`
  consistente. No reproduje el deadlock en sí (esperado — no es garantizado sin control de
  timing de laboratorio), pero confirmé que el mecanismo de retry (`$attempts=3`) sigue
  presente en las 4 clases de servicio (grep) y que la suite automatizada de concurrencia real
  (procesos separados) ya lo ejercita con más control de timing del que un curl manual permite.

## Hallazgo 🟡 no bloqueante — permisos granulares (heredado de Senior Review, sin cambios)

Sigue abierto, tal como Senior Review lo dejó: 7 endpoints (picking/inventario/entrega) sin
gate de rol más allá de `bodega.write` genérico — pendiente de confirmación PM/spec, no
bloqueante para este pase.

## Resultado

**CRÍTICO:** 1 encontrado y corregido en vivo (`registerDelivery` cap de `qty_picked=0`,
commit `0ed3d89`) + 1 de Paso 0 sin corregir por mí, **bloqueante** (security_level por
defecto de Asistente/Picker/Repartidor no otorga `bodega.write` — decisión de rol, escala a
PM/Arquitecto).
**MEDIO:** ninguno nuevo (el 🟡 de permisos granulares es heredado de Senior Review, ya
documentado, no bloqueante).

**Lo que sí funciona (confirmado en vivo, no solo por lectura de código):** las 7 etapas del
Kanban, las 3 ramas de Revisión de Inventario, back-allocation por bodega, familias de
entrega, bloqueo de despacho sin repartidor/factura con despacho conjunto selectivo, guía
firmada con avance automático, y las 2 protecciones de concurrencia (mismo pedido y pedidos
distintos) — todo verificado contra HTTP real + Postgres real, con la suite completa
(1137/1137) y PHPStan Level 8 (`No errors`) corridos por mí.

**Veredicto: rebota por el hallazgo de Paso 0.** El motor de Pedidos en sí (los 13 tickets)
funciona correctamente end-to-end — el único bloqueante real no es un bug de este batch sino
un gap de configuración de seguridad (roles de Bodega sin el `security_level` que su propio
diseño de permisos requiere) que debe resolverse antes de que QA (qa) pueda operar el
flujo con cualquier cuenta que no sea la de Liderbodega. Recomiendo al PM: (1) escalar el
`security_level` de Asistente/Picker/Repartidor al Arquitecto para decisión y seeder, (2) una
vez resuelto, este batch no necesita una nueva pasada completa de Pre-QA — solo re-confirmar
Paso 0 con la cuenta de un Asistente real.

## Re-check 2026-07-22 (noche) — checklist COMPLETO repetido tras los 2 fixes

Commits acumulados desde la pasada anterior: `e457fc0` (Paso 0 — Nivel 3 `bodega`-only para
`asistente_bodega`/`ayudante_general_bodega`/`transporte`) y `0ed3d89` (cap de `registerDelivery`
= `qty_picked` siempre) ya estaban aplicados; sin commits nuevos entre medio (`e82cd9b`/`cab5a27`/
`7bdf7c8` son de antes de la primera pasada). Regla dura respetada: no me limité a re-verificar
los 2 puntos que rebotaron — corrí el checklist completo de los 13 tickets de nuevo contra Docker
local real + HTTP real, con foco extra en posibles regresiones introducidas por ambos fixes.

**Automatizado, corrido por mí antes de tocar nada a mano:** `infra/test.sh` → **1139/1139
verde**; `phpstan analyse --memory-limit=2G` → **sin errores** (el runner paralelo por default
falló localmente por memoria del host, no por código — con memory-limit explícito corre limpio).

**Paso 0 — permisos, vía login real + JWT decodificado (no solo lectura de código ni de BD):**

| Usuario | `role_key` | `security_level` (JWT) | `bodega.write` | Resto de 6 módulos (`view`/`edit`) |
|---|---|---|---|---|
| Liderbodega (Jefe de Bodega) | `lider_bodega` | 4 | ✅ | todos `false` (sin cambios) |
| Asistentebodega2 (Asistente) | `asistente_bodega` | 3 | ✅ (antes ❌) | todos `false` |
| Asistentebodega (Asistente) | `asistente_bodega` | 3 | ✅ (antes ❌) | todos `false` |
| Auxiliarbodega (Picker) | `ayudante_general_bodega` | 3 | ✅ (antes ❌) | todos `false` |
| [persona] (Repartidor) | `transporte` | 3 | ✅ (antes ❌) | todos `false` |

Confirmado con `psql` directo que la matriz del Nivel 3 tiene `can_edit=true` únicamente en
`bodega` (los otros 6 módulos en `false`) — **el fix no sobre-otorgó nada fuera de Bodega**, tal
como lo diseñó la migración. Regresión probada en vivo: Asistentebodega2 (Level 3) contra
`POST /api/ventas-diseno/pipeline` → `403` (sigue sin poder escribir fuera de Bodega).

**RN3 de SCRUM-383 (el criterio que motivó el fix), probado de verdad:** Asistentebodega2 ejecutando
`POST /api/bodega/orders/{id}/assign-picker` con un pedido real en `asignado` → `200`, pedido
pasa a `picking_pendiente` con `picker_id` seteado. Antes del fix esto devolvía `403`. Confirmado
también que `assignCourier` sigue exclusivo de `lider_bodega`: [persona] (Transporte, ahora con
`bodega.write`) contra ese mismo endpoint → `403` explícito ("Solo el Jefe de Bodega puede asignar
el repartidor") — el Nivel 3 no rompió el gate de rol específico dentro del propio módulo.

**Bug de `registerDelivery`, probado de verdad contra un pedido fresco con `qty_picked=0`:**
`POST .../register-delivery` con `qty_delivered=5` (=`qty_requested`) → `422` (sigue bloqueado,
el fix se sostiene). Mismo pedido con `qty_delivered=0` + motivo → `200`, avanza a
`por_despachar` (el caso legítimo de "todo faltante" no se rompió). Camino feliz sin ningún
faltante (pedido con `qty_picked=qty_requested=5`, entrega completa) → `200` — **el fix no rompió
el camino feliz normal**, como pedía el brief explícitamente verificar.

**Familia (REQ-328 RN3), rehecho con 3 pedidos reales de la misma familia:** `assignCourier`
sobre el padre propaga el `courier_id` a AMBOS hermanos en `por_despachar` (incluido el que le
falta factura — la propagación de repartidor no está gateada por factura, correcto por RN2 de
REQ-326, que es un concern distinto). Al `dispatch()` del padre: el hermano con factura lista se
arrastra (`dispatched_siblings: [...]`), el hermano SIN factura queda esperando en
`por_despachar` pese a ya tener repartidor — RN3 de REQ-328 se sostiene sin regresión.

**Concurrencia — doble-dispatch mismo pedido, repetido con un pedido fresco:** 2 requests
`dispatch` simultáneos sobre el mismo pedido → exactamente 1 `200` + 1 `422` ("El pedido no está
en la etapa Por despachar"), exactamente 1 fila en `order_stage_history`. Protección de Blocker 1
sigue intacta tras ambos fixes. Deadlock entre pedidos distintos: no se repitió el intento de
reproducción de laboratorio (no aporta información nueva sobre timing no determinístico ya
documentado en la pasada anterior); confirmado por grep que el retry `$attempts=3` sigue presente
en las 9 transacciones de las 3 clases de servicio (`DeliveryService`, `PickingService`,
`InventoryReviewService`).

**Resultado del re-check:** ningún hallazgo nuevo, CRÍTICO ni MEDIO. Ninguno de los 2 fixes
introdujo una regresión — ni en el alcance de permisos (Nivel 3 sigue estrictamente
`bodega`-only), ni en el camino feliz de entrega, ni en concurrencia/familia. Fixtures de esta
sesión (`PED-PREQA-*`, `orders` 80-86) creados vía `tinker` y limpiados automáticamente al correr
`infra/test.sh` al final (mismo mecanismo que la pasada anterior).

**Veredicto final: PASADA LIMPIA.** El batch queda listo para pasar a QA (qa.qa) — no
quedan hallazgos bloqueantes ni heredados sin resolver más allá del 🟡 no bloqueante de permisos
granulares (heredado de Senior Review, sin cambios, ya documentado arriba).
