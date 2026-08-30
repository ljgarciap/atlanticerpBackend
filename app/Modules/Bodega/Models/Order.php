<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Models\User;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\Quote;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-305 a 341) — pedido de Bodega: unidad central del tablero
 * Kanban "Pedidos". Nace automáticamente cuando una cotización pasa a "Aprobado" en el Pipeline
 * de Ventas & Diseño (REQ-308) — ver `App\Modules\Bodega\Listeners\CreateOrderFromApprovedQuote` +
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
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'committed_delivery_date' => 'date',
            'invoice_ready'           => 'boolean',
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
        $card = $this->pipelineCard;
        if ($card === null || $card->sales_project_id === null) {
            return null;
        }

        return Quote::where('sales_project_id', $card->sales_project_id)
            ->whereNotNull('folio')
            ->latest('id')
            ->first();
    }
}
