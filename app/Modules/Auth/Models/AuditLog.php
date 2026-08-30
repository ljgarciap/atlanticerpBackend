<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int             $id
 * @property int|null        $user_id
 * @property string          $action
 * @property string|null     $entity_type
 * @property string|null     $entity_id
 * @property array<string,mixed>|null $old_values
 * @property array<string,mixed>|null $new_values
 * @property string|null     $ip_address
 * @property string|null     $user_agent
 * @property string          $request_id
 * @property string|null     $http_method
 * @property string|null     $http_path
 * @property int|null        $http_status
 * @property int|null        $duration_ms
 * @property array<string,mixed> $metadata
 * @property \Carbon\Carbon  $created_at
 */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'action',
        'entity_type',
        'entity_id',
        'old_values',
        'new_values',
        'ip_address',
        'user_agent',
        'request_id',
        'http_method',
        'http_path',
        'http_status',
        'duration_ms',
        'metadata',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'metadata'   => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return 'audit_logs';
        }

        return "{$tenant->slug}_auth.audit_logs";
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
