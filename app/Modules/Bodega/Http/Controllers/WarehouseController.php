<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Models\WarehouseLocation;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Services\FuzzySearchService;
use App\Shared\Http\Pagination;
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
        private readonly FuzzySearchService $fuzzySearch,
    ) {}

    /** SCRUM-451 (REQ-381) — pestañas del selector. */
    public function index(): JsonResponse
    {
        return response()->json([
            'data' => Warehouse::orderBy('id')->get(['id', 'name', 'responsable', 'capacidad_pct', 'modo_detalle']),
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

        $stockRows = ProductWarehouseStock::with(['catalogProduct.families', 'catalogProduct.provider', 'cliente'])
            ->where('warehouse_id', $id)
            ->where('quantity', '>', 0)
            ->get()
            ->filter(fn (ProductWarehouseStock $row): bool => $row->catalogProduct !== null)
            ->values();

        // REQ-383 RN1 — ubicaciones disponibles para el selector, recalculadas por bodega actual.
        $ubicaciones = $stockRows->pluck('ubicacion')->filter()->unique()->sort()->values();

        $categoria = $request->query('categoria');
        if ($categoria !== null && $categoria !== '') {
            $stockRows = $stockRows->filter(
                fn (ProductWarehouseStock $row): bool => $row->catalogProduct?->families?->contains('id', (int) $categoria) ?? false
            )->values();
        }

        $ubicacionFilter = $request->query('ubicacion');
        if ($ubicacionFilter !== null && $ubicacionFilter !== '') {
            $stockRows = $stockRows->filter(
                fn (ProductWarehouseStock $row): bool => $row->ubicacion === $ubicacionFilter
            )->values();
        }

        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            [$stockRows] = $this->fuzzySearch->search(
                array_values($stockRows->all()),
                $search,
                fn (ProductWarehouseStock $row): string => trim($row->catalogProduct?->reference.' '.$row->catalogProduct?->description.' '.($row->ubicacion ?? '')),
            );
            $stockRows = collect($stockRows);
        }

        // SCRUM-754 (rebote de Daniela 2026-08-18, "Bodega → Bodegas" quedó como el único
        // listado sin paginar del batch de estandarización) — KPIs sobre el set YA filtrado
        // (search/categoria/ubicacion), paginación se aplica después para no contar solo la
        // página actual. Mismo patrón que `ProviderController::index()` (Pagination::ofArray()
        // porque este listado ya es un array filtrado en PHP, no una query).
        $totalProducts = $stockRows->count();
        $totalUnits = $stockRows->sum('quantity');

        $pagination = Pagination::params($request, [], 'id');
        $paginator = Pagination::ofArray(array_values($stockRows->all()), $pagination['per_page'], $pagination['page']);
        $pageRows = collect($paginator->items());

        $productIds = array_values($pageRows->map(fn (ProductWarehouseStock $row): int => $row->catalog_product_id)->unique()->all());
        $porServirMap = $this->inventoryKpis->porServirMap($productIds);
        $porIngresarMap = $this->inventoryKpis->porIngresarMap($productIds);
        $enCaminoMap = $this->inventoryKpis->enCaminoMap($productIds);

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
            'disponible' => ($product->stock_quantity ?? 0) - $porServir,
            'por_servir' => $porServir,
            'stock_total' => $product->stock_quantity,
            'por_ingresar' => $porIngresar,
            'en_camino' => $enCamino,
            'stock_minimo' => $product->reorder_point,
            'estado' => $this->inventoryKpis->stockState($product, $porServir),
            'proveedor' => $product->provider?->name,
        ];

        if ($warehouse->modo_detalle === Warehouse::MODO_CLIENTE) {
            $base['cliente'] = $row->cliente?->name;
        }

        return $base;
    }
}
