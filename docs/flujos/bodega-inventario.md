# Flujo: inventario de Bodega (ajustes, reubicaciones, conteo general y devoluciones)

Complementa el pedido de Bodega de [venta a cobro](cadena-venta-a-cobro.md) §5. Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-10-29 con las cuentas demo de cada rol.

## Actores
| Rol | Qué hace |
|---|---|
| Asistente de Bodega (`bodega.write`) | solicita ajustes y reubicaciones, crea y evalúa conteos, registra devoluciones [E] |
| Jefe de Bodega (`bodega.write`) | aplica el conteo aprobado; **no aprueba** ajustes ni reubicaciones [E] |
| Gerencia (`bodega.approve`) | tiene el permiso pero **no basta**: otra cuenta de Gerencia recibe 403 «No tienes permiso para aprobar o rechazar esta solicitud» [E] |
| Aprobador primario configurado | único que aprueba o rechaza ajustes, reubicaciones y conteos (`AprobacionGate`) [E][C] |
| Vendedor | no opera inventario (403) [E] |

## 1. Ajuste de inventario
`POST bodega/adjustment-requests` (multipart: `catalog_product_id`, `lines[i][warehouse_id|tipo|cantidad|motivo|evidencia]`) [E].
- `tipo` `Sumar` o `Restar`; `motivo` del catálogo fijo (conteo físico que no coincide, producto dañado/roto, vencido/obsoleto, error de registro, robo o pérdida, otro); **la evidencia (imagen o PDF) es obligatoria** [E][C].
- **Solicitar no mueve el stock** [E]. Mientras haya un ajuste o conteo sin resolver para ese producto y almacén, otro responde **409** con el detalle de lo pendiente [E].
- `POST …/lines/{id}/approve` lo hace solo el aprobador: sube el stock (127 → 132 con Sumar 5), pasa la línea a `Aprobada`, y repetirlo responde 422 «ya fue resuelta» [E].
- `…/reject` deja el stock igual y libera el producto [E].

## 2. Reubicación entre almacenes
`POST bodega/relocations {catalog_product_id, origin_warehouse_id, destination_warehouse_id, cantidad, motivo}` [E].
- Origen y destino distintos (422); no se mueve más de lo que hay («La cantidad máxima es de 2 unidades») [E]; el origen debe tener ubicación física confirmada [C].
- La aprueba solo el aprobador: Central 153 → 151 y Sala de exhibición 0 → 2; el kardex registra «Reubicación» [E].
- **Rechazar exige motivo** (`motivo_rechazo`) y no mueve nada [E].

## 3. Conteo general de un almacén
1. `POST bodega/general-counts {warehouse_id}` genera una línea por producto con su `cantidad_sistema` (49 líneas en Central); estado `pendiente_evaluacion` [E].
2. `POST …/evaluate {lines[{id, cantidad_contada}]}` calcula las diferencias; estado `evaluado` [E]. Enviar sin evaluar → 422 [E].
3. `POST …/submit` lo manda a aprobación (`pendiente_aprobacion`); ya no se puede volver a evaluar [E].
4. `POST …/approve` / `…/reject` solo el aprobador (el jefe y otra cuenta de Gerencia, 403); rechazar exige motivo [E].
5. `POST …/apply` **solo el Jefe de Bodega** (la asistente, 403): ajusta el stock a lo contado (15 → 18 y 200 → 199) y el kardex registra «Conteo» (+3 y −1); aplicar de nuevo → 422 [E].
- Aprobar **no** aplica: entre el paso 4 y el 5 el stock no cambia [E]. Tres personas distintas intervienen en un conteo (cuenta, autoriza, aplica) [E].

## 4. Devolución de un cliente
`POST bodega/returns {order_id, contact_name, contact_phone, items[{order_item_id, qty_requested, reason}]}` [E].
- `reason` del catálogo (dañado/defectuoso, no corresponde, cambio de opinión, excedente, error de instalación, otra); folio `DEV-2026-0001`; solo sobre pedidos entregados [E][C].
- **Tope al solicitar**: no más de lo entregado menos lo ya solicitado en otras devoluciones («3 unidad(es) disponibles de 3 entregadas») [E].
- Estados: `pendiente → esperando_nota_credito → finalizado` (o `rechazada`) [E][C].
- Recepción: primero se carga el **documento firmado** (sin él, 422 «Debe cargar el documento firmado antes de confirmar la recepción»); luego `confirm-reception {destination_warehouse_id, lines[{customer_return_line_id, qty_received}]}` suma al almacén elegido y deja la devolución `esperando_nota_credito` [E].
- **Defecto corregido en esta fase (backend #46)**: la cantidad recibida no se limitaba; recibir 5 de un ítem con 3 entregados subía el stock en 5. Ahora el techo es lo entregado [E].
- La devolución termina cuando Admin & Contab emite la **nota de crédito de devolución de mercancía** vinculada (`devolucion_bodega_id`): queda `finalizado` con `credit_note_id` [E].

## Candidatos a cambio o mejora
1. **Un solo aprobador para todo el inventario** (ajustes, reubicaciones, conteos): si falta, nada se autoriza; Gerencia tiene el permiso y aun así recibe 403, lo que confunde. [E][C]
2. **El conteo aprobado conserva el estado `aprobada` tras aplicarse**: no existe un estado «aplicado»; se deduce de `aplicado_at`. [E]
3. **La cantidad recibida de una devolución puede superar la solicitada** (hasta lo entregado). [E]
4. **Los motivos de ajuste y de devolución son listas fijas en el código**, no catálogos por empresa (como los de la Fase 7). [C]
5. **Rechazar pide motivo aquí y no en Compras** (cambio de monto y sustituciones): criterio distinto entre módulos. [E]
6. **Ajuste de un solo producto por solicitud en la API** (varias líneas, un producto): el conteo general es la vía para muchos. [C]
7. **Ids no numéricos devolvían 500 en 51 de 55 rutas de Bodega**; corregido para toda la API (backend #45). [E]
8. **Sin ejercitar**: kardex y reportes en detalle, rutas del día, ubicaciones físicas, solicitudes de Zona Libre de Bodega, mapeo de asistentes, confirmación de llegada de inventario. [C]
