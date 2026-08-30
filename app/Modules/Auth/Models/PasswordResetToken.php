<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int             $id
 * @property string          $email
 * @property string          $token_hash   SHA-256 hex of raw token
 * @property \Carbon\Carbon  $expires_at
 * @property \Carbon\Carbon|null $used_at
 * @property \Carbon\Carbon  $created_at
 */
class PasswordResetToken extends Model
{
    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = ['email', 'token_hash', 'expires_at', 'used_at', 'created_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        return $tenant === null ? 'password_reset_tokens' : "{$tenant->slug}_auth.password_reset_tokens";
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }
}
