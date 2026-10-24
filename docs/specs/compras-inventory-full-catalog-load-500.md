# Spec: `GET /api/compras/inventory` 500 intermitente — carga completa del catálogo en memoria

**Date**: 2026-09-28
**Requested by**: Luis (alarma `atlanticerp-dev-nginx-5xx-high` recurrente, 2026-09-28 23:11 UTC)
**Ticket**: SCRUM-993
**Status**: APROBADA por Luis 2026-09-28, lista para PM (sin implementar)
**Project**: AtlanticERP — `atlanticerp-backend`, módulo `App\Modules\Compras`
**Relacionada**: `bodega-inventory-full-catalog-load-500.md` (mismo patrón, ya corregido en `8c73828`) y
`catalog-search-performance-trgm.md` (búsqueda/autocomplete, misma familia)

## Problem
`GET /api/compras/inventory` responde 500 de forma repetida en `dev.atlanticerp.ai` cuando alguien abre la
pantalla `/inventario` de Compras. Alarma CloudWatch `atlanticerp-dev-nginx-5xx-high` (>=5 5xx en 5 min).
En el access log de nginx de dev, del 2026-09-28 22:31→23:16 UTC: 20+ respuestas 500 de 5 bytes, todas
a esta ruta (con y sin `?page=1&per_page=20`), desde la misma IP/navegador.

## Root cause (medido en dev; el fallo exacto de PHP no está capturado)
`InventoryController::index()` (`app/Modules/Compras/Http/Controllers/InventoryController.php:200-215`)
carga **todo** el catálogo activo con 2 relaciones eager, sin importar `per_page`:
```php
$products = CatalogProduct::query()->with(['families', 'provider'])
    ->when(...)->when(...)->get()->all();
```
Después aplica en PHP el fuzzy search, los chips (`por_ingresar`, `en_atencion`, `bajo_stock`,
`sin_stock`, `bajo_stock_sin_ordenar`) y el orden (`usort`), y solo al final `Pagination::ofArray()`
corta la página. Además construye 4 mapas por ID (`porServirMap`, `porIngresarMap`, `enCaminoMap`,
`enBodegaExcluidaMap`) para el universo completo.

Medición en dev (tinker, `atlantic_ventas_diseno.catalog_products`): **29,635 productos**
(25,8xx son el catálogo de Lafayette). Solo la línea de carga + relaciones consume **134 MB de pico**;
el `memory_limit` de PHP-FPM es **256 MB**, y todavía faltan los 4 mapas, el `usort` y el JSON.

**Por qué no deja rastro**: `log_errors=Off` en PHP y ningún `ERROR` en `laravel.log` para la ventana
(0 el 2026-09-28) — un agotamiento de memoria no pasa por el exception handler de Laravel. Es la misma
firma ya documentada en la spec de Bodega. Contenedor sin OOM-kill (`OOMKilled=false`, 0 reinicios),
así que el límite que se alcanza es el de PHP, no el del SO.

**Por qué el fix anterior no lo cubrió**: `8c73828`/`3be04fb` corrigieron `BodegaInventoryController`
(`/api/bodega/inventory`). Este es otro controlador, con el mismo patrón. La spec de Bodega lo anotó
como "fuera de alcance por ahora" suponiendo que tenía "paginación en SQL" — el código real no la tiene.

## Diseño técnico (Arquitecto, 2026-09-28)
**Hallazgo adicional**: `InventoryKpiService::kpis()` (llamado en cada respuesta de `index()`) y
`lowStockWithoutOpenOrderCount()` (tarjeta "Salud · Compras" de Gerencia) también hacen
`CatalogProduct::where('is_active', true)->get()` completo. `index()` hidrata el catálogo **dos veces**
por request, y Gerencia tiene el mismo riesgo. Entran en el alcance de este ticket.

**Enfoque (A, recomendado): las agregaciones por producto pasan a subconsultas SQL, no a mapas en PHP.**
- `porServir`, `enExcluida`, `porIngresar` y `enCamino` son agregados por `catalog_product_id`. Se
  exponen como un scope `CatalogProduct::scopeWithInventoryAggregates()` que une subconsultas
  agrupadas (`LEFT JOIN (SELECT catalog_product_id, SUM(quantity) ... GROUP BY 1) x`), con
  `COALESCE(x.total, 0)`. Sin `whereIn` de decenas de miles de IDs.
- `enCamino` **no** es un `SUM` simple: hoy es `max(0, esperado - registrado)` por par
  (orden, producto), sumado por producto. En SQL: subconsulta por (orden, producto) con
  `GREATEST(0, esperado - registrado)` y luego `SUM` por producto, excluyendo órdenes en
  `STATUS_RECIBIDO`. Es la pieza de mayor riesgo; lleva su propio test de paridad.
- `disponible = stock_quantity - por_servir - en_excluida` y los umbrales (`<= 0` sin stock,
  `<= reorder_point` bajo stock, excepción de rotación `baja` en "en atención", `en_camino <= 0`)
  se expresan en **un solo lugar**: constantes/expresiones SQL en `InventoryKpiService`
  (`disponibleSql()`, `scopeChip()`). Los chips, `kpis()` y `lowStockWithoutOpenOrderCount()` usan esas
  mismas expresiones.
- **Una sola fuente de la regla**: `disponibleParaVenta()`/`stockState()`/`isInAttention()` en PHP se
  conservan para uso por producto (`show()`, `PurchaseOrderController::replaceLines()`), pero un test de
  **paridad** obligatorio recorre un dataset con todos los bordes (disponible = 0, = reorder_point,
  rotación baja/null, excluida > 0, orden recibida/no recibida) y falla si SQL y PHP difieren.
- `index()`: `select` de columnas de la tabla + agregados, `WHERE` de chips/filtros, `ORDER BY` con
  desempate por `id`, `LIMIT/OFFSET`, y **solo entonces** `with(['families','provider'])` para las filas
  de la página (`->load()`). `warehousesMap()` ya se calcula solo para la página.
- `kpis()`: un solo `SELECT` con `COUNT(*) FILTER (WHERE ...)` y `SUM(price_full * stock_quantity)`,
  sin hidratar modelos. Devuelve el mismo shape.
- Búsqueda: mientras no exista `scopeSearch()` (SCRUM-992), el fuzzy actual se aplica sobre una
  proyección liviana (`id, reference, name`, sin modelos ni relaciones) y luego se filtra por IDs. Cuando
  llegue SCRUM-992, se reemplaza por el scope.

**Alternativa descartada (B)**: proyección liviana de 4 columnas + los mismos mapas, evaluando la regla en
PHP. Menos riesgo de divergencia de reglas, pero sigue siendo O(N) por request, con un `whereIn` de ~30K
IDs y `enCaminoMap` hidratando modelos; no cumple el criterio de < 1 s de forma sostenible.

**Orden de implementación**: (1) scope de agregados + test de paridad, (2) `kpis()` y
`lowStockWithoutOpenOrderCount()`, (3) `index()`, (4) test de regresión de memoria, (5) verificación en
dev con el catálogo completo. Un solo Senior Review sobre la rama completa; Pre-QA con los 7 chips y los
5 órdenes sobre 30K productos reales.

## No es causa (descartado)
- El deploy de SCRUM-987 (23:03 UTC): el primer 500 es de las 22:31, antes.
- Las pruebas contra MSL: se hicieron por tinker, no pasan por nginx.

## Acceptance criteria
1. `GET /api/compras/inventory?page=1&per_page=20` con ~30K productos responde 200 en < 1 s y con
   pico de memoria de PHP < 64 MB (medido con `memory_get_peak_usage`).
2. Filtrado, orden y paginación se hacen en SQL (`LIMIT/OFFSET` + `ORDER BY`), nunca sobre un array
   del catálogo completo. Las relaciones `families`/`provider` se cargan solo para las filas de la página.
3. Los chips (`todos`, `inactivos`, `por_ingresar`, `en_atencion`, `bajo_stock`, `sin_stock`,
   `bajo_stock_sin_ordenar`) devuelven exactamente el mismo conjunto de productos que hoy — verificado
   con test de paridad contra la implementación actual sobre un dataset de prueba.
4. Los orden permitidos (`ALLOWED_SORTS`), incluidos `price_full` y `stock_quantity`, mantienen su
   semántica; el orden es estable (desempate por `id`).
5. `meta` de paginación (`total`, `last_page`, etc.) sigue siendo correcto con filtros aplicados.
6. `kpis()` no recorre el catálogo completo en PHP (revisar; si lo hace, se resuelve con agregados SQL).
7. Test de regresión con >= 5,000 productos que falla si alguien reintroduce `->get()->all()` sobre
   el catálogo en este endpoint (aserción de memoria o de número de modelos hidratados).
8. Verificado en vivo en dev, test y prod tras el deploy, sin 5xx en `/api/compras/inventory` durante
   al menos 30 min de uso real, y la alarma `*-nginx-5xx-high` sin dispararse por esta ruta.

## Riesgos / consideraciones
- Los chips `en_atencion`, `bajo_stock`, `sin_stock` y `bajo_stock_sin_ordenar` dependen de
  `InventoryKpiService::stockState()/isInAttention()`, que hoy se evalúan por producto en PHP con los
  mapas por ID. Pasarlos a SQL exige reexpresar esa regla como consulta (subqueries/CTE) sin duplicar
  la definición: **debe seguir habiendo una sola fuente de la regla** (hoy en `InventoryKpiService`).
  El Arquitecto define si se hace con un scope SQL en el servicio o con un pre-filtro por IDs
  paginado. No se cambia la regla de negocio.
- El fuzzy search (Levenshtein en PHP) no es paginable en SQL; su reemplazo se define en
  `catalog-search-performance-trgm.md`. Hasta entonces, este endpoint puede aplicar el fuzzy solo
  sobre IDs livianos (`select id, reference, name`), sin hidratar modelos ni relaciones.
- Las columnas ordenables calculadas (`stock_quantity`) deben existir en SQL; confirmar que no son
  accessors de Eloquent.
- Auditar en el mismo pase los demás `->get()->all()` sobre `CatalogProduct` del módulo (ver lista en
  la spec de búsqueda) — no parche por parche.

## Decisiones pendientes de Luis
- Ninguna de negocio. Aprobada 2026-09-28. Prioridad: **alta** — el endpoint está caído para cualquier usuario con el
  catálogo completo cargado en dev, y test/prod tienen el mismo volumen (a verificar).

## Fuera de alcance
- Autocomplete/búsqueda tolerante a errores (spec aparte).
- Cambios de UI.
