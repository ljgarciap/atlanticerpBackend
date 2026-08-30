<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\Ticket;
use Carbon\CarbonImmutable;

/**
 * Fase 4 — Servicios, Batch 7 (REQ-292 RN3) + Batch 10 (REQ-258, SCRUM-321).
 *
 * REQ-292 resuelve la conversión de los 3 valores capturados manualmente (Satisfacción,
 * Puntualidad, Actitud) a porcentaje. REQ-258 agrega los 2 criterios que SÍ se calculan solos
 * (Cumplimiento de tiempos/SLA, real; Calidad, manual por ahora) más el total ponderado con tope,
 * y la redistribución de peso por licencia médica.
 *
 * REQ-258 RN2 ("Calidad del trabajo técnico" debería sugerirse automáticamente desde la tasa de
 * reincidencia, mismo cálculo que REQ-211 "Resuelto en 1ra visita") queda SIN auto-sugerencia en
 * este batch — REQ-211 no existe todavía (`InternalTechnicianService::pct_resuelto_primera_visita`
 * sigue siendo un stub que devuelve null), y construir acá un cálculo de reincidencia paralelo
 * divergiría del REQ-211 real el día que se construya, mismo riesgo que ese docblock ya señala.
 * Decisión de Luis 2026-08-11: Calidad es 100% captura manual (`CommissionCapture::calidad_pct`,
 * ver `CommissionCaptureService`) hasta que REQ-211 exista.
 *
 * Todos los pesos/montos/umbrales de conversión vienen de `ServiciosSettingsService` — ninguno es
 * constante de código (regla dura, CLAUDE.md raíz § "Nunca", reforzada 2026-08-11 en esta sesión
 * tras encontrar que los umbrales de este archivo SÍ lo estaban desde Batch 7).
 */
final class CommissionCalculationService
{
    public function __construct(private readonly ServiciosSettingsService $settings) {}

    /** RN3 — umbrales configurables (default 4.5/3.5), ver ServiciosSettingsService::commissionSatisfaccionUmbrales(). */
    public function convertSatisfaccion(float $promedio): int
    {
        $umbrales = $this->settings->commissionSatisfaccionUmbrales();

        return match (true) {
            $promedio >= $umbrales[100] => 100,
            $promedio >= $umbrales[60]  => 60,
            default                      => 0,
        };
    }

    /** RN3 — máximo de incidencias que califica 50% es configurable, ver commissionPuntualidadMaxIncidencias50Pct(). */
    public function convertPuntualidad(int $incidencias): int
    {
        $maxPara50 = $this->settings->commissionPuntualidadMaxIncidencias50Pct();

        return match (true) {
            $incidencias === 0        => 100,
            $incidencias <= $maxPara50 => 50,
            default                    => 0,
        };
    }

    /** RN3 — (calificación ÷ escala máxima configurable) × 100%, ver commissionActitudEscalaMax(). */
    public function convertActitud(float $calificacion): int
    {
        $escalaMax = $this->settings->commissionActitudEscalaMax();

        return $escalaMax > 0 ? (int) round(($calificacion / $escalaMax) * 100) : 0;
    }

    /**
     * REQ-258 RN3 — % de tickets de este técnico, cerrados/resueltos en el período, dentro del SLA
     * configurado por tipo de ticket (`ServiciosSettingsService::slaDiasByTipo()`). Un ticket
     * "cumple" si `scheduled_ends_at` (o `updated_at` si no hay agenda) está dentro de los N días
     * hábiles desde `created_at` — mismo criterio de "días hábiles" que el resto del módulo
     * (`TechnicianStatusService`, zona horaria America/Panama).
     */
    public function convertSla(InternalTechnician $technician, int $year, int $month): int
    {
        $start = new CarbonImmutable(sprintf('%04d-%02d-01', $year, $month), 'America/Panama');
        $end   = $start->endOfMonth();

        $tickets = Ticket::query()
            ->where('internal_technician_id', $technician->user_id)
            ->whereIn('estado', Ticket::ESTADOS_REQUIRING_CLOSE_GATE)
            ->whereBetween('updated_at', [$start, $end])
            ->get(['tipo', 'created_at', 'updated_at']);

        if ($tickets->isEmpty()) {
            return 0;
        }

        $dentroDeSla = $tickets->filter(function (Ticket $ticket): bool {
            $slaDias    = $this->settings->slaDiasByTipo($ticket->tipo);
            $diasHabiles = $this->businessDaysBetween($ticket->created_at, $ticket->updated_at);

            return $diasHabiles <= $slaDias;
        })->count();

        $pctCumplimiento = ($dentroDeSla / $tickets->count()) * 100;

        $umbrales = $this->settings->commissionSlaUmbrales();

        return match (true) {
            $pctCumplimiento >= $umbrales[100] => 100,
            $pctCumplimiento >= $umbrales[60]   => 60,
            default                              => 0,
        };
    }

    /**
     * REQ-258 RN1/RN5/RN6 — total ponderado de los 5 criterios, tope = suma de los montos máximos
     * configurados. `porcentajes` trae el % obtenido (0-100) de cada criterio en
     * `ServiciosSettingsService::CRITERIOS`. RN5: si `licenciaMedica`, Puntualidad se pondera a 0%
     * y su monto máximo se redistribuye proporcionalmente entre los otros 4 según sus propios
     * montos configurados (no un reparto igualitario) antes de aplicar el % obtenido de cada uno.
     *
     * @param array<string, int> $porcentajes clave = criterio (`ServiciosSettingsService::CRITERIOS`), valor = % 0-100
     * @return array{total: float, desglose: list<array{criterio: string, peso_pct: int, pct_obtenido: int, monto_max: float, monto_obtenido: float}>}
     */
    public function totalPonderado(array $porcentajes, bool $licenciaMedica): array
    {
        $montosMax = [];
        foreach (ServiciosSettingsService::CRITERIOS as $criterio) {
            $montosMax[$criterio] = (float) $this->settings->commissionMontoMax($criterio);
        }

        if ($licenciaMedica) {
            $montoPuntualidad       = $montosMax['puntualidad'];
            $montosMax['puntualidad'] = 0.0;

            $otros    = array_filter(ServiciosSettingsService::CRITERIOS, static fn (string $c): bool => $c !== 'puntualidad');
            $sumaOtros = array_sum(array_map(fn (string $c): float => $montosMax[$c], $otros));

            if ($sumaOtros > 0) {
                foreach ($otros as $criterio) {
                    $montosMax[$criterio] += $montoPuntualidad * ($montosMax[$criterio] / $sumaOtros);
                }
            }
        }

        $desglose = [];
        $total    = 0.0;

        foreach (ServiciosSettingsService::CRITERIOS as $criterio) {
            $pctObtenido   = $licenciaMedica && $criterio === 'puntualidad' ? 0 : ($porcentajes[$criterio] ?? 0);
            $montoObtenido = $montosMax[$criterio] * ($pctObtenido / 100);
            $total        += $montoObtenido;

            $desglose[] = [
                'criterio'       => $criterio,
                'peso_pct'       => $this->settings->commissionPesoPct($criterio),
                'pct_obtenido'   => $pctObtenido,
                'monto_max'      => round($montosMax[$criterio], 2),
                'monto_obtenido' => round($montoObtenido, 2),
            ];
        }

        return ['total' => round($total, 2), 'desglose' => $desglose];
    }

    /** Días hábiles (lunes-viernes) entre dos fechas, mismo criterio simple usado en el resto del módulo (sin feriados). */
    private function businessDaysBetween(\DateTimeInterface $from, \DateTimeInterface $to): int
    {
        $start = CarbonImmutable::instance($from)->startOfDay();
        $end   = CarbonImmutable::instance($to)->startOfDay();

        if ($end->lessThan($start)) {
            return 0;
        }

        $days = 0;
        $cursor = $start;
        while ($cursor->lessThan($end)) {
            $cursor = $cursor->addDay();
            if (! $cursor->isWeekend()) {
                $days++;
            }
        }

        return $days;
    }
}
