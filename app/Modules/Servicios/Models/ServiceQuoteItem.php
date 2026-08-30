<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4 — Servicios, Batch 11 (REQ-230→232) — ítem de una Cotización de Servicio. Ver docblock de
 * la migración para el detalle de cada campo por tipo.
 *
 * @property int         $id
 * @property int         $service_quote_id
 * @property string      $tipo
 * @property int|null    $catalog_product_id
 * @property bool         $is_custom
 * @property int|null    $external_technician_id
 * @property string      $description
 * @property float        $quantity
 * @property float        $unit_price
 * @property float|null    $cost_reference
 * @property float|null    $margin_percent
 * @property float        $subtotal
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read ServiceQuote $serviceQuote
 * @property-read CatalogProduct|null $catalogProduct
 * @property-read ExternalTechnician|null $externalTechnician
 */
class ServiceQuoteItem extends Model
{
    use LogsAuditTrail;

    public const TIPO_PRODUCT = 'product';

    public const TIPO_LABOR = 'labor';

    public const TIPO_SUBCONTRACTED = 'subcontracted';

    /** @var list<string> */
    public const TIPOS = [self::TIPO_PRODUCT, self::TIPO_LABOR, self::TIPO_SUBCONTRACTED];

    /** @var list<string> */
    protected $fillable = [
        'service_quote_id', 'tipo', 'catalog_product_id', 'is_custom', 'external_technician_id',
        'description', 'quantity', 'unit_price', 'cost_reference', 'margin_percent', 'subtotal',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_custom'      => 'boolean',
            'quantity'       => 'float',
            'unit_price'     => 'float',
            'cost_reference' => 'float',
            'margin_percent' => 'float',
            'subtotal'       => 'float',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'service_quote_items';
        }

        return "{$tenant->slug}_servicios.service_quote_items";
    }

    /** @return BelongsTo<ServiceQuote, $this> */
    public function serviceQuote(): BelongsTo
    {
        return $this->belongsTo(ServiceQuote::class);
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /** @return BelongsTo<ExternalTechnician, $this> */
    public function externalTechnician(): BelongsTo
    {
        return $this->belongsTo(ExternalTechnician::class);
    }

    /**
     * REQ-230 RN — margen por unidad de una línea Producto contra costo de referencia; `null`
     * cuando no hay costo (líneas "Otro"/referencia libre, RN3 — nunca se valida margen ahí).
     */
    public function marginPercentAgainstCost(): ?float
    {
        if ($this->cost_reference === null || $this->unit_price <= 0) {
            return null;
        }

        return round((($this->unit_price - $this->cost_reference) / $this->unit_price) * 100, 2);
    }
}
