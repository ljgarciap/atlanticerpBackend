<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\Ticket;
use Carbon\Carbon;

/**
 * Fase 4 — Servicios, Batch 15 (REQ-211 RN2a/RN2b) — meta semiautomática de "instalaciones
 * completadas este mes" (panel Indicadores del mes de Inicio).
 *
 * Orden de resolución para un mes dado (año, mes):
 *   1. Override manual de Gerencia para ESE mes puntual (RN2a-c) — siempre gana si existe.
 *   2. Si el sistema todavía no tiene `installationGoalHistoryMonths()` meses completos de
 *      historial real (medido desde el ticket más antiguo del sistema) — valor manual configurable
 *      (RN2a-a, default 36).
 *   3. Con historial suficiente — promedio de instalaciones completadas (Ticket::TIPO_INSTALLATION,
 *      resolved|closed, `closed_at` dentro del mes) de los N meses INMEDIATOS anteriores al mes
 *      pedido, × (1 + growthPct/100) (RN2a-b).
 */
final class InstallationGoalService
{
    public function __construct(private readonly ServiciosSettingsService $settings) {}

    /**
     * @return array{value: int, source: 'manual_override'|'manual_default'|'calculated'}
     */
    public function goalForMonth(int $year, int $month): array
    {
        $override = $this->settings->installationGoalOverride($year, $month);
        if ($override !== null) {
            return ['value' => $override, 'source' => 'manual_override'];
        }

        $created = Carbon::create($year, $month, 1);
        if ($created === null) {
            throw new \InvalidArgumentException("Fecha de meta inválida: {$year}-{$month}.");
        }
        $monthStart = $created->startOfMonth();

        if (! $this->hasSufficientHistory($monthStart)) {
            return ['value' => $this->settings->installationGoalManualDefault(), 'source' => 'manual_default'];
        }

        return ['value' => $this->calculatedGoal($monthStart), 'source' => 'calculated'];
    }

    /** REQ-211 RN2a(c) — Gerencia sobrescribe la meta de un mes puntual. Delegación fina sobre ServiciosSettingsService, único dueño de la persistencia de settings. */
    public function setOverride(int $year, int $month, int $value): void
    {
        $this->settings->setInstallationGoalOverride($year, $month, $value);
    }

    /**
     * RN2a-b — "3 o más meses de historial real" se mide desde el ticket más antiguo que existe en
     * el sistema (cualquier tipo): si desde entonces ya pasaron `installationGoalHistoryMonths()`
     * meses completos antes del mes pedido, hay suficiente historial para calcular en vez de usar
     * el valor manual. No se exige que cada uno de esos meses tenga instalaciones completadas —
     * "historial" es de operación del sistema, no de ese conteo puntual (RN2a-b habla de "meses de
     * historial real de instalaciones completadas" como concepto operativo del sistema, mismo
     * criterio ya usado para "0 completadas" siendo un dato válido, no ausencia de historial).
     */
    private function hasSufficientHistory(Carbon $monthStart): bool
    {
        $earliest = Ticket::min('created_at');
        if ($earliest === null) {
            return false;
        }

        $requiredMonths = $this->settings->installationGoalHistoryMonths();
        $earliestMonth  = Carbon::parse($earliest)->startOfMonth();
        $requiredStart  = $monthStart->copy()->subMonths($requiredMonths);

        return $earliestMonth->lessThanOrEqualTo($requiredStart);
    }

    private function calculatedGoal(Carbon $monthStart): int
    {
        $requiredMonths = $this->settings->installationGoalHistoryMonths();
        $growthPct      = $this->settings->installationGoalGrowthPct();

        $counts = [];
        for ($i = 1; $i <= $requiredMonths; $i++) {
            $window   = $monthStart->copy()->subMonths($i);
            $counts[] = $this->completedInstallations($window->copy()->startOfMonth(), $window->copy()->endOfMonth());
        }

        $average = $counts === [] ? 0.0 : array_sum($counts) / count($counts);

        return (int) round($average * (1 + $growthPct / 100));
    }

    private function completedInstallations(Carbon $start, Carbon $end): int
    {
        return Ticket::whereIn('tipo', TicketTypes::keysWith(TicketTypes::INSTALACION))
            ->whereIn('estado', Ticket::ESTADOS_REQUIRING_CLOSE_GATE)
            ->whereBetween('closed_at', [$start, $end])
            ->count();
    }
}
