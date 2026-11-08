<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Http\Requests\AssignBankMovementAccountRequest;
use App\Modules\AdminCont\Http\Requests\ReassignBankMovementAccountRequest;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use App\Modules\AdminCont\Models\AdminContBankMovement;
use App\Modules\AdminCont\Models\AdminContDailyCashCount;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Services\AuditService;
use App\Shared\Support\PrimaryApprovers;
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

        // Fase 28 (grupo 4) — filtros por tipo, rango de fechas y «sin cuenta».
        $request->validate([
            'tipo'       => ['nullable', 'string', 'in:'.implode(',', [AdminContBankMovement::TIPO_COBRO, AdminContBankMovement::TIPO_DEVOLUCION, AdminContBankMovement::TIPO_COMISION])],
            'desde'      => ['nullable', 'date'],
            'hasta'      => ['nullable', 'date', 'after_or_equal:desde'],
            'sin_cuenta' => ['nullable', 'boolean'],
        ]);
        if ($request->filled('tipo')) {
            $query->where('tipo', $request->query('tipo'));
        }
        if ($request->filled('desde')) {
            $query->whereDate('fecha', '>=', $request->query('desde'));
        }
        if ($request->filled('hasta')) {
            $query->whereDate('fecha', '<=', $request->query('hasta'));
        }
        if ($request->boolean('sin_cuenta')) {
            $query->whereNull('bank_account_id');
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

        // Fase 28 — pendiente visible: salidas (todo lo que no es cobro) sin cuenta, sin importar los filtros.
        $pendientes = AdminContBankMovement::query()->whereNull('bank_account_id')->where('tipo', '!=', AdminContBankMovement::TIPO_COBRO);

        return response()->json([
            'data'    => $movements,
            'meta'    => Pagination::meta($paginator),
            'pendientes_sin_cuenta' => [
                'cantidad' => (clone $pendientes)->count(),
                'monto'    => round((float) $pendientes->sum('monto'), 2),
            ],
        ]);
    }

    public function assignAccount(AssignBankMovementAccountRequest $request, int $id): JsonResponse
    {
        if (($denegado = $this->denegarSiNoPuede($request)) !== null) {
            return $denegado;
        }

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

    /**
     * PUT /api/admin-contab/bank-movements/{id}/reassign-account — Fase 28 (grupo 4): corregir una cuenta
     * ya asignada. Pide motivo, queda en la auditoría (cuenta anterior y nueva) y no se permite si el
     * arqueo de la fecha del movimiento ya se cerró (hay que reabrirlo primero: el arqueo congela sus cifras).
     */
    public function reassignAccount(ReassignBankMovementAccountRequest $request, int $id): JsonResponse
    {
        if (($denegado = $this->denegarSiNoPuede($request)) !== null) {
            return $denegado;
        }

        $movement = AdminContBankMovement::find($id);
        if ($movement === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($movement->bank_account_id === null) {
            return response()->json(['message' => 'El movimiento no tiene cuenta asignada: usa la asignación inicial.'], 422);
        }
        $nueva = (int) $request->validated('bank_account_id');
        if ($nueva === $movement->bank_account_id) {
            return response()->json(['message' => 'El movimiento ya está en esa cuenta.'], 422);
        }
        $arqueoCerrado = AdminContDailyCashCount::query()
            ->whereDate('fecha', $movement->fecha->toDateString())
            ->where('estado', '!=', AdminContDailyCashCount::ESTADO_ABIERTO)->exists();
        if ($arqueoCerrado) {
            return response()->json(['message' => 'El arqueo de la fecha del movimiento ya está cerrado: reábrelo antes de cambiar su cuenta.'], 409);
        }

        $anterior = $movement->bank_account_id;
        $movement->update(['bank_account_id' => $nueva]);

        /** @var array{sub?: int}|null $jwt */
        $jwt = $request->attributes->get('jwt');
        app(AuditService::class)->log(
            'admin_contab.bank_movement.account_reassigned',
            'bank_movement',
            $movement->id,
            ['bank_account_id' => $anterior],
            ['bank_account_id' => $nueva],
            ['motivo' => trim((string) $request->validated('motivo')), 'tipo' => $movement->tipo, 'monto' => $movement->monto],
            isset($jwt['sub']) ? (int) $jwt['sub'] : null,
        );

        return response()->json($movement->fresh('bankAccount'));
    }

    /**
     * Asignar o corregir una cuenta lo hacen Contabilidad y la Asistente (`admin_contab.operations.manage`)
     * y, como segundo asignador cuando ellas no están, el aprobador primario o un suplente (Fase 28).
     * Se distingue «falta el permiso» de «no eres el aprobador» en el mensaje (mismo criterio que la Fase 12b).
     */
    private function denegarSiNoPuede(Request $request): ?JsonResponse
    {
        /** @var array{sub?: int, permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');
        $permissions = is_array($payload['permissions'] ?? null) ? array_values(array_filter($payload['permissions'], 'is_string')) : [];

        if (in_array('superadmin.all', $permissions, true) || in_array('admin_contab.operations.manage', $permissions, true)) {
            return null;
        }
        if (isset($payload['sub']) && PrimaryApprovers::isApprover((int) $payload['sub'], AdminContFiscalSettings::current()->primary_approver_user_id)) {
            return null;
        }

        return response()->json(['message' => 'Solo Contabilidad, la Asistente Administrativa, el aprobador primario o un suplente pueden asignar la cuenta de un movimiento.'], 403);
    }
}
