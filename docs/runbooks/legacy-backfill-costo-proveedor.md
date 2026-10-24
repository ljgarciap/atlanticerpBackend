# Runbook — `legacy:backfill-costo-proveedor`

**Para qué:** rellenar `catalog_products.cost` de productos ya migrados de ICG cuyo costo no está con el proveedor por defecto (1001). El mapper
viejo solo leía 1001 y dejaba `cost` en null aunque ICG tuviera costo con otro proveedor (caso A691-451471527K: único costo con MARSET, proveedor 70).

**Regla** (`ProductoCostoResolver`, la misma que usa el sync desde ahora): 1001 si existe → si no, el único proveedor → si hay varios, el de la compra
más reciente (`albaranes_compra.fecha`, solo entre los proveedores con costo; el valor es su `costo_neto`) → empate o sin compras: se deja vacío.

**Qué toca:** productos activos, ligados a ICG, no descatalogados, con `cost IS NULL`, con costo en `producto_costos` y SIN fila del proveedor 1001.
Nunca pisa un costo existente (el UPDATE exige `cost IS NULL`). No toca: los de costo 1001 con `cost` nulo (otra causa) ni los sin costo en ICG.

## Pasos (en cada entorno: dev → test → prod; en prod solo Luis)

1. Dry-run (solo lee): `docker compose exec -T laravel php artisan legacy:backfill-costo-proveedor --tenant=atlantic`
   Revisar el conteo y copiar la **huella** que imprime. Medido en prod 2026-10-14: 1.335 de un solo proveedor + hasta 27 con varios.
2. Aplicar: `... legacy:backfill-costo-proveedor --tenant=atlantic --apply --operador="<nombre>" --esperado=<huella>`
   Una transacción, recalcula el plan y aborta (ROLLBACK) si la huella cambió. Deja el evento de auditoría `legacy.backfill_costo_proveedor`.
3. Repetir el dry-run: debe decir «Nada que aplicar» (idempotente).

## Reversa

`... legacy:backfill-costo-proveedor --tenant=atlantic --revert=<id del evento de auditoría> --operador="<nombre>"` deja en null los costos
aplicados por esa corrida que aún valgan lo aplicado (un costo editado después en Inventario no se toca). Hasta 5.000 productos por evento.

## Limitación conocida (heredada, no nueva)

La garantía «nunca pisa un costo existente» es de ESTE comando. El sync incremental (`CatalogProductLoader`) sí reescribe `cost` de los productos que él
creó cada vez que el artículo cambia en staging, incluido un costo editado a mano en Inventario (y lo deja en null si el resolver no resuelve). Era así
antes de este cambio; si se quiere proteger la edición manual es una decisión de negocio aparte.

## Pendientes fuera de este comando

- 2.348 productos activos sin costo tampoco en ICG: dato por cargar en ICG o en Inventario.
- 23 con costo 1001 en ICG pero `cost` nulo en AtlanticERP (sync desfasado o costo borrado): ticket de investigación aparte.
- Productos con varios proveedores sin compras o con empate: quedan vacíos; el dry-run imprime sus ids para que Compras los revise.
