# Senior Review — `legacy:vincular-productos-proveedores` (2026-10-14)

**Alcance:** `LegacyVincularProductosProveedoresCommand`, `ProductoProveedorResolver`, constante de auditoría, 12 pruebas, runbook. Spec aprobada por Luis el 2026-10-14 (empates por menor código). **Veredicto: APROBADO** (1 hallazgo corregido durante la revisión, 2 observaciones sin acción).

## Verificado
- Misma estructura y garantías que `legacy:backfill-costo-proveedor` (ya en prod): dry-run por defecto, `--apply` exige `--operador` y `--esperado`, una transacción que recalcula el plan y aborta si la huella cambió o si algún UPDATE no afecta exactamente una fila, un evento de auditoría, reversa por evento.
- Nunca pisa: `provider_id IS NULL` en el UPDATE y selección sin filas previas en la pivote; no borra filas de la pivote al aplicar. La reversa solo actúa si `provider_id` aún vale lo aplicado y borra de la pivote solo lo aplicado.
- El principal usa la misma regla que el costo (1001, único, compra más reciente) con el desempate pedido por Luis; los empates se listan.
- SQL de selección con nombres de tabla del modelo (tenant-aware) y valores por parámetros; sin entradas del usuario interpoladas.
- PHPStan nivel 8 sin errores; 12 pruebas propias y las 32 del backfill de costos y del mapper en verde.

## Hallazgo corregido
1. **Choque de `factory_reference` dentro de la misma corrida:** dos productos de la corrida con el mismo `factory_reference` y el mismo proveedor principal pasaban la comprobación contra la BD (ninguno estaba aún vinculado) y rompían el índice único parcial al escribir, abortando toda la corrida. Ahora el plan lleva un registro de claves usadas y excluye al segundo (prueba `test_dos_productos_de_la_misma_corrida...`).

## Observaciones (sin acción)
- El evento de auditoría guarda id → proveedores de todos los productos (~9.300 en prod, unos cientos de KB de JSON). Si el plan supera 20.000 productos el comando se niega a aplicar para que la reversa nunca quede incompleta.
- Medido en prod (solo lectura): ningún proveedor de ICG enlazado está inactivo en `compras.providers`, ningún `compras.providers` está enlazado desde dos códigos de ICG, y hay 28 productos activos sin proveedor con `factory_reference` (el dry-run revisa su choque).
