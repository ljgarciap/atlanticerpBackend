<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\Compras\Models\ComprasSettings;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\PrimaryApprovers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fase 12b — administración de los aprobadores suplentes (solo superadmin, ruta bajo `permission:superadmin.all`). El titular de cada
 * módulo se sigue fijando donde siempre (Ajustes de Compras / Configuración Fiscal); aquí solo la lista de suplentes, compartida.
 */
class PrimaryApproverSubstituteController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_ids' => ['present', 'array', 'max:10'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);
        $ids = array_map('intval', $data['user_ids']);

        // Un titular no necesita ser suplente de sí mismo: se rechaza para que la lista no cree la falsa impresión de dos personas.
        $titulares = array_filter([ComprasSettings::current()->primary_approver_user_id, AdminContFiscalSettings::current()->primary_approver_user_id]);
        if (array_intersect($ids, $titulares) !== []) {
            return response()->json(['message' => 'El aprobador titular no puede ser también suplente.'], 422);
        }

        $table = $this->table();
        DB::transaction(function () use ($ids, $table, $request): void {
            DB::table($table)->whereNotIn('user_id', $ids)->delete();
            $existentes = DB::table($table)->pluck('user_id')->map(fn ($v): int => (int) $v)->all();
            foreach (array_diff($ids, $existentes) as $userId) {
                DB::table($table)->insert(['user_id' => $userId, 'created_by' => $request->user()?->id, 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        PrimaryApprovers::forget();

        return response()->json($this->payload());
    }

    private function table(): string
    {
        $tenant = Tenant::current() ?? throw new \LogicException('Sin tenant activo.');

        return $tenant->slug.'_auth.primary_approver_substitutes';
    }

    /** @return array{titular_compras: int|null, titular_admin_contab: int|null, suplentes: list<array{id: int, name: string, email: string}>} */
    private function payload(): array
    {
        $ids = PrimaryApprovers::substituteIds();
        $users = $ids === [] ? collect() : User::query()->whereIn('id', $ids)->get(['id', 'first_name', 'last_name', 'email'])->keyBy('id');

        return [
            'titular_compras' => ComprasSettings::current()->primary_approver_user_id,
            'titular_admin_contab' => AdminContFiscalSettings::current()->primary_approver_user_id,
            'suplentes' => array_map(fn (int $id): array => [
                'id' => $id,
                'name' => trim(($users[$id]->first_name ?? '').' '.($users[$id]->last_name ?? '')),
                'email' => (string) ($users[$id]->email ?? ''),
            ], $ids),
        ];
    }
}
