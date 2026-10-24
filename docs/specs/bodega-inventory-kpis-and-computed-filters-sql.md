# Spec: Inventario de Bodega — KPIs y filtros calculados en SQL (500 en `chip=criticos` / `estado=sin_stock` y lentitud general)

**Date**: 2026-09-29
**Ticket**: SCRUM-994
**Requested by**: Luis (continuación de SCRUM-993, misma familia de problema)
**Status**: APROBADA por Luis 2026-09-29, lista para PM (sin implementar)
**Project**: AtlanticERP — `atlanticerp-backend`, módulo `App\Modules\Bodega`
**Relacionadas**: `bodega-inventory-full-catalog-load-500.md` (fix parcial `8c73828`),
`compras-inventory-full-catalog-load-500.md` (SCRUM-993, mismo enfoque), `catalog-search-performance-trgm.md` (SCRUM-992)

## Problem
`GET /api/bodega/inventory` sigue hidratando el catálogo activo completo (~29.630 productos) en cada petición,
y dos filtros de la pantalla **ya están rotos** con el catálogo de Lafayette:

| Petición (dev, catálogo real) | Resultado medido |
|---|---|
| `GET /api/bodega/inventory?page=1&per_page=20&chip=criticos` | **HTTP 500 en 4,8 s** (por nginx) |
| `GET /api/bodega/inventory?page=1&per_page=20&estado=sin_stock` | **HTTP 500 en 4,9 s** (por nginx) |
| `chip=criticos` por el kernel (CLI, mismo `memory_limit`) | `Allowed memory size of 268435456 bytes exhausted` |
| sin filtros | 1,5–2,1 s, +164 MB de pico |
| `chip=por_ingresar` / `chip=en_atencion` | 3,0–3,6 s, +162–164 MB (funcionan hoy, con margen mínimo) |
| Pre-QA (SCRUM-992 ronda 4), Bodega con búsqueda de typos | p95 1.996 ms en proceso / 2.520 ms por nginx |

`chip=criticos` es el destino del enlace "Artículos críticos" del Home de Bodega (REQ-362 RN1 / SCRUM-432), y
`estado=sin_stock` es un filtro de la propia pantalla: hoy dan 500 para cualquier usuario. Test y prod comparten
código y volumen de catálogo (Lafayette promovido el 2026-09-27): **no verificado**, pero es esperable el mismo fallo.
Mismo síntoma mudo que SCRUM-993: `log_errors=Off`, sin `ERROR` en `laravel.log`, cuerpo de 321 bytes.

## Root cause (medido en dev; el desglose por costo es de una sola corrida)
1. **`BodegaInventoryKpiService::kpis()`** (`app/Modules/Bodega/Services/BodegaInventoryKpiService.php:62-100`)
   hace `CatalogProduct::where('is_active', true)->get()` completo y evalúa `stockState()`/`isInAttentionBodega()` en PHP
   producto por producto, con `committedMap()` y `enBodegaExcluidaMap()` sobre ~30K IDs. Solo esto: **1.601 ms y +163 MB**.
   Se llama en **cada** respuesta de `index()` (línea 304) y también desde
   `BodegaReportsService::inventorySummary()` (REQ-424 RN1, que reutiliza `kpis()` "byte a byte" para que coincida con la pantalla).
2. **Rama de "filtros calculados" de `index()`** (`BodegaInventoryController.php:150-260`): los chips `por_ingresar`, `en_atencion`,
   `criticos` y el filtro `estado` (`disponible`/`bajo_stock`/`sin_stock`) cargan **todo el conjunto candidato** con
   `(clone $baseQuery)->get()->all()`, calculan 4 mapas por ID sobre ese conjunto, filtran con `array_filter` y ordenan con `usort`
   en PHP. El comentario del fix `8c73828` afirma que esto "nunca fue el costo real, son arrays por ID": **es falso** para
   `criticos` y `estado=bajo/sin_stock`, donde quedan ~26.285 modelos vivos en memoria.
3. **Suma de picos**: en esa rama `$products` (~26K modelos) sigue referenciado cuando, al armar la respuesta, se ejecuta
   `kpis()` (+163 MB). Los dos picos se suman por encima de los 256 MB de PHP-FPM → fatal → 500 mudo. Con `por_ingresar`/
   `en_atencion` sobreviven solo porque el filtro deja 5 y 21 productos.
4. Menor: con `warehouse_id`, `pluck()` del stock de esa bodega + `whereIn('id', array_keys(...))` con hasta decenas de miles
   de IDs (sin medir aparte).
   Las facetas de proveedores y marcas **no** son problema (`distinctActiveProviders` 16 ms / `distinctActiveBrands` 11 ms).

## Contexto: por qué el fix de SCRUM-993 no lo cubrió
SCRUM-993 corrigió el `InventoryController` de **Compras** y `InventoryKpiService`. Bodega usa otra definición de "por servir"
(`OrderCommitmentService::committedMap()`, compromiso a **pedidos de Bodega** en curso, REQ-351 RN1) y otro criterio de
"En atención" (allowlist de REQ-346 RN1, distinto del blocklist de Compras REQ-169), así que nunca compartieron código
con lo que se migró. Las piezas SQL de SCRUM-993 sí se pueden reutilizar (ver Diseño).

## Diseño técnico (propuesta del Arquitecto, a validar con Luis)
Mismo enfoque de SCRUM-993: **agregados por producto como subconsultas SQL, filtros/orden/paginación en SQL, relaciones solo
para la página, una sola fuente de cada regla, con test de paridad SQL vs PHP.**

1. **Compromiso de Bodega como agregado SQL.** `OrderCommitmentService::committedSub()` ya existe (SCRUM-992 H1: suma de
   `GREATEST(0, qty_requested - qty_delivered)` por producto en etapas activas, con `CatalogControllerTest` como paridad).
   Se une con `leftJoinSub` como agregado "por servir" de Bodega. Las expresiones de `InventoryKpiService` (`sqlDisponible()`,
   `sqlSinStock()`, `sqlBajoStock()`) hoy fijan el alias `inv_ps`; se parametrizan por alias (o el subquery de Bodega se une
   con ese alias) **sin duplicar la regla** `disponible = stock - por_servir - en_excluida`.
2. **Regla propia de Bodega en un solo lugar.** `sqlEnAtencionBodega()` (allowlist REQ-346 RN1): `rotation IN ('alta','media')
   AND disponible <= COALESCE(reorder_point, 0)`; `rotation` NULL **no** cuenta. Vive en `BodegaInventoryKpiService` junto a
   `isInAttentionBodega()`, que se conserva para uso por producto. **No** se unifica con la de Compras (decisión previa de Luis).
3. **`kpis()` en un solo `SELECT`** con `COUNT(*) FILTER (...)` (total, bajo stock, sin stock, en atención) y
   `SUM(committed)` **solo de productos activos** (hoy `total_por_servir` suma el compromiso de los activos; un producto inactivo
   con pedidos no cuenta). Mismo shape de retorno. `BodegaReportsService::inventorySummary()` sigue reutilizando `kpis()` sin cambios
   (REQ-424 RN1 se mantiene por construcción; se agrega un test que lo verifique).
4. **`index()`**: los chips `por_ingresar`/`en_atencion`/`criticos` y el filtro `estado` pasan a `WHERE` sobre las mismas expresiones;
   `criticos` = `bajo_stock OR sin_stock`; `estado=todos`/vacío no filtra. Se elimina la rama PHP (`array_filter` + `usort` +
   `Pagination::ofArray`): una sola ruta `paginate()` con `ORDER BY` de `ALLOWED_SORTS = reference|name|stock_quantity|created_at`,
   desempate por `id`, y `with(['families','provider'])` solo sobre la página. Los mapas de compromiso/por ingresar/en camino/
   en excluida de `format()` ya se calculan por los IDs de la página (se conserva).
5. **Orden de texto**: hoy el camino común usa `LOWER(col) dir` (colación de la base) y la rama calculada `strcasecmp` (byte a byte):
   dos comportamientos distintos según el filtro. Al unificar queda **el del camino común** (`LOWER(col)`), que es lo que ya ve
   el usuario en la carga normal. Cambio de comportamiento menor solo para quien ordena por nombre/referencia con un chip calculado.
6. **`warehouse_id`**: subconsulta (`whereIn('id', SELECT catalog_product_id FROM product_warehouse_stock WHERE warehouse_id = ? AND quantity > 0)`)
   en vez de `pluck` + lista de IDs; la cantidad por bodega (`$warehouseScopedQtyMap`) solo para los IDs de la página.
7. **Búsqueda** (SCRUM-992): sin cambios; ya es SQL. El respaldo de typos sigue igual.

**Alternativa descartada**: recortar `$products` con `unset` antes de `kpis()` como parche. Baja el pico, pero sigue siendo O(N)
en PHP (3–5 s) y deja `criticos`/`estado` a un catálogo mayor de reventar de nuevo.

**Mitigación inmediata opcional (decisión de Luis)**: si `criticos`/`estado=sin_stock` están rotos también en test/prod y no se
puede esperar al fix completo, un parche de una línea (`unset($products)` tras paginar y antes de `kpis()`) debería evitar el
fatal. No lo recomiendo salvo confirmar que test/prod fallan; no está implementado.

## Acceptance criteria
1. `GET /api/bodega/inventory?page=1&per_page=20` responde **200** para **todas** las combinaciones de `chip`
   (`todos`, `inactivos`, `rotacion`, `por_ingresar`, `en_atencion`, `criticos`), `estado` (`todos`, `disponible`, `bajo_stock`,
   `sin_stock`), orden (4 campos × asc/desc), `search`, `rotation`, `provider_id`, `brand`, `family_id` y `warehouse_id`, con ~30K
   productos, con **p95 < 500 ms por HTTP** y pico de memoria < 64 MB (medido con `memory_get_peak_usage`).
2. `chip=criticos` y `estado=sin_stock` no dan 500 (hoy sí) con el catálogo completo en dev, test y prod.
3. **Paridad exacta**: para cada chip y estado, el conjunto de productos (todas las páginas) es idéntico al de la implementación actual
   y al cálculo PHP por producto (`stockState()`, `isInAttentionBodega()`), sobre un dataset con todos los bordes: `disponible` = 0,
   = `reorder_point`, `reorder_point` NULL, rotación alta/media/baja/NULL, `stock_quantity` NULL, bodega excluida > 0, pedidos en
   etapas activas e inactivas, `qty_delivered` > `qty_requested`, producto inactivo con pedidos.
4. `kpis()` devuelve los mismos 5 números que la implementación actual (`total_products`, `low_stock`, `out_of_stock`,
   `in_attention`, `total_por_servir`) sobre ese dataset, y `BodegaReportsService::inventorySummary()` sigue coincidiendo exacto con
   `kpis()` (REQ-424 RN1).
5. Ningún camino de `index()` hidrata más modelos `CatalogProduct` que la página pedida (test de regresión que falla si vuelve
   `->get()->all()` sobre el catálogo, y test de memoria con >= 5.000 productos).
6. El shape de la respuesta no cambia (`fuzzy`, `kpis`, `providers`, `brands`, `data[]` con todos sus campos, `meta`).
7. Paginación estable: recorrer todas las páginas de cada chip da exactamente el conjunto, sin repetidos ni faltantes (desempate por `id`).
8. `kpis()` no hidrata modelos (una sola consulta agregada).
9. Verificado en vivo en dev, test y prod tras el deploy: sin 5xx en `/api/bodega/inventory` durante 30 min de uso real y la alarma
   `*-nginx-5xx-high` sin dispararse por esta ruta.

## Riesgos / consideraciones
- **Dos definiciones de "por servir" y dos de "En atención"** (Compras vs Bodega): cualquier reuso mal alias-eado mezclaría reglas.
  Mitigación: parametrizar por alias, una sola fuente por regla y el test de paridad contra la lógica PHP actual de Bodega.
- `total_por_servir` suma solo activos: fácil de perder al pasarlo a SQL (criterio 3/4 lo cubre).
- El orden por texto cambia de `strcasecmp` a `LOWER()` con un chip calculado (punto 5); Pre-QA debe probar orden por `name`
  con acentos y mayúsculas.
- Rollout: mismas migraciones no aplican (sin cambios de esquema); el riesgo es de regresión funcional en una pantalla con
  varios consumidores (Home de Bodega, Reportes de Bodega, deep-links). Pre-QA debe recorrer cada entrada a la pantalla.
- `BodegaInventoryController::families()`/`familyDetail()` y `show()` no se tocan (no cargan el catálogo completo).

## Otros sitios que aún hidratan el catálogo activo (fuera de alcance, requieren su propio ticket)
Hallados con un grep del 2026-09-29, **sin medir ni confirmar impacto**:
- `Bodega\AdjustmentRequestController.php:84` (`CatalogProduct::where('is_active', true)->get()->all()` + fuzzy) — Fase 2 de SCRUM-992.
- `Bodega\BodegaHomeService::articulosCriticos()` (`:637-651`, carga productos activos con filtro y calcula `porServirMap`/`enExcluidaMap`).
- `Compras\CatalogProductSearchController.php:94` ("Buscar Sustituto" sin `search`, hallazgo H4 de Pre-QA, aceptado por el Senior).
- `VentasDiseno\CatalogPdfService.php:85` (`->get()->all()`, generación de PDF de catálogo).
Se recomienda una auditoría única de esta familia (un ticket, medición en dev de cada endpoint) en vez de seguir descubriéndolos uno a uno.

## Decisiones de Luis (2026-09-29, resueltas)
1. **Urgencia**: directo al fix completo, sin la mitigación de una línea y sin verificar antes test/prod (se verifican al desplegar).
2. **Orden con chips calculados**: se unifica en el comportamiento del camino común (`LOWER(col)`, punto 5 del diseño).
3. **Auditoría de la familia**: se abre ya el ticket aparte (SCRUM-995, ver Jira); no entra en este alcance.
4. Prioridad de SCRUM-994 subida a Highest.

## Fuera de alcance
- Cambios de UI, del contrato de la API o de reglas de negocio (umbrales, "En atención").
- Las facetas de proveedores y marcas (ya rápidas).
- Los demás sitios de la sección anterior.
