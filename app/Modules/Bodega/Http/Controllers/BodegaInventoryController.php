<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Services\PreferencesService;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Services\ArrivalConfirmationService;
use App\Modules\Bodega\Services\BodegaInventoryKpiService;
use App\Modules\Bodega\Services\OrderCommitmentService;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Http\Pagination;
use App\Shared\Http\QueryParams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;

/**
 * SCRUM-329 Bloque B2 (REQ-344→362, SCRUM-414→432) — "Ver Inventario" de Bodega: pantalla de
 * SOLO LECTURA sobre el mismo catálogo que administra Compras (`CatalogProduct`, ninguna tabla
 * nueva). Endpoint propio (no `Compras\Http\Controllers\InventoryController`) porque el gate real
 * es distinto (`bodega.read`/`bodega.write`, un string simple, a diferencia del OR de 2 permisos
 * que ya fuerza a `InventoryController::resolveAccess()` a ser un método aparte) y porque los
 * campos expuestos tampoco calzan ni con el modo "completo" de Compras (nunca precio/costo) ni con
 * el modo "restringido" de Ventas & Diseño (ese sí oculta `rotation`, que Bodega SÍ debe ver —
 * REQ-344/351/416).
 *
 * "Por servir"/"Disponible"/"Estado" de ESTA pantalla usan `OrderCommitmentService` (compromiso a
 * PEDIDOS de Bodega en curso — REQ-353 RN1/Escenario 1), NO `InventoryKpiService::porServirMap()`
 * (reservas de Ventas & Diseño al momento de comprar, un concepto distinto) — ver docblock de
 * `OrderCommitmentService`/`BodegaInventoryKpiService`. "Por ingresar"/"En camino" SÍ reusan
 * `InventoryKpiService::porIngresarMap()`/`enCaminoMap()` tal cual (mismo concepto, ya construido
 * sobre Ingreso de Mercancía real).
 *
 * REQ-361/AC — nunca se expone `price_full`/`cost`/`import_cost`/`freight_cost`/`handling_cost`/
 * `other_cost`/`cost_total`/`margin_percent`, ni ninguna acción de escritura sobre el producto
 * (crear/inactivar/editar) — eso sigue siendo exclusivo de Compras.
 *
 * NOTA de alcance (actualizada 2026-08-15) — REQ-361 pedía "código de barras" en el detalle de
 * producto; en su momento `CatalogProduct` no tenía ese campo. La migración
 * `2026_08_11_100001_add_barcode_to_ventas_diseno_catalog_products_table` lo agregó (ya expuesto
 * por Compras en `InventoryController::format()`), así que el rebote de REQ-361/SCRUM-431
 * (2026-08-13) lo expone también acá — solo lectura, igual que el resto de este endpoint.
 */
final class BodegaInventoryController extends Controller
{
    /**
     * SCRUM-237/240 — `description` reemplazado por `name` (nombre visible real del producto, ver
     * migración `2026_08_15_100000_...`).
     *
     * @var list<string>
     */
    private const ALLOWED_SORTS = ['reference', 'name', 'stock_quantity', 'created_at'];

    public function __construct(
        private readonly InventoryKpiService $inventoryKpis,
        private readonly OrderCommitmentService $commitments,
        private readonly BodegaInventoryKpiService $bodegaKpis,
        private readonly ArrivalConfirmationService $arrivalConfirmation,
        private readonly PreferencesService $preferences,
    ) {}

    /** REQ-344/346/349/350/351/362 — listado principal, KPIs, filtros combinables, chips y deep-link. */
    public function index(Request $request): JsonResponse
    {
        $chip = QueryParams::string($request, 'chip', 'todos');
        // docs/specs/demo-mode.md — sin usuario autenticado (no debería pasar, ruta ya exige JWT),
        // el filtro no se activa.
        $requester = $this->resolveUser($request);
        $demoModeActive = $requester !== null && $this->preferences->isDemoModeActive($requester);

        // SCRUM-994 (docs/specs/bodega-inventory-kpis-and-computed-filters-sql.md) — TODO en SQL. El fix urgente
        // del 2026-09-28 (`8c73828`) solo cubría el caso común: los chips `por_ingresar`/`en_atencion`/
        // `criticos` y el filtro `estado` seguían cargando el conjunto candidato completo (hasta ~26K modelos),
        // calculando los mapas por ID, filtrando con `array_filter` y ordenando con `usort` en PHP, y encima
        // `kpis()` hidrataba el catálogo otra vez: los picos se sumaban por encima de los 256 MB de PHP-FPM y
        // `chip=criticos`/`estado=sin_stock` respondían 500 (agotamiento de memoria, sin traza en `laravel.log`).
        // Ahora los chips/estado son `WHERE` sobre las mismas expresiones SQL de la regla de stock
        // (`BodegaInventoryKpiService`/`InventoryKpiService`), el orden y la paginación son de SQL para TODOS los
        // filtros, y `families`/`provider` se hidratan solo para la página. Los agregados por producto
        // (compromiso, en bodega excluida, por ingresar) se unen SOLO cuando el filtro los necesita.
        $baseQuery = $this->bodegaKpis->baseQuery()
            ->when($chip !== 'inactivos', fn ($q) => $q->where('cp.is_active', true))
            ->when($chip === 'inactivos', fn ($q) => $q->where('cp.is_active', false))
            ->visibleForDemoMode($demoModeActive);

        // REQ-362 RN1 (SCRUM-432) — deep-link desde "Mayor rotación" de Home, mismo campo
        // `rotation` que esta pantalla ya expone/filtra.
        if ($chip === 'rotacion') {
            $baseQuery->where('cp.rotation', CatalogProduct::ROTATION_ALTA);
        }

        // REQ-349 — filtros combinables: rotación, proveedor, marca, categoría (familia).
        $rotation = $request->query('rotation');
        if (is_string($rotation) && $rotation !== '') {
            $baseQuery->where('cp.rotation', $rotation);
        }

        $providerId = $request->query('provider_id');
        if (is_string($providerId) && $providerId !== '') {
            $baseQuery->where('cp.provider_id', (int) $providerId);
        }

        $brand = $request->query('brand');
        if (is_string($brand) && $brand !== '') {
            $baseQuery->whereRaw('LOWER(cp.brand) = LOWER(?)', [$brand]);
        }

        // "Categoría" (REQ-349/351) = `CatalogProductFamily` — mismo concepto que `family_id`/
        // `family_name` ya usado por Compras (InventoryController::format()). Subconsulta sobre la tabla
        // pivote (no `whereHas`): la consulta base usa el alias `cp`, y el `EXISTS` de `whereHas` referencia
        // la tabla por su nombre completo.
        $familyId = $request->query('family_id');
        if (is_string($familyId) && $familyId !== '') {
            $families = (new CatalogProduct)->families();
            $baseQuery->whereIn('cp.id', fn ($sub) => $sub
                ->select($families->getForeignPivotKeyName())
                ->from($families->getTable())
                ->where($families->getRelatedPivotKeyName(), (int) $familyId));
        }

        // REQ-349 RN2 — filtrar por bodega muestra SOLO productos con stock registrado en ESA
        // bodega. La subconsulta reemplaza al `pluck()` + `whereIn('id', [miles de IDs])` de antes; la cantidad
        // en ESA bodega (que alimenta "Disponible" en `format()`, SCRUM-361) se lee abajo solo para la página.
        $warehouseId = $request->query('warehouse_id');
        $scopedWarehouseId = is_string($warehouseId) && $warehouseId !== '' ? (int) $warehouseId : null;
        if ($scopedWarehouseId !== null) {
            $baseQuery->whereIn('cp.id', fn ($sub) => $sub
                ->select('catalog_product_id')
                ->from((new ProductWarehouseStock)->getTable())
                ->where('warehouse_id', $scopedWarehouseId)
                ->where('quantity', '>', 0));
        }

        $search = trim(QueryParams::string($request, 'search'));
        $estadoParam = $request->query('estado');
        $estado = is_string($estadoParam) && $estadoParam !== '' && $estadoParam !== 'todos' ? $estadoParam : null;

        $pagination = Pagination::params($request, self::ALLOWED_SORTS, 'reference');
        $fuzzy = false;

        if ($search !== '') {
            // SCRUM-992 — búsqueda en SQL sobre `search_text` (referencia, referencia de fábrica, nombre,
            // marca; índice trigram, sin acentos) + nombre del proveedor (tabla chica), como ya hacía
            // el texto que se le pasaba al fuzzy en PHP. Respaldo tolerante a typos SOLO si lo anterior
            // da 0 resultados. Se aplica ANTES de unir los agregados: la consulta de candidatos del respaldo
            // no necesita esos joins.
            // Cada palabra del término debe aparecer en el texto del producto O en el nombre de su proveedor.
            $words = CatalogProduct::searchWords($search);
            $providerIdsByWord = [];
            foreach ($words as $word) {
                $providerIdsByWord[$word] = Provider::query()
                    ->whereRaw("public.f_unaccent(lower(name)) LIKE '%' || public.f_unaccent(lower(?)) || '%'", [addcslashes($word, '%_\\')])
                    ->pluck('id')->all();
            }
            $partial = function (Builder $q) use ($providerIdsByWord): void {
                foreach ($providerIdsByWord as $word => $providerIds) {
                    $q->where(function (Builder $w) use ($word, $providerIds): void {
                        $w->where(function (Builder $x) use ($word): void {
                            CatalogProduct::applySearch($x, (string) $word, false, 'cp.');
                        });
                        if ($providerIds !== []) {
                            $w->orWhereIn('cp.provider_id', $providerIds);
                        }
                    });
                }
            };
            $probe = clone $baseQuery;
            $partial($probe);
            if ($probe->exists()) {
                $partial($baseQuery);
            } else {
                $fuzzy = CatalogProduct::applySearch($baseQuery, $search, fuzzyFallback: true, prefix: 'cp.');
            }
        }

        // Chips/estado calculados (REQ-350): agregados unidos solo si el filtro los necesita.
        if ($this->bodegaKpis->needsStockAggregates($chip, $estado)) {
            $this->bodegaKpis->withStockAggregates($baseQuery);
        }
        if ($chip === 'por_ingresar') {
            $this->bodegaKpis->withPorIngresar($baseQuery);
        }
        if ($chip === 'bajo_stock_sin_ordenar') {
            $this->bodegaKpis->withEnCamino($baseQuery);
        }
        $this->bodegaKpis->applyChip($baseQuery, $chip);
        if ($estado !== null) {
            $this->bodegaKpis->applyEstado($baseQuery, $estado);
        }

        // Mismo criterio de orden que ya tenía el camino común (decisión de Luis, SCRUM-994): texto con
        // `LOWER()`, números/fechas con el orden por defecto de Postgres. Desempate estable por `id`.
        $sortBy = $pagination['sort_by'];
        if (in_array($sortBy, ['reference', 'name'], true)) {
            $baseQuery->orderByRaw("LOWER(cp.{$sortBy}) {$pagination['sort_dir']}");
        } else {
            $baseQuery->orderBy("cp.{$sortBy}", $pagination['sort_dir']);
        }

        $paginator = $baseQuery
            ->orderBy('cp.id') // desempate estable — stock_quantity/created_at tienen muchos empates reales
            ->with(['families', 'provider'])
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        /** @var list<CatalogProduct> $pageProducts */
        $pageProducts = array_values($paginator->items());

        // Los mapas de compromiso/KPIs para construir la respuesta: solo sobre los IDs de la PÁGINA —
        // nunca sobre el universo.
        $pageProductIds = array_map(fn (CatalogProduct $p): int => $p->id, $pageProducts);
        $committedMap = $this->commitments->committedMap($pageProductIds);
        $porIngresarMap = $this->inventoryKpis->porIngresarMap($pageProductIds);
        $enCaminoMap = $this->inventoryKpis->enCaminoMap($pageProductIds);
        $enExcluidaMap = $this->inventoryKpis->enBodegaExcluidaMap($pageProductIds);
        $arrivalMap = $this->arrivalConfirmation->bulkPendingSummary($pageProductIds);
        $warehouseCounts = $this->warehouseCounts($pageProductIds);
        $warehouseScopedQtyMap = $scopedWarehouseId === null || $pageProductIds === []
            ? []
            : ProductWarehouseStock::where('warehouse_id', $scopedWarehouseId)
                ->where('quantity', '>', 0)
                ->whereIn('catalog_product_id', $pageProductIds)
                ->pluck('quantity', 'catalog_product_id')
                ->map(fn ($q) => (int) $q)
                ->all();

        return response()->json([
            'fuzzy' => $fuzzy,
            'kpis' => $this->bodegaKpis->kpis(),
            // REQ-349 (SCRUM-419) — hallazgo de QA 2026-07-24: los filtros "Proveedor"/"Marca"
            // aparecían vacíos porque no había ningún endpoint de catálogo accesible con solo
            // `bodega.read` (`ProviderController` exige `compras.read`, que ningún rol de Bodega
            // tiene). Facetas calculadas server-side sobre el universo COMPLETO de productos
            // activos (no solo la página/filtro actual — mismo criterio que `kpis` arriba), para
            // que el dropdown no se vacíe/encoja a medida que se aplican otros filtros.
            'providers' => $this->distinctActiveProviders(),
            'brands' => $this->distinctActiveBrands(),
            'data' => array_map(
                fn (CatalogProduct $p) => $this->format(
                    $p,
                    $committedMap[$p->id] ?? 0,
                    $porIngresarMap[$p->id] ?? 0,
                    $enCaminoMap[$p->id] ?? 0,
                    $arrivalMap[$p->id] ?? ['pending_bodega_action' => false, 'awaiting_compras' => false, 'confirmed_quantity' => 0, 'confirmed_by_name' => null],
                    $warehouseCounts[$p->id] ?? 0,
                    $warehouseScopedQtyMap[$p->id] ?? null,
                    $enExcluidaMap[$p->id] ?? 0,
                ),
                $pageProducts,
            ),
            'meta' => Pagination::meta($paginator),
        ]);
    }

    /** REQ-361 — modal "Ver detalle", solo lectura, sin precio/costo. */
    public function show(Request $request, int $id): JsonResponse
    {
        $product = CatalogProduct::with(['families', 'provider'])->find($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $committed = $this->commitments->committedMap([$id])[$id] ?? 0;
        $porIngresar = $this->inventoryKpis->porIngresarMap([$id])[$id] ?? 0;
        $enCamino = $this->inventoryKpis->enCaminoMap([$id])[$id] ?? 0;
        $arrival = $this->arrivalConfirmation->pendingSummary($id);
        $warehouseCount = $this->warehouseCounts([$id])[$id] ?? 0;
        $enExcluida = $this->inventoryKpis->enBodegaExcluidaMap([$id])[$id] ?? 0;

        return response()->json($this->format($product, $committed, $porIngresar, $enCamino, $arrival, $warehouseCount, enExcluida: $enExcluida));
    }

    /** REQ-352/422 — desglose de stock por bodega física; todas las bodegas con saldo distinto de 0 (SCRUM-1196: también las negativas). */
    public function warehouseBreakdown(Request $request, int $id): JsonResponse
    {
        if (CatalogProduct::find($id) === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['data' => $this->warehouseRows($id)]);
    }

    /**
     * REQ-353/423 — drill-down de "Por servir": a qué cliente/proyecto/pedido corresponde cada
     * unidad comprometida. `[]` cuando no hay nada comprometido — el frontend muestra "sin
     * compromisos activos" en vez de un modal vacío (Escenario 2).
     */
    public function commitmentDetail(Request $request, int $id): JsonResponse
    {
        if (CatalogProduct::find($id) === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['data' => $this->commitments->committedDetail($id)]);
    }

    /**
     * REQ-354/424 — drill-down de "En camino": proveedor, cantidad y fecha estimada por orden de
     * compra real. `[]` cuando no hay nada en camino (Escenario 2).
     */
    public function enCaminoDetail(Request $request, int $id): JsonResponse
    {
        if (CatalogProduct::find($id) === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['data' => $this->inventoryKpis->enCaminoDetail($id)]);
    }

    /**
     * REQ-347/417 — toggle "Familias": listado agrupado, SIN valor total ni costo (a diferencia de
     * `Compras\Http\Controllers\InventoryController::familyDetail()`, que sí expone `cost`/
     * `total_value` — ver REQ-348 PERMISOS, "Bodega: solo consulta").
     */
    public function families(Request $request): JsonResponse
    {
        $families = CatalogProductFamily::where('is_active', true)
            ->withCount(['products' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => $families->map(fn (CatalogProductFamily $f) => [
                'id' => $f->id,
                'name' => $f->name,
                'description' => $f->description,
                'product_count' => $f->products_count,
            ])->values(),
        ]);
    }

    /**
     * REQ-348/418 — detalle de una familia para Bodega. SIN `cost`/`total_value` y SIN el botón
     * "Generar compra" (ese endpoint — `generateFamilyPurchase()` — simplemente no se expone bajo
     * el prefijo `bodega/`, ver `routes/bodega.php`).
     *
     * Reescrito 2026-08-15 (rebote de Gerencia Test 2026-08-12, RN3): antes devolvía una lista
     * reducida (id/reference/description/stock_quantity/provider_id) sin ninguna de las columnas
     * operativas de la vista "Productos" — el criterio de agrupación por Familia REAL
     * (`CatalogProductFamily`, ya usado acá desde el principio, no por `category` como asumía el
     * rebote) ya era correcto; el gap real es que el detalle nunca reusaba `format()`, el mismo
     * método que ya arma las 14 columnas para `index()`/`show()`. Reusarlo acá es lo que da
     * "exactamente la misma tabla" que RN3 exige, sin duplicar el cálculo de KPIs por producto.
     */
    public function familyDetail(Request $request, int $id): JsonResponse
    {
        $family = CatalogProductFamily::where('is_active', true)->find($id);
        if ($family === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var Collection<int, CatalogProduct> $products */
        $products = $family->products()->where('is_active', true)->with(['families', 'provider'])->orderBy('name')->get();

        /** @var list<int> $productIds */
        $productIds = array_values($products->map(fn (CatalogProduct $p): int => $p->id)->all());
        $committedMap = $this->commitments->committedMap($productIds);
        $porIngresarMap = $this->inventoryKpis->porIngresarMap($productIds);
        $enCaminoMap = $this->inventoryKpis->enCaminoMap($productIds);
        $arrivalMap = $this->arrivalConfirmation->bulkPendingSummary($productIds);
        $warehouseCounts = $this->warehouseCounts($productIds);
        $enExcluidaMap = $this->inventoryKpis->enBodegaExcluidaMap($productIds);

        return response()->json([
            'id' => $family->id,
            'name' => $family->name,
            'description' => $family->description,
            'product_count' => $products->count(),
            'products' => $products->map(fn (CatalogProduct $p) => $this->format(
                $p,
                $committedMap[$p->id] ?? 0,
                $porIngresarMap[$p->id] ?? 0,
                $enCaminoMap[$p->id] ?? 0,
                $arrivalMap[$p->id] ?? ['pending_bodega_action' => false, 'awaiting_compras' => false, 'confirmed_quantity' => 0, 'confirmed_by_name' => null],
                $warehouseCounts[$p->id] ?? 0,
                enExcluida: $enExcluidaMap[$p->id] ?? 0,
            ))->values(),
        ]);
    }

    /**
     * REQ-357/427 — "Confirmar llegada física" (Paso 1 de Bodega). NO factura al inventario (eso
     * sigue siendo `GoodsReceiptController::confirm()`, exclusivo de Compras) — ver docblock de
     * `ArrivalConfirmationService`.
     */
    public function confirmArrival(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (CatalogProduct::find($id) === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'cantidad_entregada' => ['required', 'integer', 'min:1'],
        ]);

        $result = $this->arrivalConfirmation->confirmArrival($id, (int) $validated['cantidad_entregada'], $user->id);

        if ($result['status'] !== 200) {
            return response()->json(['message' => $result['message'] ?? 'Error'], $result['status']);
        }

        return response()->json([
            'confirmed_lines' => $result['confirmed_lines'] ?? [],
            'confirmed_quantity' => $result['confirmed_quantity'] ?? 0,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * REQ-349 (SCRUM-419) — proveedores distintos entre los productos activos del catálogo. Deriva
     * el dato del propio `CatalogProduct` (ya accesible con `bodega.read`) en vez de listar TODOS
     * los proveedores de `Compras\Models\Provider` — evita requerir `compras.read` y evita ofrecer
     * proveedores que ni siquiera tienen un producto asociado en este catálogo.
     *
     * Devuelve {id, name} (no solo el nombre, fix SCRUM-419/422 2026-07-28): el filtro real
     * (`index()` línea ~145-147) exige `provider_id` (entero) porque un producto se asocia por FK,
     * no por nombre — un selector que solo tuviera el nombre no tenía forma de armar ese id.
     *
     * @return list<array{id: int, name: string}>
     */
    private function distinctActiveProviders(): array
    {
        $providerIds = CatalogProduct::where('is_active', true)
            ->whereNotNull('provider_id')
            ->distinct()
            ->pluck('provider_id');

        if ($providerIds->isEmpty()) {
            return [];
        }

        return array_values(
            Provider::whereIn('id', $providerIds)
                ->orderBy('name')
                ->get(['id', 'name'])
                ->map(fn (Provider $p): array => ['id' => $p->id, 'name' => $p->name])
                ->all(),
        );
    }

    /** @return list<string> */
    private function distinctActiveBrands(): array
    {
        /** @var list<string> $brands */
        $brands = CatalogProduct::where('is_active', true)
            ->whereNotNull('brand')
            ->where('brand', '!=', '')
            ->distinct()
            ->orderBy('brand')
            ->pluck('brand')
            ->values()
            ->all();

        return $brands;
    }

    /** @return list<array{warehouse_id: int, name: string|null, quantity: int}> */
    private function warehouseRows(int $productId): array
    {
        $rows = ProductWarehouseStock::with('warehouse')
            ->enBodegaOperativa()
            ->where('catalog_product_id', $productId)
            ->where('quantity', '<>', 0) // SCRUM-1196: también los saldos negativos
            ->get();

        return array_values($rows->map(fn (ProductWarehouseStock $r) => [
            'warehouse_id' => $r->warehouse_id,
            'name' => $r->warehouse?->name,
            'quantity' => $r->quantity,
        ])->values()->all());
    }

    /**
     * @param  list<int>  $productIds
     * @return array<int, int> catalog_product_id => cantidad de bodegas distintas con saldo distinto de 0 (SCRUM-1196: incluye negativas)
     */
    private function warehouseCounts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return ProductWarehouseStock::enBodegaOperativa()
            ->whereIn('catalog_product_id', $productIds)
            ->where('quantity', '<>', 0) // SCRUM-1196: cuenta también las bodegas en negativo
            ->get()
            ->groupBy('catalog_product_id')
            ->map(fn ($rows) => $rows->count())
            ->all();
    }

    /**
     * @param  array{pending_bodega_action: bool, awaiting_compras: bool, confirmed_quantity: int, confirmed_by_name: string|null}  $arrival
     * @return array<string, mixed>
     */
    private function format(
        CatalogProduct $product,
        int $committed,
        int $porIngresar,
        int $enCamino,
        array $arrival,
        int $warehouseCount,
        ?int $warehouseScopedQty = null,
        int $enExcluida = 0,
    ): array {
        // REQ-351 RN1/RN2, Escenario 1 — "Disponible"/"Stock Total"/"Estado" de esta pantalla usan
        // el compromiso a PEDIDOS de Bodega (`$committed`), no el de Ventas & Diseño.
        //
        // SCRUM-361 — cuando la vista está filtrada por `warehouse_id` ($warehouseScopedQty !==
        // null), "Disponible" pasa a ser la cantidad real en ESA bodega puntual (misma fuente,
        // `product_warehouse_stock`, sin ajuste de comprometido — mismo criterio exacto que
        // `InsumoService::list()` usa para "Reserva Servicios"), para que ambos módulos lean
        // siempre el mismo número en la misma ubicación (RN5 de REQ-291). Sin filtro de bodega,
        // sigue siendo el total del producto en todas las ubicaciones menos comprometido, como
        // siempre fue.
        $disponible = $warehouseScopedQty ?? $this->inventoryKpis->disponibleParaVenta($product, $committed, $enExcluida);
        $estado = $this->inventoryKpis->stockState($product, $committed, $enExcluida);
        $family = $product->relationLoaded('families') ? $product->families->first() : null;

        return [
            'id' => $product->id,
            'reference' => $product->reference,
            // REQ-361 — expuesto también en el listado (no solo en `show()`), para que
            // `ProductDetailModal` (frontend) lo arme con los datos que YA trae la fila de la
            // tabla, sin necesitar un fetch aparte por producto.
            'factory_reference' => $product->factory_reference,
            'barcode' => $product->barcode,
            'name' => $product->name,
            'description' => $product->description,
            'category' => $product->category,
            'brand' => $product->brand,
            'photo_url' => $product->photo_url,
            'stock_quantity' => $product->stock_quantity,
            // SCRUM-851 — antes derivado como `$disponible + $committed`, que solo coincidía con
            // el total real cuando nada se excluía de "Disponible"; con `enExcluida` restando,
            // esa suma quedaba corta exactamente en esa cantidad. `stock_quantity` ya es la
            // fuente real (columna cacheada, Inventario Total) — se usa directo, sin derivar.
            'stock_total' => $product->stock_quantity,
            'por_servir' => $committed,
            'disponible' => $disponible,
            'por_ingresar' => $porIngresar,
            'en_camino' => $enCamino,
            'reorder_point' => $product->reorder_point,
            'estado' => $estado,
            'rotation' => $product->rotation,
            'provider_id' => $product->provider_id,
            'provider_name' => $product->provider?->name,
            'family_id' => $family?->id,
            'family_name' => $family?->name,
            'is_active' => $product->is_active,
            // SCRUM-425 (REQ-355, RN1) — ficha técnica de solo lectura, mismo campo/shape que
            // `Compras\Http\Controllers\InventoryController::format()` (misma columna JSONB de
            // `CatalogProduct`), para que `TechnicalSpecModal.tsx` (compartido entre Compras y
            // Bodega) lo reciba sin cambios. `null` para productos creados antes de SCRUM-426 —
            // el frontend muestra el estado "no disponible" en ese caso.
            'technical_spec' => $product->technical_spec,
            'warehouse_count' => $warehouseCount,
            'arrival_confirmation' => [
                // REQ-427 RN2 — el frontend muestra el botón "Confirmar llegada física" solo
                // cuando `pending_bodega_action` es true, y la nota de espera cuando
                // `awaiting_compras` es true (ambos pueden ser true a la vez si un reparto parcial
                // dejó una línea sin tocar — ver `ArrivalConfirmationService::confirmArrival()`).
                'pending_bodega_action' => $arrival['pending_bodega_action'],
                'awaiting_compras' => $arrival['awaiting_compras'],
                // Rebote REQ-427 (Gerencia Test 2026-08-13) — la nota de espera debe mostrar
                // cantidad + quién confirmó, no un genérico "esperando a Compras".
                'confirmed_quantity' => $arrival['confirmed_quantity'],
                'confirmed_by_name' => $arrival['confirmed_by_name'],
            ],
        ];
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
