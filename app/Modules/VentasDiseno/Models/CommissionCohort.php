<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-073: cohorte mensual de comisión de un vendedor — ver
 * CommissionService::cohortFor() para cómo/cuándo se fija el porcentaje.
 *
 * @property int         $id
 * @property int         $owner_id
 * @property \Carbon\Carbon $month
 * @property float       $total_amount
 * @property float       $commission_percent
 * @property float       $commission_amount
 * @property \Carbon\Carbon|null $paid_at
 * @property User        $owner
 */
class CommissionCohort extends Model
{
    /** @var list<string> */
    protected $fillable = ['owner_id', 'month', 'total_amount', 'commission_percent', 'commission_amount', 'paid_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'month'               => 'date',
            'total_amount'        => 'float',
            'commission_percent'  => 'float',
            'commission_amount'   => 'float',
            'paid_at'             => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'commission_cohorts';
        }

        return "{$tenant->slug}_ventas_diseno.commission_cohorts";
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
