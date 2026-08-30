<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int         $id
 * @property int         $master_client_id
 * @property string      $business_name
 * @property string      $tax_id
 * @property string|null $delivery_address
 * @property string|null $category
 * @property MasterClient $masterClient
 * @property \Illuminate\Database\Eloquent\Collection<int, SubClientContact> $contacts
 */
class SubClient extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['master_client_id', 'business_name', 'tax_id', 'delivery_address', 'category'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'sub_clients';
        }

        return "{$tenant->slug}_ventas_diseno.sub_clients";
    }

    /** @return BelongsTo<MasterClient, $this> */
    public function masterClient(): BelongsTo
    {
        return $this->belongsTo(MasterClient::class);
    }

    /** @return HasMany<SalesProject, $this> */
    public function salesProjects(): HasMany
    {
        return $this->hasMany(SalesProject::class);
    }

    /** @return HasMany<SubClientContact, $this> */
    public function contacts(): HasMany
    {
        return $this->hasMany(SubClientContact::class);
    }
}
