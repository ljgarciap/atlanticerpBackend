<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * REQ-037: agrupa productos del Catálogo que siempre se cotizan juntos (ej.
 * "Kit Baño"). N:M vía `catalog_product_family_items` — un producto puede
 * pertenecer a más de una familia. Sin pantalla de administración en esta
 * iteración, se siembra por seeder (mismo precedente que `CatalogProduct`).
 *
 * @property int         $id
 * @property string       $name
 * @property string|null $description
 * @property bool          $is_active
 */
class CatalogProductFamily extends Model
{
    /** @var list<string> */
    protected $fillable = ['name', 'description', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'catalog_product_families';
        }

        return "{$tenant->slug}_ventas_diseno.catalog_product_families";
    }

    /** @return BelongsToMany<CatalogProduct, $this> */
    public function products(): BelongsToMany
    {
        $tenant = Tenant::current();
        $pivot  = $tenant !== null
            ? "{$tenant->slug}_ventas_diseno.catalog_product_family_items"
            : 'catalog_product_family_items';

        return $this->belongsToMany(CatalogProduct::class, $pivot, 'family_id', 'catalog_product_id');
    }
}
