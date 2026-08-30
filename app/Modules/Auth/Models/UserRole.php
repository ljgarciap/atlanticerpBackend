<?php

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $user_id
 * @property string $role
 *
 * Composite PK (user_id, role) — no auto-increment id column exists.
 * $incrementing = false suppresses insertGetId(). Never call UserRole::find()
 * or updateOrCreate() — always access via the user relationship or DB::table().
 */
class UserRole extends Model
{
    public $timestamps   = false;
    public $incrementing = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'role'];

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return 'user_roles';
        }

        return "{$tenant->slug}_auth.user_roles";
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
