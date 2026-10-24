<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 5 de Admin&Cont (SCRUM-541, REQ-464 RN2) — pivote entre un pago y las facturas que cubre,
 * con el monto exacto aplicado a cada una (un pago puede repartirse entre varias facturas).
 *
 * @property int      $id
 * @property int      $payment_id
 * @property int      $invoice_id
 * @property float    $monto_aplicado
 * @property int|null $abono_id
 * @property float    $monto_abono_aplicado
 * @property \Carbon\Carbon|null $fecha_aplicacion
 */
class AdminContPaymentInvoice extends Model
{
    use LogsAuditTrail;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['payment_id', 'invoice_id', 'monto_aplicado', 'abono_id', 'monto_abono_aplicado', 'fecha_aplicacion'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['monto_aplicado' => 'float', 'monto_abono_aplicado' => 'float', 'fecha_aplicacion' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'payment_invoices';
        }

        return "{$tenant->slug}_admin_contab.payment_invoices";
    }

    /** @return BelongsTo<AdminContPayment, $this> */
    public function payment(): BelongsTo
    {
        return $this->belongsTo(AdminContPayment::class, 'payment_id');
    }

    /** @return BelongsTo<AdminContInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(AdminContInvoice::class, 'invoice_id');
    }
}
