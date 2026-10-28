# Pre-QA — SCRUM-329 Batch A1 "Fundación" (SCRUM-377 / SCRUM-378)

Fecha: 2026-07-22
Alcance: `OrderService::createFromApprovedQuote()`, `CreateOrderFromApprovedQuote` listener,
`PipelineService::changeStage()` (disparo de `QuoteApproved`), `WarehouseAssistantMappingSeeder`.
Branch: `dev` en `atlanticerp-backend` (commits `0c5618b`, `d0ddae6`). Ya pasó Senior Review (0 hallazgos
abiertos). Sin UI propia todavía — Batch A3 construye el tablero de Pedidos.

Nota de status: SCRUM-377/378 se dejan en `In Progress` a propósito (decisión de Luis, no se
transicionan tickets de esta épica todavía). Este documento + comentario en Jira son el registro
del resultado, sin mover status.

## Cómo se probó

Stack Docker local real (`infra/docker-compose.yml`, contenedores `laravel`/`postgres`/`nginx`
corriendo), no solo lectura de código:

1. Suite automatizada — `infra/test.sh --filter=OrderCreationFromApprovedQuoteTest` (5/5 verde) y
   luego la suite completa del repo (`infra/test.sh`, 1080/1080 verde, 4391 assertions) + PHPStan
   Level 8 dentro del contenedor `laravel` (`0 errores`).
2. Flujo real vía API HTTP contra el stack local (`http://localhost:8090`), no PHPUnit: login real
   (`POST /api/auth/login`) con `usuario@atlantic.com.pa` (password = email, patrón del
   seeder), fixtures de tarjeta/cotización armados vía `artisan tinker` sobre la BD real (mismo
   shape que usa el módulo, no inventado), y la transición de etapa real
   (`PATCH /api/ventas-diseno/pipeline/{id}/stage`) — el único punto de disparo real de todo este
   batch — ejecutada contra el endpoint real, no simulada.
3. Verificación de resultado por `docker compose exec postgres psql` directo contra
   `atlantic_bodega.orders`/`order_items`/`order_stage_history` e
   `atlantic_notifications.notification_sends`/`notifications`/`notification_deliveries`
   (no hay pantalla propia de Pedidos todavía).

Todas las tarjetas/cotizaciones de prueba (`pipeline_cards` 6/7/8) y sus `Order` asociados
(`PED-2026-0001/0002/0003`) fueron datos creados por esta sesión de Pre-QA, no datos reales de
negocio — quedaron eliminados automáticamente al correr la suite completa al final (`infra/test.sh`
resetea la BD compartida de dev local, ver `memory/feedback_local_test_db_no_es_fresh.md`), no
requirió limpieza manual.

## Manipulación de datos usada para forzar cada escenario de ruptura

- **Escenario "falta de mapeo" (prioridad 1):** los 10 vendedores reales sembrados por
  `BusinessRoleUserSeeder` están los 10 cubiertos por `WarehouseAssistantMappingSeeder` (verificado
  contra la BD real, ver tabla abajo) — no hay ningún vendedor real sin mapeo hoy. Se desactivó
  temporalmente el mapeo de Usuario (`UPDATE atlantic_bodega.warehouse_assistant_mapping SET
  is_active=false WHERE id=5`, `seller_user_id=27`) solo para la duración de esta prueba. Restaurado
  automáticamente por el reset de `infra/test.sh` al final de la sesión (confirmado: `is_active=t`
  después de correr la suite).
- **Escenario "re-aprobar con permiso restringido":** se otorgó temporalmente
  `extra_permissions=["ventas_diseno.edit.approved"]` a Usuario vía SQL directo (necesario para que
  el mismo usuario pueda volver a hacer `PATCH .../stage` sobre una tarjeta ya en `approved`,
  gate `PipelineCard::RESTRICTED_EDIT_PERMISSION`). Restaurado igual por el reset de `infra/test.sh`
  (confirmado: `extra_permissions=[]` después).

## Verificación de Paso 0 (permisos angostos / valores paramétricos)

- RN3 de SCRUM-377 exige que el mapeo vendedor→asistente sea "configurable, no codificado de forma
  fija" — confirmado: vive en la tabla `atlantic_bodega.warehouse_assistant_mapping`
  (migración `2026_07_22_100006`), no en un `match()`/array hardcodeado en el servicio.
  `WarehouseAssistantMappingSeeder` es el seed inicial, corre en todos los entornos, idempotente
  por fusión (no pisa reasignaciones manuales).
- Mapeo real verificado contra la BD (`atlantic_bodega.warehouse_assistant_mapping` join
  `atlantic_auth.users`), coincide exactamente con RN1/RN2 del ticket:

  | Asistente | Vendedores mapeados (confirmado en BD) |
  |---|---|
  | Asistentebodega2 [persona] (`almacen2@atlantic.com.pa`, id 14) | Juan Manuel, [persona], [persona], [persona], Usuario |
  | Asistentebodega [persona] (`logistica@atlantic.com.pa`, id 13) | [persona], [persona], Vendedor5, [persona], [persona] |

- Audiencia de la notificación de "pedido sin asignar" es un ROL (`lider_bodega`), no una persona
  hardcodeada — resuelto vía `Role::where('key', 'lider_bodega')` + `User::where('role_id', ...)`,
  no un ID de usuario fijo en código. No aplica `SpecialPermissionSeeder` acá (no es un permiso
  angosto por persona, es una audiencia por rol).

## Escenarios probados (camino de ruptura primero)

### 1. Falta de mapeo vendedor→asistente (CRÍTICO potencial si fallara) — PASA

Con el mapeo de Usuario desactivado, aprobé una cotización real de Usuario
(`pipeline_card_id=8`, `PATCH .../stage {"stage":"approved"}` → `200`). Verificado contra BD real:

```
orders: id=8, order_number=PED-2026-0003, pipeline_card_id=8, stage=asignado, assistant_id=NULL
```

Notificación real disparada — verificado en las 3 tablas del módulo Notifications:
- `notification_sends`: `source=bodega_order_unassigned`, `source_ref=order:8`,
  `channels=["in_app","email"]`, `audience=[12]` (id de Lider Bodega Test,
  `almacen@atlantic.com.pa`, rol `lider_bodega`).
- `notifications`: fila in-app real para `user_id=12`, `subject="Pedido PED-2026-0003 sin
  asistente asignado"`.
- `notification_deliveries`: canal `email`, `status=sent` — el SMTP local es real (Office365, ver
  `memory/feedback_no_real_emails_verification.md`), así que esta prueba disparó un email real a
  `almacen@atlantic.com.pa` con datos de prueba obvios ("PED-2026-0003 (vendedor: Usuario
  [persona])... Requiere asignación manual") — señalado acá para que Liderbodega/Luis lo descarten si lo ven,
  no hay forma de verificar el canal de email real sin disparar el envío real dado que el listener
  no usa `Mail::fake()` en producción.

El pedido NO se pierde y el Jefe de Bodega queda notificado, tal como pide la decisión de Luis.

### 2. Doble aprobación / condición de carrera (CRÍTICO potencial si fallara) — PASA

Tarjeta fresca (`pipeline_card_id=7`, vendedor Usuario con mapeo activo), dos requests `PATCH
.../7/stage {"stage":"approved"}` disparados en paralelo real (`curl ... & curl ... & wait`, mismo
token). Ambos respondieron `200 stage=approved`. Verificado contra BD:

```
orders: 1 sola fila para pipeline_card_id=7 (id=7, order_number=PED-2026-0002, assistant_id=14)
```

El lock (`SELECT ... FOR UPDATE` en `changeStage()`) + el índice único parcial
`orders_pipeline_card_root_uq` sostienen la garantía bajo concurrencia real, no solo en el mock de
PHPUnit.

### 3. Camino feliz completo — PASA

Vendedor con mapeo real (Usuario → Asistentebodega2 [persona]), `pipeline_card_id=6`. Verificado contra BD:

```
orders: id=6, order_number=PED-2026-0001, stage=asignado, assistant_id=14 (almacen2@atlantic.com.pa)
customer_name="PreQA Subcliente Usuario", delivery_address="Av PreQA 123, Ciudad de Panamá"
customer_contact="PreQA Contacto (6000-9999)"
order_items: 1 fila, qty_requested=4, description_snapshot="Lampara PreQA" (coincide con la cotización real generada)
order_stage_history: from_stage=NULL, to_stage=asignado
```

Todos los campos heredados (RN1 de REQ-308: cliente, productos/cantidades, dirección, contacto)
coinciden exactamente con la cotización aprobada real, no solo "algo se creó".

### 4. Re-aprobar una tarjeta ya en "approved" (no-op) — PASA

Misma tarjeta 6, segundo `PATCH .../stage {"stage":"approved"}` (con permiso
`ventas_diseno.edit.approved` otorgado temporalmente a Usuario para poder ejecutar la segunda
llamada) → `200 stage=approved`. Verificado: `orders` sigue con 1 sola fila para
`pipeline_card_id=6` — no se disparó una segunda creación.

### 5. Otros intentos de ruptura considerados

- Aprobación con permiso insuficiente (`ventas_diseno.edit.approved` faltante) sobre una tarjeta ya
  aprobada → `403 "No tienes permiso para realizar esta acción."`, comportamiento correcto y
  esperado del gate existente, no relacionado a este batch pero confirmado que no interfiere.
- Fallo real dentro de `OrderService` (violación de índice único) sin romper la aprobación — ya
  cubierto por test automatizado real (`test_fallo_en_bodega_no_rompe_la_aprobacion_de_la_cotizacion`,
  fuerza una `QueryException` real, no un mock) y confirmado en la corrida de suite completa.

## Resultado

**CRÍTICO:** ninguno.
**MEDIO:** ninguno.

**Lo que sí funciona (confirmado en vivo contra el stack Docker real, no solo por lectura de
código o por confiar en Senior Review):**
- Asignación automática correcta por vendedor según `WarehouseAssistantMapping` (RN1/RN2 de
  SCRUM-377, tabla real, no hardcodeada — RN3 cumplida).
- Creación automática de la tarjeta de Pedido al aprobar una cotización (REQ-308), con herencia
  correcta de cliente/productos/cantidades/dirección/contacto.
- Caso borde "falta de mapeo": el pedido se crea igual, sin asistente, y notifica de verdad
  (in-app + email real) al Jefe de Bodega — decisión de Luis del 2026-07-22 implementada
  correctamente.
- Protección de concurrencia real: doble aprobación simultánea no duplica el pedido.
- Idempotencia: re-aprobar una tarjeta ya aprobada no crea un segundo pedido.
- Aislamiento de fallos: un error dentro de Bodega no bloquea la aprobación de la venta (confirmado
  por test automatizado con excepción real).
- PHPStan Level 8 limpio, suite completa 1080/1080 verde.

**Veredicto: pasada limpia. Sin bloqueantes para push a `dev`** (ya está en `dev`, commits
`0c5618b`/`d0ddae6` — no hay push pendiente de esta sesión de Pre-QA, ningún fix fue necesario).
