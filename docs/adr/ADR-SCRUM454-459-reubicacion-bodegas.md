# ADR — Bloque B4: Reubicación entre bodegas (SCRUM-454, 457, 458, 459)

**Status: Approved** (Luis, 2026-07-24)

## Contexto

Pantalla "Bodegas" (`3F__Bodega_Bodegas.html`, REQ-381→389). REQ-381→386 ya está en producción
(selector de 7 bodegas, KPIs, tabla, chips Todos/Espacio libre en frontend). Quedan 4 tickets:

- SCRUM-454 (REQ-384) — chip "Espacio libre": backend responde `not_implemented: true` desde
  Senior Review 2026-07-21 — el modelo de datos solo registra ubicaciones OCUPADAS
  (`ProductWarehouseStock.ubicacion`), no existe catálogo de qué ubicaciones existen físicamente.
- SCRUM-457 (REQ-387) — botón "Reubicar" (hoy `disabled` en `BodegasPage.tsx`, ver comentario
  stale en el JSX).
- SCRUM-458 (REQ-388) — Aprobador aprueba/rechaza; al aprobar, ajusta inventario automático
  (-cantidad en origen, +cantidad en destino).
- SCRUM-459 (REQ-389) — bandeja "Solicitudes de reubicación" (historial + filtros + acciones).

## Decisión

### Reubicación (457/458/459)
Mismo patrón ya usado en `AdjustmentRequest`/`AdjustmentRequestLine` (Bodega solicita → Aprobador
aprueba/rechaza → automático). Simplificado a una sola tabla (no hace falta el patrón
contenedora+líneas de Adjustment, acá siempre es 1 producto/1 origen/1 destino):

```
relocation_requests(
  id, catalog_product_id, origin_warehouse_id, destination_warehouse_id,
  cantidad, motivo, solicitado_por,
  estado,            -- pendiente | aprobada | rechazada
  motivo_rechazo,    -- NULL hasta rechazo
  resuelto_por, resuelto_at,
  created_at, updated_at
)
```

- `store()`: valida `destination_warehouse_id != origin_warehouse_id` (RN1), cantidad/motivo
  obligatorios (RN2) — validación server-side, no solo el filtro del selector en el frontend.
- `approve()`: dentro de transacción con `lockForUpdate()` (mismo fix de concurrencia que
  `AdjustmentRequestController::approve()`), llama `KardexService::registrar()` DOS veces con
  `InventoryMovement::TIPO_REUBICACION` (constante ya reservada en el código para esto) —
  negativo en origen, positivo en destino, misma `referencia_entidad_id` = id de la solicitud.
- `reject()`: mismo lock, exige `motivo_rechazo`, no llama Kardex.
- Gate "exclusivo de Aprobador": reutiliza `ComprasSettings::current()->mark_approver_user_id` (mismo
  mecanismo que `AdjustmentRequestController::resolveApproverAccess()` — no un permiso de rol nuevo).
  Backend Dev puede extraer esto a un trait compartido si lo prefiere, no es bloqueante.
- Frontend: activa el botón "Reubicar" existente + modal (cantidad, bodega destino excluyendo la
  actual, motivo) + modal "Solicitudes de reubicación" (chips Todas/Pendientes/Aprobadas/
  Rechazadas, se reinicia a "Todas" cada vez que se abre — Escenario 1 de REQ-389). Mismo patrón
  visual que `SolicitudAjustePage.tsx`: los botones Aprobar/Rechazar se muestran a todos los
  perfiles, el backend es quien devuelve 403 si no sos Aprobador (consistencia con lo ya construido en
  Ajustes, no se introduce un gate visual nuevo en este batch).

### Espacio libre (454)
Decisión de Luis 2026-07-24: catálogo editable por Bodega, no un seeder fijo (a diferencia del
catálogo de 7 bodegas de SCRUM-451, que sí es fijo) — la disposición de racks cambia con el tiempo
sin que eso deba requerir un deploy.

```
warehouse_locations(
  id, warehouse_id, codigo, is_active, created_at, updated_at
)
-- UNIQUE(warehouse_id, codigo)
```

- CRUD (`index/store/update`) gateado a `bodega.write` (mismos roles que ya operan Bodega —
  Jefe/Asistente, `BodegaRoles::CAN_OPERATE`) — no hard-delete, se desactiva (`is_active=false`)
  para no perder historial.
- `WarehouseController::show()` con `chip=espacio_libre`: reemplaza el `not_implemented: true` —
  vacías = `warehouse_locations` activas del warehouse MENOS las `ubicacion` con
  `ProductWarehouseStock.quantity > 0` de ese warehouse. Mismo shape de respuesta que el resto de
  `show()` (kpis incluidos — no repetir el bug de Pre-QA 2026-07-21 de un shape distinto que
  rompía el frontend).
- Solo aplica a bodegas con `modo_detalle = ubicacion_exacta` (Central, Merma, Reclamos y
  Devoluciones en el mockup) — en `pendiente`/`cliente` el chip no tiene sentido, el frontend ya
  lo puede ocultar o mostrar vacío según corresponda.

## Consecuencias
- 2 tablas nuevas, ambas en el schema `{tenant}_bodega`.
- Ningún cambio a `ProductWarehouseStock`/`Warehouse` existentes.
- Reutiliza `KardexService`/`InventoryMovement::TIPO_REUBICACION` tal como estaban pensados desde
  que se construyó el Kardex (ver docblock de `KardexService`).
