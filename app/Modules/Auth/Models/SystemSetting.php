<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string $key
 * @property mixed  $value
 * @property int|null $updated_by
 */
class SystemSetting extends Model
{
    use LogsAuditTrail;

    public $timestamps    = false;
    public $incrementing  = false;

    /** @var string */
    protected $primaryKey = 'key';

    /** @var string */
    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['key', 'value', 'updated_by', 'updated_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['value' => 'json'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        return $tenant === null ? 'system_settings' : "{$tenant->slug}_auth.system_settings";
    }
}
