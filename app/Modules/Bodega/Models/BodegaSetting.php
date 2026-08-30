<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-329 Oleada A / Batch A1 — tabla key/value de umbrales configurables de Bodega (ej. días
 * para "urgente"/"atrasado", ver REQ-300/310). No hardcodear estos valores en código — regla
 * global del proyecto (ver CLAUDE.md § "Nunca"). Sin filas sembradas en este batch; los tickets
 * de Home/Reportes de una oleada posterior siembran/leen su propia key con un default razonable
 * si falta.
 *
 * @property int         $id
 * @property string      $key
 * @property string|null $value
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class BodegaSetting extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'bodega_settings';
        }

        return "{$tenant->slug}_bodega.bodega_settings";
    }
}
