<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-273/275, SCRUM-343/345) — qué producto del Catálogo
 * trackea la Reserva Servicios + su mínimo LOCAL configurable. Ver docblock de la migración de
 * creación para por qué esto es una tabla propia y NO `catalog_products.reorder_point`.
 *
 * "Disponible" para este insumo nunca vive acá — se lee en vivo de
 * `bodega.product_warehouse_stock` en la bodega "Reserva Servicios" (única fuente de verdad, ver
 * `InsumoService::list()`).
 *
 * @property int         $id
 * @property int         $catalog_product_id
 * @property int         $minimo
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read CatalogProduct|null $catalogProduct
 */
class InsumoSetting extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['catalog_product_id', 'minimo'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['minimo' => 'integer'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'insumo_settings';
        }

        return "{$tenant->slug}_servicios.insumo_settings";
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }
}
