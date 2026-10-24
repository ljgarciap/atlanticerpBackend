<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Models\User;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\ReportSettings;
use App\Modules\VentasDiseno\Models\SalesGoal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Pantalla Reportes (REQ-071 a 079), Grupo 4. Ver
 * memory/project_backlog_ventas_diseno_req.md para el desglose Reportes-A/B/C/D.
 */
final class ReportsService
{
    /** Historial del panel Metas — siempre 6 meses fijos (REQ-072), independiente del período. */
    private const META_HISTORY_MONTHS = 6;

    /** Mejores clientes — cuántos clientes mostrar en el ranking (REQ-074). */
    private const BEST_CLIENTS_LIMIT = 5;

    public function __construct(private readonly PipelineService $pipeline, private readonly HomeService $home) {}

    /**
     * REQ-071: alcance (own/team, mismo gate que Pipeline/Home — solo Líder/Gerencia
     * puede pedir "team") y período (mes/trimestre/año, siempre el período EN CURSO,
     * no un selector de fecha libre — así lo pide el mock).
     *
     * @param array{scope?: string, period?: string, owner_id?: int, owner_ids?: list<int>} $filters
     * @return array<string, mixed>
     */
    public function summary(User $user, array $filters = []): array
    {
        $scope     = $this->resolveScope($user, $filters);
        $period    = $filters['period'] ?? 'month';
        // SCRUM-854 — multi-selección; owner_ids gana sobre owner_id singular (retrocompat).
        $ownerIds  = ! empty($filters['owner_ids'])
            ? $filters['owner_ids']
            : (isset($filters['owner_id']) ? [(int) $filters['owner_id']] : null);

        $allApproved    = $this->approvedCards($user, $scope, $ownerIds);
        $periodApproved = $this->filterByPeriod($allApproved, $period);

        $result = [
            'scope'        => $scope,
            'period'       => $period,
            'surface_m2'   => round((float) $periodApproved->sum('worked_area_m2'), 2),
            'best_clients' => $this->bestClients($periodApproved),
            'meta'         => $this->metaPanel($user, $scope, $ownerIds),
        ];

        // REQ-075/076: exclusivos de alcance Equipo — no se muestran en Resumen personal.
        if ($scope === 'team') {
            $result['top_vendors'] = $this->topVendors($user);
            $result['forecast']    = $this->forecast($user, $period);
        }

        return $result;
    }

    /**
     * @param array{scope?: string} $filters
     */
    public function resolveScope(User $user, array $filters): string
    {
        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        $canSeeTeam = $user->canViewTeamV2('ventas_diseno');

        return ($filters['scope'] ?? 'own') === 'team' && $canSeeTeam ? 'team' : 'own';
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    public function periodRange(string $period): array
    {
        $now = CarbonImmutable::now();

        return match ($period) {
            'quarter' => [$now->firstOfQuarter(), $now],
            'year'    => [$now->startOfYear(), $now],
            default   => [$now->startOfMonth(), $now],
        };
    }

    /**
     * REQ-073/076: multiplicador de meta mensual configurada (SCRUM-146, siempre
     * mensual) al período seleccionado — trimestre = x3, año = x12.
     */
    public function periodMonthsMultiplier(string $period): int
    {
        return match ($period) {
            'quarter' => 3,
            'year'    => 12,
            default   => 1,
        };
    }

    /**
     * Todas las tarjetas Aprobado del alcance, sin acotar por período — la usan
     * tanto approvedCardsInPeriod() (Superficie/Mejores clientes) como el
     * histórico de 6 meses del panel Metas, que necesita ver más atrás del
     * período en curso.
     *
     * @param list<int>|null $ownerIds
     * @return Collection<int, PipelineCard>
     */
    public function approvedCards(User $user, string $scope, ?array $ownerIds = null): Collection
    {
        $cards = $this->pipeline->list($user, array_filter([
            'scope'     => $scope,
            'owner_ids' => $ownerIds,
        ], fn (mixed $v): bool => $v !== null));

        return $cards->where('stage', 'approved')->values();
    }

    /**
     * @param Collection<int, PipelineCard> $cards
     * @return Collection<int, PipelineCard>
     */
    public function filterByPeriod(Collection $cards, string $period): Collection
    {
        [$start, $end] = $this->periodRange($period);

        return $cards->filter(fn (PipelineCard $c) => $c->stage_changed_at->between($start, $end))->values();
    }

    /**
     * REQ-074: ranking de clientes por $ Aprobado del período, top 5.
     *
     * SCRUM-722: agrupar por client_id (sub_client_id, o master_client_id si no hay
     * subcliente), nunca por el nombre — dos clientes distintos pueden compartir
     * display name (business_name/name no son únicos), lo que antes fusionaba sus
     * montos en una sola fila del ranking.
     *
     * @param Collection<int, PipelineCard> $periodApproved
     * @return list<array{client_name: string, client_id: int|null, client_type: string|null, amount: float}>
     */
    private function bestClients(Collection $periodApproved): array
    {
        return array_values($periodApproved
            ->groupBy(fn (PipelineCard $c) => $c->sub_client_id !== null
                ? "sub:{$c->sub_client_id}"
                : ($c->master_client_id !== null ? "master:{$c->master_client_id}" : 'none'))
            ->map(function (Collection $group): array {
                /** @var PipelineCard $first */
                $first      = $group->first();
                $clientId   = $first->sub_client_id ?? $first->master_client_id;
                $clientType = match (true) {
                    $first->sub_client_id !== null    => 'sub',
                    $first->master_client_id !== null => 'master',
                    default                            => null,
                };

                return [
                    'client_name' => $first->subClient->business_name ?? $first->masterClient->name ?? 'Sin cliente',
                    'client_id'   => $clientId,
                    'client_type' => $clientType,
                    'amount'      => (float) $group->sum('amount'),
                ];
            })
            ->sortByDesc('amount')
            ->take(self::BEST_CLIENTS_LIMIT)
            ->values()
            ->all());
    }

    /**
     * REQ-072/SCRUM-146: historial real de los últimos 6 meses (aprobado por
     * mes) + la línea de meta configurada (no un promedio) — para alcance
     * Equipo, se suman todos los vendedores del equipo y la meta también es la
     * suma de sus Metas individuales (mismo criterio que usa el propio mock
     * para "meta de equipo").
     *
     * @param list<int>|null $ownerIds
     * @return array{monthly_history: list<array{month: string, amount: float}>, goal_amount: float|null}
     */
    private function metaPanel(User $user, string $scope, ?array $ownerIds): array
    {
        $allApproved = $this->approvedCards($user, $scope, $ownerIds);
        $now         = CarbonImmutable::now();

        $history = [];
        for ($i = self::META_HISTORY_MONTHS - 1; $i >= 0; $i--) {
            $monthStart = $now->subMonths($i)->startOfMonth();
            $monthEnd   = $monthStart->endOfMonth();

            $amount = $allApproved
                ->filter(fn (PipelineCard $c) => $c->stage_changed_at->between($monthStart, $monthEnd))
                ->sum('amount');

            $history[] = ['month' => $monthStart->format('Y-m'), 'amount' => round((float) $amount, 2)];
        }

        $goalQuery = SalesGoal::query();
        if (! empty($ownerIds)) {
            // SCRUM-854 — multi-selección: meta = suma de las metas individuales de cada
            // seleccionado, mismo criterio ya usado acá para "meta de equipo" sin filtro.
            $goalQuery->whereIn('user_id', $ownerIds);
        } elseif ($scope === 'own') {
            $goalQuery->where('user_id', $user->id);
        } else {
            $goalQuery->whereIn('user_id', $this->home->vendors()->pluck('id'));
        }
        $goalAmount = $goalQuery->sum('goal_amount');

        return [
            'monthly_history' => $history,
            'goal_amount'     => $goalQuery->exists() ? round((float) $goalAmount, 2) : null,
        ];
    }

    /**
     * REQ-075: desempeño de cada vendedor + alerta si lleva N meses consecutivos
     * (configurable, ReportSettings::low_sales_months) por debajo del umbral
     * (ReportSettings::low_sales_threshold). Solo tiene sentido en alcance Equipo.
     *
     * @return list<array{user_id: int, user_name: string, total_current_month: float, low_sales_alert: bool}>
     */
    private function topVendors(User $user): array
    {
        $settings = ReportSettings::current();
        $vendors  = $this->home->vendors();

        $rows = $vendors->map(function (array $vendor) use ($user, $settings) {
            $cards = $this->approvedCards($user, 'team', [$vendor['id']]);
            $now   = CarbonImmutable::now();

            $monthlyTotals = [];
            for ($i = 0; $i < $settings->low_sales_months; $i++) {
                $monthStart = $now->subMonths($i)->startOfMonth();
                $monthEnd   = $monthStart->endOfMonth();
                $monthlyTotals[] = (float) $cards
                    ->filter(fn (PipelineCard $c) => $c->stage_changed_at->between($monthStart, $monthEnd))
                    ->sum('amount');
            }

            $lowSalesAlert = count($monthlyTotals) === $settings->low_sales_months
                && collect($monthlyTotals)->every(fn (float $total) => $total < $settings->low_sales_threshold);

            return [
                'user_id'             => $vendor['id'],
                'user_name'           => $vendor['name'],
                'total_current_month' => round($monthlyTotals[0] ?? 0.0, 2),
                'low_sales_alert'     => $lowSalesAlert,
            ];
        });

        return array_values($rows->sortByDesc('total_current_month')->all());
    }

    /**
     * REQ-076: panel Forecast completo (SCRUM-113), siguiendo el mockup del
     * cliente (`1C__Ventas_Disen_o_Reportes.html`, sección #forecastPanel) — 3
     * cifras (Mejor caso/Ponderado/Comprometido) sobre lo abierto en
     * Cotización/Propuesta, más "Ya cerrado" real vs. Meta de equipo (run-rate,
     * REQ-072/SCRUM-146) y el detalle por etapa. Solo alcance Equipo.
     *
     * "Ponderado" acá es SOLO el pipeline abierto ajustado por probabilidad (no
     * incluye lo ya cerrado) — distinto del campo `weighted` que tenía la
     * versión mínima anterior de este panel (que sí sumaba `closed`). "Falta
     * para la meta" se calcula contra `closed` únicamente (no contra el pipeline
     * proyectado) — el mockup separa "lo que falta de verdad" (barra 1, real) de
     * "lo que cubriría el pipeline si cierra" (barra 2 + nota), en vez de
     * mezclarlos en un solo indicador verde/rojo.
     *
     * @return array{
     *   closed: float, best_case: float, weighted_pipeline: float, committed: float,
     *   meta_amount: float|null, remaining: float|null, meta_reached: bool|null,
     *   pct_closed: float|null, pct_projected: float|null, pipeline_covers_gap: bool|null,
     *   quote_probability_percent: float, proposal_probability_percent: float,
     *   stage_breakdown: list<array{stage: string, projects: int, open_amount: float, probability_percent: float, weighted_amount: float}>,
     * }
     */
    private function forecast(User $user, string $period): array
    {
        $settings = ReportSettings::current();

        $allTeamCards = $this->pipeline->list($user, ['scope' => 'team']);
        $closed       = (float) $this->filterByPeriod($allTeamCards->where('stage', 'approved'), $period)->sum('amount');

        $probabilities = [
            'quote'    => $settings->quote_probability_percent,
            'proposal' => $settings->proposal_probability_percent,
        ];

        $stageBreakdown   = [];
        $bestCase         = 0.0;
        $weightedPipeline = 0.0;
        $committed        = 0.0;

        foreach ($probabilities as $stage => $probabilityPercent) {
            $cards      = $allTeamCards->where('stage', $stage);
            $openAmount = (float) $cards->sum('amount');
            $weightedAmount = round($openAmount * $probabilityPercent / 100, 2);

            $bestCase += $openAmount;
            $weightedPipeline += $weightedAmount;
            if ($stage === 'proposal') {
                $committed = $openAmount;
            }

            $stageBreakdown[] = [
                'stage'               => $stage,
                'projects'            => $cards->count(),
                'open_amount'         => round($openAmount, 2),
                'probability_percent' => $probabilityPercent,
                'weighted_amount'     => $weightedAmount,
            ];
        }

        $goalSum    = (float) SalesGoal::whereIn('user_id', $this->home->vendors()->pluck('id'))->sum('goal_amount');
        $metaAmount = $goalSum > 0 ? round($goalSum * $this->periodMonthsMultiplier($period), 2) : null;
        $remaining  = $metaAmount !== null ? round(max(0.0, $metaAmount - $closed), 2) : null;

        return [
            'closed'                        => round($closed, 2),
            'best_case'                     => round($bestCase, 2),
            'weighted_pipeline'             => round($weightedPipeline, 2),
            'committed'                     => round($committed, 2),
            'meta_amount'                   => $metaAmount,
            'remaining'                     => $remaining,
            'meta_reached'                  => $metaAmount !== null ? $closed >= $metaAmount : null,
            'pct_closed'                    => $metaAmount !== null ? round($closed / $metaAmount * 100) : null,
            'pct_projected'                 => $metaAmount !== null ? round(($closed + $weightedPipeline) / $metaAmount * 100) : null,
            'pipeline_covers_gap'           => $remaining !== null ? ($remaining === 0.0 || $weightedPipeline >= $remaining) : null,
            'quote_probability_percent'     => $settings->quote_probability_percent,
            'proposal_probability_percent'  => $settings->proposal_probability_percent,
            'stage_breakdown'               => $stageBreakdown,
        ];
    }
}
