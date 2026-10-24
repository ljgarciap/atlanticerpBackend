# Runbook — vincular productos migrados de ICG con sus proveedores (`legacy:vincular-productos-proveedores`)

**Para qué:** el pipeline de ICG nunca llenó `catalog_products.provider_id` (principal) ni `catalog_product_providers` (todos). Este comando los vincula desde `legacy.producto_costos` (con quién tiene costo cada artículo) y el enlace `legacy.proveedores.provider_id` → `compras.providers`. Spec: `docs/specs/icg-vinculacion-productos-proveedores.md` (aprobada por Luis 2026-10-14).

**Regla** (`ProductoProveedorResolver`): se vinculan TODOS los proveedores del artículo que estén enlazados a `compras.providers` (los demás se omiten, no se crean). Principal: el proveedor 1001 si existe; si no, el único; si hay varios, el de la compra más reciente (la misma regla que el costo); con empate o sin compras, el de menor código de ICG y el producto se lista en el dry-run para que Compras lo revise.

**Qué toca:** productos activos, ligados a ICG, no descatalogados, con `provider_id` nulo, SIN filas en la pivote y con costos de algún proveedor enlazado. Nunca pisa un vínculo existente ni borra filas de la pivote. Un producto cuyo `factory_reference` chocaría con otro del mismo proveedor (índice único parcial) se excluye y se lista.

## Pasos (dev → test → prod; en prod lo corre solo Luis)
1. Dry-run (solo lee): `docker exec infra-laravel-1 php artisan legacy:vincular-productos-proveedores --tenant=atlantic`
   Revisar los conteos y copiar la **huella**. Medido en prod 2026-10-14 (solo lectura): ~9.091 de un solo proveedor + ~249 con varios; 2.348 sin costos en ICG quedan fuera.
2. Aplicar: `... --apply --operador="<nombre>" --esperado=<huella>`. Una transacción, recalcula el plan y aborta (ROLLBACK) si la huella cambió. Deja UN evento de auditoría `legacy.vincular_productos_proveedores` con id → principal y proveedores aplicados (si el plan supera 20.000 productos NO se aplica).
3. Repetir el dry-run: debe decir "Nada que aplicar".
4. Verificar en Inventario que un producto con varios proveedores los muestra todos, con el principal primero.

## Reversa
`... --revert=<id del evento> --operador="<nombre>"`: deja `provider_id` nulo y borra de la pivote solo lo aplicado por esa corrida, y solo en los productos cuyo `provider_id` aún vale lo aplicado (los reasignados después se dejan intactos).

## Notas
- La sincronización de ICG está detenida (2026-10-14): no hay otro mecanismo que llene estos campos.
- Los empates quedan con principal determinista (menor código); Compras puede cambiarlo desde Inventario.
