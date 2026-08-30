<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\AI\Models\AiAnalysis;
use App\Modules\Compras\Http\Requests\SearchSubstituteCandidatesRequest;
use App\Modules\Compras\Jobs\CompareSubstituteCandidatesJob;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-245 (REQ-182/183, ADR-SCRUM245) — búsqueda de sustituto por foto/ficha técnica, acotada
 * a una familia de producto (decisión de Luis 2026-07-17: familia obligatoria). "Buscar en
 * catálogo" (sin IA) no tiene endpoint acá — reusa `GET /compras/products` existente
 * (CatalogProductSearchController), ver checklist del ADR.
 */
final class ReplacementSearchController extends Controller
{
    private const ANALYSIS_TYPE  = 'replacement_search';
    private const DISK           = 's3-private';
    private const MAX_CANDIDATES = 25;

    /**
     * Cybersecurity gap #1 (ADR-SCRUM245) — cada corrida manda hasta 26 imágenes, más costoso
     * que SCRUM-211 (1 documento), así que el tope es por usuario y más bajo que el de
     * PurchaseOrderDocumentController::VALIDATION_MAX_ATTEMPTS_PER_ORDER.
     */
    private const SEARCH_MAX_ATTEMPTS_PER_USER = 10;
    private const SEARCH_DECAY_SECONDS = 3600;

    public function store(SearchSubstituteCandidatesRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $limiterKey = "compras.replacement_search:{$user->id}";
        if (RateLimiter::tooManyAttempts($limiterKey, self::SEARCH_MAX_ATTEMPTS_PER_USER)) {
            return response()->json([
                'message' => 'Se alcanzó el límite de búsquedas de sustituto con IA. Intenta de nuevo más tarde.',
            ], 429);
        }

        $family = CatalogProductFamily::find((int) $request->input('family_id'));
        if ($family === null) {
            return response()->json(['message' => 'Familia de producto no encontrada.'], 404);
        }

        $originalProductId = (int) $request->input('original_catalog_product_id');
        if (CatalogProduct::find($originalProductId) === null) {
            return response()->json(['message' => 'Producto original no encontrado.'], 404);
        }

        $tenantSlug = $this->resolveTenantSlug($request);
        if ($tenantSlug === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        // orderByDesc(created_at) solo no alcanza — esa columna es timestamp(0) (precisión de
        // segundo), catálogo cargado en bulk puede empatar; se agrega id como desempate para que
        // el recorte a 25 sea reproducible (Senior Review 20260718: el ADR pide auditar el
        // recorte, un recorte no determinístico rompe esa trazabilidad).
        /** @var list<int> $allFamilyIds */
        $allFamilyIds = $family->products()->where('is_active', true)
            ->orderByDesc('catalog_products.created_at')->orderByDesc('catalog_products.id')
            ->pluck('catalog_products.id')->all();

        $truncated = count($allFamilyIds) > self::MAX_CANDIDATES;
        $candidateIds = array_slice($allFamilyIds, 0, self::MAX_CANDIDATES);

        if ($candidateIds === []) {
            return response()->json(['message' => 'La familia seleccionada no tiene productos activos para comparar.'], 422);
        }

        RateLimiter::hit($limiterKey, self::SEARCH_DECAY_SECONDS);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $request->file('file');
        $ext  = strtolower($file->getClientOriginalExtension());
        $key  = 'compras/replacement-search/' . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');
        Storage::disk(self::DISK)->putFileAs('compras/replacement-search', $file, basename($key));

        /** @var AiAnalysis $analysis */
        $analysis = AiAnalysis::create([
            'entity_type'   => self::ANALYSIS_TYPE,
            'analysis_type' => self::ANALYSIS_TYPE,
            'status'        => AiAnalysis::STATUS_PENDING,
            'requested_by'  => $user->id,
            'extra'         => ['family_id' => $family->id, 'original_catalog_product_id' => $originalProductId],
        ]);

        CompareSubstituteCandidatesJob::dispatch(
            $analysis->id,
            $family->id,
            $originalProductId,
            $key,
            $this->resolveTargetMediaType($file),
            $candidateIds,
            $truncated,
            count($allFamilyIds),
            $tenantSlug,
        );

        return response()->json(['job_id' => $analysis->id], 202);
    }

    /** REQ-183 — poll del resultado, con stock/precio reales unidos al ranking devuelto por la IA. */
    public function show(Request $request, string $jobId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var AiAnalysis|null $analysis */
        $analysis = AiAnalysis::where('id', $jobId)->where('analysis_type', self::ANALYSIS_TYPE)->first();

        if ($analysis === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $analysis->isCompleted()) {
            return response()->json([
                'id' => $analysis->id, 'status' => $analysis->status, 'error' => $analysis->error, 'results' => null,
            ]);
        }

        /** @var array{ranking?: list<array{catalog_product_id: int, similarity_percent: float, reasoning: string}>} $result */
        $result = $analysis->result !== null ? json_decode($analysis->result, true) : [];
        $ranking = $result['ranking'] ?? [];

        $productIds = array_column($ranking, 'catalog_product_id');
        /** @var \Illuminate\Support\Collection<int, CatalogProduct> $products */
        $products = CatalogProduct::whereIn('id', $productIds)->get()->keyBy('id');

        $results = [];
        foreach ($ranking as $entry) {
            /** @var CatalogProduct|null $product */
            $product = $products->get($entry['catalog_product_id']);
            if ($product === null) {
                continue;
            }

            // SCRUM-246 (rebote de Daniela Amaya 2026-08-09) — la tabla de candidatos pedía
            // Categoría (ya existía en el modelo, solo faltaba exponerla acá) y "% de similitud";
            // este último YA lo calcula `CompareSubstituteCandidatesJob`/la IA
            // (`similarity_percent`, 0-100), no hacía falta un cálculo nuevo — solo faltaba
            // devolverlo con este nombre explícito (antes solo viajaba embebido en `reasoning`
            // como texto libre, sin un campo numérico propio para la barra visual del frontend).
            $results[] = [
                'catalog_product_id' => $product->id,
                'reference'          => $product->reference,
                'name'               => $product->name,
                'description'        => $product->description,
                'category'           => $product->category,
                'photo_url'          => $product->photo_url,
                'price_full'         => $product->price_full,
                'cost'               => $product->cost,
                'stock_quantity'     => $product->stock_quantity,
                'similarity_percent' => $entry['similarity_percent'],
                'reasoning'          => $entry['reasoning'],
            ];
        }

        usort($results, fn (array $a, array $b) => $b['similarity_percent'] <=> $a['similarity_percent']);

        return response()->json([
            'id' => $analysis->id, 'status' => $analysis->status, 'error' => null, 'results' => $results,
        ]);
    }

    private function resolveTargetMediaType(\Illuminate\Http\UploadedFile $file): string
    {
        return $file->getMimeType() ?? 'application/octet-stream';
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

    private function resolveTenantSlug(Request $request): ?string
    {
        /** @var array{tenant?: string}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['tenant']) || $payload['tenant'] === '') {
            return null;
        }

        return (string) $payload['tenant'];
    }
}
