<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Models\Project;
use Illuminate\Database\Eloquent\Builder;

final class DirectoryService
{
    private const ALLOWED_TYPES = ['developer', 'arquitecto', 'encargado', 'contacto'];

    /** @return string[] */
    public static function allowedTypes(): array
    {
        return self::ALLOWED_TYPES;
    }

    /**
     * @return array{
     *   stats: array{total_entries: int, total_projects: int, total_valor: float, total_cerrado: float},
     *   entries: list<array{
     *     nombre: string,
     *     project_count: int,
     *     areas: list<string>,
     *     ubicaciones: list<string>,
     *     valor_total: float,
     *     valor_cerrado: float,
     *     emails: list<string>,
     *     telefonos: list<string>,
     *     ultima_actividad: string|null
     *   }>
     * }
     */
    public function get(User $user, string $activeRole, string $type, string $search = ''): array
    {
        $projects = $this->visibleProjects($user, $activeRole)
            ->with('latestActivity')
            ->get();

        $map = [];

        foreach ($projects as $project) {
            $key = trim((string) ($project->$type ?? ''));
            if ($key === '') {
                continue;
            }

            if (! isset($map[$key])) {
                $map[$key] = [
                    'nombre'           => $key,
                    'projects'         => [],
                    'areas'            => [],        // keyed set: ['value' => true]
                    'ubicaciones'      => [],        // keyed set
                    'valor_total'      => 0.0,
                    'valor_cerrado'    => 0.0,
                    'emails'           => [],        // keyed set
                    'telefonos'        => [],        // keyed set
                    'ultima_actividad' => null,
                ];
            }

            $map[$key]['projects'][] = $project->id;

            if (! empty($project->area)) {
                $map[$key]['areas'][(string) $project->area] = true;
            }

            if (! empty($project->ubicacion)) {
                $map[$key]['ubicaciones'][(string) $project->ubicacion] = true;
            }

            $map[$key]['valor_total'] += (float) ($project->valor ?? 0);
            if ($project->etapa === 'cerrado') {
                $map[$key]['valor_cerrado'] += (float) ($project->valor ?? 0);
            }

            if (! empty($project->email)) {
                $map[$key]['emails'][(string) $project->email] = true;
            }

            if (! empty($project->celular)) {
                $map[$key]['telefonos'][(string) $project->celular] = true;
            }

            // ultima_actividad = max(latest activity fecha, project updated_at)
            $latestTs = null;
            if ($project->latestActivity !== null) {
                $latestTs = $project->latestActivity->fecha instanceof \Carbon\Carbon
                    ? $project->latestActivity->fecha->toDateString()
                    : (string) $project->latestActivity->fecha;
            }
            $updatedTs = $project->updated_at->toDateString();

            $candidate = $latestTs ?? $updatedTs;
            if ($candidate !== null) {
                if ($map[$key]['ultima_actividad'] === null || $candidate > $map[$key]['ultima_actividad']) {
                    $map[$key]['ultima_actividad'] = $candidate;
                }
            }
        }

        // Apply search filter
        if ($search !== '') {
            $searchNorm = mb_strtolower($search);
            $map = array_filter(
                $map,
                fn (array $e) => str_contains(mb_strtolower($e['nombre']), $searchNorm)
            );
        }

        // Sort: project_count desc, then nombre asc
        uasort($map, function (array $a, array $b): int {
            $countDiff = count($b['projects']) - count($a['projects']);
            if ($countDiff !== 0) {
                return $countDiff;
            }
            return strcmp($a['nombre'], $b['nombre']);
        });

        // Build output entries (areas/ubicaciones/emails/telefonos were keyed sets)
        $entries = array_values(array_map(fn (array $e) => [
            'nombre'           => $e['nombre'],
            'project_count'    => count($e['projects']),
            'areas'            => array_map('strval', array_keys($e['areas'])),
            'ubicaciones'      => array_map('strval', array_keys($e['ubicaciones'])),
            'valor_total'      => $e['valor_total'],
            'valor_cerrado'    => $e['valor_cerrado'],
            'emails'           => array_map('strval', array_keys($e['emails'])),
            'telefonos'        => array_map('strval', array_keys($e['telefonos'])),
            'ultima_actividad' => $e['ultima_actividad'],
        ], $map));

        $stats = [
            'total_entries'  => count($entries),
            'total_projects' => array_sum(array_map(fn (array $e) => $e['project_count'], $entries)),
            'total_valor'    => array_sum(array_map(fn (array $e) => $e['valor_total'], $entries)),
            'total_cerrado'  => array_sum(array_map(fn (array $e) => $e['valor_cerrado'], $entries)),
        ];

        return ['stats' => $stats, 'entries' => $entries];
    }

    /** @return Builder<Project> */
    private function visibleProjects(User $user, string $activeRole): Builder
    {
        $query = Project::query();

        $isManagement = in_array($activeRole, [User::ROLE_SUPERADMIN, User::ROLE_MANAGEMENT], true);

        if (! $isManagement) {
            $query->whereHas('assignees', fn (Builder $q) => $q->where('user_id', $user->id));
        }

        return $query;
    }
}
