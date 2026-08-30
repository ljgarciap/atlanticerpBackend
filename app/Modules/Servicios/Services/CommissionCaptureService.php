<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Models\User;
use App\Modules\Servicios\Models\CommissionCapture;
use App\Modules\Servicios\Models\InternalTechnician;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;

/** Fase 4 — Servicios, Batch 7 (REQ-292). Ver docblock de CommissionCalculationService. */
final class CommissionCaptureService
{
    private const BUSINESS_TIMEZONE = 'America/Panama';

    public function __construct(private readonly CommissionCalculationService $calc) {}

    /**
     * REQ-292 RN6 — si no hay captura del período pedido (por defecto el mes en curso), retorna
     * null; el caller muestra "Pendiente de captura" en vez de inventar un 0.
     *
     * @return array<string, mixed>|null
     */
    public function forPeriod(InternalTechnician $technician, ?int $year = null, ?int $month = null): ?array
    {
        $now   = Carbon::now(self::BUSINESS_TIMEZONE);
        $year  ??= $now->year;
        $month ??= $now->month;

        $capture = CommissionCapture::where('internal_technician_id', $technician->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($capture === null) {
            return null;
        }

        return $this->present($capture);
    }

    /**
     * REQ-292 — alta o edición (RN5: un mes ya cerrado sigue siendo editable, con marca de quién/
     * cuándo). RN1: un solo registro por técnico/mes/año (unique constraint + upsert).
     *
     * Hallazgo Pre-QA 2026-08-09 (SCRUM-362): dos PUT concurrentes para el mismo período sin
     * captura previa pasaban ambos el `where(...)->first()` en null (TOCTOU), y el segundo
     * `create()` reventaba con `UniqueConstraintViolationException` sin manejar — 500 con stack
     * trace expuesto en vez de la misma respuesta 200 idempotente que un guardado secuencial
     * hubiera dado. Se resuelve reintentando como edición si el `create()` choca contra el
     * unique constraint — mismo resultado final que si las dos requests hubieran llegado en
     * cualquier orden, sin duplicar filas.
     *
     * @param array{year: int, month: int, satisfaccion_promedio: float, incidencias_puntualidad: int, calificacion_actitud: float, licencia_medica?: bool} $data
     * @return array<string, mixed>
     */
    public function save(InternalTechnician $technician, array $data, User $user): array
    {
        $existing = CommissionCapture::where('internal_technician_id', $technician->id)
            ->where('year', $data['year'])
            ->where('month', $data['month'])
            ->first();

        $payload = [
            'satisfaccion_promedio'   => $data['satisfaccion_promedio'],
            'incidencias_puntualidad' => $data['incidencias_puntualidad'],
            'calificacion_actitud'    => $data['calificacion_actitud'],
            'licencia_medica'         => $data['licencia_medica'] ?? false,
            'calidad_pct'             => $data['calidad_pct'] ?? null,
        ];

        if ($existing === null) {
            try {
                $capture = CommissionCapture::create([
                    'internal_technician_id' => $technician->id,
                    'year'                   => $data['year'],
                    'month'                  => $data['month'],
                    'captured_by'            => $user->id,
                    ...$payload,
                ]);
            } catch (UniqueConstraintViolationException) {
                // Otra request ganó la carrera y ya creó el registro entre el SELECT de arriba y
                // este INSERT — tratamos esta request como la edición que en efecto es.
                $existing = CommissionCapture::where('internal_technician_id', $technician->id)
                    ->where('year', $data['year'])
                    ->where('month', $data['month'])
                    ->firstOrFail();
                $existing->fill($payload + ['updated_by' => $user->id]);
                $existing->save();
                $capture = $existing;
            }
        } else {
            $existing->fill($payload + ['updated_by' => $user->id]);
            $existing->save();
            $capture = $existing;
        }

        return $this->present($capture);
    }

    /**
     * REQ-258 — desglose + total ponderado de la comisión de un técnico con `has_bonus_plan=true`
     * para un período. `null` si no hay captura de ese período (mismo criterio que `forPeriod()` —
     * el caller muestra "Pendiente de captura", no un total inventado en $0). El % de SLA se
     * calcula en vivo desde tickets reales (`CommissionCalculationService::convertSla()`), no se
     * guarda — a diferencia de los 3+1 valores que sí se capturan mensualmente a mano.
     *
     * @return array{capture: array<string, mixed>, total: float, desglose: list<array<string, mixed>>}|null
     */
    public function commissionForTechnician(InternalTechnician $technician, ?int $year = null, ?int $month = null): ?array
    {
        $now   = Carbon::now(self::BUSINESS_TIMEZONE);
        $year  ??= $now->year;
        $month ??= $now->month;

        $capture = CommissionCapture::where('internal_technician_id', $technician->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if ($capture === null) {
            return null;
        }

        $porcentajes = [
            'calidad'      => $capture->calidad_pct ?? 0,
            'satisfaccion' => $this->calc->convertSatisfaccion((float) $capture->satisfaccion_promedio),
            'sla'          => $this->calc->convertSla($technician, $year, $month),
            'puntualidad'  => $this->calc->convertPuntualidad($capture->incidencias_puntualidad),
            'actitud'      => $this->calc->convertActitud((float) $capture->calificacion_actitud),
        ];

        $resultado = $this->calc->totalPonderado($porcentajes, $capture->licencia_medica);

        return [
            'capture'  => $this->present($capture),
            'total'    => $resultado['total'],
            'desglose' => $resultado['desglose'],
        ];
    }

    /** @return array<string, mixed> */
    private function present(CommissionCapture $capture): array
    {
        return [
            'year'                       => $capture->year,
            'month'                      => $capture->month,
            'satisfaccion_promedio'      => $capture->satisfaccion_promedio,
            'satisfaccion_pct'           => $this->calc->convertSatisfaccion((float) $capture->satisfaccion_promedio),
            'incidencias_puntualidad'    => $capture->incidencias_puntualidad,
            'puntualidad_pct'            => $this->calc->convertPuntualidad($capture->incidencias_puntualidad),
            'calificacion_actitud'       => $capture->calificacion_actitud,
            'actitud_pct'                => $this->calc->convertActitud((float) $capture->calificacion_actitud),
            'licencia_medica'            => $capture->licencia_medica,
            'calidad_pct'                => $capture->calidad_pct,
            'captured_by'                => $capture->capturedByUser?->first_name,
            'updated_by'                 => $capture->updatedByUser?->first_name,
            'updated_at'                 => $capture->updated_at->toIso8601String(),
        ];
    }
}
