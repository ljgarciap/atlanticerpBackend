<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\AI\Models\AiAnalysis;
use App\Modules\Compras\Http\Requests\StorePurchaseOrderDocumentRequest;
use App\Modules\Compras\Jobs\ValidateProviderConfirmationJob;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderDocument;
use App\Modules\Compras\Support\OrderMoneyVisibility;
use App\Modules\Compras\Services\PurchaseOrderDocumentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;

/** REQ-155 — checklist de documentos del envío, dentro de cada tarjeta de Logística. */
final class PurchaseOrderDocumentController extends Controller
{
    private const VALIDATION_ANALYSIS_TYPE = 'provider_confirmation';

    /**
     * Cybersecurity condición #3 (ADR-SCRUM211) — límite de re-intentos de validación por orden,
     * no por usuario/IP (a diferencia de `throttle:5,1` en routes/ai.php), porque distintos
     * usuarios re-subiendo/re-validando el mismo documento de la misma orden deben compartir el
     * mismo límite.
     */
    private const VALIDATION_MAX_ATTEMPTS_PER_ORDER = 5;
    private const VALIDATION_DECAY_SECONDS = 3600;

    public function __construct(private readonly PurchaseOrderDocumentService $documents)
    {
    }

    public function index(Request $request, int $orderId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::find($orderId);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var \Illuminate\Database\Eloquent\Collection<int, PurchaseOrderDocument> $documents */
        $documents = $order->documents;

        // SCRUM-1013 (Pre-QA H3) — una factura comercial, la confirmación del proveedor o la declaración de
        // nacionalización traen precios: para quien no ve montos solo se listan los documentos sin precios.
        if (OrderMoneyVisibility::hiddenFor($request)) {
            $documents = $documents->filter(
                fn (PurchaseOrderDocument $d): bool => in_array($d->category, OrderMoneyVisibility::DOCUMENT_CATEGORIES_WITHOUT_PRICES, true),
            );
        }

        return response()->json([
            'data' => $documents->map(fn (PurchaseOrderDocument $d) => $this->format($d))->values(),
        ]);
    }

    public function store(StorePurchaseOrderDocumentRequest $request, int $orderId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::find($orderId);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{category: string, file: \Illuminate\Http\UploadedFile} $validated */
        $validated = $request->validated();

        // SCRUM-211/REQ-148 RN1 (Senior Review 2026-08-07): "el documento únicamente podrá
        // adjuntarse cuando la Orden de Compra ya no se encuentre en estado 'Por aprobar'" nunca
        // se validaba acá — solo el frontend ocultaba el control de subida (en Logística y, desde
        // SCRUM-211, también en el card de Ver Órdenes). Un POST directo a este endpoint la
        // saltaba sin aviso. Acotado a `confirmacion_proveedor` porque es la única categoría con
        // esa regla explícita en su REQ — el resto del checklist (REQ-155) no la tiene.
        if ($validated['category'] === 'confirmacion_proveedor' && $order->status === PurchaseOrder::STATUS_POR_APROBAR) {
            return response()->json([
                'message' => 'El documento de confirmación del proveedor podrá adjuntarse una vez la Orden de Compra haya sido aprobada.',
            ], 422);
        }

        $document = $this->documents->upload($validated['file'], $order->id, $validated['category'], $user->id);

        // SCRUM-211 — el ticket pide que la validación corra "automáticamente" al subir la
        // confirmación, no detrás de un botón aparte (hallazgo QA 2026-07-20: el documento se
        // subía y nunca pasaba nada). El botón manual de la UI queda como re-validación.
        if ($validated['category'] === 'confirmacion_proveedor') {
            $tenantSlug = $this->resolveTenantSlug($request);
            if ($tenantSlug !== null && ! RateLimiter::tooManyAttempts($this->validationLimiterKey($order->id), self::VALIDATION_MAX_ATTEMPTS_PER_ORDER)) {
                RateLimiter::hit($this->validationLimiterKey($order->id), self::VALIDATION_DECAY_SECONDS);
                $this->dispatchValidation($document->id, $order->id, $user->id, $tenantSlug);
            }
        }

        return response()->json($this->format($document), 201);
    }

    /**
     * SCRUM-211 (ADR-SCRUM211) — dispara la validación con IA del documento de confirmación
     * contra la orden. Gateada por `compras.edit` (no `ai.use`) — mismo criterio que
     * ApprovedProjectController, para no forzarle un permiso de AI a quien ya puede editar
     * Compras.
     */
    public function validate(Request $request, int $orderId, int $documentId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $document = PurchaseOrderDocument::find($documentId);
        if ($document === null || $document->purchase_order_id !== $orderId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $order = PurchaseOrder::find($orderId);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (RateLimiter::tooManyAttempts($this->validationLimiterKey($orderId), self::VALIDATION_MAX_ATTEMPTS_PER_ORDER)) {
            return response()->json([
                'message' => 'Se alcanzó el límite de validaciones con IA para esta orden. Intenta de nuevo más tarde.',
            ], 429);
        }
        RateLimiter::hit($this->validationLimiterKey($orderId), self::VALIDATION_DECAY_SECONDS);

        $tenantSlug = $this->resolveTenantSlug($request);
        if ($tenantSlug === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $analysisId = $this->dispatchValidation($documentId, $orderId, $user->id, $tenantSlug);

        return response()->json(['job_id' => $analysisId], 202);
    }

    /**
     * SCRUM-211 — poll del resultado. Devuelve `status: null` (no un JSON `null` a secas —
     * `response()->json(null)` lo serializa como `{}`/`[]` en esta versión de Symfony, no como
     * `null` literal) si todavía no se disparó ninguna validación para este documento.
     */
    public function validation(Request $request, int $orderId, int $documentId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        // Mismo chequeo de pertenencia que validate() — sin esto, un documentId real bajo OTRA
        // orden devolvía su resultado de validación igual, ignorando el orderId de la URL.
        $document = PurchaseOrderDocument::find($documentId);
        if ($document === null || $document->purchase_order_id !== $orderId) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var AiAnalysis|null $analysis */
        $analysis = AiAnalysis::where('entity_type', 'purchase_order_document')
            ->where('entity_id', $documentId)
            ->where('analysis_type', self::VALIDATION_ANALYSIS_TYPE)
            ->latest()
            ->first();

        if ($analysis === null) {
            return response()->json(['id' => null, 'status' => null, 'result' => null, 'error' => null]);
        }

        return response()->json([
            'id'     => $analysis->id,
            'status' => $analysis->status,
            'result' => $analysis->result !== null ? json_decode($analysis->result, true) : null,
            'error'  => $analysis->error,
        ]);
    }

    private function validationLimiterKey(int $orderId): string
    {
        return "compras.provider_confirmation.validate:{$orderId}";
    }

    private function dispatchValidation(int $documentId, int $orderId, int $userId, string $tenantSlug): string
    {
        /** @var AiAnalysis $analysis */
        $analysis = AiAnalysis::create([
            'entity_type'   => 'purchase_order_document',
            'entity_id'     => $documentId,
            'analysis_type' => self::VALIDATION_ANALYSIS_TYPE,
            'status'        => AiAnalysis::STATUS_PENDING,
            'requested_by'  => $userId,
            'extra'         => ['order_id' => $orderId],
        ]);

        ValidateProviderConfirmationJob::dispatch($analysis->id, $documentId, $orderId, $tenantSlug);

        return $analysis->id;
    }

    /** @return array<string, mixed> */
    private function format(PurchaseOrderDocument $document): array
    {
        return [
            'id'                => $document->id,
            'category'          => $document->category,
            'original_filename' => $document->original_filename,
            'url'               => $this->documents->presignUrl($document),
            'created_at'        => $document->created_at->toIso8601String(),
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
