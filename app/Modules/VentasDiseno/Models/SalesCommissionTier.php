<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-944/945 — tramo paramétrico de la comisión escalonada de vendedores (antes hardcodeada en
 * `SalesTotalsService::percentFor()`, ver docblock de la migración). Mismo shape que
 * `App\Modules\AdminCont\Models\CommissionTier` — tabla separada a propósito, otro concepto de
 * negocio.
 *
 * @property int $id
 * @property float $monto_minimo
 * @property float|null $monto_maximo
 * @property float $porcentaje
 * @property int $orden
 */
class SalesCommissionTier extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['monto_minimo', 'monto_maximo', 'porcentaje', 'orden'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'monto_minimo' => 'float',
            'monto_maximo' => 'float',
            'porcentaje' => 'float',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'sales_commission_tiers';
        }

        return "{$tenant->slug}_ventas_diseno.sales_commission_tiers";
    }
}
