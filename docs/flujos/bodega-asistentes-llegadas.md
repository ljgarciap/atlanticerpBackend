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
- Con 6 de 10 contadas: responde `confirmed_quantity 6`, el inventario marca `awaiting_compras` y `confirmed_quantity`, y `pending_bodega_action` pasa a falso. **El stock no se mueve** [E].
- Reportar de nuevo el producto da 422 «no tiene unidades "Por ingresar" pendientes de reportar»: lo reportado no se completa después [E].
- **Paso 2, Compras** (`POST goods-receipts/lines/{id}/confirm`): factura al inventario y el stock sube [E]. Sube **10, lo ingresado por Compras**, no los 6 que contó Bodega [E].
- El reparto es FIFO por línea, nunca más de lo que cada línea pidió [C].

## Candidatos a cambio o mejora
(Solo documentados; ninguno se implementó.)
1. **El conteo de Bodega no cuenta**: Compras confirma lo ingresado (10) aunque Bodega reportó 6; el faltante queda solo como historial. Si el aviso debe servir de control, la confirmación debería compararlos o avisar la diferencia. [E]
2. **Una llegada reportada incompleta no se puede completar**: tras reportar 6 de 10, los 4 restantes ya no se pueden reportar (la línea queda marcada). [E]
3. **El mapeo acepta cualquier usuario**: solo valida que existan; un mapeo con un vendedor sin rol de ventas o un «asistente» que no es de Bodega se acepta, y `assistant-load` solo lista usuarios con el rol de asistente. [C]
4. **No se puede quitar un mapeo**, solo reemplazarlo; sin mapeo activo el pedido queda sin asistente. [C]
5. **Cambiar el mapeo no reasigna lo pendiente**: los pedidos ya creados siguen con el asistente anterior, y no hay pantalla de reasignación en lote. [E]
6. **El detalle del pedido no muestra al asistente**; solo el tablero. [E]
7. **Sin ejercitar**: la pantalla del navegador y el evento `ArrivalConfirmedByWarehouse` (avisos a Compras).
