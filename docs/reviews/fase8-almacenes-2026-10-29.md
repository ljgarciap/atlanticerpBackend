# Senior Review — Fase 8 (almacenes configurables, 8a y 8b)

**Veredicto**: aprobada, 0 bloqueantes. Spec: `docs/specs/fase8-almacenes-configurables.md`.

## Revisado
- **8a**: la migración renombra y agrega `funcion` (única parcial) y `tipo` (CHECK); `down()` revierte nombres y columnas. `InsumoService` ya no busca por nombre (reserva por `funcion`, central por `despacho_por_defecto`). Admin&Cont usa `tipo`. `stock_showroom` → `stock_aparte` en backend y frontend; contrato sin `showroom`/`obarrio`.
- **8b**: sin permiso nuevo (`superadmin.all`), así que no hay riesgo de fuga por nivel compartido. La baja recorre las claves foráneas SIN cascada hacia `warehouses` (catálogo de Postgres): una tabla nueva bloquea la baja sin tocar el código. Ubicaciones y stock caen en cascada. Desactivar exige almacén no de sistema, no por defecto y sin existencias, y fuerza `permite_venta = false`.
- **Cierre (grep sin exclusiones, lección de la 7c)**: los selectores (`WarehouseController`, Compras, stock por almacén, capacidad en reportes) filtran `is_active`; `paraVenta()` lo incluye; `Warehouse::existsRule()` (altas de operaciones nuevas) exige activo. Los reportes por nombre e historial (`pluck('name','id')`) no filtran a propósito.

## Verificación
PHPStan 0. Backend suite completa 5163 tests / 0 fallos. Frontend `tsc` limpio, 243 archivos / 2985 tests. Playwright CLI contra el stack local reseteado: panel «Almacenes» con los nombres neutros, alta, desactivar, borrar, almacén de sistema sin acciones, y 0 apariciones de «showroom»/«obarrio» en la pantalla de configuración.

## Sugerencias (no bloqueantes)
1. No se recorrieron con Playwright Catálogo, Inventario, hoja de picking ni Ubicaciones (solo Configuración de Bodega).
2. Los e2e renombrados no se ejecutaron (no corren en CI).
3. Un almacén desactivado conserva su nombre: no se puede reutilizar hasta renombrarlo (el nombre es único).
