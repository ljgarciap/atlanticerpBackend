<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Batch 14 (SCRUM-575→579, REQ-498→502) — tramo paramétrico de comisión escalonada interna.
 * Ver docs/adr/ADR-SCRUM575-579-batch14-comisiones-internas.md.
 *
 * @property int        $id
 * @property float       $monto_minimo
 * @property float|null   $monto_maximo
 * @property float        $porcentaje
 * @property int          $orden
 */
class CommissionTier extends Model
{
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
            return 'commission_tiers';
        }

        return "{$tenant->slug}_admin_contab.commission_tiers";
    }
}
