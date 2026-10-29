<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Compras\Events\PaymentAmountChangeApproved;
use App\Modules\Compras\Events\PaymentRegistered;
use App\Modules\Compras\Events\PaymentRequested;
use App\Modules\Compras\Http\Requests\StorePurchaseOrderPaymentRequest;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderPayment;
use App\Modules\Compras\Services\PurchaseOrderPaymentService;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\PrimaryApprovers;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;

/**
 * SCRUM-250→253 (REQ-187→190) — Pagos a Proveedores. "Enviar a pago" (RN de REQ-189) es
 * puramente informativo (notifica a Gerencia, no bloquea el registro real del pago); el registro de
 * un pago NO depende de una aprobación previa — solo el CAMBIO DE MONTO antes del primer pago sí
 * (RN7). Ver docs/architecture/compras-sustitutos-pagos-agencias-reclamos-reportes.md.
 */
final class PurchaseOrderPaymentController extends Controller
{
    public function __construct(private readonly PurchaseOrderPaymentService $payments) {}

    /** REQ-190 — historial completo, más antiguo primero. */
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

        return response()->json([
            'data' => $order->payments->map(fn (PurchaseOrderPayment $p) => $this->format($p))->values(),
        ]);
    }

    /**
     * REQ-189 Escenario 1/2/3 — comprobante obligatorio (FormRequest), bloquea si excede el
     * saldo pendiente. No depende de `payment_requested_at` ni de ninguna aprobación previa.
     */
    public function store(StorePurchaseOrderPaymentRequest $request, int $orderId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::with('payments')->find($orderId);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // SCRUM-252 (hallazgo de Gerencia Test 2026-08-09) — mientras haya un cambio de monto
        // pendiente de aprobación de Gerencia, "Registrar pago" queda BLOQUEADO de verdad (antes solo
        // se mostraba el aviso, pero el registro seguía funcionando con el monto viejo).
        if ($order->pending_amount_change !== null) {
            return response()->json([
                'message' => 'Hay un cambio de monto pendiente de aprobación del aprobador configurado. No se puede registrar un pago hasta que se resuelva.',
            ], 422);
        }

        /** @var array{amount: float, proof: UploadedFile} $validated */
        $validated = $request->validated();
        $amount = (float) $validated['amount'];

        $balance = round($order->payableTotal() - $order->paidAmount(), 2);
        if ($amount > $balance) {
            return response()->json([
                'message' => sprintf('El monto excede el saldo pendiente. El máximo permitido es %.2f.', $balance),
            ], 422);
        }

        $payment = $this->payments->register($validated['proof'], $order->id, $amount, $user->id);
        $order->refresh()->load('payments');

        event(new PaymentRegistered(
            orderId: $order->id,
            paymentId: $payment->id,
            amount: $payment->amount,
            paymentStatus: $order->paymentStatus(),
            registeredBy: $user->id,
            tenant: (string) Tenant::current()?->slug,
        ));

        return response()->json($this->format($payment), 201);
    }

    /** REQ-189 — "Enviar a pago", informativo, no bloquea nada. */
    public function requestPayment(Request $request, int $orderId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::find($orderId);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // SCRUM-252 (hallazgo de Gerencia Test) — mismo bloqueo real que store(): "Enviar a
        // pago" también queda deshabilitado mientras haya un cambio de monto pendiente.
        if ($order->pending_amount_change !== null) {
            return response()->json([
                'message' => 'Hay un cambio de monto pendiente de aprobación del aprobador configurado. No se puede enviar a pago hasta que se resuelva.',
            ], 422);
        }

        $order->update(['payment_requested_at' => now()]);

        event(new PaymentRequested(orderId: $order->id, requestedBy: $user->id, tenant: (string) Tenant::current()?->slug));

        return response()->json(['payment_requested_at' => $order->payment_requested_at?->toIso8601String()]);
    }

    /**
     * REQ-189 RN7 — cambiar el monto ANTES del primer pago registrado requiere aprobación de
     * Gerencia; queda pendiente hasta que approveAmountChange() lo aplique.
     */
    public function requestAmountChange(Request $request, int $orderId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::with('payments')->find($orderId);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($order->payments->isNotEmpty()) {
            return response()->json(['message' => 'No se puede cambiar el monto de una orden que ya tiene pagos registrados.'], 422);
        }

        $validated = $request->validate(['new_amount' => ['required', 'numeric', 'min:0.01']]);

        $order->update([
            'pending_amount_change' => $validated['new_amount'],
            'amount_change_requested_by' => $user->id,
        ]);

        return response()->json([
            'pending_amount_change' => $order->pending_amount_change,
            'amount_change_requested_by' => $order->amount_change_requested_by,
        ]);
    }

    /**
     * REQ-189 RN7 — solo Gerencia (mismo gate que PurchaseOrderController::approve()).
     *
     * Hallazgo de Pre-QA (2026-07-18, no cubierto por ningún escenario literal del ticket): si
     * se registra un pago DESPUÉS de pedir el cambio de monto pero ANTES de que Gerencia lo apruebe,
     * la solicitud queda obsoleta — aprobarla igual podría dejar `total_amount` por debajo de lo
     * ya pagado (`paid_amount > total_amount`, `payment_status` calculando mal). Mismo guard que
     * `requestAmountChange()` ya usa para bloquear un pedido nuevo con pagos existentes, acá se
     * aplica también al momento de aprobar.
     */
    public function approveAmountChange(Request $request, int $orderId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::with('payments')->find($orderId);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($order->pending_amount_change === null) {
            return response()->json(['message' => 'Esta orden no tiene un cambio de monto pendiente.'], 422);
        }

        if ($order->payments->isNotEmpty()) {
            $order->update(['pending_amount_change' => null, 'amount_change_requested_by' => null]);

            return response()->json(['message' => 'La orden ya tiene pagos registrados — el cambio de monto pendiente quedó obsoleto y se descartó.'], 422);
        }

        // superadmin.all bypassa este gate (decisión de Luis, 2026-08-22).
        $primaryApproverId = ComprasSettings::current()->primary_approver_user_id;
        if ($primaryApproverId !== null && ! PrimaryApprovers::isApprover($user->id, $primaryApproverId) && ! $user->hasPermission('superadmin.all')) {
            return response()->json(['message' => 'Solo el aprobador configurado puede aprobar un cambio de monto.'], 403);
        }

        $previousAmount = $order->total_amount;
        $newAmount = $order->pending_amount_change;

        // Fase 13 (2.1): el total debe seguir siendo la suma de las líneas, así que la diferencia queda como una línea de ajuste (calculada contra
        // lo que HOY suman las líneas, no contra el total anterior: si una orden vieja ya no cuadraba, el ajuste la deja cuadrada).
        DB::transaction(function () use ($order, $newAmount): void {
            $sumaLineas = round((float) $order->lines()->sum('subtotal'), 2);
            $diferencia = round((float) $newAmount - $sumaLineas, 2);
            if (abs($diferencia) > 0.004) {
                $order->lines()->create([
                    'catalog_product_id' => null, 'quantity' => 1, 'unit_cost' => $diferencia, 'subtotal' => $diferencia,
                    'is_adjustment' => true, 'adjustment_note' => 'Ajuste de monto aprobado',
                ]);
            }

            $order->update([
                'total_amount' => $newAmount,
                'pending_amount_change' => null,
                'amount_change_requested_by' => null,
            ]);
        });

        event(new PaymentAmountChangeApproved(
            orderId: $order->id,
            previousAmount: $previousAmount,
            newAmount: $newAmount,
            approvedBy: $user->id,
            tenant: (string) Tenant::current()?->slug,
        ));

        return response()->json(['total_amount' => $order->total_amount]);
    }

    /**
     * SCRUM-252 (hallazgo de Gerencia Test) — "Si Gerencia rechaza/cancela el cambio, el nuevo monto
     * no debe aplicarse. La orden debe conservar el monto anterior." Mismo gate de Gerencia que
     * approveAmountChange() — Lider Compras no puede rechazar su propio pedido tampoco (solo Gerencia
     * resuelve la solicitud, en cualquier sentido).
     */
    public function rejectAmountChange(Request $request, int $orderId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::find($orderId);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($order->pending_amount_change === null) {
            return response()->json(['message' => 'Esta orden no tiene un cambio de monto pendiente.'], 422);
        }

        // superadmin.all bypassa este gate (decisión de Luis, 2026-08-22).
        $primaryApproverId = ComprasSettings::current()->primary_approver_user_id;
        if ($primaryApproverId !== null && ! PrimaryApprovers::isApprover($user->id, $primaryApproverId) && ! $user->hasPermission('superadmin.all')) {
            return response()->json(['message' => 'Solo el aprobador configurado puede rechazar un cambio de monto.'], 403);
        }

        $order->update(['pending_amount_change' => null, 'amount_change_requested_by' => null]);

        return response()->json(['total_amount' => $order->total_amount]);
    }

    /** @return array<string, mixed> */
    private function format(PurchaseOrderPayment $payment): array
    {
        return [
            'id' => $payment->id,
            'amount' => $payment->amount,
            'proof_url' => $this->payments->presignProofUrl($payment),
            'registered_by' => $payment->registered_by,
            'registered_by_name' => $this->registeredByName($payment),
            'created_at' => $payment->created_at->toIso8601String(),
        ];
    }

    private function registeredByName(PurchaseOrderPayment $payment): ?string
    {
        /** @var User|null $registeredBy */
        $registeredBy = $payment->registeredByUser;

        return $registeredBy !== null ? trim("{$registeredBy->first_name} {$registeredBy->last_name}") : null;
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
