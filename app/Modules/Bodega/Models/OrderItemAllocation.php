<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-1196 — reparto de UNA línea de pedido entre bodegas (10 de Bodega Central + 1 de Sala de exhibición 1). `order_items.warehouse_id / qty_reserved /
 * qty_picked` siguen siendo el TOTAL y la bodega principal; esta tabla es el detalle por bodega que usan la reserva, el picking y el Kardex del despacho.
 *
 * @property int  $id
 * @property int  $order_item_id
 * @property int  $warehouse_id
 * @property int  $qty_reserved
 * @property int  $qty_picked
 * @property bool $manual true = la eligió Bodega en el picking; false = reserva automática en la bodega de despacho por defecto.
 * @property-read OrderItem $orderItem
 * @property-read Warehouse $warehouse
 */
class OrderItemAllocation extends Model
{
    /** @var list<string> */
    protected $fillable = ['order_item_id', 'warehouse_id', 'qty_reserved', 'qty_picked', 'manual'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['qty_reserved' => 'integer', 'qty_picked' => 'integer', 'manual' => 'boolean'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'order_item_allocations';
        }

        return "{$tenant->slug}_bodega.order_item_allocations";
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }
}
