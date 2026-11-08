# Flujo: mapeo de asistentes y llegada física de mercancía (Bodega)

Continuación de [Bodega: inventario](bodega-inventario.md) y [ubicaciones, rutas y Kardex](bodega-ubicaciones-rutas-kardex.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-03 con las cuentas demo (`npm run e2e:flujos`, archivo `bodega-asistentes-llegadas.spec.ts`, 4 pasos). El flujo deja el mapeo del vendedor como estaba al empezar.

## 1. Mapeo vendedor → asistente de Bodega
Decide qué asistente recibe los pedidos nuevos de cada vendedor. Un mapeo **activo** por vendedor (`warehouse_assistant_mapping`).
- **Leer** (`GET warehouse-assistant-mapping`): cualquiera con `bodega.read` (Líder, asistentes, auxiliar); Ventas, 403 [E]. Cada fila trae vendedor y asistente con nombre [E].
- **Editar** (`POST`, `seller_id` + `assistant_id`): **solo el Líder de Bodega o un superadmin**. Asistente, Gerencia (las dos cuentas), vendedor y Compras, 403 aunque algunos tengan `bodega.write` [E][C]. Un vendedor inexistente o un cuerpo incompleto, 422 [E].
- Crear uno nuevo para el mismo vendedor **desactiva el anterior** y crea otro (201); el listado sigue mostrando uno solo [E]. No hay ruta para borrar o desactivar un mapeo sin reemplazarlo [C].

## 2. Efecto en los pedidos
- Un pedido que nace de un proyecto aprobado toma el asistente **mapeado en ese momento** [E]. El detalle del pedido no expone al asistente; el tablero sí (`asistente`) y se filtra con `assistant_id` [E].
- Cambiar el mapeo **no reasigna los pedidos existentes**: el viejo conserva su asistente y el nuevo toma el nuevo [E].
- Sin mapeo activo el pedido se crea igual, en «Asignado» y sin asistente, y deja una advertencia en el registro; requiere asignación manual [C].

## 3. Carga por asistente
`GET orders/assistant-load` (`bodega.read`; Ventas 403) lista cada asistente (por rol) con `orders_count` y `percent_of_total`, calculado sobre los pedidos del tablero [E]. Tras el flujo, el asistente anterior y el nuevo suman al menos un pedido cada uno [E].

## 4. Llegada física: dos pasos, dos responsables
Compras registra el ingreso (la línea queda «Por ingresar», sin mover stock) [E]. Después:
- **Paso 1, Bodega** (`POST inventory/{producto}/confirm-arrival`, `cantidad_entregada`, permiso `bodega.write`): aviso de lo realmente contado. Producto inexistente 404; cantidad 0, negativa o ausente, 422; más de lo pedido pendiente, 422 «no puede superar lo pedido pendiente (N unidades)»; vendedor y Líder de Compras, 403 [E].
- Con 6 de 10 contadas: responde `confirmed_quantity 6`, el inventario marca `awaiting_compras` y `confirmed_quantity`, y `pending_bodega_action` pasa a falso. **El stock no se mueve** [E]. Mientras Compras no confirme, reportar de nuevo la misma línea da 422 «no tiene unidades "Por ingresar" pendientes de reportar» [E].
- **Paso 2, Compras** (`POST goods-receipts/lines/{id}/confirm`, `…/products/{id}/confirm-pending` o al avanzar la orden a «Recibido»): factura al inventario y el stock sube [E].
- **Fase 20c — entra lo que Bodega contó**: si Bodega reportó menos de lo registrado (6 de 10), al inventario entran **solo las 6**. La línea se parte: las 6 se confirman (Entrada de Kardex por 6, con «Bodega contó 6 de 10; 4 pendientes de llegar» en la referencia) y las 4 restantes quedan como una línea nueva «Por ingresar», **sin reporte de Bodega**, que se puede reportar y confirmar cuando lleguen. El total registrado de la orden no cambia, y el ITBMS y los prorrateos de costo se reparten proporcionalmente (el resto toma la diferencia, así la suma es exacta) [E].
- Si Bodega no reportó nada, o contó todo, la línea se confirma completa como siempre [E].
- **Dos confirmaciones simultáneas no duplican**: la línea se bloquea dentro de la transacción, una confirma y la otra recibe 422 «ya fue confirmada» [E].
- El reparto del reporte de Bodega es FIFO por línea, nunca más de lo que cada línea pidió [C].
- Avanzar la orden a «Recibido» confirma **todas** las líneas pendientes con la misma regla: una línea que Bodega contó incompleta se parte igual, así que la orden puede quedar «Recibido» con la parte no contada todavía «Por ingresar» (visible en Inventario, reportable cuando llegue); una línea sin reporte de Bodega entra completa [C].

## Candidatos a cambio o mejora
(Solo documentados; ninguno se implementó.)
1. ~~El conteo de Bodega no cuenta~~ — **corregido en la Fase 20c**: entra lo contado y el resto queda por llegar. [E]
2. ~~Una llegada reportada incompleta no se puede completar~~ — **corregido en la Fase 20c**: el resto es una línea nueva reportable. [E]
3. ~~El mapeo acepta cualquier usuario~~ — **corregido en la Fase 29**: el vendedor debe tener rol de ventas y el asistente rol de Asistente de Bodega (422 si no). [C]
4. ~~No se puede quitar un mapeo~~ — **corregido en la Fase 29**: `DELETE warehouse-assistant-mapping/{id}` (solo Líder o superadmin); sin mapeo activo el pedido nuevo queda sin asistente. [C]
5. ~~Cambiar el mapeo no reasigna lo pendiente~~ — **corregido en la Fase 29** como acción explícita: `POST warehouse-assistant-mapping/reassign-pending {seller_id}` pasa al asistente de hoy los pedidos no entregados de ese vendedor. [E]
6. ~~El detalle del pedido no muestra al asistente~~ — **ya lo muestra** (`asistente` en el detalle y en el tablero; se verificó en la Fase 29). [C]
7. **Sin ejercitar**: la pantalla del navegador y el evento `ArrivalConfirmedByWarehouse` (avisos a Compras).
