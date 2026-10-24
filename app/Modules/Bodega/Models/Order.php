<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Models\User;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-305 a 341) — pedido de Bodega: unidad central del tablero
 * Kanban "Pedidos". Nace cuando Gerencia o Contabilidad ejecutan "Pasar a Pedido" sobre un proyecto Aprobado del Pipeline
 * de Ventas & Diseño (REQ-308, SCRUM-1128) — ver `App\Modules\VentasDiseno\Services\PipelineService::passToOrder()` +
 * `App\Modules\Bodega\Services\OrderService`, ya asignado al Asistente de Bodega correcto
 * (REQ-307, ver `WarehouseAssistantMapping`).
 *
 * `stage` — una de las 7 etapas fijas del flujo Kanban (REQ-302/311, confirmadas contra los
 * tickets reales de Jira), en orden: asignado -> picking_pendiente -> en_picking -> packing ->
 * por_despachar -> despachado -> entregado. Ver `Order::STAGE_ORDER`.
 *
 * `family_id`/`sequence_in_family`/`total_in_family` — REQ-319 (entregas parciales): cuando un
 * pedido se divide en varias tarjetas, todas comparten el mismo `order_number` (HU-335) pero cada
 * una es una fila `orders` distinta, enlazada por `family_id` a la tarjeta raíz (que tiene
 * `family_id = null`).
 *
 * `invoice_ready` (REQ-327/328) — stub aprobado por Luis mientras no exista un módulo de
 * Administración/Contabilidad real.
 *
 * @property int                  $id
 * @property string               $order_number
 * @property int|null             $pipeline_card_id
 * @property string               $order_type
 * @property string               $stage
 * @property int|null             $family_id
 * @property int|null             $sequence_in_family
 * @property int|null             $total_in_family
 * @property int|null             $assistant_id
 * @property int|null             $picker_id
 * @property int|null             $courier_id
 * @property \Carbon\Carbon|null  $committed_delivery_date
 * @property string|null          $customer_name
 * @property string|null          $customer_contact
 * @property string|null          $delivery_address
 * @property bool                 $invoice_ready
 * @property string              $origin            'app' | 'icg_legacy' (pedido gemelo de ICG)
 * @property string|null          $icg_serie
 * @property int|null             $icg_numero
 * @property string|null          $icg_facturado     lo ya facturado en ICG (informativo)
 * @property string|null          $icg_total_neto    total de cabecera de ICG, con ITBMS
 * @property string|null          $icg_hoja          hoja del Excel de Atlantic de la que salió el gemelo
 * @property string|null          $icg_vendedor      vendedor que consigna el Excel (el gemelo no tiene tarjeta)
 * @property int|null             $quote_id          cotización gemela (solo gemelos)
 * @property int|null             $sub_client_id     cliente/receptor fiscal (solo gemelos)
 * @property \Carbon\Carbon       $created_at
 * @property \Carbon\Carbon       $updated_at
 * @property-read PipelineCard|null $pipelineCard
 * @property-read User|null       $assistant
 * @property-read User|null       $picker
 * @property-read User|null       $courier
 * @property-read Order|null      $family
 * @property-read Collection<int, Order> $familyMembers
 * @property-read Collection<int, OrderItem> $items
 * @property-read Collection<int, OrderStageHistory> $stageHistory
 * @property-read Collection<int, OrderDocument> $documents
 */
class Order extends Model
{
    use LogsAuditTrail;

    /** Pedido nacido de una cotización aprobada del Pipeline (todos los anteriores a los pedidos gemelos). */
    public const ORIGIN_APP = 'app';

    /** Gemelo de un pedido de ICG (cierre de ICG, 05/10/2026): sin tarjeta de pipeline, ver `quote()`/`subClient()`. */
    public const ORIGIN_ICG_LEGACY = 'icg_legacy';

    public const ORDER_TYPE_PEDIDO = 'pedido';

    public const ORDER_TYPE_REVISION_INVENTARIO = 'revision_inventario';

    public const ORDER_TYPE_FALTANTE = 'faltante';

    public const STAGE_ASIGNADO = 'asignado';

    public const STAGE_PICKING_PENDIENTE = 'picking_pendiente';

    public const STAGE_EN_PICKING = 'en_picking';

    public const STAGE_PACKING = 'packing';

    public const STAGE_POR_DESPACHAR = 'por_despachar';

    public const STAGE_DESPACHADO = 'despachado';

    public const STAGE_ENTREGADO = 'entregado';

    /** @var list<string> Orden fijo del flujo Kanban — REQ-311 (verificado contra Jira 2026-07-22). */
    public const STAGE_ORDER = [
        self::STAGE_ASIGNADO,
        self::STAGE_PICKING_PENDIENTE,
        self::STAGE_EN_PICKING,
        self::STAGE_PACKING,
        self::STAGE_POR_DESPACHAR,
        self::STAGE_DESPACHADO,
        self::STAGE_ENTREGADO,
    ];

    /** @var list<string> */
    protected $fillable = [
        'order_number', 'pipeline_card_id', 'order_type', 'stage',
        'family_id', 'sequence_in_family', 'total_in_family',
        'assistant_id', 'picker_id', 'courier_id', 'committed_delivery_date',
        'customer_name', 'customer_contact', 'delivery_address', 'invoice_ready',
        'origin', 'icg_serie', 'icg_numero', 'icg_facturado', 'icg_total_neto', 'icg_hoja', 'icg_vendedor', 'quote_id', 'sub_client_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'committed_delivery_date' => 'date',
            'invoice_ready'           => 'boolean',
            'icg_numero'              => 'integer',
            'icg_facturado'           => 'decimal:2',
            'icg_total_neto'          => 'decimal:2',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'orders';
        }

        return "{$tenant->slug}_bodega.orders";
    }

    /** @return BelongsTo<PipelineCard, $this> */
    public function pipelineCard(): BelongsTo
    {
        return $this->belongsTo(PipelineCard::class);
    }

    /** @return BelongsTo<User, $this> */
    public function assistant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assistant_id');
    }

    /** @return BelongsTo<User, $this> */
    public function picker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'picker_id');
    }

    /** @return BelongsTo<User, $this> */
    public function courier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'courier_id');
    }

    /** @return BelongsTo<Order, $this> */
    public function family(): BelongsTo
    {
        return $this->belongsTo(self::class, 'family_id');
    }

    /** @return HasMany<Order, $this> */
    public function familyMembers(): HasMany
    {
        return $this->hasMany(self::class, 'family_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<OrderStageHistory, $this> */
    public function stageHistory(): HasMany
    {
        return $this->hasMany(OrderStageHistory::class);
    }

    /** @return HasMany<OrderDocument, $this> */
    public function documents(): HasMany
    {
        return $this->hasMany(OrderDocument::class);
    }

    /** SCRUM-329 Oleada A / Batch A2 (REQ-319) — id de la tarjeta raíz de la familia, sea o no esta misma. */
    public function familyRootId(): int
    {
        return $this->family_id ?? $this->id;
    }

    /**
     * SCRUM-876 — la tarjeta RAÍZ de la familia como modelo (esta misma si ya es la raíz). Fallback
     * a `$this` si la raíz fue borrada (FK huérfana) — no debería pasar, se defiende igual.
     */
    public function familyRoot(): self
    {
        return $this->family_id === null ? $this : ($this->family()->first() ?? $this);
    }

    /**
     * SCRUM-876 — ids de todos los miembros de la familia (raíz + hijas), incl. este. Un pedido de
     * entrega única devuelve solo su propio id.
     *
     * @return list<int>
     */
    public function familyMemberIds(): array
    {
        $rootId = $this->familyRootId();

        return array_values(
            self::query()->where('id', $rootId)->orWhere('family_id', $rootId)
                ->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
        );
    }

    /** REQ-310 RN3 — "Sin stock": tarjeta de faltante confirmado, esperando reabastecimiento. */
    public function isAwaitingStock(): bool
    {
        return $this->order_type === self::ORDER_TYPE_FALTANTE && $this->stage === self::STAGE_ASIGNADO;
    }

    /**
     * Batch 2 de Facturación (REQ-436→441) — la cotización de origen de esta entrega, si existe.
     * Mismo criterio que `Quote::linkedPipelineCard()`: no hay FK directa entre `Order` y `Quote`,
     * se resuelve por convención de negocio vía `sales_project_id` (1 cotización confirmada por
     * proyecto en la práctica, aunque el modelo de datos no lo fuerce con una constraint única).
     */
    public function linkedQuote(): ?Quote
    {
        // Pedido gemelo de ICG: no tiene tarjeta; su cotización gemela cuelga directo del pedido (oculta por defecto,
        // por eso se pide explícitamente).
        if ($this->isIcgLegacy()) {
            return $this->quote_id === null ? null : Quote::withIcgLegacy()->find($this->quote_id);
        }

        $card = $this->pipelineCard;
        if ($card === null || $card->sales_project_id === null) {
            return null;
        }

        return Quote::where('sales_project_id', $card->sales_project_id)
            ->whereNotNull('folio')
            ->latest('id')
            ->first();
    }

    /**
     * SubCliente fiscal del pedido: el vínculo propio en un gemelo de ICG (no tiene tarjeta), el de la tarjeta del pipeline en
     * el resto. Único punto de resolución del receptor fiscal.
     */
    public function fiscalSubClient(): ?SubClient
    {
        return $this->isIcgLegacy() ? $this->subClient : $this->pipelineCard?->subClient;
    }

    /** Cliente principal del pedido: el del SubCliente propio en un gemelo de ICG (sin tarjeta), el de la tarjeta del pipeline en el resto. */
    public function fiscalMasterClientId(): ?int
    {
        return $this->isIcgLegacy() ? $this->subClient?->master_client_id : $this->pipelineCard?->master_client_id;
    }

    /** @var array<string, bool> memoria por tenant de "la tabla ya tiene `origin`" (ventana de despliegue: código antes que migraciones) */
    private static array $originReady = [];

    private function tieneColumnaOrigin(): bool
    {
        $slug = (string) \App\Shared\Multitenancy\Tenant::current()?->slug;

        return self::$originReady[$slug] ??= \Illuminate\Support\Facades\Schema::hasColumn($this->getTable(), 'origin');
    }

    /**
     * Excluye los pedidos gemelos de ICG de un conteo o KPI (lo que no es operación real del día: p. ej. los 120 gemelos que
     * nacen `entregado` el día de la carga). Mientras la columna `origin` no exista no filtra nada, para no dar 500 entre el
     * despliegue del código y la migración.
     *
     * @param \Illuminate\Database\Eloquent\Builder<Order> $query
     * @return \Illuminate\Database\Eloquent\Builder<Order>
     */
    public function scopeSinGemelosDeIcg($query)
    {
        if (! $this->tieneColumnaOrigin()) {
            return $query;
        }

        return $query->where($this->qualifyColumn('origin'), '<>', self::ORIGIN_ICG_LEGACY);
    }

    /**
     * Tablero y paneles de Bodega: los gemelos de ICG que ya nacen `entregado` (los parciales, ya entregados en ICG) no son trabajo
     * de Bodega y no se muestran; los gemelos que Bodega todavía debe despachar (hoja «Completo») sí, en su etapa.
     *
     * @param \Illuminate\Database\Eloquent\Builder<Order> $query
     * @return \Illuminate\Database\Eloquent\Builder<Order>
     */
    public function scopeSinGemelosEntregados($query)
    {
        if (! $this->tieneColumnaOrigin()) {
            return $query;
        }

        return $query->where(function ($q): void {
            $q->where($this->qualifyColumn('origin'), '<>', self::ORIGIN_ICG_LEGACY)
                ->orWhere($this->qualifyColumn('stage'), '<>', self::STAGE_ENTREGADO);
        });
    }

    public function isIcgLegacy(): bool
    {
        return $this->origin === self::ORIGIN_ICG_LEGACY;
    }

    /** @return BelongsTo<Quote, $this> Solo pedidos gemelos: cotización gemela (oculta por defecto). */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class, 'quote_id')->withoutGlobalScope(\App\Modules\VentasDiseno\Models\Scopes\IcgLegacyOriginScope::class);
    }

    /** @return BelongsTo<SubClient, $this> Solo pedidos gemelos: cliente/receptor fiscal (sin tarjeta de pipeline). */
    public function subClient(): BelongsTo
    {
        return $this->belongsTo(SubClient::class, 'sub_client_id');
    }
}
