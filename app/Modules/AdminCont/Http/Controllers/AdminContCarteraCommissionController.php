<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Http\Requests\StoreCarteraCommissionTierRequest;
use App\Modules\AdminCont\Http\Requests\UpdateCarteraCommissionTierRequest;
use App\Modules\AdminCont\Models\CarteraCommissionTier;
use Illuminate\Http\JsonResponse;

/**
 * Batch 22 de Admin&Cont (SCRUM-643→647, REQ-567) — CRUD de los tramos de la comisión de Contabilidad
 * por gestión de cartera +90 días. Mismo patrón que `AdminContInternalCommissionController::
 * tiers/storeTier/updateTier/destroyTier` (Batch 14) — lectura para el roster amplio de Reportes,
 * escritura exclusiva de Gerencia (`primary_approver_only` en routes/admin-contab.php).
 */
class AdminContCarteraCommissionController
{
    /** GET /api/admin-contab/commissions/cartera/tiers */
    public function tiers(): JsonResponse
    {
        return response()->json(
            CarteraCommissionTier::query()->orderBy('orden')->get(),
        );
    }

    /** POST /api/admin-contab/commissions/cartera/tiers */
    public function storeTier(StoreCarteraCommissionTierRequest $request): JsonResponse
    {
        $avisos = app(\App\Modules\AdminCont\Services\CommissionTierScaleGuard::class)
            ->check(CarteraCommissionTier::query()->get(), $request->validated(), validarOrden: true);
        $tier = CarteraCommissionTier::create($request->validated());

        return response()->json(array_merge(($tier->fresh() ?? $tier)->toArray(), ['avisos' => $avisos]), 201);
    }

    /** PUT /api/admin-contab/commissions/cartera/tiers/{id} */
    public function updateTier(UpdateCarteraCommissionTierRequest $request, int $id): JsonResponse
    {
        $tier = CarteraCommissionTier::find($id);
        if ($tier === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $datos = array_merge($tier->only(['monto_minimo', 'monto_maximo', 'orden']), $request->validated());
        $avisos = app(\App\Modules\AdminCont\Services\CommissionTierScaleGuard::class)
            ->check(CarteraCommissionTier::query()->get(), $datos, ignorarId: $tier->id, validarOrden: true);
        $tier->update($request->validated());

        return response()->json(array_merge(($tier->fresh() ?? $tier)->toArray(), ['avisos' => $avisos]));
    }

    /** DELETE /api/admin-contab/commissions/cartera/tiers/{id} */
    public function destroyTier(int $id): JsonResponse
    {
        $tier = CarteraCommissionTier::find($id);
        if ($tier === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $tier->delete();

        return response()->json(null, 204);
    }
}
