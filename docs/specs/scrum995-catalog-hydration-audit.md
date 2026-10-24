# Auditoría SCRUM-995 — sitios que hidratan el catálogo activo completo (~30K productos)

**Date**: 2026-09-29
**Ticket**: SCRUM-995 (épica SCRUM-959)
**Status**: FASE 1 (medición y clasificación) COMPLETA — fixes SIN implementar, pendientes de decisión de Luis
**Relacionadas**: SCRUM-992 (búsqueda `search_text`/trigram), SCRUM-993 (Compras), SCRUM-994 (Bodega KPIs y chips), `bodega-inventory-kpis-and-computed-filters-sql.md`

## Método

- Inventario por código: grep de `CatalogProduct::` sin límite y de las llamadas a los helpers por ID
  (`porServirMap`, `enBodegaExcluidaMap`, `committedMap`, `porIngresarMap`, `enCaminoMap`) en `app/`.
  Se leyó cada sitio para separar los que ya están acotados (SQL o `limit`) de los que hidratan todo.
- Medición en **dev** con el catálogo real (29.630 activos), por el kernel HTTP en un proceso PHP propio por caso,
  con `memory_limit=256M` (el real de PHP-FPM, `infra/php/memory.ini`). "Pico extra" = `memory_get_peak_usage`
  durante la petición menos el uso al empezar (no incluye el uso base del framework, ~30-40 MB). 2 repeticiones,
  se informa el máximo. Un `FATAL` significa que la petición murió por memoria (en FPM: 500 opaco, sin traza).
- **No medido en test ni prod.** Mismo código y volumen de catálogo (test 29.555, prod 29.506 activos; Bodega Zona Libre
  7.969 y 8.017 filas, dev 7.976), así que se espera el mismo comportamiento. El proveedor Lafayette (25.8K productos)
  tiene id 187 en dev, 125 en test y 230 en prod.
- Control: `GET /api/bodega/inventory?chip=criticos` (corregido en SCRUM-994) da 213 ms y 3,8 MB con el mismo método.

## Resultados (dev, catálogo real)

| # | Sitio (ruta) | Caso | Tiempo | Pico extra | Estado |
|---|---|---|---|---|---|
| E | `POST /api/ventas-diseno/catalog/send-pdf` (`CatalogPdfService::resolveProducts`, `mode=completo`) | generar el PDF | — | **FATAL a 242 MB** (`Allowed memory size exhausted`) | **ROTO HOY** |
| E | ídem, solo hidratar + formatear 29.630 productos | antes de renderizar | 1,6 s | 173,6 MB | — |
| G | `GET /api/bodega/warehouses/7` (Zona Libre, ~8K filas) | `search=led` | 2,9 s | **200,6 MB** (78 % del límite) | casi roto |
| G | ídem | `search=zzzqqq` (sin resultados) | 3,4 s | **200,6 MB** | casi roto |
| G | ídem sin `search` / bodega 6 con `search` | paginado en SQL / bodega chica | 0,11 s / 0,35 s | 3,5 MB / 28,2 MB | OK (crece con el tamaño de la bodega) |
| B | `GET /api/servicios/lookup/products` (`ProductLookupController`) | sin parámetros | 2,0 s | **170,7 MB**, respuesta de 4 MB | casi roto |
| B | ídem | `search=led` / `search=zzzqqq` | 1,3 s / 1,6 s | **156,5 MB** | casi roto |
| A | `GET /api/bodega/adjustment-requests/products/search` (`AdjustmentRequestController::searchProducts`) | `search=led` / `lampara` | 1,0 s | **156,5 MB** | casi roto |
| A | ídem | `search=zzzqqq` | 2,2 s | **156,5 MB** | casi roto |
| F | `GET /api/compras/products` con `original_catalog_product_id` y sin `search` ("Buscar Sustituto", proveedor Lafayette) | sin búsqueda | 3,4 s | **146,6 MB**, respuesta de 2,6 MB | casi roto |
| F | ídem con `search=led` / sin sustituto | acotados (100–500 filas) | 0,17–0,23 s | 2,9–5,1 MB | OK (SCRUM-992) |
| C | `GET /api/bodega/home/summary` (`BodegaHomeService::articulosCriticos`) | cada visita al Home de Bodega | 2,2 s | **93,3 MB** | lento; además O1 |
| D | `GET /api/compras/home/summary` (`ComprasHomeService::porReordenar`) | Home de Compras | 0,34 s | 5,8 MB | OK hoy (latente) |

Sitios revisados y descartados (ya acotados o de alcance chico): `CatalogProductController`, `CatalogService` (SCRUM-992),
`Compras\InventoryController::index` (SCRUM-993), `Bodega\BodegaInventoryController` (SCRUM-994),
`ReleaseOrdersAwaitingStock` (solo IDs de pedidos pendientes), `familyDetail` (acotado por el tamaño de la familia),
`PurchaseOrderController::replaceLines`, `ProductDeliveryService`, `WarehouseStockService` (IDs de un pedido).
`BodegaInventoryController::distinctActiveProviders/Brands` ya se midieron (11-16 ms).

## Clasificación por riesgo de 500 y frecuencia de uso

| Riesgo | Sitio | Por qué |
|---|---|---|
| **CRÍTICO (roto hoy)** | E — PDF de catálogo `completo` | La petición muere por memoria con el catálogo real (500 opaco). Frecuencia baja (exportación puntual). |
| **ALTO** | G — Bodega, búsqueda dentro de una bodega grande | 200 MB de 256; solo lo salva `memory.ini` a 256M. Frecuencia alta (personal de Bodega buscando en Zona Libre). |
| **ALTO** | B — Servicios, buscador de producto de Cotización | 156-171 MB y respuesta de 4 MB al navegador. Frecuencia alta (cada vez que se elige un producto). |
| **ALTO** | A — Bodega, buscador de "Nueva solicitud de ajuste" | 156 MB por petición, mismo patrón de escritura que ya arregló SCRUM-992 en otros buscadores. Frecuencia media-alta (se dispara al escribir). |
| **MEDIO** | F — Compras "Buscar Sustituto" sin búsqueda (proveedor Lafayette) | 147 MB, 2,6 MB de respuesta. Aceptado antes por el Senior (H4 de SCRUM-992); solo con un proveedor gigante y sin texto. |
| **MEDIO** | C — Home de Bodega | 93 MB y 2,2 s en cada visita a la pantalla de entrada; es el más frecuente de todos. Además da 26.282 en el Home y 26.285 en el Inventario al que enlaza (O1 del Pre-QA de SCRUM-994). |
| **BAJO / latente** | D — Home de Compras | Hoy solo carga los productos con `reorder_point` cargado (unos pocos); crece si se carga ese dato masivamente. |

**Riesgo de acumulación (no medido):** cada petición de las ALTO/MEDIO usa 90-200 MB. Con el pool de PHP-FPM en una `t3.small` (2 GB),
varias de ellas a la vez podrían agotar la memoria del host aunque cada una respete su `memory_limit`. Hipótesis sin verificar.

## Propuesta de corrección (a validar con Luis; el diseño sigue el patrón ya probado en SCRUM-992/993/994)

Reutilizar `CatalogProduct::applySearch()` + `orderByRelevance()` (búsqueda en SQL sobre `search_text`), `limit`, y para reglas de stock
las expresiones SQL de `InventoryKpiService`/`BodegaInventoryKpiService`, con test de paridad contra el comportamiento actual.

1. **A — `searchProducts`** (trivial, mismo shape de respuesta): `applySearch` + `orderByRelevance` + `limit(10)`. Quita el fuzzy en PHP.
2. **B — `ProductLookupController`**: búsqueda en SQL + tope de resultados (como `CatalogProductController`); conservar los filtros
   `exclude` y `sales_project_id`. Cambio de comportamiento a decidir: hoy sin `search` devuelve TODO el catálogo activo; pasaría a un tope.
3. **C — Home de Bodega `articulosCriticos()`**: mismo total que el chip `criticos` del Inventario (`BodegaInventoryKpiService::applyChip`) y
   solo el top-N que muestra la tarjeta, ordenado en SQL. Cierra O1 por construcción (con test de paridad Home = chip `criticos`).
4. **G — `WarehouseController::show` con `search`**: filtrar en SQL (unir con `catalog_products.search_text` + `ubicacion`) y paginar en SQL, con las
   relaciones solo para la página.
5. **F — "Buscar Sustituto" sin búsqueda**: aplicar el mismo tope que ya existe con búsqueda (`MAX_SUBSTITUTE_CANDIDATES`), o exigir un texto mínimo.
   Cambio de comportamiento: hoy rankea TODOS los productos del proveedor.
6. **E — PDF `completo`**: no hay corrección trivial. Un PDF con ~30K productos no se puede armar en una sola petición síncrona con dompdf.
   Opciones (decisión de producto, ver abajo): exigir filtros/tope y responder 422 si excede; generarlo en segundo plano (job de Horizon con
   `memory_limit` propio, por lotes/`cursor()`, y avisar al usuario con el enlace de descarga); o exportar por categoría.
7. **D — Home de Compras**: sin cambio ahora; anotar como latente y cubrirlo en la regla de proceso.

## Regla de proceso propuesta (punto 4 del ticket)

> Todo endpoint de listado, buscador o dashboard que toque el catálogo se mide en Pre-QA con el catálogo completo (~30K productos), en
> dev/test, con `memory_limit=256M`: latencia y pico de memoria por combinación de filtros, incluidas las de resultado vacío. Criterio:
> p95 < 500 ms y pico < 64 MB. Un sitio que hidrata `CatalogProduct` sin `limit`/`paginate` es un hallazgo aunque hoy responda 200.

Se propone registrarla en `.claude/agents/pre-qa.md` (Paso 4) y en el `CLAUDE.md` del proyecto (sección "Siempre").

## Decisiones de Luis (2026-09-29, resueltas)

1. **Alcance**: A + B + C (con O1) + G como un solo lote "buscadores y Home de Bodega en SQL". F y E van aparte.
2. **E (PDF `completo`)**: se genera en segundo plano (job de Horizon por lotes, con `memory_limit` propio y enlace de descarga al terminar);
   se mantiene la función de "catálogo completo en un PDF".
3. **B y F**: se acepta el cambio de comportamiento — tope de resultados donde hoy se devuelve todo (B como `CatalogProductController`, F con el mismo
   tope que ya existe con búsqueda).
4. **Jira y proceso**: se publican los hallazgos en SCRUM-995, se crean tickets hijos con spec y se registra la regla de proceso en
   `.claude/agents/pre-qa.md` y en el `CLAUDE.md` del proyecto.

## Tickets hijos

Ver el comentario de cierre de fase 1 en SCRUM-995 (los números se agregan ahí al crearlos): lote A+B+C+G, F (Buscar Sustituto con tope) y E (PDF
completo en segundo plano). Nada está implementado todavía; cada uno pasa por PM → dev → Senior Review → Pre-QA como es habitual.
