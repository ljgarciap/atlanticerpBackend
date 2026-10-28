<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Services\PreferencesService;
use App\Modules\Bodega\Services\OrderCommitmentService;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\VentasDiseno\Services\CatalogService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Http\QueryParams;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-036: buscador de productos del Catálogo — referencia, nombre o descripción.
 *
 * SCRUM-796 (secc. 6) — ya NO usa FuzzySearchService (heurístico/aproximado): coincidencia
 * EXACTA primero, luego PARCIAL (contains) sobre referencia/referencia de fábrica/nombre/
 * marca — nunca un resultado sin relación clara con lo escrito. FuzzySearchService sigue
 * sin cambios para el resto del sistema (QuoteListService, etc.), este reemplazo es
 * exclusivo de este controller.
 *
 * La "ficha técnica" (REQ-036 AC2) es foto/marca/descripción/disponible/precio — a
 * propósito **nunca incluye `cost`**: el costo es información de margen interna, se
 * resuelve server-side recién al seleccionar el producto para un ítem (ver
 * QuoteItemController), nunca viaja por HTTP hacia el buscador.
 */
final class CatalogProductController extends Controller
{
    private const MAX_RESULTS = 6;

    /** Listado sin texto de búsqueda (SCRUM-734 secc. 9), acotado desde SCRUM-992. */
    private const MAX_LISTING = 100;

    public function __construct(
        private readonly OrderCommitmentService $commitments,
        private readonly PreferencesService $preferences,
        private readonly InventoryKpiService $inventoryKpis,
        private readonly CatalogService $catalog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim(QueryParams::string($request, 'search'));
        $demoModeActive = $this->isDemoModeActive($request);

        if ($search === '') {
            // SCRUM-734 (secc. 9) — al abrir la lupa sin escribir nada se muestra el catálogo; con ~30K
            // productos "completo" ya no es viable (SCRUM-992): primeras MAX_LISTING por nombre.
            /** @var list<CatalogProduct> $products */
            $products = CatalogProduct::where('is_active', true)
                ->visibleForDemoMode($demoModeActive)
                ->orderBy('name')->orderBy('id')->limit(self::MAX_LISTING)->get()->all();
        } else {
            // SCRUM-992 — coincidencia parcial sobre `search_text` (índice trigram; sin acentos ni
            // mayúsculas), SIN respaldo tolerante a typos (SCRUM-796 secc. 6: nunca un resultado sin
            // relación clara con lo escrito). Exacta primero, luego prefijo, luego el resto; el tope
            // (MAX_RESULTS) se aplica en SQL en vez de cargar todas las coincidencias.
            $query = CatalogProduct::where('is_active', true)->visibleForDemoMode($demoModeActive);
            CatalogProduct::applySearch($query, $search, fuzzyFallback: false);
            CatalogProduct::orderByRelevance($query, $search);

            /** @var list<CatalogProduct> $products */
            $products = $query->orderBy('name')->orderBy('id')->limit(self::MAX_RESULTS)->get()->all();
        }

        $productIds = array_map(fn (CatalogProduct $p): int => $p->id, $products);
        $committedMap  = $this->commitments->committedMap($productIds);
        $enExcluidaMap = $this->inventoryKpis->enBodegaExcluidaMap($productIds);
        $apartadoMap   = $this->inventoryKpis->enOtrasBodegasDeVentaMap($productIds);

        return response()->json([
            // SCRUM-796 (secc. 6) — nunca heurístico, este flag queda siempre en false; se
            // mantiene el campo por compatibilidad de shape con el frontend existente.
            'fuzzy' => false,
            'data'  => array_map(fn (CatalogProduct $p) => $this->format($p, $committedMap[$p->id] ?? 0, $enExcluidaMap[$p->id] ?? 0, $apartadoMap[$p->id] ?? 0), $products),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $product = CatalogProduct::where('is_active', true)->find($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $committed  = $this->commitments->committedMap([$id])[$id] ?? 0;
        $enExcluida = $this->inventoryKpis->enBodegaExcluidaMap([$id])[$id] ?? 0;
        $aparte   = $this->inventoryKpis->enOtrasBodegasDeVentaMap([$id])[$id] ?? 0;

        return response()->json($this->format($product, $committed, $enExcluida, $aparte));
    }

    /** docs/specs/demo-mode.md — sin usuario autenticado (no debería pasar, ruta ya exige JWT), el filtro no se activa. */
    private function isDemoModeActive(Request $request): bool
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');
        if ($payload === null || !isset($payload['sub'])) {
            return false;
        }

        $user = User::find((int) $payload['sub']);

        return $user !== null && $this->preferences->isDemoModeActive($user);
    }

    /** @return array<string, mixed> — REQ-036 AC2: foto, marca, descripción, disponible, precio. Nunca `cost`. */
    private function format(CatalogProduct $product, int $committed = 0, int $enExcluida = 0, int $aparte = 0): array
    {
        return [
            'id'                => $product->id,
            'reference'         => $product->reference,
            'factory_reference' => $product->factory_reference,
            'name'              => $product->name,
            'description'       => $product->description,
            'brand'             => $product->brand,
            // SCRUM-1138 — foto resuelta (subida o legacy), no solo `photo_url`.
            'photo_url'         => $this->catalog->photoThumbnailUrl($product),
            'price_full'        => $product->price_full,
            // SCRUM-796 (secc. 5) — neto de compromisos de Bodega (mismo cálculo que
            // CatalogService/Bodega), ya no el stock bruto. SCRUM-851 — tampoco cuenta lo
            // ubicado en una bodega excluida.
            'stock_quantity'    => max(0, (int) $this->inventoryKpis->disponibleParaVenta($product, $committed, $enExcluida)),
            // SCRUM-1196 — Sala de exhibición 1, aparte del disponible (no suma): para mostrar a clientes o vender una última pieza.
            'stock_aparte'    => $aparte,
        ];
    }
}
