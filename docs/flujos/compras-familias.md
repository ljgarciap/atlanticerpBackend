# Flujo: familias de producto de Compras

Continuación de [Compras: órdenes](compras-ordenes.md) y [reposición y proveedores](compras-reposicion-proveedores.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-03 con las cuentas demo (`npm run e2e:flujos`, archivo `compras-familias.spec.ts`, 4 pasos). El flujo crea sus propias familias «[FLUJO]» y deja los productos del demo en su familia y punto de reorden originales; las órdenes que genera sí quedan en el demo.

## 1. Alta de una familia
`POST families` con `name` (permiso `compras.write` o `compras.edit`).
- Nombre vacío o solo espacios, 422. Vendedor, Bodega y Contabilidad, 403 [E].
- Nombre nuevo: 201. El mismo nombre sin importar mayúsculas devuelve la existente (200, mismo id), no duplica [E].
- Solo tiene nombre y descripción; no hay editar, desactivar ni borrar una familia por la API [C].

## 2. Listado y detalle: quién ve qué
`GET families` y `GET families/{id}` no llevan permiso de ruta; el controlador decide el acceso [C].
- **Compras y Gerencia** (`compras.read`): `restricted: false`, `can_manage: true`, valor de la familia (costo total × existencias) [E].
- **Ventas y Contabilidad** (`ventas_diseno.read`): ven la estructura con `restricted: true`, `can_manage: false` y `total_value: null`; el detalle también oculta costos [E].
- **Bodega**: 403 [E]. Familia inexistente: 404 [E].
- Las familias nuevas aparecen con `product_count: 0`; solo cuentan los productos activos [E][C].

## 3. Asignar productos
Una familia se asigna al producto con `PUT inventory/{id}` (`family_id`; `null` lo saca de toda familia), o al crear el producto [E][C]. El detalle de la familia lista los productos con la misma fila completa del inventario (KPI de por servir, por ingresar, en camino y almacenes) [E][C].

## 4. Generar compra
`POST families/{id}/generate-purchase` (permiso `compras.edit`; vendedor, Bodega y Contabilidad, 403) [E].
- Familia inexistente, 404 [E].
- **Fase 20b — solo se compra la necesidad real**: `punto de reorden − (existencias + en camino + por ingresar)`. Con el reorden en 0 o por debajo de las existencias no se compra nada de ese producto [E].
- Crea **una orden por proveedor** distinto, modalidad `directo`, moneda USD, en `pendiente_liquidar` o `por_aprobar` según las reglas de aprobación de la empresa; el costo unitario es el costo total del producto [E][C].
- La respuesta (201) trae `order_ids` y **`omitidos`**: cada producto que no se compró con su motivo, `sin_proveedor`, `sin_necesidad` o `ya_en_camino` [E].
- Si no hay nada que comprar responde **422 con el desglose**: «La familia no tiene productos activos con proveedor asignado» cuando ninguno tiene proveedor, o «no necesita compra ahora» cuando todos están cubiertos o ya en camino [E].
- **Repetir no duplica**: lo recién pedido cuenta como «en camino», así que la segunda petición es un 422 con `ya_en_camino` [E]. La operación corre bajo un bloqueo por familia, para que dos clics simultáneos tampoco dupliquen [C].

## Candidatos a cambio o mejora
(Solo documentados; ninguno se implementó.)
1. ~~Pide al menos 1 unidad aunque no haga falta~~ — **corregido en la Fase 20b**: solo entra la necesidad real. [E]
2. ~~Repetir «Generar compra» crea otras órdenes iguales~~ — **corregido en la Fase 20b**: lo ya pedido cuenta como «en camino». [E]
3. ~~Omite en silencio los productos sin proveedor~~ — **corregido en la Fase 20b**: la respuesta lista `omitidos` con su motivo. [E]
4. **Las existencias no distinguen el almacén ni descuentan lo comprometido**: usa `stock_quantity` total (sí suma lo «en camino» y «por ingresar» desde la Fase 20b). [C]
5. **Las familias no se pueden editar, desactivar ni borrar** (ni renombrar); solo crearlas. [C]
6. **Moneda y modalidad fijas** (USD, directo), sin elegir proveedor alterno ni fecha. [C]
7. **Sin ejercitar**: la pantalla de Inventario en el navegador y la asignación de varios proveedores por producto.
