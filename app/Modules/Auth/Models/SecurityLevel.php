<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int          $id
 * @property int          $level
 * @property string       $name
 * @property string|null  $description
 * @property list<string> $permissions
 * @property bool         $is_active
 */
class SecurityLevel extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['level', 'name', 'description', 'permissions', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'level'       => 'integer',
            'permissions' => 'array',
            'is_active'   => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        return $tenant === null ? 'security_levels' : "{$tenant->slug}_auth.security_levels";
    }
}
