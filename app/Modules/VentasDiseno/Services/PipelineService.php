<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Services;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Support\RoleKeys;
use App\Modules\Bodega\Services\OrderService;
use App\Modules\Notifications\Models\NotificationSend;
use App\Modules\Notifications\Services\NotificationService;
use App\Modules\VentasDiseno\Exceptions\AlreadyPassedToOrderException;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardDeliveryDate;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final class PipelineService
{
    private const MAX_RESULTS = 500;

    private const DETAIL_RELATIONS = [
        'salesProject', 'masterClient', 'subClient', 'owner', 'creator', 'orderApprover', 'orderRequester', 'contacts', 'deliveryDates', 'files',
    ];

    public function __construct(
        private readonly OrderService $orderService,
        private readonly NotificationService $notifications,
    ) {}

    public function findDetailed(int $id): ?PipelineCard
    {
        return PipelineCard::with(self::DETAIL_RELATIONS)->find($id);
    }

    /**
     * Grupo 1d — creación manual desde Pipeline (REQ-019/020); la otra vía es confirmar una cotización sin tarjeta
     * previa (QuoteService). Crear un cliente ya no crea tarjeta (SCRUM-1187 reemplazó REQ-068).
     *
     * Tipo "lead": sin sub_client_id — la tarjeta nace en Lead con cliente "—"
     * (REQ-011 exige vincular Cliente Master/Subcliente y agregar contacto recién al
     * intentar salir de Lead, no al crearla).
     *
     * Tipo "design": requiere sub_client_id de un Subcliente ya existente o recién
     * creado vía ClientPicker (POST /master-clients + /sub-clients con
     * `from_design_context: true`, ya sin efecto desde SCRUM-1187: ningún alta de cliente/subcliente crea Lead — SCRUM-1011:
     * "una única tarjeta final en Diseño, sin duplicados en Lead").
     * Nace directo en etapa "design".
     *
     * @param array{type: string, name: string, tag?: string|null, observations?: string|null, sub_client_id?: int|null} $data
     * @param list<int>|null $assigneeIds SCRUM-849.4 — Gerencia-only (gate en el controller); el
     *   primer id queda como owner_id (mismo criterio que update(), ver ahí), el resto en
     *   compartido_con. Null/vacío = comportamiento de siempre, $ownerId es quien crea.
     */
    public function create(array $data, int $ownerId, ?array $assigneeIds = null): PipelineCard
    {
        return DB::transaction(function () use ($data, $ownerId, $assigneeIds): PipelineCard {
            $subClientId = $data['sub_client_id'] ?? null;

            $project = SalesProject::create([
                'sub_client_id' => $subClientId,
                'name'          => $data['name'],
                'tag'           => $data['tag'] ?? null,
            ]);

            $masterClientId = $subClientId !== null
                ? SubClient::find($subClientId)?->master_client_id
                : null;

            // array_unique — mismo criterio que update(), ver ahí.
            $uniqueAssigneeIds = $assigneeIds !== null ? array_values(array_unique($assigneeIds)) : null;
            $resolvedOwnerId   = $uniqueAssigneeIds !== null && $uniqueAssigneeIds !== [] ? $uniqueAssigneeIds[0] : $ownerId;
            $compartidoCon     = $uniqueAssigneeIds !== null && count($uniqueAssigneeIds) > 1 ? array_slice($uniqueAssigneeIds, 1) : [];

            $card = PipelineCard::create([
                'sales_project_id' => $project->id,
                'stage'            => $data['type'] === 'design' ? PipelineStages::DESIGN : PipelineStages::LEAD,
                'master_client_id' => $masterClientId,
                'sub_client_id'    => $subClientId,
                'owner_id'         => $resolvedOwnerId,
                // SCRUM-884 — $ownerId acá es siempre quien hizo el request (ver
                // PipelineController::store()), distinto de $resolvedOwnerId cuando Gerencia
                // asigna la tarjeta a otra persona al crearla. "Creador" es inmutable: nunca se
                // vuelve a tocar fuera de este create().
                'created_by'       => $ownerId,
                'compartido_con'   => $compartidoCon,
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
     *   assignee_ids?: list<int>,
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
            // SCRUM-849.4 — "asignar a 1 persona" reasigna owner_id (reemplaza, no agrega, ver
            // RN del ticket: "el proyecto debe quedar reasignado"); "asignar a varias" deja al
            // primero como owner_id y al resto en compartido_con (split de comisión N-way, ver
            // SalesTotalsService::monthlyApprovedTotal()). Reemplaza la asignación completa —
            // un asignado que ya no está en la lista pierde el acceso, sin caso "grandfather".
            if (array_key_exists('assignee_ids', $data)) {
                // array_unique — un id repetido en la lista (UI no lo permite, pero la API no lo
                // rechaza) no debe inflar el conteo de asignados y diluir de más el split de
                // comisión N-way (SalesTotalsService::monthlyApprovedTotal() cuenta 1 + count(
                // compartido_con); un duplicado ahí resta un asignado real de la cuenta).
                $uniqueIds = array_values(array_unique($data['assignee_ids']));
                $cardUpdates['owner_id']       = $uniqueIds[0];
                $cardUpdates['compartido_con'] = array_slice($uniqueIds, 1);
            }
            if ($cardUpdates !== []) {
                $card->update($cardUpdates);
            }

            if (array_key_exists('delivery_dates', $data)) {
                // Pre-QA (SCRUM-817, 2026-08-31) — la corrección de SCRUM-817 es solo de UI: el
                // frontend ya nunca ARMA un payload inconsistente, pero la API en sí no rechazaba
                // ni normalizaba uno mandado directo (delivery_type=single con 2+ fechas volvía
                // 200 y lo guardaba tal cual). Mismo criterio que el frontend ya aplica —
                // "single" nunca conserva más de 1 fecha — pero acá, servidor, para que el
                // invariante valga siempre, sin importar quién arme el request.
                $resultingType = array_key_exists('delivery_type', $data) ? $data['delivery_type'] : $card->delivery_type;
                $dates = $resultingType === 'single'
                    ? array_slice($data['delivery_dates'], 0, 1)
                    : $data['delivery_dates'];

                // SCRUM-1027 — sincronizar por diferencia, no borrar-y-recrear: la FK de
                // `pipeline_card_product_deliveries` es cascadeOnDelete, así que recrear TODAS
                // las fechas en cada guardado borraba las asignaciones de producto aunque las
                // fechas no hubieran cambiado. Una fecha que se conserva mantiene su id (y sus
                // asignaciones); solo las quitadas se borran, y las nuevas se crean.
                /** @var array<int, PipelineCardDeliveryDate> $pool */
                $pool = $card->deliveryDates()->get()->all();
                foreach ($dates as $date) {
                    $keep = null;
                    foreach ($pool as $i => $existing) {
                        if ($existing->date->toDateString() === Carbon::parse($date)->toDateString()) {
                            $keep = $i;
                            break;
                        }
                    }
                    if ($keep !== null) {
                        unset($pool[$keep]);
                        continue;
                    }
                    $card->deliveryDates()->create(['date' => $date]);
                }
                foreach ($pool as $removed) {
                    $removed->delete();
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
     * del gate (PipelineCard::validationErrorForTransitionTo) corre antes en el controller.
     *
     * SCRUM-1128 — entrar a "approved" YA NO genera el Pedido en Bodega (antes lo hacía el evento
     * `QuoteApproved`, REQ-308): el proyecto queda "por aprobar" hasta que Gerencia o Contabilidad
     * ejecutan `passToOrder()`. El lock sigue siendo necesario para que dos cambios de etapa
     * concurrentes no se pisen.
     */
    public function changeStage(PipelineCard $card, string $targetStage): PipelineCard
    {
        return DB::transaction(function () use ($card, $targetStage): PipelineCard {
            /** @var PipelineCard $locked */
            $locked = PipelineCard::whereKey($card->id)->lockForUpdate()->firstOrFail();

            $locked->update(['stage' => $targetStage, 'stage_changed_at' => now()]);

            /** @var PipelineCard $fresh */
            $fresh = $locked->fresh(self::DETAIL_RELATIONS);
            return $fresh;
        });
    }

    /**
     * SCRUM-1128 — aprobación explícita "Pasar a Pedido": genera el Pedido en Bodega (estado
     * "Asignado a Bodega", mismo `OrderService::createFromApprovedQuote()` de siempre) y registra
     * quién y cuándo. Una sola vez por tarjeta: `SELECT ... FOR UPDATE` sobre la fila + chequeo de
     * pedido existente, así que doble clic o dos pestañas no duplican el pedido (la segunda
     * llamada recibe `AlreadyPassedToOrderException`). Cualquier fallo de Bodega propaga y revierte
     * la transacción — a diferencia del listener automático anterior, acá el usuario SÍ debe
     * enterarse si el pedido no se pudo crear.
     *
     * @throws \DomainException si la tarjeta no está en "approved"
     * @throws AlreadyPassedToOrderException si ya pasó a pedido
     */
    public function passToOrder(PipelineCard $card, User $actor): PipelineCard
    {
        return DB::transaction(function () use ($card, $actor): PipelineCard {
            /** @var PipelineCard $locked */
            $locked = PipelineCard::whereKey($card->id)->lockForUpdate()->firstOrFail();

            if (! PipelineStages::isWon($locked->stage)) {
                throw new \DomainException('Solo un proyecto en estado Aprobado puede pasar a Pedido.');
            }

            if ($locked->order_approved_at !== null || $locked->orders()->exists()) {
                throw new AlreadyPassedToOrderException();
            }

            $order = $this->orderService->createFromApprovedQuote($locked->id, $locked->owner_id);

            // `createFromApprovedQuote()` devuelve null solo si la tarjeta no existe (imposible acá,
            // está bloqueada); si no hay pedido no se marca como aprobado y el usuario puede reintentar.
            if ($order === null) {
                throw new \RuntimeException('No se pudo generar el pedido en Bodega.');
            }

            $locked->update(['order_approved_by' => $actor->id, 'order_approved_at' => now()]);

            /** @var PipelineCard $fresh */
            $fresh = $locked->fresh(self::DETAIL_RELATIONS);
            return $fresh;
        });
    }

    /**
     * Fase 16 (5.3) — quien no puede ejecutar «Pasar a Pedido» (el vendedor) pide que Gerencia lo haga. No cambia el estado del pedido: solo deja
     * la solicitud en la tarjeta y avisa a Gerencia. Una segunda solicitud sobre la misma tarjeta se rechaza (una sola pendiente).
     */
    public function requestPassToOrder(PipelineCard $card, User $actor, ?string $note): PipelineCard
    {
        $locked = DB::transaction(function () use ($card, $actor, $note): PipelineCard {
            /** @var PipelineCard $locked */
            $locked = PipelineCard::whereKey($card->id)->lockForUpdate()->firstOrFail();

            if (! PipelineStages::isWon($locked->stage)) {
                throw new \DomainException('Solo un proyecto en estado Aprobado puede pasar a Pedido.');
            }
            if ($locked->order_approved_at !== null || $locked->orders()->exists()) {
                throw new AlreadyPassedToOrderException();
            }
            if ($locked->order_requested_at !== null) {
                throw new \DomainException('Ya hay una solicitud de pase a Pedido para este proyecto.');
            }

            $locked->update([
                'order_requested_by' => $actor->id,
                'order_requested_at' => now(),
                'order_request_note' => $note !== null && trim($note) !== '' ? trim($note) : null,
            ]);

            return $locked;
        });

        $this->notifyOrderRequest($locked, $actor);

        /** @var PipelineCard $fresh */
        $fresh = $locked->fresh(self::DETAIL_RELATIONS);

        return $fresh;
    }

    private function notifyOrderRequest(PipelineCard $card, User $actor): void
    {
        $roleId = Role::where('key', RoleKeys::MANAGEMENT)->value('id');
        /** @var list<int> $audience */
        $audience = $roleId !== null
            ? User::where('role_id', $roleId)->where('is_active', true)->pluck('id')->map(fn ($id) => (int) $id)->values()->all()
            : [];
        if ($audience === []) {
            return;
        }

        $nombre = trim("{$actor->first_name} {$actor->last_name}");
        $this->notifications->send(
            audience: $audience,
            content: [
                'subject' => 'Solicitud para pasar un proyecto a Pedido',
                'body'    => "{$nombre} pidió pasar a Pedido un proyecto aprobado.",
                'cta_url' => "/ventas-diseno/pipeline?card={$card->id}",
            ],
            channels: [NotificationSend::CHANNEL_IN_APP],
            source: NotificationSend::SOURCE_PIPELINE_ORDER_REQUEST,
            sourceRef: "pipeline_card:{$card->id}",
            createdBy: $actor->id,
        );
    }

    /**
     * @param array{
     *   scope?: string,
     *   chip?: string,
     *   search?: string,
     *   order?: string,
     *   owner_id?: int,
     *   owner_ids?: list<int>,
     *   tag?: string,
     *   stage?: string,
     *   orders?: string,
     * } $filters
     * @return Collection<int, PipelineCard>
     */
    public function list(User $user, array $filters = []): Collection
    {
        $query = PipelineCard::with(['salesProject', 'masterClient', 'subClient', 'owner', 'orderApprover'])->withExists('orders as has_order');

        // Alcance (REQ-002): Diseñador ve solo lo propio; Gerencia ve "Equipo".
        // ADR-006, Fase C Task 4 — canViewTeamV2('ventas_diseno') reemplaza el supuesto viejo
        // (hasRole(MANAGEMENT)), ya resuelto por SCRUM-144/145 (rol vendedor vs gerencia).
        // SCRUM-1128 — `ventas_diseno.pipeline.read_all` (Contabilidad) da el alcance de equipo SOLO para
        // leer; ninguna ruta de escritura lo consulta.
        // SCRUM-1139 — `ventas_diseno.pipeline.edit` (Contabilidad) también da el alcance de equipo: quien edita el Pipeline lo ve completo.
        $canSeeTeam = $user->canViewTeamV2('ventas_diseno')
            || in_array('ventas_diseno.pipeline.read_all', $user->effectivePermissionsV2(), true)
            || PipelineCard::userHasPipelineEdit($user);
        $scope      = $filters['scope'] ?? 'own';

        if ($scope !== 'team' || ! $canSeeTeam) {
            // SCRUM-849.4 — un asignado secundario (`compartido_con`) también debe ver la
            // tarjeta en su propio alcance "Mío", igual que canAccess() en el controller.
            $query->where(function ($q) use ($user): void {
                $q->where('owner_id', $user->id)
                    ->orWhereJsonContains('compartido_con', $user->id);
            });
        } elseif (! empty($filters['owner_ids'])) {
            // SCRUM-854 — multi-selección (OR entre seleccionados), reemplaza a owner_id singular
            // cuando ambos vienen; owner_ids gana porque es el filtro más específico del cliente.
            $query->whereIn('owner_id', $filters['owner_ids']);
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

        // SCRUM-1128 — filtro "Pedidos": clasifica por el estado REAL de la generación del pedido
        // (existe un Order de Bodega para la tarjeta), no solo por el stage del Pipeline.
        if (($filters['orders'] ?? null) === 'approved') {
            $query->whereHas('orders');
        } elseif (($filters['orders'] ?? null) === 'pending') {
            $query->whereIn('stage', PipelineStages::won())->whereDoesntHave('orders');
        }

        $chip = $filters['chip'] ?? 'all';
        if ($chip === 'final_stage') {
            $query->whereIn('stage', PipelineStages::openAtOrAfter(PipelineStages::PROPOSAL));
        }
        // REQ-074 (Reportes — Mejores clientes): "el ícono de ojo navega a Pipeline
        // mostrando solo la etapa Aprobado, ordenada de mayor a menor valor" — mismo
        // mecanismo de chip que final_stage, no un filtro nuevo aparte.
        if ($chip === 'approved') {
            $query->whereIn('stage', PipelineStages::won());
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
}
