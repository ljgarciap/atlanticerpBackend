<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Models\WarehouseLocation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * SCRUM-454 (REQ-384) — CRUD del catálogo de ubicaciones físicas de una bodega, base del chip
 * "Espacio libre" (ver `WarehouseController::show()`). Sin hard-delete a propósito (RN de la
 * ADR): una ubicación se desactiva (`is_active=false`), nunca se borra, para no perder historial
 * de qué ubicaciones existieron.
 */
final class WarehouseLocationController extends Controller
{
    /** GET /api/bodega/warehouses/{warehouseId}/locations — todas (activas e inactivas). */
    public function index(int $warehouseId): JsonResponse
    {
        $warehouse = Warehouse::find($warehouseId);
        if ($warehouse === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $locations = WarehouseLocation::where('warehouse_id', $warehouseId)
            ->orderBy('codigo')
            ->get(['id', 'warehouse_id', 'codigo', 'is_active']);

        return response()->json(['data' => $locations]);
    }

    /** POST /api/bodega/warehouses/{warehouseId}/locations */
    public function store(Request $request, int $warehouseId): JsonResponse
    {
        $warehouse = Warehouse::find($warehouseId);
        if ($warehouse === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'codigo' => [
                'required', 'string',
                Rule::unique('warehouse_locations')->where('warehouse_id', $warehouseId),
            ],
        ]);

        $location = WarehouseLocation::create([
            'warehouse_id' => $warehouseId,
            'codigo'       => $validated['codigo'],
            'is_active'    => true,
        ]);

        return response()->json([
            'id' => $location->id, 'codigo' => $location->codigo, 'is_active' => $location->is_active,
        ], 201);
    }

    /** PATCH /api/bodega/warehouses/{warehouseId}/locations/{id} */
    public function update(Request $request, int $warehouseId, int $id): JsonResponse
    {
        $location = WarehouseLocation::where('warehouse_id', $warehouseId)->find($id);
        if ($location === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'codigo'    => [
                'sometimes', 'required', 'string',
                Rule::unique('warehouse_locations')->where('warehouse_id', $warehouseId)->ignore($location->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        $location->update($validated);

        return response()->json([
            'id' => $location->id, 'codigo' => $location->codigo, 'is_active' => $location->is_active,
        ]);
    }
}
