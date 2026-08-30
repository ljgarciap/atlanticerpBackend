<?php

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * @property int $id
 * @property int $user_id
 * @property string $token_hash
 * @property \Illuminate\Support\Carbon $expires_at
 * @property bool $revoked
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon|null $last_activity_at
 * @property \Illuminate\Support\Carbon|null $login_at
 */
class RefreshToken extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['user_id', 'token_hash', 'expires_at', 'revoked', 'last_activity_at', 'login_at'];

    /** @var array<string, string> */
    protected $casts = [
        'expires_at' => 'datetime',
        'revoked' => 'boolean',
        'last_activity_at' => 'datetime',
        'login_at' => 'datetime',
    ];

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            // Larastan llama getTable() en tiempo de análisis sin tenant activo.
            // En runtime el tenant siempre está inicializado por AuthenticateJwt o tenant:create.
            return 'refresh_tokens';
        }

        return "{$tenant->slug}_auth.refresh_tokens";
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isValid(): bool
    {
        return ! $this->revoked && $this->expires_at->isFuture();
    }
}
