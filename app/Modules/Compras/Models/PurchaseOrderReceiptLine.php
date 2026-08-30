<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-220→236 (REQ-157→173) — línea de un ingreso de mercancía. Ver docblock de la migración
 * para `confirmed_at` (NULL = "Por ingresar", REQ-172/173) y `itbms_amount` (REQ-164, solo
 * proveedor local).
 *
 * @property int         $id
 * @property int         $purchase_order_receipt_id
 * @property int         $catalog_product_id
 * @property int         $quantity
 * @property float       $unit_cost
 * @property float|null  $itbms_amount
 * @property float|null  $import_cost_share
 * @property float|null  $freight_cost_share
 * @property float|null  $handling_cost_share
 * @property float|null  $other_cost_share
 * @property int         $warehouse_id
 * @property \Carbon\Carbon|null $confirmed_at
 * @property \Carbon\Carbon|null $bodega_confirmed_at
 * @property int|null    $bodega_confirmed_quantity
 * @property int|null    $bodega_confirmed_by
 */
class PurchaseOrderReceiptLine extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'purchase_order_receipt_id', 'catalog_product_id', 'quantity', 'unit_cost',
        'itbms_amount', 'import_cost_share', 'freight_cost_share', 'handling_cost_share',
        'other_cost_share', 'warehouse_id', 'confirmed_at',
        'bodega_confirmed_at', 'bodega_confirmed_quantity', 'bodega_confirmed_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity'                  => 'integer',
            'unit_cost'                 => 'float',
            'itbms_amount'              => 'float',
            'import_cost_share'         => 'float',
            'freight_cost_share'        => 'float',
            'handling_cost_share'       => 'float',
            'other_cost_share'          => 'float',
            'confirmed_at'              => 'datetime',
            'bodega_confirmed_at'       => 'datetime',
            'bodega_confirmed_quantity' => 'integer',
        ];
    }

    /**
     * REQ-164 Escenario 1 (fix Pre-QA 2026-07-20) — costo total real de la línea, incluyendo el
     * ITBMS local o el prorrateo no-local ya persistido por componente, para exponerlo como una
     * sola cifra observable en el detalle del ingreso sin duplicar la fórmula en el controller.
     */
    public function totalCost(): float
    {
        return round(
            $this->unit_cost * $this->quantity
                + ($this->itbms_amount ?? 0)
                + ($this->import_cost_share ?? 0)
                + ($this->freight_cost_share ?? 0)
                + ($this->handling_cost_share ?? 0)
                + ($this->other_cost_share ?? 0),
            2
        );
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'purchase_order_receipt_lines';
        }

        return "{$tenant->slug}_compras.purchase_order_receipt_lines";
    }

    /** @return BelongsTo<PurchaseOrderReceipt, $this> */
    public function receipt(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderReceipt::class, 'purchase_order_receipt_id');
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * REQ-427 rebote (2026-08-13) — quién de Bodega confirmó la llegada física de esta línea.
     *
     * @return BelongsTo<User, $this>
     */
    public function bodegaConfirmedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'bodega_confirmed_by');
    }

    public function isPending(): bool
    {
        return $this->confirmed_at === null;
    }

    /** REQ-357 RN2 — Bodega ya reportó la llegada física, todavía sin que Compras la finalice. */
    public function isBodegaConfirmed(): bool
    {
        return $this->bodega_confirmed_at !== null;
    }

    /** REQ-357 RN1/RN2 — Bodega puede reportar esta línea: sigue "Por ingresar" y Bodega no la reportó todavía. */
    public function needsBodegaConfirmation(): bool
    {
        return $this->isPending() && ! $this->isBodegaConfirmed();
    }
}
