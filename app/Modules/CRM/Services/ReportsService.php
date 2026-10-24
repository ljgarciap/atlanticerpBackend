<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Models\ProjectActivity;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class ReportsService
{
    private const OPEN_STAGES = ['lead', 'diseno', 'cotizacion', 'propuesta'];

    private const ACTIVITY_TYPES = ['llamada', 'email', 'reunion', 'whatsapp', 'visita', 'otro'];

    /**
     * @param  list<int>|null  $filterUserIds  SCRUM-854 — multi-selección (OR entre seleccionados).
     *                                         null/vacío = todo el equipo (solo tiene efecto real para management, igual que antes).
     * @return array<string, mixed>
     */
    public function monthly(User $currentUser, bool $isManagement, string $month, ?array $filterUserIds): array
    {
        $parts = explode('-', $month, 2);
        $year = (int) ($parts[0] ?? 0);
        $mon = (int) ($parts[1] ?? 0);

        $start = Carbon::createFromDate($year, $mon, 1)->startOfDay();
        $end = $start->copy()->endOfMonth()->endOfDay();

        // Resolve display title
        $filterUsers = [];
        if ($isManagement && ! empty($filterUserIds)) {
            /** @var Collection<int, User> $filterUsers */
            $filterUsers = User::whereIn('id', $filterUserIds)->orderBy('first_name')->get();
        } elseif (! $isManagement) {
            $filterUsers = collect([$currentUser]);
        }
        $userTitle = count($filterUsers) > 0
            ? collect($filterUsers)->map(fn (User $u) => "{$u->first_name} {$u->last_name}")->implode(', ')
            : trans('crm.all_team');

        // Effective userIds for project / activity scoping
        $effectiveUserIds = $isManagement ? ($filterUserIds ?: null) : [$currentUser->id];

        $baseQuery = $this->projectQuery($currentUser, $isManagement, $effectiveUserIds);

        /** @var Collection<int, Project> $nuevos */
        $nuevos = (clone $baseQuery)->whereBetween('created_at', [$start, $end])->with('assignees')->get();

        /** @var Collection<int, Project> $cerrados */
        $cerrados = (clone $baseQuery)->where('etapa', 'cerrado')->whereBetween('updated_at', [$start, $end])->get();

        /** @var Collection<int, Project> $perdidos */
        $perdidos = (clone $baseQuery)->where('etapa', 'perdido')->whereBetween('updated_at', [$start, $end])->get();

        $totalDecisions = $cerrados->count() + $perdidos->count();
        $tasaCierre = $totalDecisions > 0
            ? (int) round($cerrados->count() / $totalDecisions * 100)
            : 0;

        $visibleIds = (clone $baseQuery)->pluck('id');

        $activitiesQuery = ProjectActivity::whereIn('project_id', $visibleIds)
            ->whereBetween('fecha', [$start, $end])
            ->with(['user', 'project']);

        if ($effectiveUserIds !== null) {
            $activitiesQuery->whereIn('user_id', $effectiveUserIds);
        }

        /** @var Collection<int, ProjectActivity> $activities */
        $activities = $activitiesQuery->orderByDesc('fecha')->get();

        /** @var array<string, int> $actByType */
        $actByType = array_fill_keys(self::ACTIVITY_TYPES, 0);
        foreach ($activities as $act) {
            $tipo = (string) $act->tipo;
            if (isset($actByType[$tipo])) {
                $actByType[$tipo]++;
            }
        }

        $teamBreakdown = [];
        if ($isManagement && empty($filterUserIds)) {
            $teamBreakdown = $this->teamMonthlyBreakdown($start, $end);
        }

        return [
            'month' => $month,
            'month_label' => $this->formatMonthLabel($start),
            'user_title' => $userTitle,
            'kpis' => [
                'leads_nuevos' => $nuevos->count(),
                'valor_nuevos' => (float) $nuevos->sum('valor'),
                'cerrados' => $cerrados->count(),
                'valor_cerrado' => (float) $cerrados->sum('valor'),
                'perdidos' => $perdidos->count(),
                'valor_perdido' => (float) $perdidos->sum('valor'),
                'tasa_cierre' => $tasaCierre,
                'actividades' => $activities->count(),
                'documentos' => 0,
            ],
            'actividades_por_tipo' => $actByType,
            'team_breakdown' => $teamBreakdown,
            'proyectos_nuevos' => $this->formatNuevos($nuevos),
            'bitacora' => $this->formatBitacora($activities),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function team(): array
    {
        $tenant = Tenant::current();
        $pivotTable = $tenant !== null ? "{$tenant->slug}_crm.project_assignees" : 'project_assignees';

        $userIds = DB::table($pivotTable)->distinct()->pluck('user_id');

        /** @var Collection<int, User> $users */
        $users = User::whereIn('id', $userIds)->orderBy('first_name')->orderBy('last_name')->get();

        return $users->map(function (User $u): array {
            /** @var Collection<int, Project> $userProjects */
            $userProjects = Project::query()
                ->whereHas('assignees', fn (Builder $q) => $q->where('user_id', $u->id))
                ->get();

            /** @var array<string, int> $counts */
            $counts = array_fill_keys(['lead', 'diseno', 'cotizacion', 'propuesta', 'cerrado', 'perdido'], 0);
            foreach ($userProjects as $p) {
                $etapa = (string) $p->etapa;
                if (isset($counts[$etapa])) {
                    $counts[$etapa]++;
                }
            }

            $valorActivo = (float) $userProjects
                ->filter(fn (Project $p): bool => in_array((string) $p->etapa, self::OPEN_STAGES, true))
                ->sum('valor');

            $valorCerrado = (float) $userProjects
                ->filter(fn (Project $p): bool => $p->etapa === 'cerrado')
                ->sum('valor');

            $vencidos = $userProjects
                ->filter(fn (Project $p): bool => in_array((string) $p->etapa, self::OPEN_STAGES, true)
                    && $p->fecha_entrega !== null
                    && $p->fecha_entrega->isPast()
                )
                ->count();

            return [
                'user_id' => $u->id,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'email' => $u->email,
                'counts' => $counts,
                'valor_activo' => $valorActivo,
                'valor_cerrado' => $valorCerrado,
                'vencidos' => $vencidos,
            ];
        })->values()->all();
    }

    /**
     * @param  list<int>|null  $filterUserIds
     * @return Builder<Project>
     */
    private function projectQuery(User $user, bool $isManagement, ?array $filterUserIds): Builder
    {
        $query = Project::query();

        if (! $isManagement) {
            $query->whereHas('assignees', fn (Builder $q) => $q->where('user_id', $user->id));
        } elseif (! empty($filterUserIds)) {
            // SCRUM-854 — multi-selección (OR entre seleccionados).
            $query->whereHas('assignees', fn (Builder $q) => $q->whereIn('user_id', $filterUserIds));
        }

        return $query;
    }

    /**
     * @param  Collection<int, Project>  $projects
     * @return array<int, array<string, mixed>>
     */
    private function formatNuevos(Collection $projects): array
    {
        return $projects
            ->sortByDesc('created_at')
            ->map(function (Project $p): array {
                return [
                    'id' => $p->id,
                    'created_at' => $p->created_at->toDateString(),
                    'nombre' => $p->nombre,
                    'contacto' => $p->contacto,
                    'tipo' => $p->tipo,
                    'ubicacion' => $p->ubicacion,
                    'etapa' => $p->etapa,
                    'valor' => (float) ($p->valor ?? 0),
                    'assignees' => $p->assignees->map(fn (User $u) => [
                        'id' => $u->id,
                        'first_name' => $u->first_name,
                        'last_name' => $u->last_name,
                    ])->values()->all(),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, ProjectActivity>  $activities
     * @return array<int, array<string, mixed>>
     */
    private function formatBitacora(Collection $activities): array
    {
        return $activities->map(function (ProjectActivity $a): array {
            $actUser = $a->user;
            $actProject = $a->project;

            return [
                'id' => $a->id,
                'fecha' => $a->fecha->toDateString(),
                'tipo' => $a->tipo,
                'user_id' => $a->user_id,
                'user_name' => $actUser !== null ? $actUser->first_name : '',
                'project_id' => $a->project_id,
                'project_nombre' => $actProject !== null ? $actProject->nombre : '',
                'project_contacto' => $actProject !== null ? $actProject->contacto : null,
                'notas' => $a->notas,
            ];
        })->values()->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function teamMonthlyBreakdown(Carbon $start, Carbon $end): array
    {
        $tenant = Tenant::current();
        $pivotTable = $tenant !== null ? "{$tenant->slug}_crm.project_assignees" : 'project_assignees';
        $userIds = DB::table($pivotTable)->distinct()->pluck('user_id');

        /** @var Collection<int, User> $users */
        $users = User::whereIn('id', $userIds)->orderBy('first_name')->orderBy('last_name')->get();

        return $users->map(function (User $u) use ($start, $end): array {
            $nuevosCount = Project::query()
                ->whereHas('assignees', fn (Builder $q) => $q->where('user_id', $u->id))
                ->whereBetween('created_at', [$start, $end])
                ->count();

            $valorNuevos = (float) Project::query()
                ->whereHas('assignees', fn (Builder $q) => $q->where('user_id', $u->id))
                ->whereBetween('created_at', [$start, $end])
                ->sum('valor');

            $cerradosBuilder = Project::query()
                ->whereHas('assignees', fn (Builder $q) => $q->where('user_id', $u->id))
                ->where('etapa', 'cerrado')
                ->whereBetween('updated_at', [$start, $end]);
            $cerradosCount = $cerradosBuilder->count();
            $valorCerrado = (float) $cerradosBuilder->sum('valor');

            $perdidosCount = Project::query()
                ->whereHas('assignees', fn (Builder $q) => $q->where('user_id', $u->id))
                ->where('etapa', 'perdido')
                ->whereBetween('updated_at', [$start, $end])
                ->count();

            $totalDecisions = $cerradosCount + $perdidosCount;
            $tasa = $totalDecisions > 0 ? (int) round($cerradosCount / $totalDecisions * 100) : 0;

            $assignedProjectIds = Project::query()
                ->whereHas('assignees', fn (Builder $q) => $q->where('user_id', $u->id))
                ->pluck('id');

            $actividadesCount = ProjectActivity::whereIn('project_id', $assignedProjectIds)
                ->where('user_id', $u->id)
                ->whereBetween('fecha', [$start, $end])
                ->count();

            return [
                'user_id' => $u->id,
                'first_name' => $u->first_name,
                'last_name' => $u->last_name,
                'leads_nuevos' => $nuevosCount,
                'cerrados' => $cerradosCount,
                'perdidos' => $perdidosCount,
                'tasa_cierre' => $tasa,
                'actividades' => $actividadesCount,
                'documentos' => 0,
                'valor_cerrado' => $valorCerrado,
                'valor_nuevos' => $valorNuevos,
            ];
        })->values()->all();
    }

    private function formatMonthLabel(Carbon $date): string
    {
        /** @var array<int, string> $months */
        $months = trans('crm.months');

        return ($months[$date->month] ?? '').' '.$date->year;
    }
}
