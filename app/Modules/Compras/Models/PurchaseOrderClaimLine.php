<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-260 (REQ-197) — un producto afectado dentro de un reclamo.
 *
 * @property int    $id
 * @property int    $claim_id
 * @property int    $purchase_order_line_id
 * @property int    $affected_quantity
 * @property string|null $description
 */
class PurchaseOrderClaimLine extends Model
{
    /** @var list<string> */
    protected $fillable = ['claim_id', 'purchase_order_line_id', 'affected_quantity', 'description'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'claim_lines';
        }

        return "{$tenant->slug}_compras.claim_lines";
    }

    /** @return BelongsTo<PurchaseOrderClaim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderClaim::class, 'claim_id');
    }

    /** @return BelongsTo<PurchaseOrderLine, $this> */
    public function orderLine(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderLine::class, 'purchase_order_line_id');
    }

    /** REQ-196/197 — cantidad afectada × costo unitario real de la orden. 0 si la línea de la orden ya no existe. */
    public function subtotal(): float
    {
        $orderLine = $this->orderLine;

        return $orderLine === null ? 0.0 : $this->affected_quantity * $orderLine->unit_cost;
    }
}
