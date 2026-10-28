# Flujo: órdenes de compra, pagos, cambio de monto, reclamos y sustituciones (Compras)

Complementa [venta a cobro](cadena-venta-a-cobro.md) §4 (que ya cubre alta, avance e ingreso de mercancía). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-10-28 con las cuentas demo de cada rol.

## Actores
Líder de Compras (`compras.write/edit`), aprobador primario configurado (`gerencia3` en el demo), Gerencia (otra cuenta: no aprueba), Vendedor (reporta sustituciones, no reclama a proveedores) [E].

## 1. Estados de una orden
`por_aprobar → pendiente_liquidar → ordenado → en_transito → en_aduana → en_transito_local → recibido` [E][C].
- Una orden **nace** en `por_aprobar` solo si requiere aprobación; si no, en `pendiente_liquidar` [E].
- `recibido` exige antes registrar el ingreso de mercancía y confirmar sus líneas [E].
- Editar una orden solo es posible antes de `ordenado` [C].

## 2. Cuándo requiere aprobación
**No depende del monto**: una orden de 8.000 no la requiere [E]. La regla es **envío aéreo pagado por la empresa** (`shipping_type = aereo` y `who_pays_shipping = atlantic`); aéreo pagado por el cliente o marítimo no [E][C].
- Sin aprobar, `advance` responde 422 «requiere la aprobación del aprobador configurado» [E].
- `PATCH orders/{id}/approve`: el Líder de Compras recibe 403 (sin permiso `compras.approve`); otra cuenta de Gerencia, 403 «Solo el aprobador configurado puede aprobar esta orden»; el aprobador, 200 con `approved_by` [E].
- Tras aprobar, el siguiente `advance` la lleva a `pendiente_liquidar` [E].

## 3. Pagos a proveedor
- `PATCH orders/{id}/request-payment` registra `payment_requested_at` [E].
- `POST orders/{id}/payments` (multipart `amount`, `proof`): el **tope es total + envío** (total 20 + envío 120 = 140; pagar 141 → 422 «El monto excede el saldo pendiente. El máximo permitido es 140.00») [E]. Cada pago guarda su comprobante, que se descarga con URL firmada [E].
- Estado de pago: `pendiente` → `parcial` tras el primer pago [E].
- `PATCH orders/{id}/liquidate` es **solo para órdenes de modalidad Zona Libre** (422 en las demás) [E].
- Con un pago registrado, el monto de la orden ya no se puede cambiar [E].

## 4. Cambio de monto
`PATCH orders/{id}/request-amount-change {new_amount}` (≥ 0,01) deja `pending_amount_change` [E]. Lo aprueba solo el aprobador configurado (`approve-amount-change`; el Líder de Compras, 403) y el `total_amount` pasa al valor nuevo (100 → 120) [E]. Se puede volver a solicitar y rechazar (`reject-amount-change`); sin solicitud pendiente, 422 [E].

## 5. Reclamos a proveedor
`POST compras/claims {purchase_order_id, expected_resolution, lines[{purchase_order_line_id, affected_quantity}]}` [E].
- Solo quien tiene `compras.write` (el vendedor, 403) [E]. Folio `R-1001…`; nace `en_revision` [E].
- Reglas: no más unidades que las pedidas en esa línea (422 «El máximo permitido para este producto es 10»); sin repetir producto; **cada línea debe ser de la orden reclamada** (defecto corregido en esta fase, backend #43: antes se aceptaba una línea de otra orden) [E][C].
- `expected_resolution` solo admite cinco valores (`reposicion`, `nota_credito_favor`, `nota_credito_reembolso`, `envio_repuestos`, `negado`) [E][C]. Estados `en_revision` ↔ `resuelto` (se puede reabrir) [E].

## 6. Sustituciones de producto
`POST compras/replacement-requests {original_catalog_product_id, proposed_catalog_product_id, sales_project_id}` lo reporta Ventas sobre un proyecto aprobado; calcula el margen del propuesto (47,83 %) [E].
- Estados: `pendiente` → (`pendiente_gerencia` si el margen no alcanza) → `aprobada` | `rechazada` [C][E].
- **Aprobar** una sustitución no genera orden. La orden de compra se genera desde una solicitud **rechazada** (se compra el original) y exige que ese producto tenga proveedor asignado (422 si no) [E].
- **El mismo vendedor que la reportó la aprobó** (200) con el margen sobre el mínimo [E].

## Candidatos a cambio o mejora
1. **La regla de aprobación está fija en el código** (aéreo + paga la empresa). Es un criterio de negocio; por la regla del workspace debería ser parametrizable (tipo de envío, monto, quién paga). [C]
2. **El cambio de monto altera `total_amount` sin tocar las líneas** (siguen sumando el valor anterior), así que el total deja de reconciliar con el detalle. [E]
3. **Rechazar no pide motivo** (cambio de monto y sustitución), a diferencia de las notas de crédito, las anulaciones de cobro y la cancelación de tickets. [E]
4. **Separación de funciones**: el solicitante puede aprobar su propia sustitución. [E]
5. **El estado `por_aprobar` conserva el mismo nombre tras aprobar** (la orden aprobada sigue mostrando `por_aprobar` hasta el siguiente avance). [E]
6. **«Aprobada» no genera nada y «rechazada» genera la orden**: el nombre de las acciones confunde. [E]
7. **Pagos sin tope contra lo recibido**: se puede pagar el 100 % de una orden que aún no llegó. [E][C]
8. **Sin ejercitar**: logística y envío, reposición («pendientes de reposición»), Zona Libre (solicitudes y su aprobación), agencias de liquidación, familias de producto (generar compra), proveedores (alta y cuenta bancaria), documentos de la orden y traducción. [C]
