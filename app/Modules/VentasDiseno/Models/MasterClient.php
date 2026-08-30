<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property string      $name
 * @property string|null $default_price_type
 */
class MasterClient extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['name', 'default_price_type'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'master_clients';
        }

        return "{$tenant->slug}_ventas_diseno.master_clients";
    }

    /** @return HasMany<SubClient, $this> */
    public function subClients(): HasMany
    {
        return $this->hasMany(SubClient::class);
    }

    /** @return HasMany<PipelineCard, $this> */
    public function pipelineCards(): HasMany
    {
        return $this->hasMany(PipelineCard::class);
    }
}
