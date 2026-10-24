<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-1168 F4 — descuento sobre la comisión interna de un vendedor: reversar un cobro dejó sin cobrar un pedido cuya comisión ya se había liquidada.
 * La comisión pagada no se toca ni se recalcula el mes cerrado: el descuento se resta en la siguiente liquidación (`monto_aplicado` va acumulando lo ya
 * descontado; si una liquidación no alcanza, el resto queda para la siguiente).
 *
 * @property int         $id
 * @property int         $vendedor_id
 * @property int|null    $pedido_id         tarjeta de Pipeline del pedido (el `pedido_id` de las filas de comisión)
 * @property float       $monto
 * @property float       $monto_aplicado
 * @property string      $motivo
 * @property int|null    $source_movement_id
 * @property int|null    $applied_snapshot_id
 */
class AdminContCommissionAdjustment extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['monto' => 'float', 'monto_aplicado' => 'float', 'created_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'commission_adjustments' : "{$tenant->slug}_admin_contab.commission_adjustments";
    }

    public function pendiente(): float
    {
        return round($this->monto - $this->monto_aplicado, 2);
    }
}
