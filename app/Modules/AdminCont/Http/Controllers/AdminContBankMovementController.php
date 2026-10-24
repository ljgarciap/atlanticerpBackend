<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Http\Requests\AssignBankMovementAccountRequest;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * SCRUM-610→611 (REQ-533→534) — Movimientos. Ledger fundacional, ver docblock de la migración de
 * `bank_movements`: hoy legítimamente vacío/solo datos de test hasta que existan Cobros, Notas
 * Crédito y Devoluciones, y Comisiones (batches futuros).
 */
class AdminContBankMovementController
{
    /** SCRUM-947 — paginación estándar (query Eloquent pura, `->paginate()` directo). */
    public function index(Request $request): JsonResponse
    {
        $query = AdminContBankMovement::query()->with('bankAccount')->orderByDesc('fecha');

        if ($request->filled('bank_account_id')) {
            $query->where('bank_account_id', (int) $request->query('bank_account_id'));
        }

        $pagination = Pagination::params($request, [], 'fecha', 'desc');
        /** @var LengthAwarePaginator<int, AdminContBankMovement> $paginator */
        $paginator = $query->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        $movements = collect($paginator->items())->map(fn (AdminContBankMovement $m): array => [
            'id'                 => $m->id,
            'fecha'              => $m->fecha->toDateString(),
            'tipo'               => $m->tipo,
            'concepto'           => $m->concepto,
            'monto'              => $m->monto,
            'direccion'          => $m->direccion(),
            'bank_account_id'    => $m->bank_account_id,
            'bank_account_label' => $m->bankAccount?->label(),
        ]);

        return response()->json(['data' => $movements, 'meta' => Pagination::meta($paginator)]);
    }

    public function assignAccount(AssignBankMovementAccountRequest $request, int $id): JsonResponse
    {
        $movement = AdminContBankMovement::find($id);
        if ($movement === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // RN1 de REQ-534 — solo aplica a comisiones sin cuenta asignada todavía.
        if ($movement->tipo !== AdminContBankMovement::TIPO_COMISION || $movement->bank_account_id !== null) {
            return response()->json([
                'message' => 'Solo se puede asignar cuenta a un movimiento de comisión sin cuenta asignada.',
            ], 422);
        }

        $movement->update(['bank_account_id' => $request->validated('bank_account_id')]);

        return response()->json($movement->fresh('bankAccount'));
    }
}
