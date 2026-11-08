# Flujo: ubicaciones, rutas del día y Kardex de Bodega

Continuación de [Bodega: ajustes, reubicaciones, conteo y devoluciones](bodega-inventario.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-02 con las cuentas demo de cada rol y un pedido propio (`npm run e2e:flujos`, archivo `bodega-ubicaciones-rutas-kardex.spec.ts`; la cadena del pedido vive en `e2e/flujos/apoyo/venta-cobrada.ts`).

**Zona Libre de Bodega ya no existe**: el flujo se retiró del núcleo en la Fase 14f-1 (no queda ninguna ruta ni código). Las menciones anteriores de «solicitudes de Zona Libre» en este catálogo son históricas.

## Quién ve y quién escribe
- **Leen** ubicaciones, rutas del día y Kardex: Líder de Compras, Gerencia, auxiliar, repartidor y el resto de perfiles con `bodega.read`; el **vendedor recibe 403** en las tres [E].
- **Escriben ubicaciones** (`bodega.write`): líder y asistente de bodega, y también el **auxiliar, el repartidor y Gerencia** (llegan a la validación, 422, en vez de 403); el **Líder de Compras**, que sí lee, recibe 403 al escribir [E].

## 1. Ubicaciones físicas
`GET|POST warehouses/{id}/locations` y `PATCH warehouses/{id}/locations/{id}`. Solo tiene sentido en bodegas con `modo_detalle = ubicacion_exacta` [C].
- Nace activa; el `codigo` es obligatorio (422) y **único por bodega**: se repite sin problema en otra bodega [E]. Renombrar a un código ya usado en la misma bodega → 422 [E]. Una ubicación de otra bodega (o una bodega inexistente) → 404 [E].
- **No se borran**: se desactivan con `is_active = false`; el listado devuelve activas e inactivas [E].
- **Chip «Espacio libre»** (`GET warehouses/{id}?chip=espacio_libre`): `ubicaciones` lista las activas **sin existencias**; una desactivada deja de ofrecerse [E].

## 2. Rutas de entrega del día (`GET home/rutas-dia`)
Pedidos cuya fecha de entrega comprometida es **hoy** y que no están entregados; cada fila trae número, etapa, cliente, contacto, proyecto, dirección y repartidor (`null` mientras no se asigna) [E][C]. Un pedido creado con entrega hoy aparece, y al entregarse (picking, entrega firmada) sale de la lista [E].

## 3. Kardex (`GET kardex`)
Un movimiento por cambio de existencias: fecha, producto, bodega, tipo (`Entrada`, `Salida`, `Devolución`…), cantidad (negativa en salidas), referencia legible («Pedido PED-… despachado»), quién lo hizo, saldo inicial y resultante [E].
- Despachar el pedido deja una `Salida` de −N en la bodega de origen, con `saldo_resultante = saldo_inicial + cantidad` [E].
- Filtros `tipo`, `warehouse_id` y `search` (por referencia o nombre del producto) funcionan; un filtro de otra bodega no trae el movimiento [E]. Orden por fecha descendente con el id como desempate [C].
- **La cadena de saldos es continua**: dentro de un producto y una bodega, el saldo inicial de cada movimiento es el resultante del anterior [E].

## Candidatos a cambio o mejora
1. ~~No hay un permiso angosto para escribir ubicaciones~~ — **corregido en la Fase 29**: crear y desactivar ubicaciones exige `bodega.write` **y** `bodega.locations.manage` (Líder y Asistente de Bodega, este último con nivel propio). [E]
2. ~~La ruta del día solo muestra lo de hoy~~ — **corregido en la Fase 29**: incluye lo atrasado sin entregar, arriba y con `atrasado: true`. [C]
3. **La ruta se ordena por fecha, repartidor e id** (Fase 29). No hay orden por hora porque el pedido solo guarda la fecha de entrega comprometida, no la hora. [C]
4. ~~Las ubicaciones desactivadas se acumulan~~ — **corregido en la Fase 29**: el listado oculta las desactivadas; `?incluir_inactivas=1` las muestra. Siguen sin borrarse (historial). [E]
5. ~~El Kardex no filtra por fecha ni por producto exacto~~ — **corregido en la Fase 29**: `producto_id`, `desde` y `hasta` (422 si la fecha es inválida). El `tipo` sigue filtrándose con la etiqueta guardada. [E][C]
6. **El Líder de Compras ve todo Bodega** (Kardex, ubicaciones, rutas): **decisión documentada en la Fase 29** — Compras necesita ver existencias y movimientos; ver no es escribir (las escrituras exigen sus permisos propios). [E]
