<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Models\WarehouseLocation;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Http\Pagination;
use App\Shared\Http\QueryParams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-451→456 (REQ-381→386) — pantalla "Bodegas": selector de las 7 bodegas físicas, KPIs,
 * filtros y tabla de productos por ubicación. Gateado por `bodega.read` (cada ticket dice
 * explícitamente "Visible para todos los perfiles de Bodega" — a diferencia de Inventario/Compras,
 * que gatea por `compras.*`, ver docblock de `InventoryController`).
 *
 * Las columnas globales de stock (Disponible/Por servir/Stock Total/Por ingresar/En camino/Estado)
 * reusan `InventoryKpiService` byte-a-byte (REQ-386 RN1: mismo dato en cualquier bodega, nunca un
 * recálculo paralelo) — misma fuente que ya consume `InventoryController`.
 *
 * SCRUM-454 (REQ-384, Bloque B4 — ver ADR-SCRUM454-459-reubicacion-bodegas.md): el chip
 * "Espacio libre" ya no responde `not_implemented` — vacías = `WarehouseLocation` activas de la
 * bodega MENOS las `ProductWarehouseStock.ubicacion` con `quantity > 0` de esa misma bodega. Solo
 * aplica a bodegas `modo_detalle = ubicacion_exacta` (en `pendiente`/`cliente` no hay concepto de
 * ubicación física individual, ver `formatRow()` — el chip devuelve `data`/`ubicaciones` vacíos
 * ahí, nunca inventa una lista).
 */
final class WarehouseController extends Controller
{
    public function __construct(
        private readonly InventoryKpiService $inventoryKpis,
    ) {}

    /** SCRUM-451 (REQ-381) — pestañas del selector. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Warehouse::activos()->orderBy('id')->get(['id', 'name', 'responsable', 'capacidad_pct', 'modo_detalle']),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $warehouse = Warehouse::find($id);
        if ($warehouse === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $chip = (string) $request->query('chip', 'todos');
        if ($chip === 'espacio_libre') {
            return response()->json($this->buildEspacioLibreResponse($warehouse, $request));
        }

        // SCRUM-991 — solo productos ACTIVOS, igual que las listas de Compras y de Bodega → Ver Inventario (que ocultan los
        // inactivos por defecto). Antes `whereHas('catalogProduct')` también contaba los inactivos con stock y esta vista daba
        // N productos más que el filtro por la misma bodega en las otras pantallas (3.354 contra 3.350 en dev).
        $baseQuery = ProductWarehouseStock::query()
            ->where('warehouse_id', $id)
            ->whereIn('catalog_product_id', CatalogProduct::query()->select('id')->where('is_active', true));

        // Bodegas operativas: solo productos con unidades. (SCRUM-990: el caso "listar también los de 0 unidades" era solo de Zona
        // Libre, que ahora es de solo consulta y se atiende arriba con su propia tabla.)
        $baseQuery->where('quantity', '>', 0);

        // REQ-383 RN1 — ubicaciones disponibles para el selector: sobre quantity/catalogProduct ya
        // aplicados, pero ANTES de categoria/ubicacion (el selector debe listar todas las opciones,
        // no solo la ya elegida) — mismo criterio que antes, ahora resuelto en SQL en vez de sobre
        // una Collection completa en memoria (ver CRÍTICO de Pre-QA SCRUM-973 abajo).
        $ubicaciones = (clone $baseQuery)
            ->whereNotNull('ubicacion')
            ->distinct()
            ->orderBy('ubicacion')
            ->pluck('ubicacion')
            ->values();

        $categoria = $request->query('categoria');
        if ($categoria !== null && $categoria !== '') {
            $familyId = (int) $categoria;
            $baseQuery->whereHas(
                'catalogProduct.families',
                fn ($q) => $q->where((new CatalogProductFamily)->getTable().'.id', $familyId)
            );
        }

        $ubicacionFilter = $request->query('ubicacion');
        if ($ubicacionFilter !== null && $ubicacionFilter !== '') {
            $baseQuery->where('ubicacion', $ubicacionFilter);
        }

        $pagination = Pagination::params($request, [], 'id');
        $search = trim(QueryParams::string($request, 'search'));

        // CRÍTICO real (Pre-QA SCRUM-973, 2026-09-27): con una bodega de catálogo grande esta pantalla cargaba TODO el stock
        // filtrado en memoria con 3 relaciones eager ANTES de paginar y moría con "Allowed memory size exhausted"
        // (500 opaco, sin traza). Se corrigió el caso sin `search`; SCRUM-996 (auditoría SCRUM-995, sitio G) cierra
        // el caso CON `search`, que seguía cargando todo el conjunto en PHP para aplicar Levenshtein
        // (2,9-3,4 s y 200 MB de pico en esa bodega, 78 % del límite de 256 MB): ahora el texto se resuelve en SQL
        // y hay UNA sola ruta `paginate()` con las relaciones solo para la página.
        $sortByRelevance = null;
        $totals = null;
        if ($search !== '') {
            $textQuery = clone $baseQuery;
            $this->applyTextSearch($textQuery, $search);

            // Un solo agregado (conteo + unidades) decide si hubo coincidencia parcial y alimenta los KPIs y el
            // paginador: antes eran `exists()` + `count()` + `sum()` + el `count()` propio del paginador (~110 ms de
            // 4 pasadas sobre las filas de esa bodega, medido en dev).
            $totals = $this->stockTotals($textQuery);

            if ($totals['products'] > 0) {
                $baseQuery = $textQuery;
            } else {
                $totals = null;
                // Respaldo tolerante a typos SOLO si la coincidencia parcial da 0 (mismo criterio que el resto de
                // buscadores de catálogo, SCRUM-992): candidatos acotados a los productos de ESTA bodega.
                $inWarehouse = ProductWarehouseStock::query()->select('catalog_product_id')->where('warehouse_id', $id)->where('quantity', '>', 0);
                $fuzzyProducts = CatalogProduct::query()->select('id')->whereIn('id', $inWarehouse);
                CatalogProduct::applySearch($fuzzyProducts, $search, fuzzyFallback: true);
                $baseQuery->whereIn('catalog_product_id', $fuzzyProducts);
            }

            $sortByRelevance = CatalogProduct::relevanceRank($search, 'cp.');
        }

        $totals ??= $this->stockTotals($baseQuery);
        $totalProducts = $totals['products'];
        $totalUnits = $totals['units'];

        if ($sortByRelevance !== null) {
            // Relevancia por producto (exacto > prefijo > resto) sin unir tablas: subconsulta correlacionada.
            $productsTable = (new CatalogProduct)->getTable();
            $stockTable = (new ProductWarehouseStock)->getTable();
            $baseQuery->orderByRaw(
                "(SELECT {$sortByRelevance['sql']} FROM {$productsTable} cp WHERE cp.id = {$stockTable}.catalog_product_id)",
                $sortByRelevance['bindings'],
            );
        }

        $paginator = $baseQuery
            ->with(['catalogProduct.families', 'catalogProduct.provider', 'cliente'])
            ->orderBy('id')
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page'], $totalProducts);

        $pageRows = collect($paginator->items());

        $productIds = array_values($pageRows->map(fn (ProductWarehouseStock $row): int => $row->catalog_product_id)->unique()->all());
        $porServirMap = $this->inventoryKpis->porServirMap($productIds);
        $porIngresarMap = $this->inventoryKpis->porIngresarMap($productIds);
        $enCaminoMap = $this->inventoryKpis->enCaminoMap($productIds);
        $enExcluidaMap = $this->inventoryKpis->enBodegaExcluidaMap($productIds);

        return response()->json([
            'warehouse' => $this->formatWarehouse($warehouse),
            'kpis' => [
                'responsable' => $warehouse->responsable,
                'total_products' => $totalProducts,
                'total_units' => $totalUnits,
                'capacidad_pct' => $warehouse->capacidad_pct,
            ],
            'ubicaciones' => $ubicaciones,
            'data' => $pageRows->map(fn (ProductWarehouseStock $row) => $this->formatRow(
                $row,
                $warehouse,
                $porServirMap[$row->catalog_product_id] ?? 0,
                $porIngresarMap[$row->catalog_product_id] ?? 0,
                $enCaminoMap[$row->catalog_product_id] ?? 0,
                $enExcluidaMap[$row->catalog_product_id] ?? 0,
            ))->values(),
            'meta' => Pagination::meta($paginator),
        ]);
    }

    /**
     * SCRUM-454 (REQ-384) — chip "Espacio libre". Mismo shape de nivel superior que la rama
     * normal de `show()` (`warehouse`/`kpis`/`ubicaciones`/`data`) — no repetir el bug de
     * Pre-QA 2026-07-21 (un shape distinto rompía `data.kpis.responsable` en el frontend). Las
     * filas de `data` NO son filas de producto (no hay producto en una ubicación vacía): shape
     * mínimo `{id, ubicacion, estado}` — `estado` siempre "disponible" (una ubicación vacía
     * nunca es "bajo_stock"/"sin_stock", esos estados describen inventario de producto).
     *
     * @return array<string, mixed>
     */
    private function buildEspacioLibreResponse(Warehouse $warehouse, Request $request): array
    {
        $emptyLocations = collect();

        // REQ-384 RN — el chip solo tiene sentido en bodegas con ubicación física individual;
        // en modo "pendiente"/"cliente" no existe el concepto de ubicación (ver formatRow()), así
        // que no se inventa una lista ahí.
        if ($warehouse->modo_detalle === Warehouse::MODO_UBICACION_EXACTA) {
            $activeLocations = WarehouseLocation::where('warehouse_id', $warehouse->id)
                ->where('is_active', true)
                ->get(['id', 'codigo']);

            $occupiedCodes = ProductWarehouseStock::where('warehouse_id', $warehouse->id)
                ->where('quantity', '>', 0)
                ->whereNotNull('ubicacion')
                ->pluck('ubicacion')
                ->unique();

            $emptyLocations = $activeLocations
                ->reject(fn (WarehouseLocation $location): bool => $occupiedCodes->contains($location->codigo))
                ->sortBy('codigo')
                ->values();
        }

        // SCRUM-754 (rebote 2026-08-18) — mismo mecanismo de paginación que la rama normal de
        // show(): KPIs/ubicaciones sobre el set completo, 'data' solo la página actual.
        $pagination = Pagination::params($request, [], 'id');
        $paginator = Pagination::ofArray(array_values($emptyLocations->all()), $pagination['per_page'], $pagination['page']);

        return [
            'warehouse' => $this->formatWarehouse($warehouse),
            'kpis' => [
                'responsable' => $warehouse->responsable,
                // Repurposed para este chip: cantidad de ubicaciones libres encontradas — no hay
                // "unidades" que contar en una ubicación vacía (no hay producto).
                'total_products' => $emptyLocations->count(),
                'total_units' => 0,
                'capacidad_pct' => $warehouse->capacidad_pct,
            ],
            'ubicaciones' => $emptyLocations->pluck('codigo')->values(),
            'data' => collect($paginator->items())->map(fn (WarehouseLocation $location): array => [
                'id' => $location->id,
                'ubicacion' => $location->codigo,
                'estado' => 'disponible',
            ])->values(),
            'meta' => Pagination::meta($paginator),
        ];
    }

    /**
     * Filas de stock y unidades del conjunto filtrado, en una sola consulta agregada.
     *
     * @param  Builder<ProductWarehouseStock>  $query
     * @return array{products: int, units: int}
     */
    private function stockTotals(Builder $query): array
    {
        $row = (clone $query)->toBase()->selectRaw('COUNT(*) AS products, COALESCE(SUM(quantity), 0) AS units')->first();

        return ['products' => (int) ($row->products ?? 0), 'units' => (int) ($row->units ?? 0)];
    }

    /**
     * SCRUM-996 — filtro de texto libre del stock por bodega, en SQL. Cada palabra (en cualquier orden, sin
     * distinguir mayúsculas ni acentos) debe aparecer en el producto (`search_text` = referencia + ref. de
     * fábrica + nombre + marca, o la descripción) o en la ubicación de ESA fila. Mismos campos que el
     * `FuzzySearchService` de antes (referencia, descripción, ubicación) más los que ya cubre `search_text`;
     * usa las mismas expresiones normalizadas (`public.f_unaccent(lower())`) que los índices trigram de
     * SCRUM-992.
     *
     * @param  Builder<ProductWarehouseStock>  $query
     */
    private function applyTextSearch(Builder $query, string $search): void
    {
        foreach (CatalogProduct::searchWords($search) as $word) {
            $escaped = addcslashes($word, '%_\\');

            $query->where(function (Builder $w) use ($escaped): void {
                $w->whereIn('catalog_product_id', CatalogProduct::query()->select('id')->where(function ($p) use ($escaped): void {
                    $p->whereRaw("search_text LIKE '%' || public.f_unaccent(lower(?)) || '%'", [$escaped])
                        ->orWhereRaw("public.f_unaccent(lower(coalesce(description, ''))) LIKE '%' || public.f_unaccent(lower(?)) || '%'", [$escaped]);
                }))->orWhereRaw("public.f_unaccent(lower(coalesce(ubicacion, ''))) LIKE '%' || public.f_unaccent(lower(?)) || '%'", [$escaped]);
            });
        }
    }

    /** @return array<string, mixed> */
    private function formatWarehouse(Warehouse $warehouse): array
    {
        return [
            'id' => $warehouse->id,
            'name' => $warehouse->name,
            'responsable' => $warehouse->responsable,
            'capacidad_pct' => $warehouse->capacidad_pct,
            'modo_detalle' => $warehouse->modo_detalle,
        ];
    }

    /** @return array<string, mixed> */
    private function formatRow(
        ProductWarehouseStock $row,
        Warehouse $warehouse,
        int $porServir,
        int $porIngresar,
        int $enCamino,
        int $enExcluida = 0,
    ): array {
        /** @var CatalogProduct $product */
        $product = $row->catalogProduct;

        // REQ-385 — el detalle de ubicación depende del modo de la bodega, nunca se inventa un
        // dato que esa bodega no tiene.
        $ubicacionDetalle = match ($warehouse->modo_detalle) {
            Warehouse::MODO_PENDIENTE => null,
            Warehouse::MODO_CLIENTE => null,
            default => $row->ubicacion,
        };

        $base = [
            'stock_id' => $row->id,
            // SCRUM-457 (REQ-387) — Bloque B4: el modal "Reubicar" necesita el id real de
            // catálogo para armar POST /bodega/relocations; hasta acá esta respuesta solo
            // exponía stock_id (id del renglón de stock), no el producto — hallazgo real de
            // Frontend Dev al construir el modal (el frontend recurría a buscar por referencia
            // vía otro endpoint, frágil con productos inactivos).
            'catalog_product_id' => $product->id,
            'factory_reference' => $product->factory_reference,
            'reference' => $product->reference,
            'ubicacion' => $ubicacionDetalle,
            'description' => $product->name,
            'categoria' => $product->families->first()?->name,
            // Mejora SCRUM-752 (RN3, Gerencia Test 2026-08-13) — unidades REALES en ESTA bodega
            // (no el stock global de la empresa que sí es `disponible`/`stock_total` abajo).
            // `$row` ya es el `ProductWarehouseStock` de esta bodega — el dato ya estaba en scope,
            // solo nunca se devolvía.
            'unidades_en_bodega' => $row->quantity,
            // REQ-386 RN1 — dato GLOBAL del producto (toda la empresa), no exclusivo de esta bodega.
            'disponible' => $this->inventoryKpis->disponibleParaVenta($product, $porServir, $enExcluida),
            'por_servir' => $porServir,
            'stock_total' => $product->stock_quantity,
            'por_ingresar' => $porIngresar,
            'en_camino' => $enCamino,
            'stock_minimo' => $product->reorder_point,
            'estado' => $this->inventoryKpis->stockState($product, $porServir, $enExcluida),
            'proveedor' => $product->provider?->name,
        ];

        if ($warehouse->modo_detalle === Warehouse::MODO_CLIENTE) {
            $base['cliente'] = $row->cliente?->name;
        }

        return $base;
    }
}
