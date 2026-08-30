<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-724 — override de visibilidad de módulo por usuario, mismo shape que
 * RoleModuleVisibility (ver esa clase para el significado de can_view). Solo
 * existe una fila acá cuando el valor pedido DIFIERE de lo que el rol ya
 * otorgaría — ver UserService::updateModuleVisibility().
 *
 * @property int    $id
 * @property int    $user_id
 * @property string $module
 * @property int    $can_view
 * @property bool   $can_view_team
 */
class UserModuleVisibility extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'module', 'can_view', 'can_view_team'];

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
        return $tenant === null ? 'user_module_visibility' : "{$tenant->slug}_auth.user_module_visibility";
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
