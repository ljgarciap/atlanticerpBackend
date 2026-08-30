<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-259 (REQ-196) — foto de evidencia de un reclamo.
 *
 * @property int    $id
 * @property int    $claim_id
 * @property string $storage_key
 * @property int|null $uploaded_by
 * @property \Carbon\Carbon $created_at
 */
class PurchaseOrderClaimPhoto extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['claim_id', 'storage_key', 'uploaded_by'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'claim_photos';
        }

        return "{$tenant->slug}_compras.claim_photos";
    }

    /** @return BelongsTo<PurchaseOrderClaim, $this> */
    public function claim(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderClaim::class, 'claim_id');
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
