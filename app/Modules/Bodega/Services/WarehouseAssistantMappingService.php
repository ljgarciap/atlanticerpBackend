<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\WarehouseAssistantMapping;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-329 Oleada A / Batch A2 (REQ-307 RN3) — CRUD del mapeo vendedor→Asistente de Bodega. La
 * lógica de ASIGNACIÓN AUTOMÁTICA ya existe desde Batch A1 (`OrderService::
 * createFromApprovedQuote()`); lo que faltaba era hacer la tabla editable ("no codificada de
 * forma fija") — este service es ese CRUD, gateado en el controller a Jefe de Bodega/superadmin
 * (REQ-307 PERMISOS: "editable solo por el Jefe de Bodega o un administrador del sistema").
 */
final class WarehouseAssistantMappingService
{
    /** @return Collection<int, WarehouseAssistantMapping> */
    public function active(): Collection
    {
        return WarehouseAssistantMapping::where('is_active', true)
            ->with(['seller', 'assistant'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Crea (o reemplaza) el mapeo activo de un vendedor — desactiva cualquier mapeo activo previo
     * para el mismo vendedor antes de crear el nuevo, para respetar el índice único parcial
     * `warehouse_assistant_mapping_active_seller_uq` (una sola fila ACTIVA por vendedor).
     */
    public function setMapping(int $sellerId, int $assistantId): WarehouseAssistantMapping
    {
        return DB::transaction(function () use ($sellerId, $assistantId): WarehouseAssistantMapping {
            WarehouseAssistantMapping::where('seller_user_id', $sellerId)
                ->where('is_active', true)
                ->lockForUpdate()
                ->update(['is_active' => false]);

            return WarehouseAssistantMapping::create([
                'seller_user_id'    => $sellerId,
                'assistant_user_id' => $assistantId,
                'is_active'         => true,
            ]);
        });
    }
}
