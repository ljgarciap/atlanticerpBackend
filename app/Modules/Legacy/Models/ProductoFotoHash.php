<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-751 — mecanismo de dedup por hash (ver docblock de la migración). Upsert por `hash`
 * (UNIQUE) en `Services\CatalogPhotoLoader` — un hash ya visto (misma corrida o una anterior)
 * reusa `s3_key` sin volver a subir el JPEG a S3.
 *
 * @property int         $id
 * @property string      $hash
 * @property string      $s3_key
 * @property int|null    $catalog_product_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read CatalogProduct|null $catalogProduct
 */
class ProductoFotoHash extends Model
{
    /** @var list<string> */
    protected $fillable = ['hash', 's3_key', 'catalog_product_id'];

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'producto_foto_hashes';
        }

        return "{$tenant->slug}_legacy.producto_foto_hashes";
    }
}
