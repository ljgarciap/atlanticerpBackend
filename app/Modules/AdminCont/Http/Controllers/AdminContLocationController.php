<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Http\Requests\StoreLocationRequest;
use App\Modules\AdminCont\Http\Requests\UpdateLocationRequest;
use App\Modules\AdminCont\Models\AdminContLocation;
use App\Modules\Bodega\Models\Warehouse;
use Illuminate\Http\JsonResponse;

/**
 * SCRUM-640 (REQ-563) — combina las bodegas/exhibiciones operativas de Atlantic (App\Modules\Bodega\Models\Warehouse::query(): las bodegas
 * operativas), solo lectura acá — RN1: nunca un nombre distinto al de Bodega) con las ubicaciones administrativas
 * propias de este módulo (`editable: true`). El frontend arma su key de fila como `{source}-{id}` —
 * los ids de ambas tablas NO son mutuamente exclusivos.
 */
class AdminContLocationController
{
    public function index(): JsonResponse
    {
        $warehouses = Warehouse::query()
            ->orderBy('id')
            ->get()
            ->map(fn (Warehouse $w): array => [
                'id'        => $w->id,
                'nombre'    => $w->name,
                'tipo'      => $w->tipo,
                'direccion' => null,
                'activa'    => $w->is_active,
                'editable'  => false,
                'source'    => 'bodega',
            ]);

        $locations = AdminContLocation::query()
            ->orderBy('id')
            ->get()
            ->map(fn (AdminContLocation $l): array => [
                'id'        => $l->id,
                'nombre'    => $l->nombre,
                'tipo'      => $l->tipo,
                'direccion' => $l->direccion,
                'activa'    => $l->activa,
                'editable'  => true,
                'source'    => 'admin_contab',
            ]);

        return response()->json($warehouses->concat($locations)->values());
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        // `activa` no está en las reglas de StoreLocationRequest — toda ubicación nueva nace
        // activa, sin importar qué mande el cliente (mismo criterio que RN4 de REQ-558 en
        // ItbmsRate: se asigna explícito acá, no se confía en el default de la migración para
        // que el modelo en memoria refleje el valor real sin necesitar un refresh()).
        $location = new AdminContLocation($request->validated());
        $location->activa = true;
        $location->save();

        return response()->json($location, 201);
    }

    public function update(UpdateLocationRequest $request, int $id): JsonResponse
    {
        $location = AdminContLocation::find($id);
        if ($location === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $location->update($request->validated());

        return response()->json($location->fresh());
    }
}
