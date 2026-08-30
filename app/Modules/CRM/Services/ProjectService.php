<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Events\ActivityAdded;
use App\Modules\CRM\Events\ProjectCreated;
use App\Modules\CRM\Events\ProjectStageChanged;
use App\Modules\CRM\Models\Project;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

final class ProjectService
{
    /**
     * @param array{
     *   search?: string,
     *   etapa?: string,
     *   urgency?: string,
     *   freshness?: string,
     *   area?: string,
     *   tipo?: string,
     *   despacho?: string,
     *   ubicacion?: string,
     *   user_id?: int,
     *   dialux?: bool,
     *   per_page?: int,
     * } $filters
     * @return LengthAwarePaginator<int, Project>
     */
    public function list(User $user, array $filters = []): LengthAwarePaginator
    {
        $query = Project::with(['assignees', 'latestActivity'])
            ->withCount('documents')
            ->orderBy('updated_at', 'desc');

        // Visibility: management/superadmin see all; others see only assigned projects
        if (! $user->hasRole(User::ROLE_MANAGEMENT) && ! $user->hasRole(User::ROLE_SUPERADMIN)) {
            $query->whereHas('assignees', fn ($q) => $q->where('user_id', $user->id));
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term): void {
                $q->where('nombre',    'ilike', "%{$term}%")
                  ->orWhere('contacto', 'ilike', "%{$term}%")
                  ->orWhere('encargado', 'ilike', "%{$term}%");
            });
        }

        if (! empty($filters['etapa'])) {
            $query->where('etapa', $filters['etapa']);
        }

        if (! empty($filters['tipo'])) {
            $query->where('tipo', $filters['tipo']);
        }

        if (! empty($filters['area'])) {
            $query->where('area', $filters['area']);
        }

        if (! empty($filters['despacho'])) {
            $query->where('despacho', $filters['despacho']);
        }

        if (! empty($filters['ubicacion'])) {
            $query->where('ubicacion', 'ilike', "%{$filters['ubicacion']}%");
        }

        if (! empty($filters['user_id'])) {
            $query->whereHas('assignees', fn ($q) => $q->where('user_id', (int) $filters['user_id']));
        }

        if (isset($filters['dialux'])) {
            $query->where('dialux', (bool) $filters['dialux']);
        }

        // Urgency filter — based on fecha_entrega
        if (! empty($filters['urgency'])) {
            match ($filters['urgency']) {
                'vencido' => $query->whereNotNull('fecha_entrega')
                                   ->whereDate('fecha_entrega', '<', now()),
                'proximo' => $query->whereNotNull('fecha_entrega')
                                   ->whereDate('fecha_entrega', '>=', now())
                                   ->whereDate('fecha_entrega', '<=', now()->addDays(3)),
                default   => null,
            };
        }

        // Freshness filter — based on last activity date, via EXISTS subqueries
        if (! empty($filters['freshness'])) {
            $cutRecent = now()->subDays(7);
            $cutStale  = now()->subDays(21);

            match ($filters['freshness']) {
                'recent' => $query->whereHas(
                    'activities',
                    fn ($q) => $q->where('fecha', '>=', $cutRecent)
                ),
                'stale' => $query
                    ->whereDoesntHave('activities', fn ($q) => $q->where('fecha', '>=', $cutRecent))
                    ->whereHas('activities',         fn ($q) => $q->where('fecha', '>=', $cutStale)),
                'cold' => $query->where(function ($q) use ($cutStale): void {
                    $q->doesntHave('activities')
                      ->orWhereDoesntHave('activities', fn ($q) => $q->where('fecha', '>=', $cutStale));
                }),
                default => null,
            };
        }

        return $query->paginate((int) ($filters['per_page'] ?? 20));
    }

    /**
     * @param array{
     *   nombre: string,
     *   tipo: string,
     *   etapa?: string,
     *   contacto?: string|null,
     *   email?: string|null,
     *   celular?: string|null,
     *   encargado?: string|null,
     *   developer?: string|null,
     *   arquitecto?: string|null,
     *   dialux?: bool,
     *   area?: string|null,
     *   despacho?: string|null,
     *   ubicacion?: string|null,
     *   fecha_entrega?: string|null,
     *   valor?: float|null,
     *   notas?: string|null,
     *   assignee_ids?: list<int>,
     * } $data
     */
    public function create(User $user, array $data): Project
    {
        return DB::transaction(function () use ($user, $data): Project {
            /** @var Project $project */
            $project = Project::create([
                'nombre'        => $data['nombre'],
                'tipo'          => $data['tipo'],
                'etapa'         => $data['etapa'] ?? 'lead',
                'contacto'      => $data['contacto'] ?? null,
                'email'         => $data['email'] ?? null,
                'celular'       => $data['celular'] ?? null,
                'encargado'     => $data['encargado'] ?? null,
                'developer'     => $data['developer'] ?? null,
                'arquitecto'    => $data['arquitecto'] ?? null,
                'dialux'        => $data['dialux'] ?? false,
                'area'          => $data['area'] ?? null,
                'despacho'      => $data['despacho'] ?? null,
                'ubicacion'     => $data['ubicacion'] ?? null,
                'fecha_entrega' => $data['fecha_entrega'] ?? null,
                'valor'         => $data['valor'] ?? null,
                'notas'         => $data['notas'] ?? null,
                'created_by'    => $user->id,
            ]);

            if (! empty($data['assignee_ids'])) {
                $project->assignees()->sync($data['assignee_ids']);
            }

            event(new ProjectCreated(
                projectId: $project->id,
                nombre:    $project->nombre,
                etapa:     $project->etapa,
                createdBy: $user->id,
                tenant:    $this->requiredTenantSlug(),
            ));

            $project->load(['assignees', 'latestActivity', 'reminders']);
            $project->loadCount('documents');

            return $project;
        });
    }

    /**
     * @param array{
     *   nombre?: string,
     *   tipo?: string,
     *   etapa?: string,
     *   contacto?: string|null,
     *   email?: string|null,
     *   celular?: string|null,
     *   encargado?: string|null,
     *   developer?: string|null,
     *   arquitecto?: string|null,
     *   dialux?: bool,
     *   area?: string|null,
     *   despacho?: string|null,
     *   ubicacion?: string|null,
     *   fecha_entrega?: string|null,
     *   valor?: float|null,
     *   notas?: string|null,
     *   assignee_ids?: list<int>,
     * } $data
     */
    public function update(Project $project, array $data): Project
    {
        return DB::transaction(function () use ($project, $data): Project {
            $fillable = [
                'nombre', 'tipo', 'etapa', 'contacto', 'email', 'celular',
                'encargado', 'developer', 'arquitecto', 'dialux', 'area',
                'despacho', 'ubicacion', 'fecha_entrega', 'valor', 'notas',
            ];

            foreach ($fillable as $field) {
                if (array_key_exists($field, $data)) {
                    $project->{$field} = $data[$field]; // @phpstan-ignore-line
                }
            }

            $project->save();

            if (array_key_exists('assignee_ids', $data)) {
                $project->assignees()->sync($data['assignee_ids']);
            }

            $project->load(['assignees', 'latestActivity', 'reminders']);
            $project->loadCount('documents');

            return $project;
        });
    }

    public function changeStage(Project $project, User $user, string $newEtapa): Project
    {
        $oldEtapa = $project->etapa;

        $project->etapa = $newEtapa;
        $project->save();

        event(new ProjectStageChanged(
            projectId: $project->id,
            nombre:    $project->nombre,
            oldEtapa:  $oldEtapa,
            newEtapa:  $newEtapa,
            changedBy: $user->id,
            tenant:    $this->requiredTenantSlug(),
        ));

        return $project;
    }

    public function delete(Project $project): void
    {
        $project->delete();
    }

    public function getUrgency(Project $project): string
    {
        if ($project->fecha_entrega === null) {
            return 'ok';
        }

        $days = (int) now()->startOfDay()->diffInDays($project->fecha_entrega, false);

        if ($days < 0) {
            return 'vencido';
        }

        if ($days <= 3) {
            return 'proximo';
        }

        return 'ok';
    }

    /**
     * Expects $project->latestActivity to be eager-loaded.
     * Returns 'recent' | 'stale' | 'cold'.
     */
    public function getFreshness(Project $project): string
    {
        $latest = $project->latestActivity;

        if ($latest === null) {
            return 'cold';
        }

        $days = (int) $latest->fecha->diffInDays(now());

        if ($days <= 7) {
            return 'recent';
        }

        if ($days <= 21) {
            return 'stale';
        }

        return 'cold';
    }

    /**
     * Dispatches ActivityAdded event. Called by ActivityController after persisting.
     */
    public function dispatchActivityAdded(int $activityId, Project $project, string $tipo, string $fecha, int $userId): void
    {
        event(new ActivityAdded(
            activityId: $activityId,
            projectId:  $project->id,
            tipo:       $tipo,
            fecha:      $fecha,
            userId:     $userId,
            tenant:     $this->requiredTenantSlug(),
        ));
    }

    private function requiredTenantSlug(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            throw new \RuntimeException('Cannot dispatch domain event without an active tenant.');
        }

        return $tenant->slug;
    }
}
