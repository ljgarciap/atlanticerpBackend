<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Compras\Services\SubstituteSimilarityService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Services\FuzzySearchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-130 — buscador de productos del Catálogo para "Nueva Orden", filtrado por proveedor.
 *
 * A propósito NO reutiliza `App\Modules\VentasDiseno\Http\Controllers\CatalogProductController`
 * (buscador de Ventas & Diseño): ese endpoint nunca expone `cost` a propósito (información de
 * margen, no le corresponde a un vendedor) — pero acá es justo lo contrario, REQ-130 Escenario 2
 * exige explícitamente que "la referencia de fábrica, referencia pública y costo ya vienen
 * llenos con los datos reales del catálogo" al elegir un producto. Hallazgo real corregido
 * durante la implementación del wizard (ver docs/specs/compras-proveedores-sprint2.md) — la
 * regla "el costo nunca viaja al buscador" es propia del contexto de venta, no universal.
 *
 * `original_catalog_product_id` (Lote 4, SCRUM-246, opcional) — reutilizado también por el método
 * "Buscar en catálogo" de Comparación de Referencias (REQ-183): cuando viene presente, decora cada
 * resultado con `similarity_percent` (`SubstituteSimilarityService`) y descarta los que no
 * alcanzan el umbral mínimo — "Nueva Orden" nunca manda este parámetro, así que su comportamiento
 * no cambia.
 */
final class CatalogProductSearchController extends Controller
{
    public function __construct(
        private readonly FuzzySearchService $fuzzySearch,
        private readonly SubstituteSimilarityService $similarity,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $query = CatalogProduct::where('is_active', true);

        $providerId = $request->query('provider_id');
        if (is_numeric($providerId)) {
            $query->where('provider_id', (int) $providerId);
        }

        /** @var list<CatalogProduct> $products */
        $products = $query->orderBy('name')->get()->all();

        $search = trim((string) $request->query('search', ''));
        $fuzzy  = false;

        if ($search !== '') {
            [$products, $fuzzy] = $this->fuzzySearch->search(
                $products,
                $search,
                fn (CatalogProduct $p): string => trim("{$p->reference} {$p->factory_reference} {$p->name} {$p->brand}"),
            );
        }

        $originalId = $request->query('original_catalog_product_id');
        if (is_numeric($originalId)) {
            /** @var CatalogProduct|null $original */
            $original = CatalogProduct::find((int) $originalId);
            if ($original !== null) {
                $ranked = $this->similarity->rank($original, $products);

                return response()->json([
                    'fuzzy' => $fuzzy,
                    'data'  => array_map(
                        fn (array $r) => $this->format($r['product'], $r['similarity_percent']),
                        $ranked,
                    ),
                ]);
            }
        }

        return response()->json([
            'fuzzy' => $fuzzy,
            'data'  => array_map(fn (CatalogProduct $p) => $this->format($p), $products),
        ]);
    }

    /** @return array<string, mixed> */
    private function format(CatalogProduct $product, ?float $similarityPercent = null): array
    {
        return [
            'id'                 => $product->id,
            'reference'          => $product->reference,
            'factory_reference'  => $product->factory_reference,
            'name'               => $product->name,
            'description'        => $product->description,
            'brand'              => $product->brand,
            'category'           => $product->category,
            'photo_url'          => $product->photo_url,
            'price_full'         => $product->price_full,
            'cost'               => $product->cost,
            'stock_quantity'     => $product->stock_quantity,
            'reorder_point'      => $product->reorder_point,
            'similarity_percent' => $similarityPercent,
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
