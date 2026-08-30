<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Models;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-750 — staging de ARTICULOS (solo activos, ver docblock de la migración). Llave natural de
 * ICG: `icg_codarticulo` (UNIQUE) — el `Loader` de staging (`ProductoStagingLoader`) hace upsert
 * por esa columna, nunca por `id`.
 *
 * @property int         $id
 * @property int         $icg_codarticulo
 * @property string      $descripcion
 * @property string|null $familia_nombre
 * @property string|null $marca_nombre
 * @property string|null $ref_proveedor
 * @property bool         $descatalogado
 * @property int|null    $catalog_product_id
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read CatalogProduct|null $catalogProduct
 */
class Producto extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'icg_codarticulo', 'descripcion', 'familia_nombre', 'marca_nombre', 'ref_proveedor',
        'descatalogado', 'catalog_product_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'descatalogado' => 'boolean',
        ];
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'productos';
        }

        return "{$tenant->slug}_legacy.productos";
    }
}
