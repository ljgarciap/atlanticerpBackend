# Runbook — carga única del stock de ICG a las bodegas de AtlanticERP (SCRUM-1136 F6)

ICG está congelado (último movimiento 2026-10-09, sync apagado). Esta carga pasa `legacy.stock_almacen` a `product_warehouse_stock` + Kardex en las bodegas equivalentes de los almacenes
confirmados (AL2→Showroom Obarrio, B1→Showroom Cliente, B2→Showroom SM, ALX→Merma, ALY→Reclamos y Devoluciones, ALR→Reserva Servicios). Bodega Central (AL4) NO se toca: ya se cargó
(SCRUM-1016/1114). A1, ALM, AL1 y AL3 quedan fuera (sin equivalente, segunda pasada). Spec: `docs/specs/icg-stock-a-bodega-f6.md`; diseño: `docs/architecture/icg-stock-a-bodega-f6-diseno.md`.

## Qué hace cada comando (todos con `--tenant=atlantic`; dry-run por defecto; `--apply` exige `--operador="<nombre>"`)
| Comando | Efecto |
|---|---|
| `php artisan db:seed --class=AlmacenEquivalenciaSeeder --force` | Siembra `legacy.almacen_equivalencias` (confirmadas) resolviendo la bodega por NOMBRE. Falla sin escribir nada si falta o es ambigua una bodega. Solo-si-no-existe. No está en `DatabaseSeeder`. |
| `legacy:crear-articulos-sin-catalogo [--almacen=B2]` | Crea INACTIVOS en `catalog_products` los activos de ICG con stock en un almacén confirmado y sin catálogo (42 de B2 en dev). No escribe stock. Descatalogados sin catálogo siguen siendo de F5 (`legacy:crear-descatalogados-inactivos`). |
| `legacy:cargar-stock-bodegas [--almacen=AL2] [--lote=500]` | Siembra stock + Kardex (`Ajuste`, `saldo_inicial 0`) + delta del agregado `catalog_products.stock_quantity`. Una transacción por almacén, ROLLBACK ante cualquier discrepancia. |
| `legacy:verify-stock-bodegas` | Solo lectura: esperado vs `product_warehouse_stock` por bodega; sale con 1 si hay diferencia. |
| `legacy:revertir-stock-bodegas [--almacen=AL2]` | **Destructivo.** Borra filas de stock y líneas de Kardex de la carga que Bodega NO movió después, y resta el delta del agregado. |

Reglas de la carga: cantidad = Σ `stock` por producto de catálogo, redondeada (mitad lejos de cero); negativos tal cual; excluidos `2999` y `68602`
(`config('legacy.carga_stock_excluir_codarticulos')`, env `LEGACY_CARGA_STOCK_EXCLUIR`); stock 0/nulo y `CODALMACEN` vacío no se cargan; nunca pisa una fila existente; aborta si el destino es Bodega Central
o `solo_consulta`. El descatalogado equivalente (F5) suma sobre el producto del activo. Artículos que no resuelven a catálogo se LISTAN y no se cargan.

## Orden por entorno (dev → test → prod, un entorno a la vez; el `--apply` lo corre Luis)
1. Backup (prod obligatorio; ver abajo). Avisar a Bodega/QA y a Daniela/Mark: el valor a costo fuera de Bodega Central (~1,1 M) aparecerá en reportes de inventario/Gerencia.
2. `db:seed --class=AlmacenEquivalenciaSeeder --force`.
3. F5 si el entorno aún no lo tiene (`legacy:crear-descatalogados-inactivos`) y `legacy:crear-articulos-sin-catalogo` (dry-run → revisión → `--apply`).
4. `legacy:cargar-stock-bodegas` dry-run → revisión de Luis (filas, unidades, valor, sin catálogo, excluidos) → `--apply --operador="…"`.
5. `legacy:verify-stock-bodegas` = OK y cotejo contra el Excel del 13-oct (AL2/B1/B2).
Las corridas largas por ssh se lanzan con `nohup` y se sondean (no atadas al terminal).

## Consecuencias operativas
- **Agregado:** cada fila sembrada suma su cantidad a `catalog_products.stock_quantity` (mismo delta que `KardexService::registrar()`, SCRUM-429), porque el «Disponible» = agregado − lo que hay en bodegas `excluye_disponible`: sin esa suma Merma/Reclamos/Reserva dejarían el disponible descuadrado.
- **Negativos:** invisibles en los listados por bodega (filtran `quantity > 0`), igual que SCRUM-1016.
- **Inactivos:** el stock de los productos inactivos (F5 + B2) existe pero ningún listado lo muestra (ticket aparte: filtro «con stock»).
- **Auditoría:** un evento resumen por almacén (`legacy.cargar_stock_bodegas`); la carga no genera una fila de `audit_logs` por producto.

## Reversa
Backup previo del schema de Bodega (y de `catalog_products` por el agregado):

    pg_dump -Fc -n atlantic_bodega -f bodega-antes-scrum1136-f6.dump "$DATABASE_URL"
    pg_dump -Fc -t atlantic_ventas_diseno.catalog_products -f catalog-products-antes-scrum1136-f6.dump "$DATABASE_URL"

`legacy:revertir-stock-bodegas` (dry-run → `--apply --operador=`) identifica la carga por las líneas de Kardex `referencia_entidad_tipo = 'icg_carga_inicial'` con `saldo_inicial = 0`. Solo revierte filas cuyo
`quantity` sigue igual a `saldo_resultante` y sin ninguna línea de Kardex posterior; el resto se reporta como «NO se revierten». **No borra los productos inactivos creados**: si hay que deshacerlos, se
identifican con el evento `legacy.crear_articulos_sin_catalogo` (`ids_creados`) y es un paso aparte con confirmación de Luis. Si Bodega ya movió lo cargado, la única reversa completa es `pg_restore`
con la pérdida de lo registrado desde el respaldo.
