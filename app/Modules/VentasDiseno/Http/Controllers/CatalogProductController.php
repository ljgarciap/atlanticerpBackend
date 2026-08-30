<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Modules\Bodega\Services\OrderCommitmentService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
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

    public function __construct(private readonly OrderCommitmentService $commitments) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        if ($search === '') {
            /** @var list<CatalogProduct> $products */
            $products = CatalogProduct::where('is_active', true)->orderBy('name')->get()->all();
        } else {
            $like = '%' . addcslashes($search, '%_\\') . '%';

            /** @var list<CatalogProduct> $products */
            $products = CatalogProduct::where('is_active', true)
                ->where(function ($q) use ($like): void {
                    $q->where('reference', 'ilike', $like)
                        ->orWhere('factory_reference', 'ilike', $like)
                        ->orWhere('name', 'ilike', $like)
                        ->orWhere('brand', 'ilike', $like);
                })
                ->orderBy('name')
                ->get()
                // SCRUM-796 (secc. 6) — coincidencia exacta primero (sort estable de PHP 8+
                // conserva el orden alfabético entre productos con el mismo booleano).
                ->sortByDesc(fn (CatalogProduct $p): bool => $this->isExactMatch($p, $search))
                ->take(self::MAX_RESULTS)
                ->values()
                ->all();
        }

        $committedMap = $this->commitments->committedMap(array_map(fn (CatalogProduct $p): int => $p->id, $products));

        return response()->json([
            // SCRUM-796 (secc. 6) — nunca heurístico, este flag queda siempre en false; se
            // mantiene el campo por compatibilidad de shape con el frontend existente.
            'fuzzy' => false,
            'data'  => array_map(fn (CatalogProduct $p) => $this->format($p, $committedMap[$p->id] ?? 0), $products),
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $product = CatalogProduct::where('is_active', true)->find($id);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $committed = $this->commitments->committedMap([$id])[$id] ?? 0;

        return response()->json($this->format($product, $committed));
    }

    private function isExactMatch(CatalogProduct $product, string $search): bool
    {
        $needle = mb_strtolower($search);

        foreach ([$product->reference, $product->factory_reference, $product->name, $product->brand] as $field) {
            if ($field !== null && mb_strtolower($field) === $needle) {
                return true;
            }
        }

        return false;
    }

    /** @return array<string, mixed> — REQ-036 AC2: foto, marca, descripción, disponible, precio. Nunca `cost`. */
    private function format(CatalogProduct $product, int $committed = 0): array
    {
        return [
            'id'                => $product->id,
            'reference'         => $product->reference,
            'factory_reference' => $product->factory_reference,
            'name'              => $product->name,
            'description'       => $product->description,
            'brand'             => $product->brand,
            'photo_url'         => $product->photo_url,
            'price_full'        => $product->price_full,
            // SCRUM-796 (secc. 5) — neto de compromisos de Bodega (mismo cálculo que
            // CatalogService/Bodega), ya no el stock bruto.
            'stock_quantity'    => max(0, ($product->stock_quantity ?? 0) - $committed),
        ];
    }
}
