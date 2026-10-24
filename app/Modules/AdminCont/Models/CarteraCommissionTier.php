<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * Batch 22 (SCRUM-643→647, REQ-566→570) — tramo paramétrico de la comisión de Felix por gestión
 * de cartera vencida +90 días. Ver docblock de la migración de creación para el porqué de los
 * límites exactos y de esta tabla separada de `CommissionTier` (comisión interna, otro concepto).
 *
 * @property int        $id
 * @property float       $monto_minimo
 * @property float|null   $monto_maximo
 * @property float        $porcentaje
 * @property int          $orden
 */
class CarteraCommissionTier extends Model
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
            'porcentaje'   => 'float',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'cartera_commission_tiers';
        }

        return "{$tenant->slug}_admin_contab.cartera_commission_tiers";
    }
}
