<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;

/**
 * @property string          $id           UUID — used as challenge_id sent to client
 * @property int             $user_id
 * @property string          $code_hash    SHA-256 hex
 * @property int             $attempts
 * @property \Carbon\Carbon  $expires_at
 * @property \Carbon\Carbon|null $used_at
 * @property \Carbon\Carbon  $created_at
 */
class MfaCode extends Model
{
    public $timestamps    = false;
    public $incrementing  = false;

    /** @var string */
    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = ['id', 'user_id', 'code_hash', 'attempts', 'expires_at', 'used_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'attempts'   => 'integer',
            'expires_at' => 'datetime',
            'used_at'    => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        return $tenant === null ? 'mfa_codes' : "{$tenant->slug}_auth.mfa_codes";
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    public function isUsed(): bool
    {
        return $this->used_at !== null;
    }

    public function isExhausted(): bool
    {
        return $this->attempts >= 5;
    }

    public function isValid(): bool
    {
        return ! $this->isExpired() && ! $this->isUsed() && ! $this->isExhausted();
    }
}
