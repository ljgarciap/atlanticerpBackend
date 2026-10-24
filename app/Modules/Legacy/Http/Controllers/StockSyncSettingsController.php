<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Http\Controllers;

use App\Models\User;
use App\Modules\Legacy\Http\Requests\StockSyncSettingsRequest;
use App\Modules\Legacy\Models\StockSyncSettings;
use App\Modules\Legacy\Models\SyncRun;
use App\Modules\Legacy\Services\LegacySyncAlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-1114 — parámetros de `legacy:sync-incremental-stock` (interruptor, umbrales del circuit breaker y
 * correos de alerta) más el estado de las últimas corridas, para la pantalla de superadmin. Gate
 * `role:superadmin` en la ruta, igual que el resto de Migración. Cada cambio queda en `audit_logs`
 * (`LogsAuditTrail` en el modelo).
 */
final class StockSyncSettingsController
{
    /** Corridas de stock que devuelve el estado (suficiente para ver la última y la tendencia). */
    private const ULTIMAS = 10;

    public function __construct(private readonly LegacySyncAlertService $alerts)
    {
    }

    public function show(): JsonResponse
    {
        return response()->json($this->payload(StockSyncSettings::current()));
    }

    public function update(StockSyncSettingsRequest $request): JsonResponse
    {
        $datos = $request->validated();

        // La fila se bloquea para leer el "antes" y actualizar en la misma transacción: con PUT concurrentes cada
        // diff del correo parte del valor realmente anterior a SU cambio, no de uno que otro PUT ya pisó.
        /** @var array{0: StockSyncSettings, 1: array<string, mixed>}|JsonResponse $res */
        $res = DB::transaction(function () use ($datos) {
            /** @var StockSyncSettings $settings */
            $settings = StockSyncSettings::query()->lockForUpdate()->findOrFail(1);

            // Encender el sync sin línea base haría fallar cada corrida nocturna en silencio (revisión S2).
            if (($datos['activo'] ?? false) === true && $settings->baseline_inicializada_at === null) {
                return response()->json([
                    'message' => 'No se puede activar la sincronización: la línea base de stock no está inicializada (legacy:init-stock-baseline).',
                    'errors'  => ['activo' => ['La línea base de stock no está inicializada.']],
                ], 422);
            }

            $antes = $settings->only(array_keys($datos));
            $settings->update($datos);

            return [$settings, $antes];
        });
        if ($res instanceof JsonResponse) {
            return $res;
        }
        [$fresh, $antes] = $res;

        // Quien enciende/apaga el sync o cambia un umbral deja rastro en un correo a los superadmins, además de
        // `audit_logs` (revisión de seguridad S-02: bajar los topes o apagar el sync es la vía para dejar pasar un delta).
        $cambios = [];
        foreach ($datos as $campo => $nuevo) {
            if (($antes[$campo] ?? null) != $fresh->{$campo}) {
                $cambios[] = $campo . ': ' . json_encode($antes[$campo] ?? null) . ' -> ' . json_encode($fresh->{$campo});
            }
        }
        if ($cambios !== []) {
            $this->alerts->alertEvento('stock', 'Ajustes de la sincronización de stock modificados', array_merge(
                ['Por: ' . $this->actor($request) . ' (IP ' . $request->ip() . ')'],
                $cambios,
            ));
        }

        return response()->json($this->payload($fresh));
    }

    private function actor(StockSyncSettingsRequest $request): string
    {
        /** @var array{sub?: int}|null $jwt */
        $jwt  = $request->attributes->get('jwt');
        $user = isset($jwt['sub']) ? User::find((int) $jwt['sub']) : null;

        return $user !== null ? $user->email : 'desconocido';
    }

    /** @return array<string, mixed> */
    private function payload(StockSyncSettings $s): array
    {
        return [
            'settings' => [
                'activo'                        => $s->activo,
                'baseline_inicializada_at'      => $s->baseline_inicializada_at?->toIso8601String(),
                'max_productos_con_delta_pct'   => $s->max_productos_con_delta_pct,
                'max_unidades_abs_por_producto' => $s->max_unidades_abs_por_producto,
                'max_unidades_abs_total'        => $s->max_unidades_abs_total,
                'min_filas_icg_pct'             => $s->min_filas_icg_pct,
                'horas_aviso_apagado'           => $s->horas_aviso_apagado,
                'correos_alerta'                => $s->correos_alerta,
            ],
            'ultimas_corridas' => SyncRun::query()
                ->where('entidad', 'stock')
                ->orderByDesc('started_at')
                ->limit(self::ULTIMAS)
                ->get()
                ->map(static fn (SyncRun $run): array => [
                    'id'          => $run->id,
                    'origen'      => $run->origen,
                    'status'      => $run->status,
                    'extraidos'   => $run->extraidos,
                    'con_delta'   => $run->mapeados,
                    'aplicados'   => $run->cargados,
                    'errores'     => $run->errores,
                    'duracion_ms' => $run->duracion_ms,
                    'started_at'  => $run->started_at->toIso8601String(),
                    'resumen'     => $run->resumen,
                ])
                ->values(),
        ];
    }
}
