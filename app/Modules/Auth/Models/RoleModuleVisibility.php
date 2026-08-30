<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-006, Decisión 2. `can_view`: 0=none, 1=readonly (reservado, sin usar hasta Fase D),
 * 2=full. "Visible" en el algoritmo de resolución significa `can_view >= 2`.
 *
 * @property int    $id
 * @property int    $role_id
 * @property string $module
 * @property int    $can_view
 * @property bool   $can_view_team
 */
class RoleModuleVisibility extends Model
{
    public const VIEW_NONE     = 0;
    public const VIEW_READONLY = 1;
    public const VIEW_FULL     = 2;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['role_id', 'module', 'can_view', 'can_view_team'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'can_view'      => 'integer',
            'can_view_team' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        return $tenant === null ? 'role_module_visibility' : "{$tenant->slug}_auth.role_module_visibility";
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
