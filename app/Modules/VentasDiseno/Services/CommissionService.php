<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Models\User;
use App\Modules\VentasDiseno\Models\CommissionCohort;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Shared\Auth\JwtActor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * REQ-073 — Panel "Comisiones". La comisión se calcula agregada por mes y por
 * vendedor (no por proyecto individual), con una tabla escalonada 1.5%–5% sobre
 * el total mensual de pedidos Aprobados. El % queda FIJO al cerrar el mes — ver
 * cohortFor(). Ver memory/project_backlog_ventas_diseno_req.md para el detalle
 * de la decisión sobre "Pagadas/Por Pagar" (marcado manual, sin módulo de
 * facturación real) y el corte único en $80,000 (ambigüedad del propio Excel).
 */
final class CommissionService
{
    public function __construct(
        private readonly ReportsService $reports,
        private readonly SalesTotalsService $totals,
        private readonly HomeService $home,
    ) {}

    /**
     * Tabla escalonada REQ-073 — corte único en cada umbral (el Excel deja una
     * zona ambigua entre $80,000/$81,000 y recomienda usar un corte único salvo
     * indicación contraria del cliente).
     */
    public function percentFor(float $total): float
    {
        return $this->totals->percentFor($total);
    }

    /**
     * Cohorte de un vendedor para un mes dado. Si el mes ya "cerró" (es anterior
     * al mes en curso) y no existía, se calcula y se persiste — el % queda fijo
     * de ahí en más aunque cambien los datos subyacentes. El mes EN CURSO nunca
     * se persiste (todavía no cerró): se calcula en vivo cada vez, es una
     * vista previa, no un compromiso — ver REQ-073, "al cierre de cada mes".
     */
    public function cohortFor(User $vendor, CarbonImmutable $monthStart): CommissionCohort
    {
        $existing = CommissionCohort::where('owner_id', $vendor->id)
            ->whereDate('month', $monthStart->toDateString())
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $total   = $this->monthlyApprovedTotal($vendor, $monthStart);
        $percent = $this->percentFor($total);
        $amount  = round($total * $percent / 100, 2);

        $attributes = [
            'owner_id' => $vendor->id, 'month' => $monthStart->toDateString(),
            'total_amount' => $total, 'commission_percent' => $percent, 'commission_amount' => $amount,
        ];

        // Un mes sin ventas no deja cohorte real que "cerrar" — persistir un
        // stub en $0 solo ensuciaría la tabla (y la UI de "marcar como pagada")
        // sin ningún propósito, ya que summary() de todos modos lo descarta.
        $isClosedMonth = $amount > 0 && $monthStart->lt(CarbonImmutable::now()->startOfMonth());
        // SCRUM-1158 (Pre-QA MEDIO-2, mismo patrón que el snapshot de Admin&Cont) — con «Ver como usuario» se devuelve la cohorte calculada sin persistirla.
        if ($isClosedMonth && ! JwtActor::currentRequestIsImpersonating()) {
            return CommissionCohort::create($attributes);
        }

        return new CommissionCohort($attributes);
    }

    /**
     * REQ-073: resumen Pagadas/Por Pagar/Final Stage del alcance+período
     * seleccionado en Reportes.
     *
     * @return array{paid: float, pending: float, final_stage: float, cohorts: list<array<string, mixed>>}
     */
    public function summary(User $actor, string $scope, string $period, ?int $ownerId): array
    {
        $vendors = $this->vendorsInScope($actor, $scope, $ownerId);
        $months  = $this->monthsInPeriod($period);

        $paid = 0.0;
        $pending = 0.0;
        $finalStage = 0.0;
        $cohorts = [];

        foreach ($vendors as $vendor) {
            foreach ($months as $monthStart) {
                $cohort = $this->cohortFor($vendor, $monthStart);
                if ($cohort->commission_amount <= 0) {
                    continue;
                }

                if ($cohort->paid_at !== null) {
                    $paid += $cohort->commission_amount;
                } else {
                    $pending += $cohort->commission_amount;
                }

                $cohorts[] = [
                    'id'                  => $cohort->id,
                    'owner_id'            => $vendor->id,
                    'owner_name'          => trim("{$vendor->first_name} {$vendor->last_name}"),
                    'month'               => $monthStart->format('Y-m'),
                    'total_amount'        => $cohort->total_amount,
                    'commission_percent'  => $cohort->commission_percent,
                    'commission_amount'   => $cohort->commission_amount,
                    'paid_at'             => $cohort->paid_at?->toIso8601String(),
                ];
            }

            $finalStage += $this->finalStageEstimate($vendor);
        }

        return [
            'paid'        => round($paid, 2),
            'pending'     => round($pending, 2),
            'final_stage' => round($finalStage, 2),
            'cohorts'     => $cohorts,
        ];
    }

    /**
     * Marca una cohorte cerrada (ya persistida) como pagada. Una cohorte del mes
     * en curso nunca tiene id (no se persiste todavía) así que no se puede
     * marcar — coincide con el propio REQ-073 ("el pago ocurre después de que
     * el pedido se facture y cobre", siempre posterior al cierre del mes).
     */
    public function markPaid(int $cohortId): ?CommissionCohort
    {
        $cohort = CommissionCohort::find($cohortId);
        if ($cohort === null) {
            return null;
        }

        $cohort->update(['paid_at' => now()]);

        /** @var CommissionCohort $fresh */
        $fresh = $cohort->fresh();
        return $fresh;
    }

    public function monthlyApprovedTotal(User $vendor, CarbonImmutable $monthStart): float
    {
        return $this->totals->monthlyApprovedTotal($vendor, $monthStart);
    }

    /**
     * "Final Stage" (REQ-073, tercer segmento del mock): comisión incremental
     * que se sumaría este mes si los pedidos hoy en Propuesta también cerraran
     * — no es un cálculo definido explícitamente en el ticket (solo Pagadas/Por
     * Pagar tienen regla exacta), se documenta como estimación derivada: aplica
     * la misma tabla escalonada al total combinado (Aprobado del mes + en
     * Propuesta) y toma la diferencia contra la comisión ya contada del mes.
     */
    private function finalStageEstimate(User $vendor): float
    {
        $now = CarbonImmutable::now()->startOfMonth();
        $currentTotal = $this->monthlyApprovedTotal($vendor, $now);

        // SCRUM-849.4 — mismo criterio de reparto N-way que monthlyApprovedTotal(); si no se
        // hiciera acá, "Final Stage" sobrecontaría el monto completo de una tarjeta compartida
        // en cada uno de sus N asignados.
        $proposalTotal = (float) PipelineCard::where('stage', 'proposal')
            ->where(function ($q) use ($vendor): void {
                $q->where('owner_id', $vendor->id)
                    ->orWhereJsonContains('compartido_con', $vendor->id);
            })
            ->get(['amount', 'compartido_con'])
            ->sum(fn (PipelineCard $card): float => ((float) ($card->amount ?? 0)) / (1 + count($card->compartido_con ?? [])));

        if ($proposalTotal <= 0) {
            return 0.0;
        }

        $combinedTotal      = $currentTotal + $proposalTotal;
        $combinedCommission = round($combinedTotal * $this->percentFor($combinedTotal) / 100, 2);
        $currentCommission  = round($currentTotal * $this->percentFor($currentTotal) / 100, 2);

        return max(0.0, $combinedCommission - $currentCommission);
    }

    /**
     * SCRUM-712 (Pre-QA 2026-07-30) — devolvía vacío en alcance Equipo: resolvía el
     * roster vía el enum legado user_roles (role='designer'), que ninguno de los 10
     * vendedores reales tiene desde que BusinessRoleUserSeeder migró a roles de
     * negocio (ADR-006) — mismo hallazgo que SCRUM-146 en ReportsConfigController.
     * Ahora reusa HomeService::vendors() (role_id + RoleModuleVisibility), igual
     * que ReportsService::topVendors().
     *
     * @return Collection<int, User>
     */
    private function vendorsInScope(User $actor, string $scope, ?int $ownerId): Collection
    {
        if ($scope !== 'team') {
            $vendor = $ownerId !== null ? User::find($ownerId) : $actor;
            return $vendor !== null ? collect([$vendor]) : collect();
        }

        if ($ownerId !== null) {
            $vendor = User::find($ownerId);
            return $vendor !== null ? collect([$vendor]) : collect();
        }

        $vendorIds = $this->home->vendors()->pluck('id');
        return User::whereIn('id', $vendorIds)->get();
    }

    /** @return list<CarbonImmutable> */
    private function monthsInPeriod(string $period): array
    {
        [$start, $end] = $this->reports->periodRange($period);

        $months = [];
        $cursor = $start->startOfMonth();
        while ($cursor->lte($end)) {
            $months[] = $cursor;
            $cursor   = $cursor->addMonth();
        }

        return $months;
    }
}
