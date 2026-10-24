<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\AI\Models\AiAnalysis;
use App\Modules\Compras\Http\Requests\StoreProviderBankImageRequest;
use App\Modules\Compras\Http\Requests\StoreProviderRequest;
use App\Modules\Compras\Http\Requests\UpdateProviderRequest;
use App\Modules\Compras\Jobs\ExtractProviderBankInfoJob;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Services\ProviderBankImageService;
use App\Modules\VentasDiseno\Services\FuzzySearchService;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\RateLimiter;

/**
 * REQ-120→127 — Proveedores. Búsqueda reutiliza FuzzySearchService (mismo mecanismo que
 * CatalogProductController, VentasDiseno). Umbral de "calificación baja" (REQ-121/122) no viene
 * especificado con un número exacto en el texto de los tickets — pedido explícito de Luis: nada
 * de valores hardcodeados, vive en `compras_settings.low_rating_threshold` (parametrizable sin
 * deploy vía ComprasSettingsController, mismo patrón que ReportSettings). En la práctica no afecta
 * a nada todavía porque ningún proveedor tiene calificación real en este sprint (ver
 * Provider::ratingScore()).
 */
final class ProviderController extends Controller
{
    /** @var list<string> */
    private const ALLOWED_SORTS = ['name', 'category', 'currency', 'created_at'];

    private const BANK_EXTRACTION_ANALYSIS_TYPE = 'provider_bank_info';

    /** ADR-SCRUM819, condición de Cybersecurity #8 — límite de re-intentos por proveedor. */
    private const BANK_EXTRACTION_MAX_ATTEMPTS_PER_PROVIDER = 5;

    private const BANK_EXTRACTION_DECAY_SECONDS = 3600;

    public function __construct(
        private readonly FuzzySearchService $fuzzySearch,
        private readonly ProviderBankImageService $bankImages,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var list<Provider> $providers */
        $providers = Provider::where('is_active', true)->orderBy('name')->get()->all();

        $search = trim((string) $request->query('search', ''));
        $fuzzy = false;
        if ($search !== '') {
            [$providers, $fuzzy] = $this->fuzzySearch->search(
                $providers,
                $search,
                fn (Provider $p): string => $p->name,
            );
        }

        $category = $request->query('category');
        if (is_string($category) && $category !== '') {
            $providers = array_values(array_filter(
                $providers,
                fn (Provider $p): bool => $p->category === $category,
            ));
        }

        // REQ-122 Escenario 1: el chip "Calificación baja" sincroniza con este mismo filtro.
        if ($request->query('rating_range') === 'low') {
            $threshold = ComprasSettings::current()->low_rating_threshold;
            $providers = array_values(array_filter(
                $providers,
                fn (Provider $p): bool => $p->ratingScore() !== null && $p->ratingScore() < $threshold,
            ));
        }

        // SCRUM-185 (hallazgo QA 2026-07-16): rango de calificación real, además del chip fijo
        // "Baja" de arriba — sin bucketizar (Alta/Media/Baja) porque el ticket no trae esos
        // cortes especificados; min/max numérico es lo único que se puede implementar sin
        // inventar un umbral de negocio no pedido.
        $ratingMin = $request->query('rating_min');
        if (is_numeric($ratingMin)) {
            $min = (float) $ratingMin;
            $providers = array_values(array_filter(
                $providers,
                fn (Provider $p): bool => $p->ratingScore() !== null && $p->ratingScore() >= $min,
            ));
        }
        $ratingMax = $request->query('rating_max');
        if (is_numeric($ratingMax)) {
            $max = (float) $ratingMax;
            $providers = array_values(array_filter(
                $providers,
                fn (Provider $p): bool => $p->ratingScore() !== null && $p->ratingScore() <= $max,
            ));
        }

        // Política global de paginación/sorting (ver memory/feedback_pagination_sorting_policy.md)
        // — se ordena/pagina el array ya filtrado, porque FuzzySearchService trabaja en PHP, no
        // sobre una query (mismo motivo que Pagination::ofArray() existe).
        $pagination = Pagination::params($request, self::ALLOWED_SORTS, 'name');
        usort($providers, function (Provider $a, Provider $b) use ($pagination): int {
            /** @var string $field */
            $field = $pagination['sort_by'];
            $cmp = match ($field) {
                'created_at' => $a->created_at <=> $b->created_at,
                default => strcasecmp((string) $a->{$field}, (string) $b->{$field}),
            };

            return $pagination['sort_dir'] === 'desc' ? -$cmp : $cmp;
        });
        $paginator = Pagination::ofArray($providers, $pagination['per_page'], $pagination['page']);

        return response()->json([
            'fuzzy' => $fuzzy,
            'kpis' => $this->kpis(),
            'data' => array_map(fn (Provider $p) => $this->format($p), $paginator->items()),
            'meta' => Pagination::meta($paginator),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $provider = Provider::find($id);
        if ($provider === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->format($provider, detailed: true));
    }

    public function store(StoreProviderRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $data = $request->validated();
        $data['currency'] = $data['currency'] ?? 'USD'; // REQ-126 RN2
        $data['origin'] = Provider::deriveOrigin($data['category'] ?? null); // REQ-141, SCRUM-189
        $data['created_by'] = $user->id;

        $provider = Provider::create($data);

        return response()->json($this->format($provider, detailed: true), 201);
    }

    public function update(UpdateProviderRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $provider = Provider::find($id);
        if ($provider === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $data = $request->validated();
        // REQ-141, SCRUM-189: `origin` no es input directo, se recalcula siempre a partir de la
        // categoría resultante. `array_key_exists` (no `??`) porque un `category: null` explícito
        // (vaciar el campo) es una categoría real distinta de "no la mandé" — `??` los confundiría
        // y dejaría el `origin` viejo sin recalcular.
        $data['origin'] = Provider::deriveOrigin(
            array_key_exists('category', $data) ? $data['category'] : $provider->category,
        );
        $provider->update($data);

        return response()->json($this->format($provider, detailed: true));
    }

    /** SCRUM-819 (RN2/RN5/RN8) — subir reemplaza cualquier imagen previa, nunca la conserva. */
    /**
     * ADR-SCRUM819 — tras un upload exitoso, dispara automáticamente la extracción de datos
     * bancarios con IA (sin botón manual, pedido explícito de Luis). El rate limit se chequea
     * ANTES de disparar pero nunca bloquea el upload en sí — si se alcanzó el límite, la imagen
     * queda guardada igual, simplemente sin `extraction_job_id` en la respuesta.
     */
    public function uploadBankImage(StoreProviderBankImageRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $provider = Provider::find($id);
        if ($provider === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{file: UploadedFile} $validated */
        $validated = $request->validated();
        $provider = $this->bankImages->upload($validated['file'], $provider);

        $extractionJobId = null;
        $tenantSlug = $this->resolveTenantSlug($request);
        if ($tenantSlug !== null && ! RateLimiter::tooManyAttempts($this->bankExtractionLimiterKey($provider->id), self::BANK_EXTRACTION_MAX_ATTEMPTS_PER_PROVIDER)) {
            RateLimiter::hit($this->bankExtractionLimiterKey($provider->id), self::BANK_EXTRACTION_DECAY_SECONDS);
            $extractionJobId = $this->dispatchBankExtraction($provider->id, $user->id, $tenantSlug);
        }

        return response()->json([...$this->format($provider, detailed: true), 'extraction_job_id' => $extractionJobId]);
    }

    /**
     * ADR-SCRUM819 — poll del resultado de extracción. Mismo shape que
     * `PurchaseOrderDocumentController::validation()`: `status: null` explícito (no un JSON
     * vacío) si todavía no se disparó ninguna extracción para este proveedor.
     */
    public function bankImageExtraction(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $provider = Provider::find($id);
        if ($provider === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var AiAnalysis|null $analysis */
        $analysis = AiAnalysis::where('entity_type', 'provider')
            ->where('entity_id', $id)
            ->where('analysis_type', self::BANK_EXTRACTION_ANALYSIS_TYPE)
            ->latest()
            ->first();

        if ($analysis === null) {
            return response()->json(['id' => null, 'status' => null, 'result' => null, 'error' => null]);
        }

        return response()->json([
            'id' => $analysis->id,
            'status' => $analysis->status,
            'result' => $analysis->result !== null ? json_decode($analysis->result, true) : null,
            'error' => $analysis->error,
        ]);
    }

    /** SCRUM-819 (RN7) — eliminar sin reemplazar. */
    public function deleteBankImage(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $provider = Provider::find($id);
        if ($provider === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $provider = $this->bankImages->delete($provider);

        return response()->json($this->format($provider, detailed: true));
    }

    private function bankExtractionLimiterKey(int $providerId): string
    {
        return "compras.provider_bank_info.extract:{$providerId}";
    }

    private function dispatchBankExtraction(int $providerId, int $userId, string $tenantSlug): string
    {
        /** @var AiAnalysis $analysis */
        $analysis = AiAnalysis::create([
            'entity_type' => 'provider',
            'entity_id' => $providerId,
            'analysis_type' => self::BANK_EXTRACTION_ANALYSIS_TYPE,
            'status' => AiAnalysis::STATUS_PENDING,
            'requested_by' => $userId,
        ]);

        ExtractProviderBankInfoJob::dispatch($analysis->id, $providerId, $tenantSlug);

        return $analysis->id;
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

    /** @return array<string, mixed> */
    private function kpis(): array
    {
        /** @var Collection<int, Provider> $active */
        $active = Provider::where('is_active', true)->get();

        /** @var Collection<int, float> $rated */
        $rated = $active->map(fn (Provider $p): ?float => $p->ratingScore())->filter(fn (?float $r): bool => $r !== null);
        $averageRating = $rated->isEmpty() ? null : (float) $rated->avg();
        $threshold = ComprasSettings::current()->low_rating_threshold;

        return [
            'total_providers' => $active->count(),
            // REQ-121 RN: promedio excluye "sin calificar" (Escenario 1).
            'average_rating' => $averageRating === null ? null : round($averageRating, 1),
            'low_rating_count' => $rated->filter(fn (float $r): bool => $r < $threshold)->count(),
            'active_categories' => $active->pluck('category')->filter()->unique()->count(),
            // SCRUM-185 — lista real de categorías en uso, para que el filtro sea un <select>
            // con opciones reales en vez de un input de texto libre.
            'categories' => $active->pluck('category')->filter()->unique()->sort()->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    private function format(Provider $provider, bool $detailed = false): array
    {
        $lastOrder = $provider->purchaseOrders()->orderByDesc('created_at')->first();

        $base = [
            'id' => $provider->id,
            'name' => $provider->name,
            'category' => $provider->category,
            'origin' => $provider->origin,
            'currency' => $provider->currency,
            'is_active' => $provider->is_active,
            // SCRUM-186 (2026-08-06, hallazgo Gerencia Test): columna "Contacto" de la tabla
            // principal — se expone acá (no solo en el detalle) para no requerir un fetch extra
            // por fila.
            'contact_name' => $provider->contact_name,
            // REQ-124 Escenario 2 / REQ-123: null = "Sin calificar" / "Sin compras registradas
            // aún" — el frontend decide el texto, el backend nunca inventa 0.
            'rating' => $provider->ratingScore(),
            'last_purchase_at' => $lastOrder?->created_at?->toDateString(),
        ];

        if (! $detailed) {
            return $base;
        }

        return array_merge($base, [
            // SCRUM-1014 — `tax_id` se guardaba pero nunca viajaba en el detalle: el formulario lo
            // recargaba vacío y el RUC parecía no persistir.
            'tax_id' => $provider->tax_id,
            'tax_id_dv' => $provider->tax_id_dv,
            'whatsapp' => $provider->whatsapp,
            'phone' => $provider->phone,
            'email' => $provider->email,
            'address' => $provider->address,
            'country' => $provider->country,
            'bank_name' => $provider->bank_name,
            'bank_account_number' => $provider->bank_account_number,
            'bank_swift' => $provider->bank_swift,
            'bank_beneficiary' => $provider->bank_beneficiary,
            // SCRUM-819 — respaldo visual, independiente de los campos bank_* de arriba (RN9).
            // null cuando no hay imagen cargada, nunca un objeto vacío (RN de "sin dato" ya
            // establecido en el resto del proyecto, ej. Provider::completePercent()).
            'bank_image' => $provider->bank_image_storage_key === null ? null : [
                'url' => $this->bankImages->presignUrl($provider),
                'filename' => $provider->bank_image_original_filename,
            ],
        ]);
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
