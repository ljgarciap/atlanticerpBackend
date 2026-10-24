<?php

declare(strict_types=1);

namespace App\Modules\CRM\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

/**
 * SCRUM-944/945 — tabla key/value de umbrales configurables de CRM (ventanas de días para
 * urgencia de entrega y frescura de actividad, ver `CrmSettingsService`). No hardcodear estos
 * valores en código — regla global del proyecto (ver CLAUDE.md § "Nunca"). Mismo patrón que
 * `App\Modules\Bodega\Models\BodegaSetting`.
 *
 * @property int $id
 * @property string $key
 * @property string|null $value
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class CrmSetting extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'crm_settings';
        }

        return "{$tenant->slug}_crm.crm_settings";
    }
}
