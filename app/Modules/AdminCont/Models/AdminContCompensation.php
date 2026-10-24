<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-1168 F3 — un cruce confirmado: dinero disponible de un cliente aplicado a sus facturas, con folio `CMP-nnn`.
 *
 * @property int            $id
 * @property int            $master_client_id
 * @property string         $folio
 * @property string         $estado
 * @property float          $monto_total
 * @property int|null       $created_by
 * @property \Carbon\Carbon $confirmed_at
 */
class AdminContCompensation extends Model
{
    use LogsAuditTrail;

    public const ESTADO_CONFIRMADO = 'confirmado';
    public const ESTADO_REVERSADO  = 'reversado';

    /** @var list<string> */
    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['monto_total' => 'float', 'confirmed_at' => 'datetime', 'reversed_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        return $tenant === null ? 'compensations' : "{$tenant->slug}_admin_contab.compensations";
    }

    /** @return HasMany<AdminContCompensationLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(AdminContCompensationLine::class, 'compensation_id')->orderBy('id');
    }
}
