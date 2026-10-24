<?php

namespace App\Modules\Auth\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int    $id
 * @property string $name
 * @property bool   $is_active
 */
class Department extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['name', 'is_active'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return 'departments';
        }

        return "{$tenant->slug}_auth.departments";
    }
}
