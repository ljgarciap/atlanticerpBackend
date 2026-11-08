<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Modules\AdminCont\Services\ProcessApprovers;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Fase 28 (grupo 6) — aprobadores propios por proceso de Admin & Contab. Solo superadmin (la ruta vive bajo
 * `permission:superadmin.all`, sin permiso nuevo: no hay riesgo de fuga por nivel compartido). Lista vacía =
 * el proceso sigue al aprobador primario y a los suplentes.
 */
class ProcessApproverController
{
    public function index(): JsonResponse
    {
        return response()->json($this->payload());
    }

    public function update(Request $request, string $process): JsonResponse
    {
        if (! in_array($process, ProcessApprovers::PROCESSES, true)) {
            return response()->json(['message' => 'Proceso desconocido.'], 404);
        }

        $data = $request->validate([
            'user_ids'   => ['present', 'array', 'max:10'],
            'user_ids.*' => ['integer', 'distinct', 'exists:users,id'],
        ]);
        $ids = array_map('intval', $data['user_ids']);

        /** @var array{sub?: int}|null $jwt */
        $jwt     = $request->attributes->get('jwt');
        $actorId = isset($jwt['sub']) ? (int) $jwt['sub'] : null;
        $antes   = ProcessApprovers::idsOf($process);

        $table = ProcessApprovers::table();
        DB::transaction(function () use ($ids, $table, $process, $actorId): void {
            DB::table($table)->where('process', $process)->whereNotIn('user_id', $ids)->delete();
            $existentes = DB::table($table)->where('process', $process)->pluck('user_id')->map(fn ($v): int => (int) $v)->all();
            foreach (array_diff($ids, $existentes) as $userId) {
                DB::table($table)->insert(['process' => $process, 'user_id' => $userId, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        // Control de separación de funciones: quién cambió quiénes aprueban un proceso, y cuándo, queda en la auditoría.
        app(AuditService::class)->log(
            'admin_contab.process_approvers.updated',
            'process_approvers',
            null,
            ['user_ids' => $antes],
            ['user_ids' => $ids],
            ['process' => $process],
            $actorId,
        );

        return response()->json($this->payload());
    }

    /** @return array{processes: array<string, array{user_ids: list<int>, sigue_al_primario: bool}>} */
    private function payload(): array
    {
        $out = [];
        foreach (ProcessApprovers::PROCESSES as $process) {
            $ids = ProcessApprovers::idsOf($process);
            $out[$process] = ['user_ids' => $ids, 'sigue_al_primario' => $ids === []];
        }

        return ['processes' => $out];
    }
}
