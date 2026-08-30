<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-253 (REQ-190) RN2 — permanente, nunca editable ni borrable: sin `updated_at`
 * (`UPDATED_AT = null`), y `PurchaseOrderPaymentController` nunca expone update/destroy.
 *
 * @property int    $id
 * @property int    $purchase_order_id
 * @property float  $amount
 * @property string $payment_proof_storage_key
 * @property int|null $registered_by
 * @property \Carbon\Carbon $created_at
 */
class PurchaseOrderPayment extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['purchase_order_id', 'amount', 'payment_proof_storage_key', 'registered_by'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['amount' => 'float'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'purchase_order_payments';
        }

        return "{$tenant->slug}_compras.purchase_order_payments";
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function registeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'registered_by');
    }
}
