<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 2 de Facturación (SCRUM-513→518, REQ-436→441). Ver docblock de la migración
 * `create_admin_contab_invoices_table` para el detalle de `order_id`/`es_incobrable`.
 *
 * @property int         $id
 * @property string       $numero
 * @property int           $order_id
 * @property int|null      $quote_id
 * @property int|null      $itbms_rate_id
 * @property int|null      $sales_project_id
 * @property int|null      $master_client_id
 * @property string        $cliente
 * @property float          $monto
 * @property float           $saldo_aplicado
 * @property bool            $es_incobrable
 * @property string|null    $motivo_incobrable
 * @property bool            $incobrable_pendiente
 * @property \Carbon\Carbon|null $anulada_at
 * @property int|null      $created_by
 * @property \Carbon\Carbon $created_at
 * @property-read AdminContItbmsRate|null $itbmsRate
 */
class AdminContInvoice extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'numero', 'order_id', 'quote_id', 'itbms_rate_id', 'sales_project_id', 'master_client_id',
        'cliente', 'monto', 'saldo_aplicado', 'es_incobrable', 'motivo_incobrable',
        'incobrable_pendiente', 'anulada_at', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'monto'          => 'float',
            'saldo_aplicado' => 'float',
            'es_incobrable'  => 'boolean',
            'incobrable_pendiente' => 'boolean',
            'anulada_at'     => 'datetime',
            'created_at'     => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'invoices';
        }

        return "{$tenant->slug}_admin_contab.invoices";
    }

    /**
     * Batch 10 (REQ-480) — tasa de ITBMS aplicada al facturar.
     * @return BelongsTo<AdminContItbmsRate, $this>
     */
    public function itbmsRate(): BelongsTo
    {
        return $this->belongsTo(AdminContItbmsRate::class, 'itbms_rate_id');
    }
}
