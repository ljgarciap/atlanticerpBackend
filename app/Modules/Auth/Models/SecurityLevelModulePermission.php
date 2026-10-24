<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-006, Decisión 4 — "Crear-Editar"/"Aprobar" del ticket SCRUM-145. "Ver" no vive acá, vive
 * en RoleModuleVisibility (separación deliberada rol=alcance / nivel=capacidad).
 *
 * @property int    $id
 * @property int    $security_level_id
 * @property string $module
 * @property bool   $can_edit
 * @property bool   $can_approve
 */
class SecurityLevelModulePermission extends Model
{
    use LogsAuditTrail;

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['security_level_id', 'module', 'can_edit', 'can_approve'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'can_edit'    => 'boolean',
            'can_approve' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        return $tenant === null
            ? 'security_level_module_permissions'
            : "{$tenant->slug}_auth.security_level_module_permissions";
    }

    /** @return BelongsTo<SecurityLevel, $this> */
    public function securityLevel(): BelongsTo
    {
        return $this->belongsTo(SecurityLevel::class);
    }
}
