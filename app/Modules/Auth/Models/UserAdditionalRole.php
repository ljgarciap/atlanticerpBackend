<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * ADR-006, Decisión 1 revisada (2026-07-09) — roles adicionales (2do en adelante), exclusivos de
 * superadmin. `users.role_id` sigue siendo el rol base singular; esta tabla es la excepción.
 *
 * @property int $id
 * @property int $user_id
 * @property int $role_id
 */
class UserAdditionalRole extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'role_id'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        return $tenant === null ? 'user_additional_roles' : "{$tenant->slug}_auth.user_additional_roles";
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
