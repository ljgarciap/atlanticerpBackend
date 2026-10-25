<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * ADR-006, Decisión 1 — reemplaza únicamente al enum de `user_roles`, NO a `security_levels`
 * (los dos ejes coexisten). Ver docs/adr/006-roles-permisos-scrum144-145-diseno.md.
 *
 * @property int         $id
 * @property string      $key
 * @property string      $name
 * @property bool         $is_superadmin
 * @property bool         $is_system
 * @property bool         $is_active
 * @property int|null    $default_security_level_id
 * @property Collection<int, RoleModuleVisibility> $moduleVisibility
 * @property SecurityLevel|null $defaultSecurityLevel
 */
class Role extends Model
{
    use LogsAuditTrail;

    /** Rol de sistema del registro público pendiente de aprobación (Fase 2, migración 2026_10_24_500000). Inactivo: no aparece en selectores. */
    public const KEY_GUEST = 'guest';

    public const KEY_SUPERADMIN = 'superadmin';

    /** @var list<string> */
    protected $fillable = ['key', 'name', 'is_superadmin', 'is_system', 'is_active', 'default_security_level_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_superadmin' => 'boolean',
            'is_system'     => 'boolean',
            'is_active'     => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        return $tenant === null ? 'roles' : "{$tenant->slug}_auth.roles";
    }

    /** @return HasMany<RoleModuleVisibility, $this> */
    public function moduleVisibility(): HasMany
    {
        return $this->hasMany(RoleModuleVisibility::class);
    }

    /** @return BelongsTo<SecurityLevel, $this> */
    public function defaultSecurityLevel(): BelongsTo
    {
        return $this->belongsTo(SecurityLevel::class, 'default_security_level_id');
    }
}
