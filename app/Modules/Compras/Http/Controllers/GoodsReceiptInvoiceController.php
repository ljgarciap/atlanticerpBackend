<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\AI\Models\AiAnalysis;
use App\Modules\Compras\Http\Requests\StoreGoodsReceiptInvoiceRequest;
use App\Modules\Compras\Jobs\MatchInvoiceToOrderJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SCRUM-224 (ADR-SCRUM224) — Paso 0 del wizard de Ingreso de Mercancía: sube la factura/proforma
 * y dispara la detección automática con IA (MatchInvoiceToOrderJob). Mismo patrón
 * store()/dispara-Job + show()/poll que PurchaseOrderDocumentController::validate()/validation()
 * (SCRUM-211), pero sin `orderId` en la URL — acá la orden todavía no se conoce, es justamente lo
 * que el Job tiene que resolver.
 */
final class GoodsReceiptInvoiceController extends Controller
{
    private const DISK          = 's3-private';
    private const ANALYSIS_TYPE = 'goods_receipt_invoice_match';

    /**
     * ADR-SCRUM224 punto 6 — rate limit por usuario (no por orden, porque acá no hay una orden
     * todavía cuando se sube el archivo). 10/hora confirmado por Luis.
     */
    private const MATCH_MAX_ATTEMPTS_PER_USER = 10;
    private const MATCH_DECAY_SECONDS = 3600;

    /** Sube la factura y dispara la detección. */
    public function store(StoreGoodsReceiptInvoiceRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $limiterKey = "compras.goods_receipt_invoice.match:{$user->id}";
        if (RateLimiter::tooManyAttempts($limiterKey, self::MATCH_MAX_ATTEMPTS_PER_USER)) {
            return response()->json([
                'message' => 'Se alcanzó el límite de detecciones con IA para facturas. Intenta de nuevo más tarde.',
            ], 429);
        }

        $tenantSlug = $this->resolveTenantSlug($request);
        if ($tenantSlug === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{file: \Illuminate\Http\UploadedFile} $validated */
        $validated = $request->validated();
        $file      = $validated['file'];

        $ext = strtolower($file->getClientOriginalExtension());
        $key = 'compras/goods-receipt-invoices/' . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');

        $uploaded = Storage::disk(self::DISK)->putFileAs('compras/goods-receipt-invoices', $file, basename($key));
        if ($uploaded === false) {
            return response()->json(['message' => 'No se pudo subir la factura.'], 500);
        }

        RateLimiter::hit($limiterKey, self::MATCH_DECAY_SECONDS);

        /** @var AiAnalysis $analysis */
        $analysis = AiAnalysis::create([
            'entity_type'   => 'goods_receipt_invoice',
            'entity_id'     => null,
            'analysis_type' => self::ANALYSIS_TYPE,
            'status'        => AiAnalysis::STATUS_PENDING,
            'requested_by'  => $user->id,
            'extra'         => [
                'storage_key'       => $key,
                'original_filename' => $file->getClientOriginalName(),
            ],
        ]);

        MatchInvoiceToOrderJob::dispatch(
            $analysis->id,
            $key,
            $file->getClientOriginalName(),
            $file->getMimeType() ?? 'application/octet-stream',
            $tenantSlug,
        );

        return response()->json(['job_id' => $analysis->id], 202);
    }

    /**
     * Poll del resultado. Devuelve `status: null` explícito (no un JSON `null` a secas, ver mismo
     * comentario en PurchaseOrderDocumentController::validation()) si el id no corresponde a un
     * análisis de este tipo.
     */
    public function show(Request $request, string $jobId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var AiAnalysis|null $analysis */
        $analysis = AiAnalysis::where('id', $jobId)
            ->where('analysis_type', self::ANALYSIS_TYPE)
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
