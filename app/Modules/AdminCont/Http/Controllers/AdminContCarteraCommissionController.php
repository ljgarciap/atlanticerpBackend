<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Http\Requests\StoreCarteraCommissionTierRequest;
use App\Modules\AdminCont\Http\Requests\UpdateCarteraCommissionTierRequest;
use App\Modules\AdminCont\Models\CarteraCommissionTier;
use Illuminate\Http\JsonResponse;

/**
 * Batch 22 de Admin&Cont (SCRUM-643→647, REQ-567) — CRUD de los tramos de la comisión de Felix
 * por gestión de cartera +90 días. Mismo patrón que `AdminContInternalCommissionController::
 * tiers/storeTier/updateTier/destroyTier` (Batch 14) — lectura para el roster amplio de Reportes,
 * escritura exclusiva de Mark (`primary_approver_only` en routes/admin-contab.php).
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
        $tier = CarteraCommissionTier::create($request->validated());

        return response()->json($tier, 201);
    }

    /** PUT /api/admin-contab/commissions/cartera/tiers/{id} */
    public function updateTier(UpdateCarteraCommissionTierRequest $request, int $id): JsonResponse
    {
        $tier = CarteraCommissionTier::find($id);
        if ($tier === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $tier->update($request->validated());

        return response()->json($tier->fresh());
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
