<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * Batch 2 (REQ-222 RN4) — tabla key/value de umbrales configurables de Servicios, mismo patrón
 * que `App\Modules\Bodega\Models\BodegaSetting`.
 *
 * @property int         $id
 * @property string      $key
 * @property string|null $value
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class ServiciosSetting extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'servicios_settings';
        }

        return "{$tenant->slug}_servicios.servicios_settings";
    }
}
