<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de `BodegaZonaLibreRequest` — `unit_cost_snapshot` congela el costo del producto al
 * momento de armar la orden (mismo criterio que `PurchaseOrderLine`/`QuoteItem`: nunca una
 * referencia en vivo a `catalog_products.cost`, que puede cambiar después).
 *
 * @property int   $id
 * @property int   $bodega_zona_libre_request_id
 * @property int   $catalog_product_id
 * @property int   $quantity
 * @property float $unit_cost_snapshot
 */
class BodegaZonaLibreRequestLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'bodega_zona_libre_request_id', 'catalog_product_id', 'quantity', 'unit_cost_snapshot',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['unit_cost_snapshot' => 'float'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'bodega_zona_libre_request_lines';
        }

        return "{$tenant->slug}_compras.bodega_zona_libre_request_lines";
    }

    /** @return BelongsTo<BodegaZonaLibreRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(BodegaZonaLibreRequest::class, 'bodega_zona_libre_request_id');
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    public function subtotal(): float
    {
        return $this->unit_cost_snapshot * $this->quantity;
    }
}
