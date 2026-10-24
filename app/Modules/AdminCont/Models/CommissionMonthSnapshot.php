<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Batch 14 (SCRUM-575→579, REQ-502) — % y monto de comisión de un vendedor, fijados una sola vez
 * al cerrar su mes. Ver `App\Modules\AdminCont\Services\CommissionOrderService::snapshotFor()` y
 * docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md §2.
 *
 * @property int              $id
 * @property int              $vendedor_id
 * @property \Carbon\Carbon   $mes_cierre
 * @property float            $total_pedidos
 * @property float            $porcentaje
 * @property float            $monto_comision
 * @property User             $vendedor
 */
class CommissionMonthSnapshot extends Model
{
    use LogsAuditTrail;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = ['vendedor_id', 'mes_cierre', 'total_pedidos', 'porcentaje', 'monto_comision'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'mes_cierre'     => 'date',
            'total_pedidos'  => 'float',
            'porcentaje'     => 'float',
            'monto_comision' => 'float',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'commission_month_snapshots';
        }

        return "{$tenant->slug}_admin_contab.commission_month_snapshots";
    }

    /** @return BelongsTo<User, $this> */
    public function vendedor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'vendedor_id');
    }
}
