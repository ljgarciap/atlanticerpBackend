<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Models\User;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-428/429/446/447 (REQ-358/359/376/377) — solicitud de ajuste (contenedora). El estado
 * real vive en `AdjustmentRequestLine`, ver docblock de la migración.
 *
 * @property int             $id
 * @property int             $catalog_product_id
 * @property int             $solicitado_por
 * @property \Carbon\Carbon  $created_at
 * @property-read CatalogProduct|null $catalogProduct
 * @property-read User|null  $solicitante
 */
class AdjustmentRequest extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['catalog_product_id', 'solicitado_por'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'adjustment_requests';
        }

        return "{$tenant->slug}_bodega.adjustment_requests";
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /** @return BelongsTo<User, $this> */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    /** @return HasMany<AdjustmentRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AdjustmentRequestLine::class);
    }
}
