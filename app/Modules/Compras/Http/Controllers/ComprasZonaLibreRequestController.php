<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Compras\Models\BodegaZonaLibreRequest;
use App\Modules\Compras\Models\BodegaZonaLibreRequestLine;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-440 (REQ-370) — decisión de Yirena (Compras) sobre una solicitud de Zona Libre generada
 * por Bodega. Mismo mecanismo exacto que `ReplacementRequestController::generateOrder()`: al
 * aprobar, crea un `PurchaseOrder` completamente normal (`status = STATUS_POR_APROBAR`,
 * `modality = MODALITY_ZONA_LIBRE`, constante ya existente en `PurchaseOrder`, ver REQ-153) que de
 * ahí en más sigue el flujo normal de Compras (pago, logística, ingreso) — cumple RN1/RN3 de
 * REQ-370 literalmente: la orden generada es indistinguible de cualquier otra.
 *
 * Idempotencia de estado (SCRUM-440 RN, hallazgo esperado de Pre-QA): una solicitud ya
 * `aprobada`/`rechazada` no se puede volver a resolver — el chequeo de estado vive DENTRO de la
 * transacción, contra una fila re-obtenida con `lockForUpdate()`, mismo patrón que corrigió el
 * CRÍTICO real de `AdjustmentRequestController::approve()`/`reject()` (2 approve concurrentes
 * sobre la misma solicitud no deben generar 2 órdenes).
 */
final class ComprasZonaLibreRequestController extends Controller
{
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $result = DB::transaction(function () use ($id, $user): array {
            /** @var BodegaZonaLibreRequest|null $zonaLibreRequest */
            $zonaLibreRequest = BodegaZonaLibreRequest::with('lines.catalogProduct')->lockForUpdate()->find($id);
            if ($zonaLibreRequest === null) {
                return ['status' => 404];
            }
            if (! $zonaLibreRequest->isPending()) {
                return ['status' => 422, 'message' => 'Esta solicitud ya fue resuelta.'];
            }
            if ($zonaLibreRequest->lines->isEmpty()) {
                return ['status' => 422, 'message' => 'Esta solicitud no tiene líneas — no se puede generar la orden.'];
            }

            $providerId = ComprasSettings::current()->zona_libre_provider_id;
            if ($providerId === null) {
                return ['status' => 422, 'message' => 'No hay un proveedor de Zona Libre configurado.'];
            }

            $order = PurchaseOrder::create([
                'provider_id'             => $providerId,
                'status'                  => PurchaseOrder::STATUS_POR_APROBAR,
                'status_changed_at'       => now(),
                'modality'                => PurchaseOrder::MODALITY_ZONA_LIBRE,
                'shipping_type'           => $zonaLibreRequest->shipping_type,
                'estimated_arrival_date'  => $zonaLibreRequest->estimated_arrival_date,
                'requires_mark_approval'  => false,
                'currency'                => 'USD',
                'total_amount'            => $zonaLibreRequest->lines->sum(fn (BodegaZonaLibreRequestLine $l) => $l->subtotal()),
                'notes'                   => $zonaLibreRequest->notes,
                'created_by'              => $user->id,
            ]);

            foreach ($zonaLibreRequest->lines as $line) {
                PurchaseOrderLine::create([
                    'purchase_order_id'  => $order->id,
                    'catalog_product_id' => $line->catalog_product_id,
                    'quantity'           => $line->quantity,
                    'unit_cost'          => $line->unit_cost_snapshot,
                    'subtotal'           => $line->subtotal(),
                ]);
            }

            $zonaLibreRequest->update([
                'status'             => BodegaZonaLibreRequest::STATUS_APROBADA,
                'approved_by'        => $user->id,
                'generated_order_id' => $order->id,
            ]);

            return ['status' => 200, 'order_id' => $order->id];
        });

        if ($result['status'] === 404) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result['status'] === 422) {
            return response()->json(['message' => $result['message']], 422);
        }

        return response()->json(['order_id' => $result['order_id']]);
    }

    /** RN (ADR sección 3) — motivo obligatorio, nunca automático, nunca genera `PurchaseOrder`. */
    public function reject(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'min:1']]);

        $result = DB::transaction(function () use ($id, $user, $validated): array {
            /** @var BodegaZonaLibreRequest|null $zonaLibreRequest */
            $zonaLibreRequest = BodegaZonaLibreRequest::lockForUpdate()->find($id);
            if ($zonaLibreRequest === null) {
                return ['status' => 404];
            }
            if (! $zonaLibreRequest->isPending()) {
                return ['status' => 422];
            }

            $zonaLibreRequest->update([
                'status'            => BodegaZonaLibreRequest::STATUS_RECHAZADA,
                'rejected_by'       => $user->id,
                'rejection_reason'  => $validated['rejection_reason'],
            ]);

            return ['status' => 200];
        });

        if ($result['status'] === 404) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result['status'] === 422) {
            return response()->json(['message' => 'Esta solicitud ya fue resuelta.'], 422);
        }

        return response()->json(['message' => 'Solicitud rechazada.']);
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
