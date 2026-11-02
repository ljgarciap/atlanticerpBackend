# Flujo: reposición, proveedores y agencias de liquidación de Compras

Continuación de [Compras: órdenes, pagos, cambio de monto, reclamos y sustituciones](compras-ordenes.md). Marcas: **[E]** ejecutado, **[C]** leído del código (ver `README.md`). Verificado el 2026-11-02 con las cuentas demo de cada rol y un pedido propio que se queda sin existencias (`npm run e2e:flujos`, archivo `compras-reposicion-proveedores.spec.ts`; la cadena del pedido vive en `e2e/flujos/apoyo/venta-cobrada.ts`).

## Quién ve y quién escribe
`replenishment`, `providers` y `liquidation-agencies` los usan Líder de Compras y Gerencia. El vendedor, la asistente de bodega, Contabilidad y el Líder de Operaciones reciben 403 en la reposición, y el vendedor también al escribir proveedores y agencias [E].

## 1. Pendientes de reposición (`GET replenishment`, `compras.write`)
Consolida lo que falta de los pedidos que hoy son un **faltante «sin stock»** (etapa asignado, ítems no encontrados) y lo agrupa por proveedor principal del producto; «Sin proveedor» queda al final [C].
- **Cómo nace el faltante**: el picker alista 0 de un producto, `complete-picking` abre la tarjeta de revisión y el `resolve-inventory-review` con `found = false` la convierte en tarjeta **faltante** [E].
- La fila trae `faltante` (suma de los pedidos que esperan el producto), `stock`, `unit_cost` precargado, `en_camino`, los pedidos con su cotización y los proveedores elegibles [E]. `faltante` es la suma de las cantidades de sus pedidos, también cuando hay varios faltantes del mismo producto [E].
- Los artículos libres (sin producto del catálogo) no se reponen: solo se cuentan en `custom_items_count` [E][C].

## 2. Asignar proveedor a un producto pendiente (`PUT replenishment/products/{id}/provider`)
Mismo permiso que generar la orden (`compras.write`, no el de editar inventario), por eso está acotado a propósito [C].
- Sin `provider_id` → 422; proveedor inexistente → 422 [E]. El vendedor → 403 [E].
- **Solo productos que hoy esperan reposición**: otro producto → 403 con código `replenishment_scope` [E].
- Producto **sin proveedor**: se asigna (200) y la fila cambia de grupo; repetir la misma petición no cambia nada [E].
- Una vista vieja que cree que el producto no tiene proveedor, cuando ya lo tiene, recibe **409 `provider_changed`** y no pisa nada [E].
- **Corregir** la elección hecha en esta pantalla exige indicar `expected_provider_id` (el que dejó la pantalla): funciona (200); un esperado que la pantalla nunca asignó → 403 [E]. Nunca quita otros proveedores del producto ni toca órdenes existentes [C].

## 3. Comprar y recibir libera el faltante
Con una orden de compra del producto al proveedor y su ingreso confirmado, el faltante se libera solo y la fila desaparece [E]. Mientras la orden está comprada pero **sin recibir**, el producto sigue pendiente (`en_camino` lo refleja) [E]. Si había varios faltantes del mismo producto, una compra por el total los libera todos [E].

## 4. Proveedores
- Alta (`compras.write`): `name` (único **sin distinguir mayúsculas**), `category` (clave del catálogo `provider_category`), `country` y al menos un medio de contacto (`whatsapp`, `phone` o `email`); teléfono con formato, moneda de 3 letras. Todo lo inválido → 422 [E].
- **El origen no se envía**: se deriva de la categoría (`locales` → `local`, otras → `internacional`); la moneda por defecto es USD [E].
- Edición (`PUT`, `compras.edit`): **requiere el cuerpo completo** (nombre, categoría, país, contacto), no admite cambiar un solo campo [E]. El vendedor → 403; un id inexistente → 404 [E]. La búsqueda por texto encuentra al proveedor [E].
- No se borran [C].

## 5. Agencias de liquidación
- Alta (`compras.write`): `name` obligatorio, correo y teléfono con formato [E]. **El nombre se puede repetir** [E].
- Situación de pago (`PUT`, `compras.edit`): `pending_payment_amount` y `paid_amount` no negativos; el resumen suma los pendientes en sus indicadores [E]. Nombre y contacto principal **no son editables** tras el alta [C].
- **Dos conceptos de contacto**: el contacto principal (nombre, teléfono, correo de la agencia) NO figura en la lista `contacts`; esa solo trae los contactos que se agregan aparte [E]. Se pueden agregar, editar y quitar; **no se quita el único contacto que queda en la lista** (422) [E].

Al resolverse la revisión como «no encontrado», Bodega **notifica a Compras y a Ventas & Diseño** del faltante (`InventoryReviewService::notifyShortage`) [C].

## Candidatos a cambio o mejora
1. **El nombre de agencia se puede repetir** (los proveedores tienen nombre único y las agencias no). [E]
2. **El contacto principal de la agencia no se puede editar y no cuenta como contacto de la lista**: hay dos conceptos de «contacto» y, si el principal queda desactualizado, no hay cómo corregirlo. [E]
3. **La edición de proveedor exige reenviar todos los campos** (`PUT` completo), con lo que dos personas editando pueden pisarse entre sí. [E]
4. **Los faltantes del mismo producto se acumulan en una sola fila**, pero la reposición no sugiere cantidad mínima ni redondeo a la presentación del proveedor: Compras compra «exactamente lo que falta». [C]
5. **Los productos del demo no traen proveedor** (salvo el primero): el primer pendiente de cada producto obliga a elegirlo a mano; no hay alta masiva de proveedor por familia. [E]
6. **No hay desactivación ni baja de proveedores ni de agencias**: los de prueba se acumulan. [C]
