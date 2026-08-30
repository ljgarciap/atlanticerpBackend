# ADR — Bloque B5: Conteos generales de inventario (SCRUM-460→466)

**Status: Approved** (Luis, 2026-07-25)

## Contexto

Pantalla "Inventario general" (`3H__Bodega_InventarioGeneral.html`, REQ-390→396). 7 tickets, todos
nuevos (no hay backend previo, a diferencia de otros bloques):

- SCRUM-460 (REQ-390) — iniciar conteo: selector de bodega, precarga productos registrados.
- SCRUM-461 (REQ-391) — aviso de cruce con solicitud de ajuste pendiente sobre el mismo producto.
- SCRUM-462 (REQ-392) — "Evaluar": calcula diferencia (contada − sistema) por producto.
- SCRUM-463 (REQ-393) — "Enviar a aprobación de Mark": valida que se evaluó, confirma el cruce.
- SCRUM-464 (REQ-394) — al enviar (no al aprobar), la solicitud de ajuste cruzada se elimina.
- SCRUM-465 (REQ-395) — bandeja/historial con filtros, aprobar/rechazar (motivo obligatorio).
- SCRUM-466 (REQ-396) — "Realizar ajuste": botón separado, solo tras aprobación, aplica al Kardex.

Descripción de SCRUM-465 estaba vacía en Jira — completada en esta sesión con el Excel
`3__Requerimientos_Bodega.xlsx` (fila REQ-395 + HU-395) y el mockup adjunto, con OK explícito de
Luis para avanzar con el bloque completo a pesar del vacío original.

## Decisión

### Modelo de datos — contenedora + líneas (como `AdjustmentRequest`, no plano como `RelocationRequest`)

Un conteo es inherentemente multi-producto (toda la bodega), a diferencia de Reubicación
(1 producto). Estado de aprobación vive a nivel del conteo completo (REQ-393/395 hablan de
aprobar/rechazar "el conteo", no cada producto por separado):

```
general_count_requests(
  id, warehouse_id, realizado_por,
  estado,              -- pendiente_evaluacion | evaluado | pendiente_aprobacion | aprobada | rechazada
  motivo_rechazo,      -- NULL hasta rechazo
  resuelto_por, resuelto_at,
  aplicado_at,         -- NULL hasta "Realizar ajuste" (SCRUM-466) — separado de resuelto_at
  created_at, updated_at
)

general_count_request_lines(
  id, general_count_request_id, catalog_product_id,
  cantidad_sistema,    -- snapshot de ProductWarehouseStock.quantity al momento de evaluar
  cantidad_contada, diferencia,   -- NULL hasta "Evaluar"
  created_at, updated_at
)
```

- `store()`: crea la contenedora (`estado=pendiente_evaluacion`) + una línea por producto
  registrado en la bodega elegida (`ProductWarehouseStock` con `quantity > 0`, mismo criterio que
  ya usa `WarehouseController::show()` — ver nota abierta abajo).
- `evaluar()`: exige `cantidad_contada` en todas las líneas (RN1 REQ-392), calcula `diferencia`,
  pasa a `estado=evaluado`. Editar cualquier `cantidad_contada` después de evaluar vuelve a poner
  `diferencia=NULL` en esa línea y el conteo a `pendiente_evaluacion` (RN2 REQ-392) — el frontend
  ya lo maneja ocultando la columna, el backend debe reflejar el mismo estado para que un GET
  posterior no muestre diferencias stale.
- `submit()` (Enviar a aprobación): exige `estado=evaluado` (RN1 REQ-393). Si algún producto con
  diferencia cruza con una solicitud pendiente (ver definición del cruce abajo), el frontend ya
  pidió confirmación explícita antes de llamar este endpoint — el backend no vuelve a confirmar,
  pero SÍ ejecuta la eliminación de las líneas cruzadas de `AdjustmentRequestLine` en la misma
  transacción (RN de REQ-394, ver abajo). Pasa a `estado=pendiente_aprobacion`.
- `approve()`/`reject()`: mismo patrón que `RelocationRequestController` — `lockForUpdate()`,
  gate exclusivo de Mark vía `ComprasSettings::current()->mark_approver_user_id` (no
  `bodega_settings`, corrección respecto al supuesto inicial). `reject()` exige `motivo_rechazo`
  (RN2 REQ-395). **Ninguno de los dos toca el Kardex** — es la diferencia real de este bloque
  respecto a Ajustes/Reubicaciones.
- `apply()` (Realizar ajuste, SCRUM-466): solo si `estado=aprobada` y `aplicado_at IS NULL`
  (idempotencia — el botón se deshabilita en frontend tras el primer click, pero el backend no
  debe confiar solo en eso). Por cada línea con `diferencia != 0`, un `KardexService::registrar()`
  con cantidad con signo = `diferencia`, `tipo: InventoryMovement::TIPO_CONTEO` (nuevo — anticipado
  textualmente en el docblock de la migración de `inventory_movements`, nunca creado hasta ahora),
  `referenciaEntidadTipo: 'CONTEO_GENERAL'`, `referenciaEntidadId: $generalCountRequest->id`. Marca
  `aplicado_at = now()`.

### Precarga de productos (SCRUM-460)

Reutiliza la misma fuente que `WarehouseController::show()` (`ProductWarehouseStock` por
`warehouse_id`) en vez de duplicar la query — mismo criterio ya aplicado entre
`AdjustmentRequestController` y `OrderBoardController::warehouseBreakdown`.

**Decidido (Luis, 2026-07-25):** mismo criterio que `WarehouseController::show()` —
`quantity > 0` únicamente. Un sobrante físico de un producto que el sistema tiene en 0 queda fuera
de este flujo (no es un gap de este bloque, es alcance confirmado).

### Cruce con "solicitud cíclica pendiente" (REQ-391/394)

**Hallazgo de la exploración de código:** no existe en el sistema ningún campo que distinga una
`AdjustmentRequest` "cíclica" de otro tipo de ajuste — `AdjustmentRequestLine.tipo` solo distingue
`Sumar`/`Restar` (dirección), no naturaleza. Es decir, toda `AdjustmentRequest` hoy ES el ajuste
individual producto-por-producto que el ticket llama "cíclico"; no hay una taxonomía previa que
separar. Este ADR define el criterio de cruce, porque no hay nada heredado que lo determine:

> Un producto del conteo "cruza" si existe una `AdjustmentRequestLine` con `estado=Pendiente` cuya
> `AdjustmentRequest.catalog_product_id` coincide, cruzando además por `warehouse_id` de la línea
> contra la bodega del conteo (para no bloquear un conteo de Bodega Central por una solicitud
> pendiente que aplica a Zona Libre).

**Decidido (Luis, 2026-07-25):** si tras borrar la línea cruzada la `AdjustmentRequest` contenedora
queda sin ninguna línea, se elimina también la contenedora — evita acumular solicitudes fantasma
sin ningún detalle real.

### Rutas (`routes/bodega.php`)

```php
Route::prefix('general-counts')->group(function (): void {
    Route::get('/', [GeneralCountController::class, 'index'])->middleware('permission:bodega.read');
    Route::post('/', [GeneralCountController::class, 'store'])->middleware('permission:bodega.write');
    Route::post('/{id}/evaluate', [GeneralCountController::class, 'evaluate'])->middleware('permission:bodega.write');
    Route::post('/{id}/submit', [GeneralCountController::class, 'submit'])->middleware('permission:bodega.write');
    Route::post('/{id}/approve', [GeneralCountController::class, 'approve'])->middleware('permission:bodega.approve');
    Route::post('/{id}/reject', [GeneralCountController::class, 'reject'])->middleware('permission:bodega.approve');
    Route::post('/{id}/apply', [GeneralCountController::class, 'apply'])->middleware('permission:bodega.write');
});
```

Sin permiso de rol nuevo — mismo criterio que Ajustes/Reubicación (`bodega.read/write/approve`
existentes, exclusividad de Mark resuelta en controller, no en middleware).

## Consecuencias

- 2 tablas nuevas en `{tenant}_bodega`: `general_count_requests`, `general_count_request_lines`.
- 1 constante nueva en `InventoryMovement`: `TIPO_CONTEO`.
- Ningún cambio a `AdjustmentRequest`/`AdjustmentRequestLine` existentes, salvo el `delete()` que
  dispara `submit()` sobre las líneas cruzadas (decisión abierta 2).
- Primer flujo del proyecto donde "Mark aprueba" y "se aplica al inventario" son dos pasos
  desacoplados — documentado explícitamente porque rompe el patrón que synthesized Ajustes y
  Reubicaciones (aprobación = aplicación inmediata).
