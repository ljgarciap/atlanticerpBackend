<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Http\Requests\StoreBankAccountRequest;
use App\Modules\AdminCont\Models\AdminContBankAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** SCRUM-607→609 (REQ-530→532) — Cuentas Bancarias. */
class AdminContBankAccountController
{
    /**
     * `?activa=true` — filtro reusable por futuros selectores (REQ-534 y los módulos de Cobros/
     * Comisiones que todavía no existen, ver docs/architecture/admin-contab-batches.md). RN3 de
     * REQ-532: una cuenta inactiva nunca debe ofrecerse para asignar a un movimiento nuevo.
     */
    public function index(Request $request): JsonResponse
    {
        $query = AdminContBankAccount::query()->withCount('movements')->orderBy('id');

        if ($request->filled('activa')) {
            $query->where('activa', $request->boolean('activa'));
        }

        $accounts = $query->get()
            ->map(fn (AdminContBankAccount $a): array => [
                'id'                => $a->id,
                'banco'             => $a->banco,
                'tipo_cuenta'       => $a->tipo_cuenta,
                'ultimos_4_digitos' => $a->ultimos_4_digitos,
                'moneda'            => $a->moneda,
                'activa'            => $a->activa,
                'movimientos_count' => $a->movements_count,
            ]);

        return response()->json($accounts);
    }

    public function store(StoreBankAccountRequest $request): JsonResponse
    {
        // RN1/RN2 de REQ-530 — nace siempre activa (sin importar qué mande el cliente, mismo
        // criterio que AdminContLocationController::store()), y tipo_cuenta/moneda necesitan
        // asignación explícita acá: son defaults de columna a nivel de base de datos, que Eloquent
        // no refleja en el objeto en memoria recién creado sin un refresh() (mismo gotcha ya
        // encontrado en AdminContItbmsRate/AdminContLocation de batches anteriores).
        $account = new AdminContBankAccount($request->validated());
        $account->activa      = true;
        $account->tipo_cuenta = $request->validated('tipo_cuenta') ?? AdminContBankAccount::TIPO_CORRIENTE;
        $account->moneda      = $request->validated('moneda') ?? 'USD';
        $account->save();

        return response()->json($account, 201);
    }

    public function deactivate(int $id): JsonResponse
    {
        $account = AdminContBankAccount::find($id);
        if ($account === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $account->update(['activa' => false]);

        return response()->json($account->fresh());
    }

    public function reactivate(int $id): JsonResponse
    {
        $account = AdminContBankAccount::find($id);
        if ($account === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $account->update(['activa' => true]);

        return response()->json($account->fresh());
    }
}
