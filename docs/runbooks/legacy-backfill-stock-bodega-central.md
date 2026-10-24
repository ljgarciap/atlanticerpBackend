# Runbook — `legacy:backfill-stock-bodega-central` (SCRUM-1016, épica SCRUM-749)

SCRUM-921 sembró el stock de ICG (AL4 = Bodega Central) solo en `catalog_products.stock_quantity`. Este comando lo refleja también en `product_warehouse_stock` (Bodega Central).
Decisión de Luis: los negativos se reflejan tal cual (igual que `stock_quantity`).

## Qué hace
Selecciona los `catalog_products` ligados a ICG (`legacy.productos.catalog_product_id`) con `stock_quantity` no nulo y distinto de 0 y **sin ninguna fila** en
`product_warehouse_stock` (en cualquier bodega) y crea UNA fila (producto, Bodega Central) con `quantity = stock_quantity`. No pisa nada que Bodega ya gestione, no suma sobre
filas existentes y no toca productos de Lafayette (no están ligados a `legacy.productos`). Aborta si "Bodega Central" no existe o es `solo_consulta`.
Idempotente: la segunda corrida no encuentra nada. Por defecto es DRY-RUN.

**Se EXCLUYEN** (decisión de Luis) los productos inactivos (`catalog_products.is_active = false`) y los descatalogados en ICG (`legacy.productos.descatalogado`): no se ven en Compras/Bodega
Inventario y tampoco deben aparecer en el listado por bodega. El dry-run los cuenta por separado (con sus unidades). También avisa (sin tocarlos) de los productos omitidos por ya tener
fila cuyo saldo por bodega no cuadra con `stock_quantity` (cuántos y la diferencia total), y avisa si Bodega Central está marcada `excluye_disponible` (bajaría el "Disponible" de lo sembrado).

    php artisan legacy:backfill-stock-bodega-central --tenant=atlantic                     # dry-run (solo lee)
    php artisan legacy:backfill-stock-bodega-central --tenant=atlantic --apply --operador="<nombre>"

El dry-run reporta: productos a sembrar, unidades positivas y negativas (y cuántos productos negativos), Σ de las filas nuevas (= Σ `stock_quantity` de lo seleccionado),
omitidos por ya tener fila y omitidos por stock 0/nulo. `--apply` verifica antes/después (filas de Bodega Central, ningún `stock_quantity` cambió, ninguna fila previa cambió, Σ nuevas)
y hace ROLLBACK ante cualquier discrepancia (las verificaciones están ACOTADAS a los productos seleccionados: un movimiento normal de Bodega sobre otro producto no provoca un aborto falso;
el INSERT repite los predicados de la selección); deja un evento de auditoría resumen (`legacy.backfill_stock_bodega_central`) con operador, entorno y conteos. `--lote` (500 por defecto) fija el tamaño de cada INSERT.

## Orden por entorno
1. **test (primero):** dry-run, revisar los conteos (en la medición: ~3.277 productos ICG con stock / ~85.000 u. y solo 28 filas en Bodega Central), respaldo (ver abajo), `--apply`. Avisar antes a Bodega/QA y
   correrlo en una ventana de poca actividad (si aborta con "verificación fallida", repetir el dry-run y el apply).
2. **dev:** ya tiene filas (las creó el seeder de demostración): el comando no las duplica ni las pisa (omite los productos con fila). Correr el dry-run solo para confirmar.
3. **prod:** NO se ejecuta hasta que Bodega entre en operación, junto con `legacy:backfill-stock-al4` (hoy ICG ni siquiera está sembrado en prod). Orden: primero `legacy:backfill-stock-al4`, luego este comando.

## Camino de creación
`CatalogProductLoader`, al CREAR un producto de ICG con stock inicial distinto de 0, crea en la misma transacción la fila de Bodega Central con la misma cantidad. En una
re-sincronización o actualización no la toca (Bodega manda después). Sin una "Bodega Central" operativa en el tenant, no hace nada.

## Notas
- No genera movimientos de kardex (`inventory_movements`): el stock inicial de ICG es un saldo de arranque, igual que `stock_quantity`.
- Un producto con stock 0 en ICG no recibe fila (queda sin fila hasta que Bodega lo gestione).

## Respaldo previo y reversa (B3)
Antes del `--apply`:

    pg_dump -Fc -n atlantic_bodega -f bodega-antes-scrum1016.dump "$DATABASE_URL"        # schema bodega completo (o: -t atlantic_bodega.product_warehouse_stock)

Reversa: el evento de auditoría `legacy.backfill_stock_bodega_central` guarda en `metadata` la hora del evento, la huella y `ids_sembrados` (hasta 5.000 ids; `ids_sembrados_truncado` indica si se recortó).
Las filas creadas se pueden identificar con seguridad por (bodega = Bodega Central) + (ids del evento) + `created_at` ≥ hora de la corrida y, **solo si Bodega no las movió después**, borrarlas:

    DELETE FROM atlantic_bodega.product_warehouse_stock s
    WHERE s.warehouse_id = <id de Bodega Central> AND s.catalog_product_id = ANY (<ids_sembrados del evento>)
      AND s.created_at >= '<hora de la corrida>'
      AND NOT EXISTS (SELECT 1 FROM atlantic_bodega.inventory_movements m WHERE m.catalog_product_id = s.catalog_product_id AND m.warehouse_id = s.warehouse_id AND m.created_at > s.created_at);

Si hubo movimientos posteriores sobre esas filas no hay reversa segura por SQL (borrarlas perdería lo que Bodega registró): en ese caso el respaldo (`pg_restore`) es la única reversa, con la pérdida de lo
registrado desde el respaldo.

## Consecuencias operativas (M3, asumidas por Luis)
- **Reservas:** al existir filas en Bodega Central, `StockReservationService` podrá asignar allí los pedidos en curso (antes no había nada que asignar). Es el objetivo, pero cambia el comportamiento de Bodega
  en el entorno el mismo día: avisar a Bodega/QA.
- **Negativos invisibles por bodega:** el listado por bodega, el conteo de bodegas y el filtro por bodega usan `quantity > 0`, así que los productos con saldo negativo (reflejado tal cual) no aparecen ahí aunque el
  `stock_total` del producto sí lo muestre. Habrá diferencias entre pantallas; es lo decidido.
- **Volumen:** el listado de Central pasa de ~28 a ~3.300 filas. Medido con un dataset sintético de 3.500 filas (`tests/Feature/Bodega/BodegaCentralVolumenTest.php`): listado 18 ms, con `search` 44 ms, página 20 de 100 en
  27 ms, `search` sin resultados 39 ms; memoria sin crecimiento apreciable (objetivo p95 < 500 ms y pico < 64 MB). Repetir en dev (que ya tiene ~3.368 filas) con `GET /bodega/warehouses/{Central}` antes de promover.
