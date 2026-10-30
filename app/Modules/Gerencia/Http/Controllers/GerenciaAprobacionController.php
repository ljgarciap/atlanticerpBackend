<?php

declare(strict_types=1);

namespace App\Modules\Gerencia\Http\Controllers;

use App\Models\User;
use App\Modules\Bodega\Http\Controllers\AdjustmentRequestController;
use App\Modules\Bodega\Http\Controllers\GeneralCountController;
use App\Modules\Bodega\Http\Controllers\RelocationRequestController;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Shared\Support\AprobacionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-162 — Aprobar / Rechazar solicitudes directamente desde el panel de Gerencia.
 *
 * Tipos soportados: purchase_order, relocation, adjustment, general_count.
 *
 * POST /api/gerencia/aprobaciones/{type}/{id}/aprobar
 * POST /api/gerencia/aprobaciones/{type}/{id}/rechazar  (acepta body: { razon?: string })
 *
 * Autorización: `AprobacionGate::canAct($user, $type)` — Gerencia (purchase_order/relocation/
 * adjustment/general_count), más `ReglaAprobacion` activa para el tipo exacto en los 3 tipos no-PO (SCRUM-163).
 *
 * Rediseño 2026-08-26 (SCRUM-161/162, hallazgo Pre-QA v7): antes este controller duplicaba la
 * lógica de aprobar/rechazar de cada tipo con literales de enum inválidos (`'aprobado'` en vez de
 * `'aprobada'`, etc.) y sin propagar el motivo de rechazo. Ahora DELEGA la acción real de los 4
 * tipos no-PO al controller propio de cada módulo — reutiliza sus constantes, sus locks de
 * concurrencia y (vía `AprobacionGate`, ya que la llamada directa a un método de otro controller
 * NO pasa por el middleware de ruta) su autorización real. `purchase_order` sigue con su propia
 * lógica simple (sin cambios) — es el flujo de dinero real, deliberadamente fuera de este
 * rediseño (ver docs/architecture/gerencia-epic-analisis-20260826.md).
 */
final class GerenciaAprobacionController extends Controller
{
    /** Campo de motivo de rechazo que espera cada controller delegado. */
    private const CAMPO_MOTIVO_RECHAZO = [
        'relocation'    => 'motivo_rechazo',
        'adjustment'    => 'motivo_rechazo',
        'general_count' => 'motivo_rechazo',
    ];

    public function __construct(private readonly AprobacionGate $gate)
    {
    }

    public function aprobar(Request $request, string $type, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! $this->gate->canAct($user, $type)) {
            return response()->json(['message' => $this->gate->motivoDenegacion($type)], 403);
        }

        return match ($type) {
            'purchase_order' => $this->aprobarPurchaseOrder($id, $user->id),
            'relocation'     => app(RelocationRequestController::class)->approve($request, $id),
            'adjustment'     => app(AdjustmentRequestController::class)->approve($request, $id),
            'general_count'  => app(GeneralCountController::class)->approve($request, $id),
            default          => response()->json(['message' => 'Tipo de solicitud desconocido.'], 422),
        };
    }

    public function rechazar(Request $request, string $type, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! $this->gate->canAct($user, $type)) {
            return response()->json(['message' => $this->gate->motivoDenegacion($type)], 403);
        }

        if ($type === 'purchase_order') {
            return response()->json(['message' => 'Las órdenes de compra no pueden rechazarse desde Gerencia. Gestiona el rechazo desde el módulo de Compras.'], 422);
        }

        $campoDestino = self::CAMPO_MOTIVO_RECHAZO[$type] ?? null;
        if ($campoDestino === null) {
            return response()->json(['message' => 'Tipo de solicitud desconocido.'], 422);
        }

        // El motivo de rechazo se captura acá bajo el nombre público `razon` (contrato de la API
        // de Gerencia, sin cambios), pero cada módulo destino valida su propio campo real
        // (`motivo_rechazo`/`rejection_reason`) — lo remapeamos antes de delegar para que la
        // validación (y su obligatoriedad, donde el módulo la exige) sea la real, no una copia.
        $validated = $request->validate(['razon' => 'nullable|string|max:500']);
        $request->merge([$campoDestino => $validated['razon'] ?? null]);

        return match ($type) {
            'relocation'     => app(RelocationRequestController::class)->reject($request, $id),
            'adjustment'     => app(AdjustmentRequestController::class)->reject($request, $id),
            'general_count'  => app(GeneralCountController::class)->reject($request, $id),
            default          => response()->json(['message' => 'Tipo de solicitud desconocido.'], 422),
        };
    }

    // ── purchase_order — sin cambios, fuera del rediseño (ver docblock de la clase) ──────────────

    private function aprobarPurchaseOrder(int $id, int $userId): JsonResponse
    {
        $order = PurchaseOrder::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Orden no encontrada.'], 404);
        }
        if ($order->status !== PurchaseOrder::STATUS_POR_APROBAR) {
            return response()->json(['message' => 'La orden ya no está pendiente de aprobación.'], 422);
        }
        if ($order->approved_by !== null) {
            return response()->json(['message' => 'Esta orden ya fue aprobada.'], 422);
        }

        $order->update(['approved_by' => $userId, 'approved_at' => now()]);

        return response()->json(['ok' => true]);
    }

    // ── Auth helpers ─────────────────────────────────────────────────────────────

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
