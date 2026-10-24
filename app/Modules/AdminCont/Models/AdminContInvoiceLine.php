<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-929 (B1b) — una línea de lo facturado. Ver docblock de la migración `create_admin_contab_
 * invoice_lines_table`. Inmutable a propósito (sin `updated_at`): corregir una factura es anularla y
 * emitir otra, nunca editar sus líneas.
 *
 * @property int         $id
 * @property int         $invoice_id
 * @property int|null    $quote_item_id
 * @property int|null    $order_item_id
 * @property string      $descripcion
 * @property float       $cantidad
 * @property float       $precio_unitario
 * @property float       $subtotal
 * @property int|null    $itbms_rate_id
 * @property float       $itbms
 * @property float       $total
 * @property \Carbon\Carbon $created_at
 * @property-read AdminContInvoice $invoice
 */
class AdminContInvoiceLine extends Model
{
    use LogsAuditTrail;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'invoice_id', 'quote_item_id', 'order_item_id', 'descripcion', 'cantidad', 'precio_unitario',
        'subtotal', 'itbms_rate_id', 'itbms', 'total',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad'        => 'float',
            'precio_unitario' => 'float',
            'subtotal'        => 'float',
            'itbms'           => 'float',
            'total'           => 'float',
            'created_at'      => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'invoice_lines';
        }

        return "{$tenant->slug}_admin_contab.invoice_lines";
    }

    /** @return BelongsTo<AdminContInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(AdminContInvoice::class, 'invoice_id');
    }

    /** SCRUM-929 (B3b Punto 3) — tasa de ITBMS de ESTA línea (`FiscalDocumentItemsBuilder`).
     * @return BelongsTo<AdminContItbmsRate, $this> */
    public function itbmsRate(): BelongsTo
    {
        return $this->belongsTo(AdminContItbmsRate::class, 'itbms_rate_id');
    }

    /** SCRUM-929 (B3b Punto 3) — fuente del `codigo` (`QuoteItem::reference`) para el PAC.
     * @return BelongsTo<QuoteItem, $this> */
    public function quoteItem(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class, 'quote_item_id');
    }
}
