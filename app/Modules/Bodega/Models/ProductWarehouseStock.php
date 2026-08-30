<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-244 (REQ-181) — desglose de stock por bodega. Sin fila para un producto en una bodega =
 * 0 unidades ahí (ver docblock de la migración). `ubicacion`/`cliente_id` (SCRUM-455/456,
 * REQ-385/386) agregados 2026-07-21 — única fuente para Compras (Ver Inventario) y Bodega.
 *
 * @property int         $id
 * @property int         $catalog_product_id
 * @property int         $warehouse_id
 * @property string|null $ubicacion
 * @property int|null    $cliente_id
 * @property int         $quantity
 */
class ProductWarehouseStock extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['catalog_product_id', 'warehouse_id', 'ubicacion', 'cliente_id', 'quantity'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['quantity' => 'integer'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'product_warehouse_stock';
        }

        return "{$tenant->slug}_bodega.product_warehouse_stock";
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /** @return BelongsTo<MasterClient, $this> */
    public function cliente(): BelongsTo
    {
        return $this->belongsTo(MasterClient::class, 'cliente_id');
    }
}
