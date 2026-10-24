<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Services\UatVisibilityService;
use App\Modules\Auth\Support\ModuleCatalog;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\ArrivalConfirmationService;
use App\Modules\Compras\Http\Requests\StoreInventoryProductRequest;
use App\Modules\Compras\Http\Requests\StoreInventoryProductTechnicalSheetRequest;
use App\Modules\Compras\Http\Requests\UpdateInventoryProductRequest;
use App\Modules\Compras\Http\Requests\UploadInventoryProductPhotoRequest;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Modules\VentasDiseno\Services\CatalogService;
use App\Shared\Http\Pagination;
use App\Shared\Http\QueryParams;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-231→244 (REQ-168→181) — Inventario. Reusa `CatalogProduct` (VentasDiseno) en vez de una
 * tabla nueva (mismo precedente sprint2, ver docblock de 2026_07_20_100003). Gateado por
 * `compras.*` (no `bodega.*`) porque las 14 tickets dicen explícitamente "Compras y Gerencia" en
 * su sección PERMISOS — confirmado con Luis 2026-07-17, el gateo `modules.bodega.view` que ya
 * traía el Sidebar era un placeholder nunca validado contra esta spec.
 *
 * SCRUM-235/236 (REQ-172/173, columnas "Por ingresar"/"En camino" y confirmar ingreso) — ya
 * conectados a Ingreso de Mercancía real (`InventoryKpiService::porIngresarMap()`/`enCaminoMap()`,
 * SCRUM-220→230). RN5 de REQ-172 sigue vigente: no hay forma manual de marcar un producto "Por
 * ingresar", solo se origina de un registro real en `GoodsReceiptController`. Botón "Ingresar a
 * Inventario" (REQ-173) es `POST /compras/goods-receipts/products/{productId}/confirm-pending`
 * (`GoodsReceiptController::confirmPendingForProduct()`) — confirma TODAS las líneas pendientes
 * del producto de una vez, cada una a su propia bodega ya elegida al ingresar (RN1). `toggleActive()`
 * bloquea inactivar un producto mientras tenga unidades "Por ingresar" sin confirmar.
 */
final class InventoryController extends Controller
{
    /**
     * SCRUM-237/240 — `description` reemplazado por `name` (nombre visible real del producto, ver
     * migración `2026_08_15_100000_...`); `description` deja de ser el criterio de orden porque ya
     * no es el nombre.
     *
     * @var list<string>
     */
    private const ALLOWED_SORTS = ['reference', 'name', 'price_full', 'stock_quantity', 'created_at'];

    public function __construct(
        private readonly InventoryKpiService $inventoryKpis,
        private readonly CatalogService $catalogService,
        private readonly ArrivalConfirmationService $arrivalConfirmation,
    ) {}

    /**
     * SCRUM-237 (REQ-174, hallazgo de Gerencia Test 2026-08-09) — validación de unicidad de
     * Referencia pública EN VIVO mientras se escribe (mismo criterio que crear un producto,
     * REQ-178), reutilizando la misma tabla/columna que `StoreInventoryProductRequest`/
     * `UpdateInventoryProductRequest` ya validan al guardar — este endpoint es solo un chequeo de
     * lectura anticipado, el guardado real sigue validando de nuevo server-side (nunca confiar
     * solo en el chequeo en vivo del cliente).
     */
    public function checkReference(Request $request): JsonResponse
    {
        $reference = trim(QueryParams::string($request, 'reference'));
        $excludeId = $request->query('exclude_id');

        if ($reference === '') {
            return response()->json(['available' => true]);
        }

        $existing = CatalogProduct::where('reference', $reference)
            ->when($excludeId !== null, fn ($q) => $q->where('id', '!=', (int) $excludeId))
            ->first();

        if ($existing === null) {
            return response()->json(['available' => true]);
        }

        return response()->json([
            'available' => false,
            'message' => "La referencia pública '{$reference}' ya pertenece al producto '{$existing->name}'.",
        ]);
    }

    /**
     * SCRUM-237 (REQ-174, hallazgo de Gerencia Test 2026-08-09) — "Ficha técnica" como DOCUMENTO
     * (archivo o link externo), distinto del bloque de 12 campos estructurados `technical_spec`
     * (SCRUM-425/426) que ya existe en este mismo modal. Proxy delgado a `CatalogService`
     * (SCRUM-698/REQ-618, ya construido para la pantalla Catálogo de Ventas & Diseño) — no se
     * duplica la lógica de upload/versionado; solo se re-gatea con `compras.*` en vez de
     * `ventas_diseno.read` para que un usuario de Compras sin ese permiso también pueda usarlo
     * desde Inventario. Ver nota de alcance en el comentario del ticket sobre la ambigüedad de
     * tener 2 "fichas técnicas" con el mismo nombre visible en el mismo modal.
     */
    public function technicalSheetUrl(int $id): JsonResponse
    {
        $product = CatalogProduct::find($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($product->technical_sheet_key === null) {
            return response()->json(['message' => 'No technical sheet uploaded'], 404);
        }

        // Caso B "link externo" (ver uploadTechnicalSheet()) — la key guardada YA es la URL
        // completa, no una key de S3: devolverla tal cual en vez de pedirle a CatalogService una
        // presigned URL (rompería, `Storage::temporaryUrl()` espera una key real del disco).
        if (str_starts_with($product->technical_sheet_key, 'http://') || str_starts_with($product->technical_sheet_key, 'https://')) {
            return response()->json(['url' => $product->technical_sheet_key]);
        }

        $url = $this->catalogService->technicalSheetUrl($product);
        if ($url === null) {
            return response()->json(['message' => 'No technical sheet uploaded'], 404);
        }

        return response()->json(['url' => $url]);
    }

    public function uploadTechnicalSheet(StoreInventoryProductTechnicalSheetRequest $request, int $id): JsonResponse
    {
        $product = CatalogProduct::find($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var UploadedFile|null $file */
        $file = $request->file('file');
        if ($file !== null) {
            $this->catalogService->uploadTechnicalSheet($product, $file);
        } else {
            // REQ-174 Caso A/B — alternativa de link externo: se persiste como "documento"
            // igual, reusando las mismas 3 columnas (`technical_sheet_key` guarda la URL
            // completa en vez de una key de S3; `technicalSheetUrl()`/`hasTechnicalSheet()` no
            // necesitan saber la diferencia, ambos casos son "hay un documento o no").
            $product->update([
                'technical_sheet_key' => (string) $request->input('link'),
                'technical_sheet_filename' => (string) $request->input('link'),
                'technical_sheet_uploaded_at' => now(),
            ]);
        }

        return response()->json([
            'id' => $product->id,
            'has_technical_sheet' => $product->hasTechnicalSheet(),
            'technical_sheet_filename' => $product->technical_sheet_filename,
            'technical_sheet_uploaded_at' => $product->technical_sheet_uploaded_at?->toIso8601String(),
        ]);
    }

    /**
     * SCRUM-830 — reemplazo de imagen en edición. Mismo mecanismo de creación (RN08/RN09): esta
     * ruta SIEMPRE exige un archivo válido (`UploadInventoryProductPhotoRequest`), así que
     * `PUT /inventory/{id}` (UpdateInventoryProductRequest, sin campo de imagen) nunca puede dejar
     * un producto sin foto — la única forma de tocar la imagen es este endpoint, que nunca borra
     * la anterior hasta confirmar la nueva (ver `CatalogService::uploadPhoto()`).
     */
    public function uploadPhoto(UploadInventoryProductPhotoRequest $request, int $id): JsonResponse
    {
        $product = CatalogProduct::find($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var UploadedFile $file */
        $file = $request->validated()['photo'];
        $this->catalogService->uploadPhoto($product, $file);

        return response()->json([
            'id' => $product->id,
            'photo_url' => $this->catalogService->photoThumbnailUrl($product),
            'photo_full_url' => $this->catalogService->photoUrl($product),
            'has_uploaded_photo' => $product->hasUploadedPhoto(),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $access = $this->resolveAccess($request);
        if ($access === null) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }
        [$user, $restricted, $canManage] = $access;

        $chip = QueryParams::string($request, 'chip', 'todos');
        $warehouseIds = $this->parseWarehouseIds($request);
        $familyId = $this->parseFamilyId($request);
        $pagination = Pagination::params($request, self::ALLOWED_SORTS, 'reference');

        // SCRUM-993 — filtro, orden y paginación en SQL. Antes: `->get()->all()` del catálogo completo
        // (~30K productos con 2 relaciones eager) + 4 mapas por ID + `usort` en PHP, y recién al final
        // `Pagination::ofArray()` — agotaba `memory_limit` (256 MB) con el catálogo de Lafayette.
        // Las relaciones (`families`, `provider`) se cargan SOLO para las filas de la página.
        $query = $this->inventoryKpis->withAggregates(
            $this->inventoryKpis->baseQuery()
                ->when($chip !== 'inactivos', fn ($q) => $q->where('cp.is_active', true))
                ->when($chip === 'inactivos', fn ($q) => $q->where('cp.is_active', false))
                // SCRUM-743 — `warehouse_ids[]` ausente o vacío = sin filtro (todas las bodegas, nunca
                // tabla vacía por default, criterio explícito del ticket). Con 1+ IDs: unión/OR entre
                // bodegas seleccionadas — un producto con stock en CUALQUIERA de las bodegas elegidas
                // matchea. `quantity > 0` usa el mismo criterio que `InventoryKpiService::warehousesMap()`
                // (la columna "Bodega(s)" ya solo muestra bodegas con stock > 0), para que el filtro
                // nunca devuelva un producto que la propia tabla no puede mostrar como "en esa bodega".
                ->when($warehouseIds !== [], fn ($q) => $q->whereIn('cp.id', fn ($sub) => $sub
                    ->select('catalog_product_id')
                    ->from((new ProductWarehouseStock)->getTable())
                    ->whereIn('warehouse_id', $warehouseIds)
                    ->where('quantity', '>', 0)))
                // SCRUM-1010 — `family_id` ausente o inválido = sin filtro. Una sola familia (select, no
                // multiselección): la relación es N:M, así que un producto compartido aparece al filtrar
                // por cualquiera de sus familias. Subconsulta sobre el pivot (índice único
                // (family_id, catalog_product_id)), nunca carga la familia en PHP — mismo criterio SQL
                // de SCRUM-993 con el catálogo completo.
                ->when($familyId !== null, fn ($q) => $q->whereIn('cp.id', fn ($sub) => $sub
                    ->select('catalog_product_id')
                    ->from((new CatalogProductFamily)->products()->getTable())
                    ->where('family_id', $familyId))),
        );

        $search = trim(QueryParams::string($request, 'search'));
        $fuzzy = false;
        if ($search !== '') {
            // SCRUM-992 — búsqueda en SQL sobre `search_text` (índice trigram, sin acentos), con respaldo
            // tolerante a typos solo si la parcial da 0 resultados. Ya no recorre las ~30K filas en PHP.
            $fuzzy = CatalogProduct::applySearch($query, $search, fuzzyFallback: true, prefix: 'cp.');
        }

        // REQ-172 Escenario 1/REQ-235, REQ-112, SCRUM-168 RN2 — chips por_ingresar / en_atencion /
        // bajo_stock / sin_stock / bajo_stock_sin_ordenar: las reglas viven en `InventoryKpiService`
        // (`sql*()`), la misma fuente que `kpis()` y `stockState()`, nunca una definición paralela.
        $this->inventoryKpis->applyChip($query, $chip);

        $this->applySort($query, $pagination['sort_by'], $pagination['sort_dir']);
        $paginator = $query->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);
        (new \Illuminate\Database\Eloquent\Collection($paginator->items()))->load(['families', 'provider', 'providers']);

        // SCRUM-234/238 — "Bodega(s)" solo se calcula para la página actual.
        $pageIds = array_values(array_map(fn (CatalogProduct $p): int => $p->id, $paginator->items()));
        $warehousesMap = $this->inventoryKpis->warehousesMap($pageIds);
        $showroomMap = $this->inventoryKpis->enShowroomMap($pageIds); // SCRUM-1196 — una consulta agregada por página

        return response()->json([
            'fuzzy' => $fuzzy,
            'restricted' => $restricted,
            'can_manage' => $canManage,
            // SCRUM-1013 (Pre-QA H1) — en modo restringido (vendedor) `total_value` (precio × stock) tampoco viaja:
            // la pantalla lo ocultaba pero la API lo devolvía igual (familias ya lo enmascaraba).
            'kpis' => $restricted ? [...$this->kpis(), 'total_value' => null] : $this->kpis(),
            'data' => array_map(
                fn (CatalogProduct $p) => $this->format(
                    $p,
                    (int) $p->getAttribute('por_servir'),
                    $restricted,
                    (int) $p->getAttribute('por_ingresar'),
                    (int) $p->getAttribute('en_camino'),
                    $warehousesMap[$p->id] ?? [],
                    enExcluida: (int) $p->getAttribute('en_excluida'),
                    showroom: $showroomMap[$p->id] ?? 0,
                ),
                $paginator->items(),
            ),
            'meta' => Pagination::meta($paginator),
        ]);
    }

    /**
     * SCRUM-993 — orden en SQL equivalente al `usort` anterior: texto con `strcasecmp` (minúsculas, byte a
     * byte → `LOWER() COLLATE "C"`, NULL como cadena vacía) y números/fechas con NULL primero en asc y
     * último en desc (el `<=>` de PHP trata null como el menor). Desempate estable por `id`.
     *
     * @param  Builder<CatalogProduct>  $query
     */
    private function applySort($query, string $field, string $dir): void
    {
        $desc = $dir === 'desc';
        $column = 'cp.'.$field; // `$field` ya viene validado contra ALLOWED_SORTS por Pagination::params()

        if (in_array($field, ['reference', 'name'], true)) {
            $query->orderByRaw("LOWER(COALESCE({$column}, '')) COLLATE \"C\" ".($desc ? 'DESC' : 'ASC'));
        } else {
            $query->orderByRaw("{$column} ".($desc ? 'DESC NULLS LAST' : 'ASC NULLS FIRST'));
        }
        $query->orderBy('cp.id');
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $access = $this->resolveAccess($request);
        if ($access === null) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }
        [, $restricted] = $access;

        $product = CatalogProduct::with(['families', 'provider', 'providers'])->find($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $porServir = $this->inventoryKpis->porServirMap([$id])[$id] ?? 0;
        $porIngresar = $this->inventoryKpis->porIngresarMap([$id])[$id] ?? 0;
        $enCamino = $this->inventoryKpis->enCaminoMap([$id])[$id] ?? 0;
        $warehouses = $this->inventoryKpis->warehousesMap([$id])[$id] ?? [];
        $enExcluida = $this->inventoryKpis->enBodegaExcluidaMap([$id])[$id] ?? 0;
        $showroom = $this->inventoryKpis->enShowroomMap([$id])[$id] ?? 0;
        // Rebote REQ-427 (Gerencia Test 2026-08-13) — el detalle de Compras debe reflejar si
        // Bodega ya confirmó la llegada física (cantidad + quién), solo en el detalle completo
        // (no en index(), para no pagar 1 query extra por fila de la tabla).
        $arrival = $this->arrivalConfirmation->pendingSummary($id);

        return response()->json($this->format($product, $porServir, $restricted, $porIngresar, $enCamino, $warehouses, includeRestrictedDetail: true, arrival: $arrival, enExcluida: $enExcluida, showroom: $showroom));
    }

    public function store(StoreInventoryProductRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validated();

        // SCRUM-240 (corrección de Gerencia Test 2026-08-09) — "Stock inicial (opcional)" es
        // ahora una entrada por bodega (`initial_stock`), no una sola bodega — `stock_quantity`
        // de la columna se deriva como la suma (sigue siendo la fuente que el resto de Inventario
        // ya lee para "Stock"/KPIs, no se duplica ese cálculo en otro lado).
        /** @var list<array{warehouse_id: int, quantity: int}> $initialStock */
        $initialStock = $validated['initial_stock'] ?? [];
        $totalStockQuantity = array_sum(array_column($initialStock, 'quantity'));

        /** @var UploadedFile|null $technicalSheetFile */
        $technicalSheetFile = $request->file('file');
        $technicalSheetLink = $request->input('link');

        /** @var UploadedFile $photoFile */
        $photoFile = $request->file('photo');

        $product = DB::transaction(function () use (
            $validated, $initialStock, $totalStockQuantity, $technicalSheetFile, $technicalSheetLink, $photoFile,
        ): CatalogProduct {
            // RN1/Escenario 1 (REQ-177): el stock inicial cuenta como disponible de inmediato,
            // nunca pasa por "Por ingresar" — a diferencia de un producto creado desde una orden.
            $product = CatalogProduct::create([
                'reference' => $validated['reference'],
                'factory_reference' => $validated['factory_reference'],
                'name' => $validated['name'],
                'description' => $validated['description'] ?? '',
                'brand' => $validated['brand'],
                'barcode' => $validated['barcode'],
                'photo_url' => $validated['photo_url'] ?? null,
                'price_full' => $validated['price_full'],
                'cost' => $validated['cost'],
                // SCRUM-240 — "Costo adicional estimado" ($ o % ya resueltos por el frontend a un
                // monto, ver docblock de StoreInventoryProductRequest) se persiste en `other_cost`
                // (parte de CatalogProduct::costTotal()), mismo campo que la edición ya usa para
                // ajustes de costo post-creación — "es un estimado, se reemplaza cuando llegue el
                // Ingreso de Mercancía real" encaja con cómo `other_cost` ya se trata ahí.
                'other_cost' => $validated['other_cost'],
                'reorder_point' => $validated['reorder_point'],
                'provider_id' => $validated['provider_id'],
                'category' => $validated['category'],
                'rotation' => $validated['rotation'],
                'stock_quantity' => $totalStockQuantity,
                'is_active' => true,
            ]);

            if (isset($validated['family_id'])) {
                $product->families()->sync([$validated['family_id']]);
            }

            // SCRUM-1126 — pivote de proveedores (el principal siempre incluido).
            $product->syncProviders($validated['provider_ids'] ?? [], (int) $validated['provider_id']);

            foreach ($initialStock as $entry) {
                if ($entry['quantity'] <= 0) {
                    continue;
                }
                ProductWarehouseStock::create([
                    'catalog_product_id' => $product->id,
                    'warehouse_id' => $entry['warehouse_id'],
                    'quantity' => $entry['quantity'],
                ]);
            }

            // SCRUM-426 — mismo mecanismo que uploadTechnicalSheet(), pero DENTRO de esta
            // transacción: si algo falla acá, el producto tampoco queda creado (nunca un producto
            // sin ficha técnica en la base, ni siquiera transitoriamente).
            if ($technicalSheetFile !== null) {
                $this->catalogService->uploadTechnicalSheet($product, $technicalSheetFile);
            } else {
                $product->update([
                    'technical_sheet_key' => (string) $technicalSheetLink,
                    'technical_sheet_filename' => (string) $technicalSheetLink,
                    'technical_sheet_uploaded_at' => now(),
                ]);
            }

            // SCRUM-830 — mismo criterio que la ficha técnica de arriba: DENTRO de esta transacción,
            // nunca un producto sin imagen en la base ni siquiera transitoriamente (RN01/RN02/RN08).
            $this->catalogService->uploadPhoto($product, $photoFile);

            return $product;
        });

        // `format()` lee `family`/`provider_name` vía relationLoaded() — sin este load() vuelven
        // null aunque family_id/provider_id ya estén seteados (mismo criterio que update()).
        $product->load(['families', 'provider', 'providers']);

        return response()->json($this->format($product, porServir: 0, restricted: false, porIngresar: 0, enCamino: 0), 201);
    }

    public function update(UpdateInventoryProductRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $product = CatalogProduct::find($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validated();

        // SCRUM-237 — `family_id` no es una columna de catalog_products (relación N:M, ver
        // CatalogProduct::families()), se sincroniza aparte del resto de campos.
        $hasFamilyId = array_key_exists('family_id', $validated);
        $familyId = $validated['family_id'] ?? null;
        unset($validated['family_id']);

        // SCRUM-1126 — `provider_ids` tampoco es columna: se sincroniza en la pivote. `provider_id` (principal)
        // ya viene resuelto por UpdateInventoryProductRequest::prepareForValidation().
        $hasProviderIds = array_key_exists('provider_ids', $validated);
        $providerIds = $validated['provider_ids'] ?? [];
        unset($validated['provider_ids']);

        DB::transaction(function () use ($product, $validated, $hasProviderIds, $providerIds, $hasFamilyId, $familyId): void {
            $product->update($validated);
            if ($hasProviderIds) {
                $product->syncProviders($providerIds, $product->provider_id);
            } elseif (array_key_exists('provider_id', $validated)) {
                if ($product->provider_id !== null) {
                    // Cambio de principal sin lista: el nuevo principal entra a la pivote, los demás se conservan.
                    $product->providers()->syncWithoutDetaching([$product->provider_id]);
                } else {
                    // provider_id null sin lista = producto sin proveedores (igual que provider_ids: []): sin filas viejas en la pivote.
                    $product->providers()->sync([]);
                    $product->unsetRelation('providers');
                }
            }
            if ($hasFamilyId) {
                $product->families()->sync($familyId !== null ? [$familyId] : []);
            }
        });
        $product->load(['families', 'provider', 'providers']);

        $porServir = $this->inventoryKpis->porServirMap([$id])[$id] ?? 0;
        $porIngresar = $this->inventoryKpis->porIngresarMap([$id])[$id] ?? 0;
        $enCamino = $this->inventoryKpis->enCaminoMap([$id])[$id] ?? 0;
        $warehouses = $this->inventoryKpis->warehousesMap([$id])[$id] ?? [];

        return response()->json($this->format($product, $porServir, restricted: false, porIngresar: $porIngresar, enCamino: $enCamino, warehouses: $warehouses));
    }

    /** SCRUM-239 (REQ-176) — activar/inactivar, sin pérdida de datos (stock/referencias intactos). */
    public function toggleActive(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $product = CatalogProduct::find($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $porIngresar = $this->inventoryKpis->porIngresarMap([$id])[$id] ?? 0;

        // REQ-173 RN — mientras un producto tiene unidades "Por ingresar" sin confirmar, no se
        // puede marcar inactivo (evita que quede mercancía físicamente en bodega sin poder
        // confirmarla porque el producto ya no aparece en las vistas activas de Inventario).
        if ($product->is_active && $porIngresar > 0) {
            return response()->json([
                'message' => 'No se puede inactivar un producto con unidades "Por ingresar" sin confirmar.',
            ], 422);
        }

        $product->update(['is_active' => ! $product->is_active]);
        $product->load(['provider', 'providers']);

        $porServir = $this->inventoryKpis->porServirMap([$id])[$id] ?? 0;
        $enCamino = $this->inventoryKpis->enCaminoMap([$id])[$id] ?? 0;
        $warehouses = $this->inventoryKpis->warehousesMap([$id])[$id] ?? [];

        return response()->json($this->format($product, $porServir, restricted: false, porIngresar: $porIngresar, enCamino: $enCamino, warehouses: $warehouses));
    }

    /** SCRUM-244 (REQ-181) — desglose de stock por bodega; todas las bodegas con saldo distinto de 0 (SCRUM-1196: también las negativas). */
    public function warehouseBreakdown(Request $request, int $id): JsonResponse
    {
        $access = $this->resolveAccess($request);
        if ($access === null) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $product = CatalogProduct::find($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // SCRUM-990: una fila residual contra una bodega de solo consulta (Zona Libre) no es stock de Atlantic.
        $rows = ProductWarehouseStock::with('warehouse')
            ->enBodegaOperativa()
            ->where('catalog_product_id', $id)
            ->where('quantity', '<>', 0) // SCRUM-1196: también los negativos; la suma coincide con el Stock total
            ->get();

        return response()->json([
            'data' => $rows->map(fn (ProductWarehouseStock $r) => [
                'warehouse_id' => $r->warehouse_id,
                'warehouse_name' => $r->warehouse?->name,
                'quantity' => $r->quantity,
            ])->values(),
        ]);
    }

    /**
     * SCRUM-242 (REQ-179 Escenario 1) — aviso de mercancía ya en camino antes de abrir el
     * formulario de Nueva Orden, más una cantidad sugerida. "En camino" = mismo cálculo que la
     * columna "En camino" de Inventario (REQ-172, `InventoryKpiService::enCaminoMap()`): lo
     * esperado de órdenes todavía no "Recibido" menos lo ya registrado (confirmado o no).
     */
    public function orderPrefill(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $product = CatalogProduct::find($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $enCamino = $this->inventoryKpis->enCaminoMap([$id])[$id] ?? 0;

        $suggestedQuantity = max(1, ($product->reorder_point ?? 0) - ($product->stock_quantity ?? 0));

        return response()->json([
            'provider_id' => $product->provider_id,
            'catalog_product_id' => $product->id,
            'suggested_quantity' => $suggestedQuantity,
            'has_pending_shipment' => $enCamino > 0,
            'pending_quantity' => $enCamino,
        ]);
    }

    /**
     * SCRUM-764 — listado de familias para la pestaña "Familias" de Inventario. Faltaba: el
     * frontend (`InventarioPage.tsx`) llamaba directo a `CatalogProductFamilyController::index()`
     * de VentasDiseno (`GET /ventas-diseno/catalog-product-families`), gateado por
     * `permission:ventas_diseno.read` — un permiso que ningún rol real de Compras (`lider_compras`
     * y similares) tiene asignado en `role_module_visibility`. Resultado: 403 real para Lider Compras
     * (líder_compras) al abrir el tab en test.atlanticerp.ai, confirmado en vivo. Endpoint propio,
     * mismo `resolveAccess()`/`restricted` que `familyDetail()` (compras.read completo O
     * ventas_diseno.read en modo restringido).
     *
     * `total_value` agregado en Lote 4 (SCRUM-243, 2026-08-17, mockup del ticket) — antes solo
     * viajaba en el detalle porque ningún consumidor lo necesitaba en el listado; el mockup nuevo
     * pide mostrarlo en cada card COLAPSADA, sin expandir. Eager-load de `products` (no
     * `withCount`) para calcularlo con una sola query extra en vez de N+1, misma fórmula que
     * `familyDetail()` (Costo Total × Stock, sumado) — `null` en modo restringido, igual que ahí.
     */
    public function families(Request $request): JsonResponse
    {
        $access = $this->resolveAccess($request);
        if ($access === null) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }
        [, $restricted, $canManage] = $access;

        $families = CatalogProductFamily::where('is_active', true)
            ->with(['products' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        $rows = $families->map(fn (CatalogProductFamily $f) => [
            'id' => $f->id,
            'name' => $f->name,
            'description' => $f->description,
            'product_count' => $f->products->count(),
            'total_value' => $restricted ? null : $f->products->sum(
                fn (CatalogProduct $p) => $p->costTotal() * (int) ($p->stock_quantity ?? 0)
            ),
        ])->values();

        // SCRUM-754 — sin filtros/sort en esta pestaña hoy, solo per_page/page.
        $pagination = Pagination::params($request, [], 'name');
        $paginator = Pagination::ofArray(array_values($rows->all()), $pagination['per_page'], $pagination['page']);

        return response()->json([
            // Lote 4 — expuesto a nivel de respuesta (no solo `total_value: null` por familia) para
            // que el frontend pueda ocultar "Generar compra" sin depender de mirar cada fila.
            'restricted' => $restricted,
            'can_manage' => $canManage,
            'data' => array_values($paginator->items()),
            'meta' => Pagination::meta($paginator),
        ]);
    }

    /**
     * SCRUM-243 (REQ-180) — detalle de una familia para la pestaña "Familias" de Inventario, CON
     * costo y valor total (Costo Total × Stock Total por producto, sumado). Endpoint propio en
     * Compras (no reusa CatalogProductFamilyController::show() de VentasDiseno) porque ese
     * endpoint nunca expone `cost` a propósito (REQ-036 AC2, Catálogo de Ventas & Diseño) —
     * mezclar ambos casos de uso en un solo endpoint filtraría costo a Ventas & Diseño. Mismo
     * gateo `resolveAccess()`/`restricted` que el resto de Inventario: en modo restringido se
     * devuelven los productos pero sin costo ni valor total.
     *
     * Alcance ampliado en Lote 4 (2026-08-17, mockup del ticket): `products` pasa de un shape
     * mínimo propio a exactamente el mismo `format()` que usa `index()` (tabla "Productos") — el
     * mockup pide la tabla completa de 21 columnas al expandir una familia, igual a Productos, no
     * un resumen aparte. Mismos mapas de KPI (por servir/por ingresar/en camino/bodegas) que
     * `index()`, calculados solo para los productos de esta familia.
     */
    public function familyDetail(Request $request, int $id): JsonResponse
    {
        $access = $this->resolveAccess($request);
        if ($access === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        [, $restricted, $canManage] = $access;

        $family = CatalogProductFamily::where('is_active', true)->find($id);
        if ($family === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // RN (REQ-180) — inactivos y productos "Por ingresar" (alta automática vía ingreso, RN de
        // REQ-166, sin implementar — ver docblock de `GoodsReceiptController`) nunca aparecen
        // agrupados acá; un producto YA existente con algunas unidades "Por ingresar" sí aparece
        // (ese eje no lo excluye de su familia, solo se ve en la columna de la fila).
        $products = $family->products()->where('is_active', true)->with('provider')->orderBy('name')->get();

        $totalValue = $restricted ? null : $products->sum(
            fn (CatalogProduct $p) => $p->costTotal() * (int) ($p->stock_quantity ?? 0)
        );

        /** @var list<int> $productIds */
        $productIds = $products->map(fn (CatalogProduct $p): int => $p->id)->values()->all();
        $porServirMap = $this->inventoryKpis->porServirMap($productIds);
        $porIngresarMap = $this->inventoryKpis->porIngresarMap($productIds);
        $enCaminoMap = $this->inventoryKpis->enCaminoMap($productIds);
        $warehousesMap = $this->inventoryKpis->warehousesMap($productIds);

        return response()->json([
            'id' => $family->id,
            'name' => $family->name,
            'description' => $family->description,
            'restricted' => $restricted,
            'can_manage' => $canManage,
            'total_value' => $totalValue,
            'product_count' => $products->count(),
            'products' => $products->map(fn (CatalogProduct $p) => $this->format(
                $p,
                $porServirMap[$p->id] ?? 0,
                $restricted,
                $porIngresarMap[$p->id] ?? 0,
                $enCaminoMap[$p->id] ?? 0,
                $warehousesMap[$p->id] ?? [],
            ))->values(),
        ]);
    }

    /**
     * SCRUM-243 (REQ-180 Escenario 1) — genera 1 orden "Por aprobar" por proveedor distinto entre
     * los productos activos de la familia, nunca una mezclada. No incluye asignación de proyecto
     * por línea todavía (marcado "todavía no aplicado" en el propio criterio de aceptación).
     */
    public function generateFamilyPurchase(Request $request, int $familyId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $family = CatalogProductFamily::find($familyId);
        if ($family === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var Collection<int, CatalogProduct> $products */
        $products = $family->products()->where('is_active', true)->whereNotNull('provider_id')->get();

        if ($products->isEmpty()) {
            return response()->json(['message' => 'La familia no tiene productos activos con proveedor asignado.'], 422);
        }

        $orders = DB::transaction(function () use ($products, $user): array {
            $byProvider = $products->groupBy('provider_id');
            $created = [];

            foreach ($byProvider as $providerId => $providerProducts) {
                $order = PurchaseOrder::create([
                    'provider_id' => (int) $providerId,
                    'status' => PurchaseOrder::STATUS_POR_APROBAR,
                    'status_changed_at' => now(),
                    'modality' => PurchaseOrder::MODALITY_DIRECTO,
                    'requires_primary_approval' => false,
                    'currency' => 'USD',
                    'created_by' => $user->id,
                ]);

                $totalAmount = 0.0;
                foreach ($providerProducts as $product) {
                    $quantity = max(1, ($product->reorder_point ?? 0) - ($product->stock_quantity ?? 0));
                    $unitCost = $product->costTotal();
                    $subtotal = $unitCost * $quantity;
                    $totalAmount += $subtotal;

                    PurchaseOrderLine::create([
                        'purchase_order_id' => $order->id,
                        'catalog_product_id' => $product->id,
                        'quantity' => $quantity,
                        'unit_cost' => $unitCost,
                        'subtotal' => $subtotal,
                    ]);
                }

                $order->update(['total_amount' => $totalAmount]);
                $created[] = $order->id;
            }

            return $created;
        });

        return response()->json(['order_ids' => $orders], 201);
    }

    /**
     * SCRUM-237/240 (REQ-174/177, rebote de Gerencia Test 2026-08-12) — hasta ahora el selector de
     * Familia solo permitía elegir de las ya sembradas por seeder (ver docblock de
     * `CatalogProductFamily`), ningún endpoint de creación existía. find-or-create por nombre
     * (case-insensitive, para no crear "Kit Baño"/"kit baño" como 2 familias distintas) — la
     * familia nueva queda disponible de inmediato en TODOS los desplegables de Familia de la app
     * (este modal, SCRUM-418, Catálogo de Ventas & Diseño), no hay un scope aparte por módulo.
     * Mismo endpoint reusado por los 2 modales que lo piden (edición SCRUM-237, creación
     * SCRUM-240) — no se duplica.
     */
    public function storeFamily(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $name = trim((string) $request->input('name', ''));
        if ($name === '') {
            return response()->json(['message' => 'El nombre de la familia es obligatorio.'], 422);
        }

        $family = CatalogProductFamily::where('is_active', true)
            ->whereRaw('lower(name) = ?', [mb_strtolower($name)])
            ->first();

        $created = false;
        if ($family === null) {
            $family = CatalogProductFamily::create(['name' => $name, 'is_active' => true]);
            $created = true;
        }

        return response()->json([
            'id' => $family->id,
            'name' => $family->name,
            'description' => $family->description,
        ], $created ? 201 : 200);
    }

    /** SCRUM-240 (REQ-177) — catálogo fijo de bodegas, para el selector al crear un producto. */
    public function warehouses(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json([
            'data' => Warehouse::operativas()->orderBy('id')->get(['id', 'name']),
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * REQ-169 — extraído a InventoryKpiService para reusarlo byte-a-byte en REQ-204 (Reportes).
     *
     * @return array<string, mixed>
     */
    private function kpis(): array
    {
        return $this->inventoryKpis->kpis();
    }

    /**
     * SCRUM-743 — `warehouse_ids[]` en query string. Laravel ya parsea
     * `?warehouse_ids[]=1&warehouse_ids[]=2` (serialización estándar de arrays de Axios/PHP) como
     * array nativo en `$request->query('warehouse_ids')`; acá se tolera además un único valor
     * escalar (`?warehouse_ids=3`, PHP lo entrega como string, no array) para no exigirle al
     * frontend el sufijo `[]` cuando hay una sola bodega seleccionada. IDs no numéricos/≤0 se
     * descartan en silencio en vez de romper la query — un ID de bodega inválido no debe tumbar el
     * listado completo de Inventario.
     *
     * @return list<int>
     */
    private function parseWarehouseIds(Request $request): array
    {
        $raw = $request->query('warehouse_ids', []);
        if (! is_array($raw)) {
            $raw = [$raw];
        }

        return array_values(array_unique(array_filter(
            array_map(fn ($v): int => is_numeric($v) ? (int) $v : 0, $raw),
            fn (int $id): bool => $id > 0,
        )));
    }

    /** SCRUM-1010 — `family_id` entero positivo; cualquier otra cosa (ausente, vacío, no numérico, 0) = sin filtro. */
    private function parseFamilyId(Request $request): ?int
    {
        $raw = $request->query('family_id');

        return is_numeric($raw) && (int) $raw > 0 ? (int) $raw : null;
    }

    /**
     * @param  list<array{warehouse_id: int, warehouse_name: string|null, quantity: int}>  $warehouses
     * @param  array{pending_bodega_action: bool, awaiting_compras: bool, confirmed_quantity: int, confirmed_by_name: string|null}|null  $arrival
     * @return array<string, mixed>
     */
    private function format(CatalogProduct $product, int $porServir, bool $restricted, int $porIngresar = 0, int $enCamino = 0, array $warehouses = [], bool $includeRestrictedDetail = false, ?array $arrival = null, int $enExcluida = 0, int $showroom = 0): array
    {
        // REQ-172 Escenario 1/2 — "Disponible" nunca cuenta lo "Por ingresar" (llegó, falta
        // confirmar) hasta que se confirma; "En camino" (todavía no llegó de ninguna forma) es un
        // eje aparte que no afecta ni a Disponible ni al estado de stock. SCRUM-851 — tampoco
        // cuenta lo ubicado en una bodega excluida (Reserva Servicios/Merma/Reclamos).
        $disponible = $this->inventoryKpis->disponibleParaVenta($product, $porServir, $enExcluida);
        $estado = match (true) {
            $disponible <= 0 => 'sin_stock',
            $disponible <= ($product->reorder_point ?? 0) => 'bajo_stock',
            default => 'disponible',
        };

        // SCRUM-234/238 (hallazgo de Gerencia Test 2026-08-09, reconfirmado 2026-08-12 con el
        // mockup adjunto a SCRUM-238 específicamente): Categoría, Rotación, Bodega(s), Stock
        // mínimo, Proveedor, Por ingresar y En camino pasan a verse en AMBOS modos (Compras y
        // Ventas & Diseño restringido) — decisión de Luis 2026-08-14, revierte la lectura de
        // REQ-172 PERMISOS aplicada el 2026-08-11 (esos 3 campos NO son información financiera —
        // costo/margen/precio de compra sí lo son y siguen exclusivos de modo Compras más abajo).
        $base = [
            'id' => $product->id,
            'reference' => $product->reference,
            'name' => $product->name,
            'description' => $product->description,
            'brand' => $product->brand,
            // SCRUM-830 — 'photo_url' sigue siendo la miniatura (ya la consume la tabla como
            // thumbnail chico), 'photo_full_url' es la imagen completa para el detalle.
            'photo_url' => $this->catalogService->photoThumbnailUrl($product),
            'photo_full_url' => $this->catalogService->photoUrl($product),
            'has_uploaded_photo' => $product->hasUploadedPhoto(),
            'category' => $product->category,
            'rotation' => $product->rotation,
            'reorder_point' => $product->reorder_point,
            'warehouses' => $warehouses,
            'price_full' => $product->price_full,
            'stock_quantity' => $product->stock_quantity,
            'por_servir' => $porServir,
            'por_ingresar' => $porIngresar,
            'en_camino' => $enCamino,
            'provider_name' => $product->relationLoaded('provider') ? $product->provider?->name : null,
            // SCRUM-1126 — todos los proveedores del producto (principal primero); `provider_name` sigue siendo el principal.
            'providers' => $product->relationLoaded('providers') ? $product->providersPayload() : [],
            'disponible' => $disponible,
            // SCRUM-1196 — lo que hay en Showroom Obarrio (se puede vender, pero NO suma al disponible): dato aparte para el vendedor.
            'stock_showroom' => $showroom,
            'estado' => $estado,
            'is_active' => $product->is_active,
            // SCRUM-425 (REQ-355, RN1) — ficha técnica de solo lectura, visible para Bodega igual
            // que el resto del detalle de producto (RN1 no menciona restricción por permiso, a
            // diferencia del bloque de costo/margen de abajo). `null` para productos creados antes
            // de SCRUM-426 (nunca se backfillea con datos falsos) — el frontend debe mostrar un
            // estado "no disponible" en ese caso, no asumir que siempre viene completo.
            'technical_spec' => $product->technical_spec,
            'has_technical_sheet' => $product->hasTechnicalSheet(),
            'technical_sheet_filename' => $product->technical_sheet_filename,
            'technical_sheet_uploaded_at' => $product->technical_sheet_uploaded_at?->toIso8601String(),
        ];

        if ($restricted) {
            // SCRUM-238 (rebote de Gerencia Test 2026-08-15, ver comentario en el ticket) —
            // el modal de DETALLE de un producto en modo Ventas & Diseño necesita 9 métricas que
            // ya existen en la tabla de Inventario: Rotación, Estado, Proveedor, Stock mínimo,
            // Disponible, Por servir, Stock Total, Por ingresar, En camino. Ninguna es costo/
            // margen, no choca con RN1 (ver decisión explícita de Luis en el comentario del
            // ticket) — a diferencia de la tabla (`index()`), que sigue sin exponer Por
            // ingresar/En camino (regla de REQ-172 ya validada por Pre-QA 2026-07-19, sin
            // confirmación de Luis de ampliarla a la tabla, solo al modal de detalle). Por eso
            // este bloque solo corre cuando `$includeRestrictedDetail` es true (show(), nunca
            // index()) — decisión de alcance documentada, no resuelta en silencio.
            if ($includeRestrictedDetail) {
                return array_merge($base, [
                    'provider_name' => $product->relationLoaded('provider') ? $product->provider?->name : null,
                    'por_ingresar' => $porIngresar,
                    'en_camino' => $enCamino,
                ]);
            }

            return $base;
        }

        // REQ-172 PERMISOS — "Ver: todos en Compras y Gerencia": costo/margen/desglose de costo y
        // el proveedor como FK editable (provider_id) siguen exclusivos de modo Compras —
        // información financiera real, a diferencia de provider_name/por_ingresar/en_camino que ya
        // se movieron a $base arriba. SCRUM-237 (REQ-174) — Costo Total/Margen viven en el modelo
        // (CatalogProduct::costTotal()/marginPercent()) para no volver a copiar la fórmula.
        $family = $product->relationLoaded('families') ? $product->families->first() : null;
        $base = array_merge($base, [
            'factory_reference' => $product->factory_reference,
            'barcode' => $product->barcode,
            // Rebote REQ-427 (Gerencia Test 2026-08-13) — solo presente en show() (detalle
            // completo), null en index(). "Ver Inventario > Por ingresar > Detalle".
            'bodega_confirmation' => $arrival === null ? null : [
                'awaiting_compras' => $arrival['awaiting_compras'],
                'confirmed_quantity' => $arrival['confirmed_quantity'],
                'confirmed_by_name' => $arrival['confirmed_by_name'],
            ],
            'cost' => $product->cost,
            'import_cost' => $product->import_cost,
            'freight_cost' => $product->freight_cost,
            'handling_cost' => $product->handling_cost,
            'other_cost' => $product->other_cost,
            'cost_total' => $product->costTotal(),
            'margin_percent' => $product->marginPercent(),
            'provider_id' => $product->provider_id,
            'family_id' => $family?->id,
            'family_name' => $family?->name,
        ]);

        return $base;
    }

    /**
     * REQ-168 Escenario 1 — un usuario con acceso completo puede pasar `?mode=ventas` para
     * PREVISUALIZAR el modo restringido (oculta costo/margen y las acciones de escritura del
     * encabezado) sin perder su acceso real. Un usuario que solo tiene ventas_diseno.read
     * SIEMPRE queda restringido, sin importar qué `mode` pida — el query param nunca amplía acceso.
     *
     * SCRUM-773 — el 3er elemento (`canManage`) es una señal DISTINTA de `restricted`: `restricted`
     * solo gatea si se enmascara costo/margen; `canManage` gatea si el frontend debe mostrar
     * acciones de escritura (crear producto, generar orden de compra, Proveedores). Antes de este
     * ticket el frontend reusaba `restricted` para ambas cosas — funcionaba mientras las únicas 2
     * ramas eran "acceso completo" (compras.read) y "solo lectura restringida" (ventas_diseno.read),
     * pero se rompió con `compras.limited.view` (Líder de Operaciones): esa rama quiere
     * `restricted=false` (ve costos) pero NUNCA `canManage=true` (no puede escribir). Ver
     * ../../../../memory/atlanticerp/project_sesion_20260818_scrum773_restricted_flag_bug.md
     * (workspace Illumination, fuera de este repo) para el detalle completo.
     *
     * @return array{0: User, 1: bool, 2: bool}|null
     */
    private function resolveAccess(Request $request): ?array
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return null;
        }

        /** @var list<string> $permissions */
        $permissions = is_array($request->attributes->get('jwt')['permissions'] ?? null)
            ? array_values(array_filter($request->attributes->get('jwt')['permissions'], 'is_string'))
            : [];

        $isSuperadmin = in_array('superadmin.all', $permissions, true);

        // SCRUM-741 (decisión de Luis, tras hallazgo de Pre-QA: Gerencia/Mark tienen compras.read
        // REAL vía su rol Gerencia — "compras.read igual pierde acceso" no es un caso raro, es
        // exactamente el camino que el ticket reportó) — mientras Compras esté oculto por la
        // etapa de UAT actual, NADIE no-superadmin accede de verdad, ni siquiera con compras.read
        // real. Corta antes de evaluar cualquier rama de permiso — a diferencia del resto de la
        // app, donde la máscara UAT es cosmética a propósito (ver UatVisibilityService), acá
        // también corta el acceso real porque este endpoint es la única fuente de estos datos.
        if (! $isSuperadmin && app(UatVisibilityService::class)->isModuleHidden(ModuleCatalog::COMPRAS)) {
            return null;
        }

        // SCRUM-771 (corrección 2026-08-18) — compras.limited.view (Líder de Operaciones):
        // acceso NO restringido a Inventario (sin costos ocultos, a diferencia de la rama
        // ventas_diseno.read de abajo), pero angosto a nivel de rutas de Compras en general —
        // ver routes/compras.php para el resto de pantallas que sí siguen exigiendo compras.read.
        $hasFullAccess = $isSuperadmin || in_array('compras.read', $permissions, true)
            || in_array('compras.limited.view', $permissions, true);
        if ($hasFullAccess) {
            $previewRestricted = $request->query('mode') === 'ventas';
            // compras.limited.view por sí solo NUNCA da canManage=true, aunque hasFullAccess sea
            // true — ver docblock de arriba.
            $canManage = $isSuperadmin || in_array('compras.read', $permissions, true);

            return [$user, $previewRestricted, $canManage];
        }

        if (in_array('ventas_diseno.read', $permissions, true)) {
            return [$user, true, false];
        }

        return null;
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
