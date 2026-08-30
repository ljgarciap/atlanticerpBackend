<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Models\User;
use App\Modules\CRM\Models\Contact;
use App\Modules\CRM\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

final class ContactService
{
    /**
     * List contacts from the `contacts` table enriched with project stats.
     * Optionally filter by type and/or search string.
     *
     * @return array{
     *   stats: array{total: int, total_projects: int, total_valor: float, total_cerrado: float},
     *   entries: array<int, array<string, mixed>>
     * }
     */
    public function list(User $user, string $activeRole, string $type = '', string $q = ''): array
    {
        $query = Contact::query()->orderBy('nombre');

        if ($type !== '') {
            $query->where('tipo', $type);
        }

        if ($q !== '') {
            $query->where('nombre', 'ilike', '%' . $q . '%');
        }

        /** @var Collection<int, Contact> $contacts */
        $contacts = $query->get();

        // Load visible projects once and attach stats per contact
        /** @var Collection<int, Project> $projects */
        $projects = $this->visibleProjects($user, $activeRole)->with('latestActivity')->get();

        $entries = $contacts->map(function (Contact $contact) use ($projects): array {
            return $this->enrichWithProjectStats($contact, $projects);
        })->values()->all();

        $totalProjects = (int) array_sum(array_column($entries, 'project_count'));
        $totalValor    = (float) array_sum(array_column($entries, 'valor_total'));
        $totalCerrado  = (float) array_sum(array_column($entries, 'valor_cerrado'));

        return [
            'stats' => [
                'total'          => count($entries),
                'total_projects' => $totalProjects,
                'total_valor'    => $totalValor,
                'total_cerrado'  => $totalCerrado,
            ],
            'entries' => $entries,
        ];
    }

    /** @param array<string, mixed> $data */
    public function create(array $data): Contact
    {
        /** @var Contact $contact */
        $contact = Contact::create($data);

        return $contact;
    }

    /** @param array<string, mixed> $data */
    public function update(Contact $contact, array $data): Contact
    {
        $contact->update($data);

        return $contact->fresh() ?? $contact;
    }

    /**
     * Delete a contact. Returns the count of active projects linked by name+type
     * so the caller can decide to reject (409) or force-delete.
     */
    public function activeProjectCount(Contact $contact): int
    {
        $activeStages = ['lead', 'diseno', 'cotizacion', 'propuesta'];

        return Project::query()
            ->where($contact->tipo, $contact->nombre)
            ->whereIn('etapa', $activeStages)
            ->count();
    }

    public function delete(Contact $contact): void
    {
        $contact->delete();
    }

    /**
     * One-shot import: creates Contact records for every unique name found in
     * project fields that does not already exist in the contacts table. For
     * contacts that already exist with empty email/telefono, backfills those
     * fields from a matching project if one has the data (never overwrites a
     * value that's already set).
     *
     * @return array{created: int, updated: int, skipped: int}
     */
    public function import(): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        foreach (Contact::ALLOWED_TYPES as $tipo) {
            // Unique non-empty values for this field across all projects
            $names = Project::query()
                ->whereNotNull($tipo)
                ->where($tipo, '!=', '')
                ->distinct()
                ->pluck($tipo)
                ->map(fn (mixed $v): string => trim((string) $v))
                ->filter(fn (string $v): bool => $v !== '')
                ->unique()
                ->values();

            foreach ($names as $nombre) {
                // Solo el campo "contacto" del proyecto tiene email/celular asociados
                // en el propio registro — developer/arquitecto/encargado son solo
                // referencias de nombre, sin datos de contacto propios que copiar.
                $projectInfo = $tipo === Contact::TYPE_CONTACTO
                    ? $this->findContactoInfo($nombre)
                    : null;

                /** @var Contact|null $contact */
                $contact = Contact::query()->where('tipo', $tipo)->where('nombre', $nombre)->first();

                if ($contact !== null) {
                    $changed = false;

                    if ($projectInfo !== null) {
                        if (($contact->email === null || $contact->email === '') && $projectInfo['email'] !== null) {
                            $contact->email = $projectInfo['email'];
                            $changed = true;
                        }
                        if (($contact->telefono === null || $contact->telefono === '') && $projectInfo['telefono'] !== null) {
                            $contact->telefono = $projectInfo['telefono'];
                            $changed = true;
                        }
                    }

                    if ($changed) {
                        $contact->save();
                        $updated++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                $data = ['nombre' => $nombre, 'tipo' => $tipo];
                if ($projectInfo !== null) {
                    $data = array_merge($data, $projectInfo);
                }

                Contact::create($data);
                $created++;
            }
        }

        return ['created' => $created, 'updated' => $updated, 'skipped' => $skipped];
    }

    /** @return array{email: string|null, telefono: string|null}|null */
    private function findContactoInfo(string $nombre): ?array
    {
        $project = Project::query()
            ->where('contacto', $nombre)
            ->where(function ($q): void {
                $q->where(function ($q2): void {
                    $q2->whereNotNull('email')->where('email', '!=', '');
                })->orWhere(function ($q2): void {
                    $q2->whereNotNull('celular')->where('celular', '!=', '');
                });
            })
            ->first();

        if ($project === null) {
            return null;
        }

        return [
            'email'    => $project->email !== '' ? $project->email : null,
            'telefono' => $project->celular !== '' ? $project->celular : null,
        ];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * @param  Collection<int, Project> $projects
     * @return array<string, mixed>
     */
    private function enrichWithProjectStats(Contact $contact, Collection $projects): array
    {
        /** @var Collection<int, Project> $matching */
        $matching = $projects->filter(
            fn (Project $p): bool => trim((string) ($p->{$contact->tipo} ?? '')) === $contact->nombre
        );

        $areas      = $matching->pluck('area')->filter()->unique()->values()->all();
        $ubicaciones = $matching->pluck('ubicacion')->filter()->unique()->values()->all();
        $valorTotal  = (float) $matching->sum('valor');
        $valorCerrado = (float) $matching->filter(fn (Project $p): bool => $p->etapa === 'cerrado')->sum('valor');

        $ultimaActividad = null;
        foreach ($matching as $p) {
            $candidate = null;
            if ($p->latestActivity !== null) {
                $candidate = $p->latestActivity->fecha instanceof \Carbon\Carbon
                    ? $p->latestActivity->fecha->toDateString()
                    : (string) $p->latestActivity->fecha;
            }
            $candidate = $candidate ?? $p->updated_at->toDateString();

            if ($ultimaActividad === null || $candidate > $ultimaActividad) {
                $ultimaActividad = $candidate;
            }
        }

        return [
            'id'               => $contact->id,
            'nombre'           => $contact->nombre,
            'tipo'             => $contact->tipo,
            'email'            => $contact->email,
            'telefono'         => $contact->telefono,
            'empresa'          => $contact->empresa,
            'notas'            => $contact->notas,
            'source'           => 'contacts',
            'project_count'    => $matching->count(),
            'areas'            => array_map('strval', (array) $areas),
            'ubicaciones'      => array_map('strval', (array) $ubicaciones),
            'valor_total'      => $valorTotal,
            'valor_cerrado'    => $valorCerrado,
            'ultima_actividad' => $ultimaActividad,
            'created_at'       => $contact->created_at->toDateTimeString(),
            'updated_at'       => $contact->updated_at->toDateTimeString(),
        ];
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
