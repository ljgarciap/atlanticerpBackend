<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-130/132/133 — `sales_project_id` nullable = "Ninguno (stock)" (REQ-133), asignación
 * independiente por línea, nunca heredada de la orden completa. Sin trait de auditoría propio:
 * las líneas se auditan como parte del alta de la orden completa (mismo criterio que
 * quote_items dentro de Quote), no como entidad independiente.
 *
 * @property int         $id
 * @property int         $purchase_order_id
 * @property int|null    $catalog_product_id
 * @property int         $quantity
 * @property float       $unit_cost
 * @property float       $subtotal
 * @property float|null  $additional_cost_percent
 * @property float|null  $additional_cost_amount
 * @property string|null $additional_cost_type
 * @property int|null    $sales_project_id
 * @property string|null $stock_state_at_order
 * @property bool        $is_adjustment    Fase 13 — línea de ajuste del cambio de monto aprobado (sin producto)
 * @property string|null $adjustment_note
 */
class PurchaseOrderLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'purchase_order_id', 'catalog_product_id', 'quantity', 'unit_cost', 'subtotal',
        'additional_cost_percent', 'additional_cost_amount', 'additional_cost_type',
        'sales_project_id', 'stock_state_at_order', 'is_adjustment', 'adjustment_note',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity'                 => 'integer',
            'is_adjustment'            => 'boolean',
            'unit_cost'                => 'float',
            'subtotal'                 => 'float',
            'additional_cost_percent'  => 'float',
            'additional_cost_amount'   => 'float',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'purchase_order_lines';
        }

        return "{$tenant->slug}_compras.purchase_order_lines";
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /** @return BelongsTo<SalesProject, $this> */
    public function salesProject(): BelongsTo
    {
        return $this->belongsTo(SalesProject::class);
    }
}
