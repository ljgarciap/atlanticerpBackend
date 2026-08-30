<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Models\ProjectReminder;
use Illuminate\Database\Eloquent\Builder;

final class DashboardService
{
    private const OPEN_STAGES   = ['lead', 'diseno', 'cotizacion', 'propuesta'];
    private const CLOSED_STAGES = ['cerrado'];

    /** @return array<string, mixed> */
    public function kpis(User $user, string $activeRole): array
    {
        $base = $this->baseQuery($user, $activeRole);

        // Stage counts — initialise all stages to 0, then fill from DB
        $counts = array_fill_keys(
            ['lead', 'diseno', 'cotizacion', 'propuesta', 'cerrado', 'perdido'],
            0
        );

        $stagePluck = (clone $base)
            ->selectRaw('etapa, COUNT(*) as total')
            ->groupBy('etapa')
            ->pluck('total', 'etapa');

        foreach ($stagePluck as $stage => $count) {
            $counts[(string) $stage] = (int) $count;
        }

        // Tipo counts
        $tipoPluck = (clone $base)
            ->selectRaw('tipo, COUNT(*) as total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        $countsByTipo = [
            'diseno'     => (int) ($tipoPluck->get('diseno')     ?? 0),
            'cotizacion' => (int) ($tipoPluck->get('cotizacion') ?? 0),
            'ambos'      => (int) ($tipoPluck->get('ambos')      ?? 0),
        ];

        // Pipeline values — sum() always returns a numeric string/int/float, never null
        $pipelineActivo  = (float) (clone $base)->whereIn('etapa', self::OPEN_STAGES)->sum('valor');
        $pipelineCerrado = (float) (clone $base)->whereIn('etapa', self::CLOSED_STAGES)->sum('valor');

        // Alerts — SCRUM-14: QA 2026-07-05 encontró que el dashboard solo mostraba el conteo,
        // sin la lista de proyectos afectados ni el botón "Enviar recordatorios" del mock. El
        // botón del mock es 100% client-side (arma un mailto: con el resumen) — por eso acá
        // solo se expone el detalle necesario para componerlo en el frontend, sin un endpoint
        // de envío nuevo.
        $openBase       = (clone $base)->whereIn('etapa', self::OPEN_STAGES);
        $vencidosQuery  = (clone $openBase)
            ->whereNotNull('fecha_entrega')
            ->whereDate('fecha_entrega', '<', now());

        $proximosQuery = (clone $openBase)
            ->whereNotNull('fecha_entrega')
            ->whereDate('fecha_entrega', '>=', now())
            ->whereDate('fecha_entrega', '<=', now()->addDays(3));

        $friosQuery = (clone $openBase)
            ->whereDoesntHave('activities', fn (Builder $q) => $q->where('fecha', '>=', now()->subDays(21)));

        $vencidosProjects = (clone $vencidosQuery)->with('assignees:id,first_name,last_name,email')
            ->get(['id', 'nombre', 'contacto', 'etapa', 'fecha_entrega']);
        $proximosProjects = (clone $proximosQuery)->with('assignees:id,first_name,last_name,email')
            ->get(['id', 'nombre', 'contacto', 'etapa', 'fecha_entrega']);
        $friosProjects    = (clone $friosQuery)->get(['id', 'nombre']);

        $managementEmails = User::whereHas('userRoles', fn (Builder $q) => $q->where('role', User::ROLE_MANAGEMENT))
            ->whereNotNull('email')
            ->pluck('email')
            ->all();

        return [
            'counts_by_stage'          => $counts,
            'counts_by_tipo'           => $countsByTipo,
            'pipeline_activo'          => $pipelineActivo,
            'pipeline_cerrado'         => $pipelineCerrado,
            'alerts_vencidos'          => $vencidosProjects->count(),
            'alerts_proximos'          => $proximosProjects->count(),
            'alerts_frios'             => $friosProjects->count(),
            'alerts_vencidos_projects' => $vencidosProjects->map(fn (Project $p) => $this->alertProjectPayload($p))->values()->all(),
            'alerts_proximos_projects' => $proximosProjects->map(fn (Project $p) => $this->alertProjectPayload($p))->values()->all(),
            'alerts_frios_projects'    => $friosProjects->map(fn (Project $p) => ['id' => $p->id, 'nombre' => $p->nombre])->values()->all(),
            'management_emails'       => $managementEmails,
        ];
    }

    /** @return array<string, mixed> */
    private function alertProjectPayload(Project $p): array
    {
        $today = now()->startOfDay();
        $due   = $p->fecha_entrega?->copy()->startOfDay();

        return [
            'id'              => $p->id,
            'nombre'          => $p->nombre,
            'contacto'        => $p->contacto,
            'etapa'           => $p->etapa,
            'fecha_entrega'   => $due?->toDateString(),
            // Positivo = días restantes, negativo = días de atraso — mismo criterio que
            // `daysUntil()` en el mock de referencia.
            'dias'       => $due !== null ? (int) round(($due->getTimestamp() - $today->getTimestamp()) / 86400) : null,
            'assignees'  => $p->assignees->map(fn (User $u) => [
                'name'  => trim("{$u->first_name} {$u->last_name}"),
                'email' => $u->email,
            ])->values()->all(),
        ];
    }

    /**
     * Pending reminders (completado=false) for the user's visible projects, ordered by date.
     *
     * @return array<int, array<string, mixed>>
     */
    public function userReminders(User $user, string $activeRole): array
    {
        $projectIds = $this->baseQuery($user, $activeRole)->pluck('id');

        $reminders = ProjectReminder::whereIn('project_id', $projectIds)
            ->where('completado', false)
            ->with('project')
            ->orderBy('fecha')
            ->limit(20)
            ->get();

        return $reminders->map(function (ProjectReminder $r): array {
            $project = $r->project;
            return [
                'id'            => $r->id,
                'project_id'    => $r->project_id,
                'project_name'  => $project->nombre,
                'project_etapa' => $project->etapa,
                'tipo'          => $r->tipo,
                'fecha'         => $r->fecha->toDateString(),
                'nota'          => $r->nota,
            ];
        })->values()->all();
    }

    /** @return Builder<Project> */
    private function baseQuery(User $user, string $activeRole): Builder
    {
        $query = Project::query();

        $isManagement = in_array($activeRole, [User::ROLE_SUPERADMIN, User::ROLE_MANAGEMENT], true);

        if (! $isManagement) {
            $query->whereHas('assignees', fn (Builder $q) => $q->where('user_id', $user->id));
        }

        return $query;
    }
}
