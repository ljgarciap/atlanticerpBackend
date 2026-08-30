<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Models\User;
use App\Modules\VentasDiseno\Models\PipelineCard;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

/**
 * SCRUM-690/691/693 (REQ-610/611/613, Batch D) — pantalla "Lista de Proyectos". Mismo scope
 * Mías/Equipo que PipelineService::list() (RN1/RN2), pero con paginación real server-side (política
 * global, App\Shared\Http\Pagination) en vez del `limit(500)` client-side que usa Pipeline — ver
 * memory/feedback_pagination_sorting_policy.md. No se reutiliza PipelineService::list() porque esa
 * política es incompatible con LengthAwarePaginator sin duplicar el query dos veces.
 */
final class ProjectsListService
{
    /**
     * @param array{scope?: string, owner_id?: int, search?: string, stage?: string, tag?: string} $filters
     * @return Builder<PipelineCard>
     */
    public function query(User $user, array $filters = []): Builder
    {
        $query = PipelineCard::query()
            ->with(['salesProject', 'masterClient', 'subClient', 'owner'])
            ->withCount('files')
            // RN de "fecha de entrega": solo la próxima fecha pendiente (>= hoy, la más
            // cercana) — decisión de Luis 2026-07-31, no siempre la primera cargada. Si
            // todas las fechas ya pasaron, PipelineCard puede tener 0 filas acá y el
            // formatter cae a la última fecha vencida por separado (ver formatRow()).
            ->with(['deliveryDates' => fn ($q) => $q->orderBy('date')]);

        // RN1/RN2 (REQ-610) — idéntico al scope de PipelineService::list(), no reinventado.
        $canSeeTeam = $user->canViewTeamV2('ventas_diseno');
        $scope      = $filters['scope'] ?? 'own';
        if ($scope !== 'team' || ! $canSeeTeam) {
            $query->where('owner_id', $user->id);
        } elseif (isset($filters['owner_id'])) {
            // RN4 (REQ-611): filtro de Responsable, solo tiene efecto real si ya se pasó el
            // gate de arriba (scope=team + canSeeTeam) — un Vendedor/Diseñador nunca llega acá.
            $query->where('owner_id', $filters['owner_id']);
        }

        // RN1 (REQ-611): búsqueda de texto libre, proyecto o Cliente Master, sin distinguir mayúsculas.
        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function (Builder $q) use ($term): void {
                $q->whereHas('salesProject', fn ($sq) => $sq->where('name', 'ilike', "%{$term}%"))
                  ->orWhereHas('masterClient', fn ($mq) => $mq->where('name', 'ilike', "%{$term}%"));
            });
        }

        // RN2 (REQ-611): filtro de Etapa.
        if (! empty($filters['stage'])) {
            $query->where('stage', $filters['stage']);
        }

        // RN3 (REQ-611): filtro de Etiqueta — vive en salesProject.tag, no en PipelineCard.
        if (! empty($filters['tag'])) {
            $query->whereHas('salesProject', fn ($sq) => $sq->where('tag', $filters['tag']));
        }

        return $query;
    }

    /**
     * RN4 (REQ-611): opciones reales del filtro de Responsable — todo el equipo visible,
     * independiente de los filtros ya aplicados (mismo patrón que
     * PurchaseOrderController::distinctCreators(), evitando un join crudo contra el schema
     * de auth — igual que allá, se resuelve vía la relación Eloquent). Solo tiene sentido
     * pedirlo cuando canViewTeamV2() ya es true; el caller no debe llamarlo para un
     * Vendedor/Diseñador.
     *
     * @return list<array{id: int, name: string}>
     */
    public function distinctOwners(): array
    {
        $owners = PipelineCard::with('owner')
            ->get()
            ->pluck('owner')
            ->filter()
            ->unique('id')
            ->map(fn (User $u): array => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}")])
            ->sortBy('name')
            ->values()
            ->all();

        return array_values($owners);
    }

    /** @return array<string, mixed> */
    public function formatRow(PipelineCard $card): array
    {
        return [
            'id'                 => $card->id,
            'stage'              => $card->stage,
            'sales_project'      => [
                'id'   => $card->salesProject->id,
                'name' => $card->salesProject->name,
                'tag'  => $card->salesProject->tag,
            ],
            'master_client'      => $card->masterClient !== null ? [
                'id'   => $card->masterClient->id,
                'name' => $card->masterClient->name,
            ] : null,
            'owner'              => [
                'id'   => $card->owner->id,
                'name' => trim("{$card->owner->first_name} {$card->owner->last_name}"),
            ],
            'amount'             => $card->amount,
            'worked_area_m2'     => $card->worked_area_m2,
            'days_in_stage'      => $card->daysInStage(),
            'next_delivery_date' => $this->nextDeliveryDate($card),
            'files_count'        => (int) $card->files_count,
        ];
    }

    /**
     * Próxima fecha pendiente (>= hoy) y más cercana; si todas ya pasaron, la más reciente
     * de las vencidas. null si el proyecto no tiene ninguna fecha registrada (RN6, "—" en frontend).
     */
    private function nextDeliveryDate(PipelineCard $card): ?string
    {
        $dates = $card->deliveryDates;
        if ($dates->isEmpty()) {
            return null;
        }

        $today = Carbon::today();
        $upcoming = $dates->first(fn ($d) => $d->date->greaterThanOrEqualTo($today));
        if ($upcoming !== null) {
            return $upcoming->date->toDateString();
        }

        return $dates->last()->date->toDateString();
    }
}
