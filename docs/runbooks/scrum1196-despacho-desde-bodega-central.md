# SCRUM-1196 — Despachos desde Bodega Central: puesta en marcha y datos a corregir

Reglas de Esteban Cárdenas (Líder de Bodega, 22-10-2026). Diseño: `docs/specs/scrum1196-despacho-bodega-central.md` (repo de docs).

## Qué cambia al desplegar

- Migración `2026_10_23_100000_add_sale_flags_and_order_item_allocations_to_bodega`:
  - `warehouses.permite_venta` y `despacho_por_defecto` (Central y Showroom Obarrio de venta; Central por defecto).
  - `excluye_disponible` pasa a verdadero también para **Showroom Obarrio, Showroom Cliente y Showroom SM**.
  - Tabla `order_item_allocations` (reparto de una línea entre bodegas).
- **El «Disponible» baja en todas las pantallas** para todo producto con stock en un showroom (Inventario, catálogo de cotización, Home de Compras/Bodega, órdenes de compra). Lo de Showroom Obarrio se ve aparte («En Showroom Obarrio: N»). **Avisar a Ventas antes del despliegue.**
- El picking solo reserva y alista de bodegas de venta; nunca más de lo que tiene una bodega; el despacho nunca deja una bodega en negativo.
- Los pedidos anteriores (sin filas en `order_item_allocations`) se siguen despachando por `order_items.warehouse_id`, como siempre.

Las reglas se cambian sin deploy en **Configuración de Bodega → Reglas de bodega** (superadmin).

## Orden recomendado

1. **Antes de desplegar** (solo lectura): `docker compose exec -T laravel php artisan bodega:reporte-despachos-fuera-de-central --desde=2026-10-16 --csv=/tmp/despachos-fuera-de-central.csv`
   Guarda la foto del problema: (A) despachos desde el 16 de octubre que no salieron de Central, (B) productos con alguna bodega en negativo (con Stock total y suma por bodega), (C) pedidos en vuelo con bodega no permitida.
2. Desplegar (migraciones incluidas).
3. **Pedidos en vuelo:** `php artisan bodega:reasignar-pedidos-en-vuelo` (simula) y, tras revisar, `--force`.
   Reasigna a Central solo los pedidos **en picking sin nada alistado**. Los ya alistados, en Packing o Por despachar **no se tocan**: salen listados para que Bodega decida (ya hay movimiento físico).
4. Repetir el reporte (paso 1) y entregarlo a Bodega.

## Datos de producción a corregir (los hace Bodega después de contar)

- **ML-10246ABK:** conteo físico en Central, Showroom Obarrio y Reclamos y ajuste de cada bodega a lo real; considerar la devolución de 16 unidades del 22 de octubre.
- **Otros productos:** con el reporte (B), Bodega cuenta y ajusta las bodegas en negativo. Con el reporte (A), revisar los despachos que salieron de una bodega que no era Central.
- **Carga de ICG:** confirmar si las cantidades cargadas en Reclamos (ALY) y en los showrooms existían físicamente.
- Los ajustes de inventario **nunca** se hacen desde el código del release: se usan las solicitudes de ajuste de Bodega, con su evidencia.

## Qué pueden notar los usuarios

- En la hoja de picking aparece la columna **Bodega**: la línea sale de Bodega Central con su saldo. Si se pide más de lo que hay, solo se puede alistar lo disponible y el resto queda como faltante (pendiente de compra). Si el resto está en Showroom Obarrio, «Tomar de otra bodega».
- Un despacho se bloquea con «no hay saldo suficiente» si el saldo de la bodega cambió después del picking: ajustar el inventario y reintentar.
- «Ver bodegas» muestra todas las bodegas con saldo distinto de 0, también las negativas (en rojo), y un total que coincide con el Stock total.

## Reversa

El `down()` de la migración quita las dos columnas, la tabla `order_item_allocations` y devuelve `excluye_disponible` de los showrooms a falso. Solo es seguro si esta es la **última migración aplicada** (se revierte con el mecanismo de migraciones de tenant de `infra/deploy-manual.sh`, no a mano). Las asignaciones ya creadas se pierden y los pedidos en vuelo vuelven a resolverse por `order_items.warehouse_id`. Antes de revertir, consultar con Bodega: con el comportamiento anterior volvería el problema del ticket. Comando exacto: **sin verificar** (no se probó en ningún entorno); validarlo primero en `dev`.
