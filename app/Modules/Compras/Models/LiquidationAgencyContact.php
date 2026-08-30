<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-255 (REQ-192) — contacto de una agencia de liquidación. Relación 1:N con
 * LiquidationAgency; RN2 ("no se puede quedar sin ningún contacto") se aplica en el controller.
 *
 * @property int    $id
 * @property int    $liquidation_agency_id
 * @property string $name
 * @property string|null $phone
 * @property string|null $email
 */
class LiquidationAgencyContact extends Model
{
    /** @var list<string> */
    protected $fillable = ['liquidation_agency_id', 'name', 'phone', 'email'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'liquidation_agency_contacts';
        }

        return "{$tenant->slug}_compras.liquidation_agency_contacts";
    }

    /** @return BelongsTo<LiquidationAgency, $this> */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(LiquidationAgency::class, 'liquidation_agency_id');
    }
}
