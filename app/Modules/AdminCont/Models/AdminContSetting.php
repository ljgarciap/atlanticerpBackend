<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * REQ-635 (SCRUM-811) — tabla key/value de umbrales configurables de Admin&Cont (alertas de
 * fraude, ver `AdminContSettingsService`). No hardcodear estos valores en código — regla global
 * del proyecto (ver CLAUDE.md § "Nunca"). Mismo patrón exacto que `BodegaSetting`.
 *
 * @property int         $id
 * @property string      $key
 * @property string|null $value
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
class AdminContSetting extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['key', 'value'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'admin_contab_settings';
        }

        return "{$tenant->slug}_admin_contab.admin_contab_settings";
    }
}
