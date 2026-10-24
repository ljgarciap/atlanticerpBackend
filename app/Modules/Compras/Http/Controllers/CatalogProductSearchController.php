<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Compras\Services\SubstituteCandidatePreselector;
use App\Modules\Compras\Services\SubstituteSimilarityService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Http\QueryParams;
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
 *
 * SCRUM-818 — `ventas_diseno.edit` agregado al OR del gate de ruta para que el vendedor/diseñador
 * responsable de un proyecto pueda usar "Buscar Sustituto" en Comparación de Referencias (antes
 * 403 total, sin ninguno de los 3 permisos que ya aceptaba este endpoint). Pero el docblock de
 * arriba es explícito: este endpoint SÍ expone `cost`, a propósito, para Compras — filtrarlo para
 * quien entra solo con `ventas_diseno.edit` (sin ningún permiso real de Compras) es necesario para
 * no violar esa misma regla ("el costo nunca viaja al buscador [de venta]") por la puerta de atrás.
 * Mismo patrón de "modo restringido" que ya usa `InventoryController::resolveAccess()`.
 */
final class CatalogProductSearchController extends Controller
{
    /** SCRUM-992 — tope de resultados del buscador (Nueva Orden); antes devolvía todo el catálogo del proveedor. */
    private const MAX_RESULTS = 100;

    /** SCRUM-1163 — tope de ids ya agregados al carrito que el cliente puede pedir excluir de la página. */
    private const MAX_EXCLUDED_IDS = 500;

    private const MAX_PAGE = 1_000_000;

    /** Candidatos máximos para el ranking de sustitutos cuando hay texto de búsqueda (sin texto: `SubstituteCandidatePreselector::MAX_CANDIDATES`, el mismo valor). */
    private const MAX_SUBSTITUTE_CANDIDATES = 500;

    public function __construct(
        private readonly SubstituteSimilarityService $similarity,
        private readonly SubstituteCandidatePreselector $preselector,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $hasCostAccess = $this->hasCostAccess($request);

        $query = CatalogProduct::where('is_active', true);

        $providerId = $request->query('provider_id');
        if (is_numeric($providerId)) {
            $query->forProvider((int) $providerId);
        }

        // SCRUM-992 — búsqueda en SQL (`search_text` + índice trigram) con el respaldo tolerante a
        // typos SOLO si la coincidencia parcial da 0 resultados; ya no se hidrata el catálogo
        // completo para aplicar Levenshtein en PHP. El flag `fuzzy` conserva su shape.
        $search = trim(QueryParams::string($request, 'search'));
        $fuzzy  = false;

        if ($search !== '') {
            $fuzzy = CatalogProduct::applySearch($query, $search, fuzzyFallback: true);
            CatalogProduct::orderByRelevance($query, $search, $fuzzy);
        }
        $query->orderBy('name')->orderBy('id');

        $originalId = $request->query('original_catalog_product_id');
        /** @var CatalogProduct|null $original */
        $original = is_numeric($originalId) ? CatalogProduct::find((int) $originalId) : null;

        // SCRUM-1163 — paginación REAL en servidor (con `total`), solo para el listado normal de Nueva Orden: quien manda `page` recibe `meta` y navega
        // todo el catálogo del proveedor (antes el tope de 100 ocultaba el resto). Sin `page` (Reemplazos, precheck de producto nuevo) todo queda igual.
        $page = $request->query('page');
        if ($original === null && is_scalar($page) && is_numeric($page)) {
            $rawPerPage = QueryParams::string($request, 'per_page', '20');
            $perPage    = min(max(is_numeric($rawPerPage) ? (int) $rawPerPage : 20, 1), self::MAX_RESULTS);
            // Una página absurda (> int) no debe desbordar el OFFSET ni volver a la 1: se acota y devuelve una página vacía (mismo contrato que los demás listados).
            $pageNumber = min(max((int) QueryParams::string($request, 'page', '1'), 1), self::MAX_PAGE);
            $rawExclude = $request->query('exclude_ids', []);
            $exclude    = array_values(array_filter(
                array_map('intval', array_filter(is_array($rawExclude) ? $rawExclude : [], 'is_scalar')),
                fn (int $id) => $id > 0,
            ));
            if ($exclude !== []) {
                $query->whereNotIn($query->getModel()->qualifyColumn('id'), array_slice($exclude, 0, self::MAX_EXCLUDED_IDS));
            }

            $paginator = $query->paginate($perPage, ['*'], 'page', $pageNumber);

            return response()->json([
                'fuzzy' => $fuzzy,
                'data'  => array_map(fn (CatalogProduct $p) => $this->format($p, $hasCostAccess), $paginator->items()),
                'meta'  => [
                    'total'        => $paginator->total(),
                    'per_page'     => $paginator->perPage(),
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                ],
            ]);
        }

        // Sin sustituto: siempre acotado (MAX_RESULTS). Con sustituto ("Buscar Sustituto") el ranking por
        // similitud necesita candidatos: con búsqueda, un tope amplio (MAX_SUBSTITUTE_CANDIDATES) tomado por
        // relevancia del texto. SCRUM-997 — sin búsqueda ya NO se rankean todos los productos del proveedor
        // (Lafayette: ~26K, 3,4 s y 146 MB): `SubstituteCandidatePreselector` elige en SQL los 500 mejores
        // candidatos con la misma fórmula de `rank()` (categoría, texto, precio y margen) y `rank()` los puntúa.
        if ($original === null) {
            $query->limit(self::MAX_RESULTS);
        } elseif ($search !== '') {
            $query->limit(self::MAX_SUBSTITUTE_CANDIDATES);
        } else {
            $this->preselector->preselect($query, $original);
        }

        /** @var list<CatalogProduct> $products */
        $products = $query->get()->all();

        if ($original !== null) {
            $ranked = $this->similarity->rank($original, $products);

            return response()->json([
                'fuzzy' => $fuzzy,
                'data'  => array_map(
                    fn (array $r) => $this->format($r['product'], $hasCostAccess, $r['similarity_percent']),
                    $ranked,
                ),
            ]);
        }

        return response()->json([
            'fuzzy' => $fuzzy,
            'data'  => array_map(fn (CatalogProduct $p) => $this->format($p, $hasCostAccess), $products),
        ]);
    }

    /** @return array<string, mixed> */
    private function format(CatalogProduct $product, bool $hasCostAccess, ?float $similarityPercent = null): array
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
            'cost'               => $hasCostAccess ? $product->cost : null,
            // SCRUM-818 (rebote 2026-09-03, Hallazgo 5) — el % de margen viaja SIEMPRE, sin
            // importar `hasCostAccess` (decisión de Luis): Vendedor/Diseñador necesita ver el
            // margen del sustituto en Comparación de Referencias para decidir, pero el costo/
            // precio de compra crudos siguen sin corresponderle (ver docblock de la clase). Se
            // calcula acá, server-side, con la MISMA fórmula canónica que usa
            // ReplacementRequestController::computeMarginPercent() (CatalogProduct::marginPercent(),
            // sobre Costo Total) — nunca la fórmula simplificada que el frontend calculaba antes.
            'margin_percent'     => $product->marginPercent(),
            'stock_quantity'     => $product->stock_quantity,
            'reorder_point'      => $product->reorder_point,
            'similarity_percent' => $similarityPercent,
        ];
    }

    /** SCRUM-818 — ver docblock de la clase. */
    private function hasCostAccess(Request $request): bool
    {
        /** @var list<string> $permissions */
        $permissions = is_array($request->attributes->get('jwt')['permissions'] ?? null)
            ? array_values(array_filter($request->attributes->get('jwt')['permissions'], 'is_string'))
            : [];

        return in_array('superadmin.all', $permissions, true)
            || in_array('compras.read', $permissions, true)
            || in_array('bodega.read', $permissions, true)
            || in_array('compras.sustitutos.manage', $permissions, true);
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
