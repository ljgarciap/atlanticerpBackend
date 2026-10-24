<?php

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int    $user_id
 * @property string $role
 *
 * Composite PK (user_id, role) — no auto-increment id column exists.
 * $incrementing = false suppresses insertGetId(). Never call UserRole::find()
 * or updateOrCreate() — always access via the user relationship or DB::table().
 *
 * SCRUM-915 — LogsAuditTrail llama a getKey() para audit_logs.entity_id, que acá siempre da null
 * (no hay columna `id`) — no rompe nada (AuditService acepta entity_id nulo), pero la
 * identificación real de la fila queda en old_values/new_values (user_id + role), no en
 * entity_id.
 */
class UserRole extends Model
{
    use LogsAuditTrail;

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
