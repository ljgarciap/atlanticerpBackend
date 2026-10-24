# Spec (Analista) — F5 de SCRUM-1136: artículos descatalogados de ICG con stock

- **Estado:** APROBADO por Luis 2026-10-15 (alcance y enfoque), dentro de SCRUM-1136 como fase F5.
- **Contexto:** `legacy.productos` solo contiene artículos activos (`ARTICULOS.DESCATALOGADO='F'`) y el sync de productos de
  ICG ya está apagado. Los artículos descatalogados que todavía tienen stock físico asumido (decisión de Luis: el stock de
  ICG se asume real y se ajusta con el inventario físico) no llegarían nunca a AtlanticERP. Sin ellos la migración de stock quedaría
  incompleta. Diseño: sección 11 de `docs/architecture/icg-staging-almacenes-diseno.md`.

## 1. Datos medidos (ICG, 2026-10-15, solo lectura)
- 73 341 artículos; 60 985 descatalogados (`DESCATALOGADO='T'`).
- **95 artículos descatalogados con `STOCKS.STOCK <> 0` en algún almacén** (65 con stock positivo), en 7 almacenes:
  AL4 44, A1 25, ALM 18, AL1 16, ALX 10, AL2 8, ALY 6. Los 95 tienen filas en `PRECIOSVENTA`.
- De esos 95: **10** comparten `REFPROVEEDOR` con un artículo activo ya presente en `legacy.productos` y en
  `catalog_products` (activos); **84** no existen en `catalog_products`; **1** no tiene referencia en ICG.
- Los 8 de AL2 (28 u) son los que explican la diferencia contra el Excel de inventario del 13-oct.

## 2. Requisitos
- **RF1 — Staging.** Cargar en `legacy.productos` (con `descatalogado = true`), `legacy.producto_precios` y
  `legacy.producto_costos` los artículos descatalogados con stock distinto de cero en cualquier almacén de `STOCKS`, usando
  las mismas reglas de mapeo que los activos (tarifas mapeadas, etc.). Carga única, idempotente (re-correr no duplica), con
  `--dry-run`. Sin tocar el flujo de productos activos ni su comando.
- **RF2 — Equivalencia por referencia (revisado 2026-10-15 tras Senior Review).** Para los artículos del RF1 cuya
  `REFPROVEEDOR` (recortada y comparada sin distinguir mayúsculas; debe contener algún carácter alfanumérico y no ser un
  token genérico) coincide con la de UN solo artículo activo de `legacy.productos`, registrar el vínculo hacia ese activo
  (columna nullable `equivalente_icg_codarticulo`). El descatalogado equivalente **NO recibe `catalog_product_id`** (queda
  NULL): compartirlo rompía consultas existentes (backfill de Bodega Central, cuadre de stock). El catálogo se resuelve
  siguiendo el vínculo (`COALESCE(p.catalog_product_id, e.catalog_product_id)` con LEFT JOIN al activo) en el mapeo de stock.
  Nunca crear un producto duplicado. Si hay más de un activo con la misma referencia, o el descatalogado ya apunta a otro
  catálogo, es ambiguo/conflicto: no se vincula y va a revisión manual.
- **RF3 — Productos inactivos para el resto.** Los descatalogados con stock, con referencia y sin equivalente activo, se
  crean en `ventas_diseno.catalog_products` como **inactivos** (`is_active=false`), usando el mapeo de producto existente
  (costo, precios, marca, familia), para poder asociarles stock por bodega sin que aparezcan en selectores ni cotizaciones.
  Comando separado, **dry-run por defecto**, `--apply` con `--operador=` (mismo patrón que
  `legacy:backfill-stock-bodega-central`). Idempotente. Registra `catalog_product_id` en `legacy.productos`.
- **RF4 — Sin referencia.** El descatalogado con stock sin `REFPROVEEDOR` NO se crea en el catálogo; se stagea y se lista en
  la salida del comando para revisión manual de Luis.
- **RF5 — Verificación.** Un resumen en la salida de los comandos (y/o `legacy:verify-descatalogados`) con: artículos
  esperados (95), staged, con equivalente activo, creados inactivos, sin referencia, y suma de stock por almacén de esos
  artículos en `legacy.stock_almacen` frente a ICG.
- **RF6 — Alcance de stock.** Esta fase NO escribe stock en `bodega.*` (`product_warehouse_stock`, kardex): eso es la fase de
  mapeo de stock por bodega. Aquí solo se garantiza que cada artículo con stock tenga su producto de catálogo resoluble.

## 3. Fuera de alcance
- Línea de servicio 68602 (activa, −1): se excluye del stock en la fase de mapeo, no aquí.
- Fotos, descripciones largas u otros datos no usados por el mapeo de producto existente.
- Cualquier cambio al sync de activos, al scheduler o a `legacy:sync-incremental-*`.

## 4. Criterios de aceptación
1. Dry-run del staging en dev muestra 95 artículos, 0 errores, sin escribir.
2. Tras la corrida real en dev: 95 filas nuevas en `legacy.productos` con `descatalogado=true`; sus precios y costos cargados;
   segunda corrida = 0 cambios.
3. Los 10 con referencia repetida quedan con `equivalente_icg_codarticulo` apuntando al activo, `catalog_product_id` NULL y ningún `catalog_products` nuevo.
4. Dry-run de RF3 informa 84 productos a crear; tras `--apply`, 84 `catalog_products` con `is_active=false` y 0 duplicados de
   `reference`; segunda corrida = 0 creados.
5. El descatalogado sin referencia no se crea y aparece en el listado de revisión manual.
6. Los productos creados no aparecen en listados/selectores de catálogo activos (verificado por consulta).
7. Cada uno de los 95 artículos con stock en `legacy.stock_almacen` resuelve a un `catalog_product_id` (propio o vía su equivalente activo; excepto el sin referencia). El verify sale con error si alguno no resuelve y compara el stock por almacén contra ICG.
8. Tests sin red (fixtures), PHPStan nivel 8, gate completo verde.
