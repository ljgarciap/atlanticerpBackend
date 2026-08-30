<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-220→236 (REQ-157→173) — Ingreso de Mercancía. Ver docblock de la migración para el
 * porqué de los 4 campos de costeo del header (RN de REQ-164, proveedor no local) y por qué
 * REQ-167 ("corregir") edita este mismo registro in-place, sin versionado.
 *
 * @property int         $id
 * @property int         $purchase_order_id
 * @property string|null $invoice_storage_key
 * @property string|null $invoice_original_filename
 * @property numeric-string|null $import_cost_total
 * @property numeric-string|null $freight_total
 * @property numeric-string|null $handling_total
 * @property numeric-string|null $other_costs_total
 * @property int|null    $created_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class PurchaseOrderReceipt extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'purchase_order_id', 'invoice_storage_key', 'invoice_original_filename',
        'import_cost_total', 'freight_total', 'handling_total', 'other_costs_total', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'import_cost_total' => 'decimal:2',
            'freight_total'      => 'decimal:2',
            'handling_total'     => 'decimal:2',
            'other_costs_total'  => 'decimal:2',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'purchase_order_receipts';
        }

        return "{$tenant->slug}_compras.purchase_order_receipts";
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return HasMany<PurchaseOrderReceiptLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(PurchaseOrderReceiptLine::class);
    }

    /** REQ-164 RN — el prorrateo de gastos de envío (no local) solo aplica si hay algún total capturado. */
    public function hasShippingCosts(): bool
    {
        return $this->import_cost_total !== null || $this->freight_total !== null
            || $this->handling_total !== null || $this->other_costs_total !== null;
    }

    public function totalShippingCost(): float
    {
        return (float) $this->import_cost_total + (float) $this->freight_total
            + (float) $this->handling_total + (float) $this->other_costs_total;
    }
}
