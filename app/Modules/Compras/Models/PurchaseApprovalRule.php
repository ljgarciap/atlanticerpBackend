<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * Fase 14a — regla que decide si una orden de compra necesita la aprobación del aprobador primario. Las condiciones vacías (null) coinciden
 * con cualquier valor; `min_amount`/`max_amount` se comparan con el total a pagar al proveedor (`PurchaseOrder::payableTotal()`), ambos
 * límites inclusivos. Ver `PurchaseApprovalRules` para la evaluación (primera regla activa que coincide).
 *
 * @property int $id
 * @property string $name
 * @property int $sort_order
 * @property bool $is_active
 * @property string|null $shipping_type
 * @property string|null $who_pays_shipping
 * @property numeric-string|null $min_amount
 * @property numeric-string|null $max_amount
 * @property bool $requires_approval
 */
class PurchaseApprovalRule extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = [
        'name', 'sort_order', 'is_active', 'shipping_type', 'who_pays_shipping', 'min_amount', 'max_amount', 'requires_approval',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'sort_order' => 'integer',
        'is_active' => 'boolean',
        'min_amount' => 'decimal:2',
        'max_amount' => 'decimal:2',
        'requires_approval' => 'boolean',
    ];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'purchase_approval_rules';
        }

        return "{$tenant->slug}_compras.purchase_approval_rules";
    }

    public function matches(?string $shippingType, ?string $whoPaysShipping, float $amount): bool
    {
        if ($this->shipping_type !== null && $this->shipping_type !== $shippingType) {
            return false;
        }
        if ($this->who_pays_shipping !== null && $this->who_pays_shipping !== $whoPaysShipping) {
            return false;
        }
        if ($this->min_amount !== null && $amount < (float) $this->min_amount) {
            return false;
        }

        return $this->max_amount === null || $amount <= (float) $this->max_amount;
    }
}
