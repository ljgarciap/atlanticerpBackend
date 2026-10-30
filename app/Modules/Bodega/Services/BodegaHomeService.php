<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Support\ModuleCatalog;
use App\Modules\Bodega\Models\AdjustmentRequestLine;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\Bodega\Models\OrderStageHistory;
use App\Modules\Bodega\Support\BodegaRoles;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Integrations\Outlook\Services\OutlookCalendarService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * SCRUM-329 Oleada A / Batch B1 (REQ-294→304, SCRUM-363→374) — "Home de Bodega". Mismo patrón
 * arquitectónico que `App\Modules\VentasDiseno\Services\HomeService` (alcance Mío/Equipo vía
 * `User::canViewTeamV2()`) y que `ComprasHomeService` (paneles de solo lectura reusando servicios
 * ya existentes byte-a-byte, nunca una fórmula paralela).
 *
 * Alcance Mío/Equipo (REQ-295/296) — 5 paneles lo respetan (Mi día, Mi calendario, Pendientes,
 * Despachos urgentes, Estado de pedidos); "Por recibir"/"Mayor rotación"/"Artículos críticos" son
 * inventario general y NUNCA cambian con el toggle (confirmado explícitamente en la spec).
 *
 * Interpretación de "target" para el alcance Mío/Equipo — DECISIÓN DEL BATCH, no confirmada
 * literalmente por ningún ticket: `Order` no tiene un único campo "asignado a mí" válido para
 * los 4 roles de Bodega (Jefe no queda asignado a ningún pedido en particular, solo lo supervisa)
 * — se toma como "mío" cualquier pedido donde el usuario objetivo aparezca en `assistant_id`,
 * `picker_id` O `courier_id` (los 3 roles operativos que sí quedan asignados a un pedido real).
 * Un Jefe de Bodega en alcance "Mío" legítimamente puede ver estos paneles en cero — se espera
 * que use "Equipo" la mayor parte del tiempo, no es un bug.
 */
final class BodegaHomeService
{
    public function __construct(
        private readonly OrderBoardService $orderBoard,
        private readonly BodegaSettingsService $settings,
        private readonly BodegaInventoryKpiService $bodegaKpis,
        private readonly OutlookCalendarService $outlook,
    ) {}

    /**
     * SCRUM-365/366 (REQ-295/296) — selector "ver a una persona puntual" del alcance Equipo.
     * "Asistentes/Operativos de Bodega" — SOLO `BodegaRoles::TEAM_SELECTABLE` (fix QA 2026-07-24,
     * ver docblock de esa constante: excluye Jefe/Picker/Transporte), dinámico — nunca una lista
     * de nombres hardcodeada.
     *
     * Fix de regresión cruzada (2026-07-28) — wrapper de compatibilidad sobre `teamMembers()`
     * (ver docblock ahí y de `BodegaRoles::TEAM_MEMBERS_QUERY_MAP`): el contrato HTTP de
     * `GET /bodega/home/team` no cambia, sigue devolviendo solo Asistentes.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public function team(): Collection
    {
        return $this->teamMembers(BodegaRoles::ASISTENTE_BODEGA) ?? collect();
    }

    /**
     * Fix de regresión cruzada (2026-07-28, ver docblock de `BodegaRoles::TEAM_MEMBERS_QUERY_MAP`)
     * — listado genérico de personas seleccionables de Bodega por un único `role_key` real (no un
     * valor público del mapa: acepta directamente `BodegaRoles::ASISTENTE_BODEGA`/`PICKER`/
     * `COURIER`/`LIDER_BODEGA`, la traducción desde el query param público vive en
     * `BodegaTeamMembersController`). Mismo shape de payload que `team()` a propósito, para que el
     * frontend no tenga que remapear tipos entre selectores.
     *
     * @return Collection<int, array{id: int, name: string}>|null null si `$roleKey` no corresponde
     *         a ningún rol real — el controller responde 422.
     */
    public function teamMembers(string $roleKey): ?Collection
    {
        $roleIds = Role::where('key', $roleKey)->pluck('id');
        if ($roleIds->isEmpty()) {
            return null;
        }

        return User::whereIn('role_id', $roleIds)
            ->orderBy('first_name')
            ->get()
            ->map(fn (User $u) => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}")])
            ->values();
    }

    /**
     * @param array{scope?: string, owner_id?: int} $filters
     * @return array<string, mixed>
     */
    public function summary(User $user, array $filters = []): array
    {
        $targetIds = $this->resolveTargetIds($user, $filters);
        // SCRUM-366 RN2 (hallazgo QA 2026-07-24, BUG 2) — "seleccionar una persona filtra... EXACTAMENTE
        // igual que si esa persona estuviera en Inicio": cuando el selector narrowa a una persona
        // puntual dentro de "Equipo" (`$targetIds` no es null), el saludo debe hablar en singular
        // ("Tienes...") como si esa persona hubiera iniciado sesión, no en plural de equipo. Solo
        // "Equipo completo" (`$targetIds === null`) cuenta como alcance de equipo real.
        $isTeamScope = ($filters['scope'] ?? 'own') === 'team'
            && $user->canViewTeamV2(ModuleCatalog::BODEGA)
            && $targetIds === null;

        $miDia = $this->miDia($targetIds);
        $pendientes = $this->pendientes($targetIds);

        return [
            'saludo'             => $this->saludo($user, $isTeamScope, $miDia['asignados_hoy'], $pendientes['count']),
            'mi_dia'             => $miDia,
            'mi_calendario'      => ['events_today_count' => $this->eventsTodayCount($user, $filters)],
            'pendientes'         => $pendientes,
            'despachos_urgentes' => $this->despachosUrgentes($targetIds),
            'estado_pedidos'     => $this->estadoPedidos($targetIds),
            'por_recibir'        => $this->porRecibir(),
            'mayor_rotacion'     => $this->mayorRotacion(),
            'articulos_criticos' => $this->articulosCriticos(),
        ];
    }

    // ── SCRUM-364 (REQ-294) — Saludo ────────────────────────────────────────

    /** @return array{nombre: string, fecha: string, resumen: string} */
    private function saludo(User $user, bool $isTeamScope, int $asignadosHoy, int $pendientesCount): array
    {
        $resumen = $isTeamScope
            ? "El equipo tiene {$asignadosHoy} pedidos asignados hoy y {$pendientesCount} pendientes."
            : "Tienes {$asignadosHoy} pedidos asignados hoy y {$pendientesCount} pendientes.";

        // SCRUM-863 — solo primer nombre en el saludo, alineado con el resto de los módulos.
        return [
            'nombre'  => trim((string) $user->first_name),
            'fecha'   => CarbonImmutable::now()->toDateString(),
            'resumen' => $resumen,
        ];
    }

    // ── SCRUM-367 (REQ-297) — Mi día / Día del equipo ───────────────────────

    /**
     * REQ-297 — corregido tras hallazgo de QA (qa.qa, 2026-07-26, comentario SCRUM-367):
     * "Pedidos hoy"/`asignados_hoy` mostraba 0 con 12 pedidos activos sembrados (SCRUM-501) en
     * alcance Equipo — mismo síntoma que SCRUM-372 (commit a23e9ae). La interpretación original
     * contaba pedidos que ENTRARON a `STAGE_ASIGNADO` HOY (`order_stage_history.to_stage`), y
     * `QaTicketsBatch20260725Seeder::makeOrder()` (además del helper `makeOrder()` de este mismo
     * test suite) crea las `Order` directo vía `Order::create()` sin pasar por ningún servicio de
     * transición de etapa (`OrderService`/`PickingService`/`DeliveryService`/`ChangesOrderStage`),
     * así que NINGUNA fila de `order_stage_history` existe para esos pedidos — el conteo por
     * evento da 0 sin importar la fecha.
     *
     * A diferencia de SCRUM-372 (una sola causa, bug de fórmula), acá se confirmó ADEMÁS que la
     * fórmula original tampoco era la semántica correcta: el mockup adjunto (`3__Bodega_Home.html`)
     * muestra "Pedidos hoy" como EXACTAMENTE el denominador de "despachos completados hoy" en los
     * 3 escenarios de datos del mock (5/11, 4/7, 9/18 — el "11"/"7"/"18" es el mismo número en
     * ambos lugares). Un conteo de "entró a Asignado HOY" y un conteo de "se despachó HOY" son
     * poblaciones casi siempre disjuntas (un pedido normalmente no se asigna y se despacha el
     * mismo día — pasa por 4 etapas intermedias), así que no podrían coincidir con el
     * denominador de forma consistente bajo esa interpretación. Se reinterpreta como el BACKLOG
     * ACTUAL asignado a `$targetIds` (cualquier etapa salvo "Entregado", que ya está cerrada) —
     * MISMO criterio que `estadoPedidos()`/`OrderBoardService::board()`, vía `scopedOrdersQuery()`,
     * no una fórmula paralela.
     *
     * @param list<int>|null $targetIds
     * @return array{asignados_hoy: int, despachados_hoy: int, percent_despachado_hoy: float|null, percent_a_tiempo: float|null}
     */
    private function miDia(?array $targetIds): array
    {
        $asignadosHoy = $this->scopedOrdersQuery($targetIds)
            ->where('stage', '!=', Order::STAGE_ENTREGADO)
            ->count();
        $dispatched   = $this->dispatchedTodayOnTime($targetIds);

        return [
            'asignados_hoy'           => $asignadosHoy,
            'despachados_hoy'         => $dispatched['count'],
            'percent_despachado_hoy'  => $asignadosHoy > 0 ? round($dispatched['count'] / $asignadosHoy * 100, 1) : null,
            'percent_a_tiempo'        => $dispatched['percent'],
        ];
    }

    /**
     * REQ-297 — "% despachado a tiempo": fecha REAL de despacho (última transición a
     * `STAGE_DESPACHADO` en `order_stage_history`) vs. `committed_delivery_date`. Mismo criterio
     * de exclusión que `ComprasHomeService::onTimePercent()` — un pedido sin fecha comprometida
     * no puede evaluarse "a tiempo"/"atrasado", se excluye del denominador del porcentaje pero
     * SÍ cuenta en `despachados_hoy` (el conteo bruto no depende de tener fecha comprometida).
     *
     * @param list<int>|null $targetIds
     * @return array{count: int, percent: float|null}
     */
    private function dispatchedTodayOnTime(?array $targetIds): array
    {
        $query = OrderStageHistory::query()
            ->where('to_stage', Order::STAGE_DESPACHADO)
            ->where('created_at', '>=', CarbonImmutable::now()->startOfDay())
            ->with('order:id,committed_delivery_date');

        $this->scopeStageHistoryToOrders($query, $targetIds);

        /** @var Collection<int, OrderStageHistory> $rows */
        $rows = $query->get()->unique('order_id');
        $total = $rows->count();

        if ($total === 0) {
            return ['count' => 0, 'percent' => null];
        }

        $withCommitted = $rows->filter(fn (OrderStageHistory $h) => $h->order->committed_delivery_date !== null);
        if ($withCommitted->isEmpty()) {
            return ['count' => $total, 'percent' => null];
        }

        $onTime = $withCommitted->filter(function (OrderStageHistory $h): bool {
            $committed = $h->order->committed_delivery_date;

            return $committed !== null && $h->created_at->toDateString() <= $committed->toDateString();
        });

        return ['count' => $total, 'percent' => round($onTime->count() / $withCommitted->count() * 100, 1)];
    }

    // ── SCRUM-368 (REQ-298) — Mi calendario / Calendario del equipo ─────────

    /**
     * Mismo mecanismo de 3 vías que `HomeService::eventsTodayCount()` (Ventas & Diseño) y
     * `ComprasHomeService`, reusando `OutlookCalendarService` — no se duplica la llamada a Graph.
     *
     * @param array{scope?: string, owner_id?: int} $filters
     */
    private function eventsTodayCount(User $user, array $filters): int
    {
        $canSeeTeam = $user->canViewTeamV2(ModuleCatalog::BODEGA);
        $scope      = $filters['scope'] ?? 'own';

        if ($scope !== 'team' || ! $canSeeTeam) {
            $emails = array_values(array_filter([$user->email]));
        } elseif (isset($filters['owner_id']) && $this->isBodegaTeamMember((int) $filters['owner_id'])) {
            $target = User::find((int) $filters['owner_id']);
            $emails = $target !== null && $target->email !== null ? [$target->email] : [];
        } elseif (isset($filters['owner_id'])) {
            // owner_id fuera del equipo de Bodega — mismo guard que OutlookCalendarController:
            // cae al calendario propio del actor, nunca al del objetivo pedido.
            $emails = array_values(array_filter([$user->email]));
        } else {
            $emails = $this->teamEmails();
        }

        if ($emails === []) {
            return 0;
        }

        $today  = CarbonImmutable::now();
        $result = $this->outlook->eventsForUsers($emails, $today->startOfDay(), $today->endOfDay());

        return count($result['events']);
    }

    /** @return list<string> */
    private function teamEmails(): array
    {
        /** @var list<string> $emails */
        $emails = User::whereIn('role_id', $this->bodegaRoleIds())
            ->pluck('email')
            ->filter()
            ->values()
            ->all();

        return $emails;
    }

    // ── SCRUM-369 (REQ-299) — Pendientes ─────────────────────────────────────

    /**
     * @param list<int>|null $targetIds
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function pendientes(?array $targetIds): array
    {
        $items = array_merge(
            $this->pendientesPickingNoIniciado($targetIds),
            $this->pendientesGuiaSinSubir($targetIds),
            $this->pendientesAjustesInventario($targetIds),
        );

        return ['count' => count($items), 'items' => $items];
    }

    /**
     * REQ-299, condición 1 — pedido en `STAGE_ASIGNADO` (excluye tarjetas "Faltante" esperando
     * stock, `isAwaitingStock()` — su bloqueo es de inventario, no de que nadie inició el picking,
     * ya señalizado aparte por `eta_proveedor` en el tablero) hace más de N horas sin avanzar.
     *
     * @param list<int>|null $targetIds
     * @return list<array<string, mixed>>
     */
    private function pendientesPickingNoIniciado(?array $targetIds): array
    {
        /** @var Collection<int, Order> $orders */
        $orders = $this->scopedOrdersQuery($targetIds)
            ->where('stage', Order::STAGE_ASIGNADO)
            ->where('order_type', Order::ORDER_TYPE_PEDIDO)
            ->get(['id', 'order_number', 'customer_name']);

        if ($orders->isEmpty()) {
            return [];
        }

        $thresholdHours = $this->settings->pendientesPickingHoras();
        /** @var list<int> $orderIds */
        $orderIds = array_values(array_map('intval', $orders->pluck('id')->all()));
        $enteredAt = $this->latestStageEntry($orderIds, Order::STAGE_ASIGNADO);

        $items = [];
        foreach ($orders as $order) {
            $enteredAtForOrder = $enteredAt->get($order->id);
            if ($enteredAtForOrder === null) {
                continue;
            }

            $hours = $enteredAtForOrder->diffInHours(CarbonImmutable::now());
            if ($hours > $thresholdHours) {
                $items[] = [
                    'type'         => 'picking_no_iniciado',
                    'order_id'     => $order->id,
                    'order_number' => $order->order_number,
                    'cliente'      => $order->customer_name,
                    'horas'        => (int) $hours,
                    'suggestion'   => 'Asignar un picker o iniciar el picking cuanto antes.',
                ];
            }
        }

        return $items;
    }

    /**
     * REQ-299, condición 2 — pedido en `STAGE_DESPACHADO` sin `OrderDocument` tipo
     * `guia_entrega`, hace más de N días sin subirla. No se evalúa `STAGE_ENTREGADO`: por
     * `DeliveryService::registerSignedGuide()`, la guía firmada SIEMPRE se crea ANTES de
     * transicionar a Entregado — un pedido Entregado sin guía es estructuralmente imposible en el
     * flujo actual, evaluarlo sería una condición muerta.
     *
     * @param list<int>|null $targetIds
     * @return list<array<string, mixed>>
     */
    private function pendientesGuiaSinSubir(?array $targetIds): array
    {
        /** @var Collection<int, Order> $orders */
        $orders = $this->scopedOrdersQuery($targetIds)
            ->where('stage', Order::STAGE_DESPACHADO)
            ->with('documents')
            ->get(['id', 'order_number', 'customer_name']);

        if ($orders->isEmpty()) {
            return [];
        }

        $thresholdDays = $this->settings->pendientesGuiaDias();
        /** @var list<int> $orderIds */
        $orderIds = array_values(array_map('intval', $orders->pluck('id')->all()));
        $enteredAt = $this->latestStageEntry($orderIds, Order::STAGE_DESPACHADO);

        $items = [];
        foreach ($orders as $order) {
            $hasGuide = $order->documents->contains(fn (OrderDocument $d) => $d->tipo === OrderDocument::TIPO_GUIA_ENTREGA);
            if ($hasGuide) {
                continue;
            }

            $enteredAtForOrder = $enteredAt->get($order->id);
            if ($enteredAtForOrder === null) {
                continue;
            }

            $days = $enteredAtForOrder->diffInDays(CarbonImmutable::now());
            if ($days > $thresholdDays) {
                $items[] = [
                    'type'         => 'guia_sin_subir',
                    'order_id'     => $order->id,
                    'order_number' => $order->order_number,
                    'cliente'      => $order->customer_name,
                    'dias'         => (int) $days,
                    'suggestion'   => 'Subir la guía de entrega firmada para cerrar el pedido.',
                ];
            }
        }

        return $items;
    }

    /**
     * REQ-299, condición 3 — INTERPRETACIÓN DEL BATCH (marcado explícitamente para que PM/Luis lo
     * corrija si no era la intención): la spec original habla de "descuadre de inventario
     * detectado" como disparador de Pendientes, pero no existe ningún concepto con ese nombre
     * exacto en el modelo de datos actual. Se toma como equivalente el caso real más cercano ya
     * construido: una `AdjustmentRequestLine` en `ESTADO_PENDIENTE` (SCRUM-428/446, "Solicitud de
     * ajuste") — Bodega ya reportó una discrepancia de stock y está esperando que Gerencia la
     * apruebe/rechace.
     *
     * @param list<int>|null $targetIds
     * @return array<int, array<string, mixed>>
     */
    private function pendientesAjustesInventario(?array $targetIds): array
    {
        $query = AdjustmentRequestLine::query()
            ->where('estado', AdjustmentRequestLine::ESTADO_PENDIENTE)
            ->with(['request.catalogProduct', 'request.solicitante', 'warehouse']);

        if ($targetIds !== null) {
            $query->whereHas('request', fn (Builder $q) => $q->whereIn('solicitado_por', $targetIds));
        }

        return $query->get()->map(function (AdjustmentRequestLine $line): array {
            $solicitante = $line->request->solicitante;

            return [
                'type'                        => 'ajuste_inventario_pendiente',
                'adjustment_request_line_id'  => $line->id,
                'producto'                    => $line->request->catalogProduct?->name,
                'bodega'                      => $line->warehouse?->name,
                'solicitante'                 => $solicitante !== null ? trim("{$solicitante->first_name} {$solicitante->last_name}") : null,
                'dias'                        => (int) $line->created_at->diffInDays(CarbonImmutable::now()),
                'suggestion'                  => 'Revisar y resolver la solicitud de ajuste de inventario.',
            ];
        })->values()->all();
    }

    // ── SCRUM-370 (REQ-300) — Despachos urgentes ────────────────────────────

    /**
     * @param list<int>|null $targetIds
     * @return array<int, array<string, mixed>>
     */
    private function despachosUrgentes(?array $targetIds): array
    {
        $query = $this->scopedOrdersQuery($targetIds);
        $this->orderBoard->applyUrgentScope($query);

        /** @var Collection<int, Order> $orders */
        $orders = $query->orderBy('committed_delivery_date')->orderBy('created_at')->get();

        return $orders->map(fn (Order $o) => [
            'order_id'                   => $o->id,
            'order_number'                => $o->order_number,
            'cliente'                     => $o->customer_name,
            'stage'                       => $o->stage,
            'fecha_entrega_comprometida'  => $o->committed_delivery_date?->toDateString(),
        ])->values()->all();
    }

    // ── SCRUM-372 (REQ-302) — Estado de pedidos ─────────────────────────────

    /**
     * REQ-302 — corregido tras hallazgo de QA (qa.qa, 2026-07-26, comentario SCRUM-372): la
     * interpretación original de "las primeras 6 etapas cuentan 'hoy'" como "pedidos que ENTRARON
     * a esa etapa hoy" (`order_stage_history.to_stage`) dejaba el panel en 0/0/0/0/0/0 mientras el
     * Kanban (REQ-311) mostraba 12 pedidos activos — para un usuario que abre Inicio, "0 en cada
     * etapa" lee como "no hay trabajo" cuando sí lo hay. Confirmado con Luis (decisión de negocio):
     * las 6 primeras etapas cuentan el BACKLOG ACTUAL sentado en cada columna ahora mismo — MISMO
     * criterio que `OrderBoardService::board()` (conteo de `$orders` agrupado por `stage`, sin
     * filtro de fecha), no una fórmula paralela. Solo "Entregado" (7ma) sigue con ventana "esta
     * semana" (RN2, sin tocar — el ticket la trata explícitamente distinta).
     *
     * @param list<int>|null $targetIds
     * @return array{stages: list<array{stage: string, count: int}>, urgentes_count: int}
     */
    private function estadoPedidos(?array $targetIds): array
    {
        /** @var Collection<string, int> $currentCounts */
        $currentCounts = $this->scopedOrdersQuery($targetIds)
            ->selectRaw('stage, count(*) as aggregate')
            ->groupBy('stage')
            ->pluck('aggregate', 'stage');

        $stages = [];
        foreach (Order::STAGE_ORDER as $stage) {
            $count = $stage === Order::STAGE_ENTREGADO
                ? $this->ordersEnteringStageCount($stage, CarbonImmutable::now()->subDays(7)->startOfDay(), $targetIds)
                : (int) ($currentCounts[$stage] ?? 0);

            $stages[] = ['stage' => $stage, 'count' => $count];
        }

        $urgentQuery = $this->scopedOrdersQuery($targetIds);
        $this->orderBoard->applyUrgentScope($urgentQuery);

        return ['stages' => $stages, 'urgentes_count' => $urgentQuery->count()];
    }

    // ── SCRUM-371 (REQ-301) — Por recibir (no cambia con el scope) ──────────

    /**
     * REQ-301 — órdenes de compra EN TRÁNSITO hacia Bodega, una fila por línea (no por orden):
     * cada línea es un producto/cantidad distinto y la tabla los lista individualmente. El
     * criterio "!= Recibido" (en vez de solo los estados de transporte propiamente dichos) es el
     * mismo que ya usa `ComprasHomeService`/`OrderBoardService::supplierEta()` para "orden todavía
     * no recibida" en este sistema, sin inventar un tercer subconjunto de estados.
     *
     * @return list<array<string, mixed>>
     */
    private function porRecibir(): array
    {
        /** @var Collection<int, PurchaseOrder> $orders */
        $orders = PurchaseOrder::query()
            ->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO)
            ->with(['provider', 'lines.catalogProduct'])
            ->orderBy('estimated_arrival_date')
            ->get();

        $rows = [];
        foreach ($orders as $order) {
            foreach ($order->lines as $line) {
                $product = $line->catalogProduct;

                $rows[] = [
                    'purchase_order_id'      => $order->id,
                    'factory_reference'      => $product?->factory_reference,
                    'reference'               => $product?->reference,
                    'description'             => $product?->name,
                    'quantity'                => $line->quantity,
                    'status'                  => $order->status,
                    'estimated_arrival_date'  => $order->estimated_arrival_date?->toDateString(),
                    'provider_name'           => $order->provider?->name,
                ];
            }
        }

        return $rows;
    }

    // ── SCRUM-373 (REQ-303) — Mayor rotación (no cambia con el scope) ───────

    /** @return array<int, array<string, mixed>> */
    private function mayorRotacion(): array
    {
        $since = CarbonImmutable::now()->subDays($this->settings->rotacionVentanaDias());

        /** @var Collection<int, InventoryMovement> $rows */
        $rows = InventoryMovement::query()
            ->where('tipo', InventoryMovement::TIPO_SALIDA)
            ->where('created_at', '>=', $since)
            ->selectRaw('catalog_product_id, SUM(ABS(cantidad)) as total_salidas')
            ->groupBy('catalog_product_id')
            ->orderByDesc('total_salidas')
            ->limit($this->settings->rotacionTopN())
            ->get();

        if ($rows->isEmpty()) {
            return [];
        }

        /** @var list<int> $productIds */
        $productIds = $rows->pluck('catalog_product_id')->map(fn ($id) => (int) $id)->all();
        $products   = CatalogProduct::whereIn('id', $productIds)->get()->keyBy('id');

        return $rows->map(function (InventoryMovement $row) use ($products): array {
            /** @var CatalogProduct|null $product */
            $product = $products->get($row->catalog_product_id);

            return [
                'catalog_product_id' => (int) $row->catalog_product_id,
                'factory_reference'  => $product?->factory_reference,
                'reference'          => $product?->reference,
                'description'        => $product?->name,
                'total_salidas'      => (int) ($row->total_salidas ?? 0),
            ];
        })->values()->all();
    }

    // ── SCRUM-171 (REQ-109 RN2) — Rutas de entrega del día completo ─────────

    /**
     * SCRUM-171 RN2 (2026-08-26) — pantalla "Bodega → Rutas de entrega del día completo", destino
     * del botón "Ver detalle" del panel "Agenda de Bodega — hoy" de Gerencia (que solo muestra un
     * resumen de 5, ver `GerenciaHomeService::agendas()`). Mismo patrón que
     * `Servicios\Services\HomeService::rutasDia()`/`InternalTechnicianService::todaysRoutes()`:
     * TODAS las entregas/recolecciones de hoy, no acotado a un resumen — misma query base que
     * `GerenciaHomeService::agendas()['bodega']` (pedidos activos con `committed_delivery_date` =
     * hoy), con más detalle (repartidor) porque acá SÍ es la pantalla completa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function rutasDia(): array
    {
        $hoy = CarbonImmutable::today();

        return Order::whereDate('committed_delivery_date', $hoy)
            ->whereNotIn('stage', [Order::STAGE_ENTREGADO])
            ->with(['pipelineCard:id,sales_project_id', 'pipelineCard.salesProject:id,name', 'courier:id,first_name,last_name'])
            ->orderBy('committed_delivery_date')
            ->get()
            ->map(fn (Order $o): array => [
                'id'             => $o->id,
                'order_number'   => $o->order_number,
                'stage'          => $o->stage,
                'customer'       => $o->customer_name,
                'customer_contact' => $o->customer_contact,
                'project'        => $o->pipelineCard?->salesProject?->name,
                'address'        => $o->delivery_address,
                'repartidor'     => $o->courier !== null
                    ? trim("{$o->courier->first_name} {$o->courier->last_name}")
                    : null,
            ])
            ->values()
            ->all();
    }

    // ── SCRUM-374 (REQ-304) — Artículos críticos (no cambia con el scope) ───

    /**
     * REQ-304 — cualquier producto activo cuyo estado sea "bajo_stock" o "sin_stock" cuenta como crítico. Desde
     * SCRUM-996 (auditoría SCRUM-995, sitio C) se resuelve en SQL con la MISMA regla del chip `criticos` del
     * Inventario de Bodega (`BodegaInventoryKpiService::criticalArticles()`, compromiso a pedidos de Bodega,
     * REQ-351 RN1): el total del botón "Ver los N artículos" coincide con el listado al que enlaza
     * (`/bodega/inventario?filter=criticos`), y la tarjeta ya no hidrata el catálogo activo completo en cada
     * visita al Home (2,2 s y 93 MB). Historial: SCRUM-871 Pre-QA (2026-09-13) ya había acotado la lista a
     * `articulosCriticosTopN()` + el total real (antes devolvía las ~11K filas completas, 13-14 s y 2,19 MB).
     *
     * @return array{items: list<array<string, mixed>>, total: int}
     */
    private function articulosCriticos(): array
    {
        return $this->bodegaKpis->criticalArticles($this->settings->articulosCriticosTopN());
    }

    // ── Alcance Mío/Equipo (REQ-295/296) ─────────────────────────────────────

    /**
     * @param array{scope?: string, owner_id?: int} $filters
     * @return list<int>|null null = "Equipo completo", sin restricción por persona.
     */
    private function resolveTargetIds(User $user, array $filters): ?array
    {
        $canSeeTeam = $user->canViewTeamV2(ModuleCatalog::BODEGA);
        $scope      = $filters['scope'] ?? 'own';

        if ($scope !== 'team' || ! $canSeeTeam) {
            return [$user->id];
        }

        if (! isset($filters['owner_id'])) {
            return null;
        }

        $ownerId = (int) $filters['owner_id'];
        if (! $this->isBodegaTeamMember($ownerId)) {
            // Mismo guard que `OutlookCalendarController` (2026-07-21, hallazgo CRÍTICO de Pre-QA):
            // un owner_id fuera del equipo real cae al alcance propio del actor, nunca al del
            // objetivo pedido.
            return [$user->id];
        }

        return [$ownerId];
    }

    private function isBodegaTeamMember(int $userId): bool
    {
        return User::whereIn('role_id', $this->bodegaRoleIds())->where('id', $userId)->exists();
    }

    /**
     * "Equipo de Bodega" = los 4 `role_key` de negocio de `BodegaRoles::ALL` — ver docblock de
     * esa constante para por qué esto NO se deriva de `RoleModuleVisibility(module: bodega)`
     * (esa tabla también incluye a Gerencia, visibilidad transversal a los 7 módulos).
     *
     * @return Collection<int, int>
     */
    private function bodegaRoleIds(): Collection
    {
        return Role::whereIn('key', BodegaRoles::ALL)->pluck('id');
    }

    // ── Helpers de query compartidos por los paneles con scope ──────────────

    /**
     * "Mío" = el usuario objetivo aparece como asistente, picker o repartidor del pedido — ver
     * docblock de clase para la justificación de esta interpretación.
     *
     * @param list<int>|null $targetIds
     * @return Builder<Order>
     */
    private function scopedOrdersQuery(?array $targetIds): Builder
    {
        $query = Order::query()->whereIn('stage', Order::STAGE_ORDER);

        if ($targetIds !== null) {
            $query->where(function (Builder $q) use ($targetIds): void {
                $q->whereIn('assistant_id', $targetIds)
                    ->orWhereIn('picker_id', $targetIds)
                    ->orWhereIn('courier_id', $targetIds);
            });
        }

        return $query;
    }

    /**
     * @param Builder<OrderStageHistory> $query
     * @param list<int>|null             $targetIds
     */
    private function scopeStageHistoryToOrders(Builder $query, ?array $targetIds): void
    {
        if ($targetIds === null) {
            return;
        }

        $query->whereHas('order', function (Builder $q) use ($targetIds): void {
            $q->where(function (Builder $qq) use ($targetIds): void {
                $qq->whereIn('assistant_id', $targetIds)
                    ->orWhereIn('picker_id', $targetIds)
                    ->orWhereIn('courier_id', $targetIds);
            });
        });
    }

    /**
     * @param list<int>|null $targetIds
     */
    private function ordersEnteringStageCount(string $stage, CarbonInterface $since, ?array $targetIds): int
    {
        $query = OrderStageHistory::query()
            ->where('to_stage', $stage)
            ->where('created_at', '>=', $since);

        $this->scopeStageHistoryToOrders($query, $targetIds);

        // Un pedido puede re-entrar a la misma etapa más de una vez dentro de la ventana (ej.
        // Revisión de Inventario que vuelve a Asignado) — cuenta como UN solo pedido, no una fila
        // por transición.
        return $query->pluck('order_id')->unique()->count();
    }

    /**
     * Última vez (mayor `created_at`) que cada pedido de `$orderIds` entró a `$stage` — una sola
     * query batcheada (mismo criterio de `OrderBoardService::bulkSupplierEtaByProduct()`), nunca
     * una consulta de historial por pedido.
     *
     * @param list<int> $orderIds
     * @return Collection<int, Carbon>
     */
    private function latestStageEntry(array $orderIds, string $stage): Collection
    {
        if ($orderIds === []) {
            return collect();
        }

        /** @var Collection<int, OrderStageHistory> $history */
        $history = OrderStageHistory::whereIn('order_id', $orderIds)
            ->where('to_stage', $stage)
            ->orderByDesc('created_at')
            ->get();

        return $history->groupBy('order_id')->map(function (Collection $g): Carbon {
            /** @var OrderStageHistory $first */
            $first = $g->first();

            return $first->created_at;
        });
    }
}
