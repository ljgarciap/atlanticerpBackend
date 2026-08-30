<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Models\User;
use App\Modules\VentasDiseno\Events\QuoteApproved;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final class PipelineService
{
    private const MAX_RESULTS = 500;

    private const DETAIL_RELATIONS = [
        'salesProject', 'masterClient', 'subClient', 'owner', 'contacts', 'deliveryDates', 'files',
    ];

    public function findDetailed(int $id): ?PipelineCard
    {
        return PipelineCard::with(self::DETAIL_RELATIONS)->find($id);
    }

    /**
     * Grupo 1d — creación manual desde Pipeline (REQ-019/020), única vía además de la
     * regla de Lead automático (REQ-068 en ClientsService) para que nazca una tarjeta.
     *
     * Tipo "lead": sin sub_client_id — la tarjeta nace en Lead con cliente "—"
     * (REQ-011 exige vincular Cliente Master/Subcliente y agregar contacto recién al
     * intentar salir de Lead, no al crearla).
     *
     * Tipo "design": requiere sub_client_id de un Subcliente ya existente o recién
     * creado vía ClientPicker (POST /master-clients + /sub-clients, que a propósito no
     * disparan el Lead automático de REQ-068 — así se cumple "una única tarjeta final
     * en Diseño, sin duplicados en Lead"). Nace directo en etapa "design".
     *
     * @param array{type: string, name: string, tag?: string|null, observations?: string|null, sub_client_id?: int|null} $data
     */
    public function create(array $data, int $ownerId): PipelineCard
    {
        return DB::transaction(function () use ($data, $ownerId): PipelineCard {
            $subClientId = $data['sub_client_id'] ?? null;

            $project = SalesProject::create([
                'sub_client_id' => $subClientId,
                'name'          => $data['name'],
                'tag'           => $data['tag'] ?? null,
            ]);

            $masterClientId = $subClientId !== null
                ? SubClient::find($subClientId)?->master_client_id
                : null;

            $card = PipelineCard::create([
                'sales_project_id' => $project->id,
                'stage'            => $data['type'] === 'design' ? 'design' : 'lead',
                'master_client_id' => $masterClientId,
                'sub_client_id'    => $subClientId,
                'owner_id'         => $ownerId,
                'observations'     => $data['observations'] ?? null,
                'stage_changed_at' => now(),
            ]);

            /** @var PipelineCard $fresh */
            $fresh = $card->fresh(self::DETAIL_RELATIONS);
            return $fresh;
        });
    }

    /**
     * @param array{
     *   name?: string,
     *   tag?: string|null,
     *   master_client_id?: int|null,
     *   sub_client_id?: int|null,
     *   worked_area_m2?: float|null,
     *   delivery_type?: string|null,
     *   delivery_dates?: list<string>,
     *   observations?: string|null,
     * } $data
     */
    public function update(PipelineCard $card, array $data): PipelineCard
    {
        return DB::transaction(function () use ($card, $data): PipelineCard {
            $projectUpdates = [];
            if (array_key_exists('name', $data)) {
                $projectUpdates['name'] = $data['name'];
            }
            if (array_key_exists('tag', $data)) {
                $projectUpdates['tag'] = $data['tag'];
            }
            if ($projectUpdates !== []) {
                $card->salesProject->update($projectUpdates);
            }

            $cardUpdates = [];
            foreach (['master_client_id', 'sub_client_id', 'worked_area_m2', 'delivery_type', 'observations'] as $field) {
                if (array_key_exists($field, $data)) {
                    $cardUpdates[$field] = $data[$field];
                }
            }
            if ($cardUpdates !== []) {
                $card->update($cardUpdates);
            }

            if (array_key_exists('delivery_dates', $data)) {
                $card->deliveryDates()->delete();
                foreach ($data['delivery_dates'] as $date) {
                    $card->deliveryDates()->create(['date' => $date]);
                }
            }

            /** @var PipelineCard $fresh */
            $fresh = $card->fresh(self::DETAIL_RELATIONS);
            return $fresh;
        });
    }

    /**
     * REQ-011/014/016/017/018: mueve la tarjeta de etapa, reiniciando stage_changed_at
     * (REQ-004: "días en etapa" se reinicia a 0 en cada cambio de etapa). La validación
     * del gate (PipelineCard::validationErrorForTransitionTo) corre antes en el controller
     * (sobre la fila posiblemente stale — ver nota de concurrencia abajo, no invalida el gate:
     * en el peor caso una transición ya en curso vuelve a evaluarse una vez el lock se libera).
     *
     * REQ-308 (SCRUM-329 Batch A1): dispara `QuoteApproved` cuando la tarjeta ENTRA a "approved"
     * (old stage != 'approved' -> new stage == 'approved') — nunca en un no-op (llamar
     * changeStage() con el mismo stage actual es una transición válida según
     * `validationErrorForTransitionTo()`, pero no debe volver a crear un pedido en Bodega).
     * `Order` en Bodega es el único consumidor hoy (ver
     * `App\Modules\Bodega\Listeners\CreateOrderFromApprovedQuote`).
     *
     * Blocker 2 de Senior Review (2026-07-22): sin lock, dos aprobaciones concurrentes de la
     * misma tarjeta podían ambas leer `oldStage != 'approved'` antes de que cualquiera
     * escribiera, ambas disparar `QuoteApproved`, y crear dos `Order` para el mismo
     * `pipeline_card_id` (mismo patrón de bug que ya tuvo el proyecto en `AdjustmentRequest`
     * approve/reject). Fix: `DB::transaction()` + `SELECT ... FOR UPDATE` sobre la fila real de
     * `PipelineCard` (releída acá, no se confía en `$card->stage` que el caller pudo haber
     * cargado antes de que otro request ganara la carrera) — mismo patrón de lock que
     * `OrderFolioService::next()`. La segunda request de una carrera espera a que la primera
     * comitee, relee `oldStage == 'approved'` y NO vuelve a disparar el evento — el pedido de
     * Bodega nunca se duplica, aunque la segunda request igual actualiza `stage_changed_at` (un
     * no-op válido según el gate, no un error).
     */
    public function changeStage(PipelineCard $card, string $targetStage): PipelineCard
    {
        return DB::transaction(function () use ($card, $targetStage): PipelineCard {
            /** @var PipelineCard $locked */
            $locked = PipelineCard::whereKey($card->id)->lockForUpdate()->firstOrFail();
            $oldStage = $locked->stage;

            $locked->update(['stage' => $targetStage, 'stage_changed_at' => now()]);

            if ($targetStage === 'approved' && $oldStage !== 'approved') {
                event(new QuoteApproved(
                    pipelineCardId: $locked->id,
                    salesProjectId: $locked->sales_project_id,
                    ownerId: $locked->owner_id,
                    tenant: $this->requiredTenantSlug(),
                ));
            }

            /** @var PipelineCard $fresh */
            $fresh = $locked->fresh(self::DETAIL_RELATIONS);
            return $fresh;
        });
    }

    /**
     * @param array{
     *   scope?: string,
     *   chip?: string,
     *   search?: string,
     *   order?: string,
     *   owner_id?: int,
     *   tag?: string,
     *   stage?: string,
     * } $filters
     * @return Collection<int, PipelineCard>
     */
    public function list(User $user, array $filters = []): Collection
    {
        $query = PipelineCard::with(['salesProject', 'masterClient', 'subClient', 'owner']);

        // Alcance (REQ-002): Diseñador ve solo lo propio; Gerencia ve "Equipo".
        // ADR-006, Fase C Task 4 — canViewTeamV2('ventas_diseno') reemplaza el supuesto viejo
        // (hasRole(MANAGEMENT)), ya resuelto por SCRUM-144/145 (rol vendedor_disenador vs gerencia).
        $canSeeTeam = $user->canViewTeamV2('ventas_diseno');
        $scope      = $filters['scope'] ?? 'own';

        if ($scope !== 'team' || ! $canSeeTeam) {
            $query->where('owner_id', $user->id);
        } elseif (isset($filters['owner_id'])) {
            // REQ-056 (Grupo 3, Inicio): dentro del alcance "Equipo", Líder/Gerencia puede
            // acotar a un vendedor específico — mismo mecanismo que ya usa Inicio para
            // filtrar el resumen de Pipeline, reutilizado acá en vez de duplicarlo.
            $query->where('owner_id', $filters['owner_id']);
        }

        if (! empty($filters['search'])) {
            $term = $filters['search'];
            $query->where(function ($q) use ($term): void {
                $q->whereHas('salesProject', fn ($sq) => $sq->where('name', 'ilike', "%{$term}%"))
                  ->orWhereHas('masterClient', fn ($mq) => $mq->where('name', 'ilike', "%{$term}%"))
                  ->orWhereHas('subClient', fn ($sq) => $sq->where('business_name', 'ilike', "%{$term}%"));
            });
        }

        // SCRUM-796 (secc. 1.3) — filtro por SalesProject::tag (design|quote|both).
        if (! empty($filters['tag'])) {
            $query->whereHas('salesProject', fn ($sq) => $sq->where('tag', $filters['tag']));
        }

        // SCRUM-796 (secc. 1.1/1.2) — filtro genérico por etapa literal, independiente de
        // `chip` (ver docblock de PipelineController::index()).
        if (! empty($filters['stage'])) {
            $query->where('stage', $filters['stage']);
        }

        $chip = $filters['chip'] ?? 'all';
        if ($chip === 'final_stage') {
            $query->where('stage', 'proposal');
        }
        // REQ-074 (Reportes — Mejores clientes): "el ícono de ojo navega a Pipeline
        // mostrando solo la etapa Aprobado, ordenada de mayor a menor valor" — mismo
        // mecanismo de chip que final_stage, no un filtro nuevo aparte.
        if ($chip === 'approved') {
            $query->where('stage', 'approved');
        }

        $order = $filters['order'] ?? 'days';
        if ($order === 'value') {
            $query->orderByDesc('amount');
        } else {
            $query->orderBy('stage_changed_at');
        }

        /** @var Collection<int, PipelineCard> $cards */
        $cards = $query->limit(self::MAX_RESULTS)->get();

        // El chip "estancados" depende de daysInStage(), calculado en PHP a partir de
        // stage_changed_at — no es una columna filtrable directamente en SQL sin
        // duplicar la lógica de umbral en dos lugares.
        if ($chip === 'stagnant') {
            $cards = $cards->filter(fn (PipelineCard $card): bool => $card->isStagnant())->values();
        }

        return $cards;
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
