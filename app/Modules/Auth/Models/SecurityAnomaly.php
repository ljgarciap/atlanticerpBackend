<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int             $id
 * @property \Carbon\Carbon  $detected_at
 * @property string          $severity
 * @property string          $reasoning
 * @property array<string,mixed> $raw_response
 * @property int|null        $affected_user_id
 * @property \Carbon\Carbon  $range_start
 * @property \Carbon\Carbon  $range_end
 * @property string          $status
 * @property int|null        $reviewed_by
 * @property \Carbon\Carbon|null $reviewed_at
 * @property \Carbon\Carbon  $created_at
 */
class SecurityAnomaly extends Model
{
    public const UPDATED_AT = null;

    public const SEVERITY_LOW    = 'low';
    public const SEVERITY_MEDIUM = 'medium';
    public const SEVERITY_HIGH   = 'high';

    public const STATUS_PENDING   = 'pending';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_DISMISSED = 'dismissed';

    /** @var list<string> */
    protected $fillable = [
        'detected_at',
        'severity',
        'reasoning',
        'raw_response',
        'affected_user_id',
        'range_start',
        'range_end',
        'status',
        'reviewed_by',
        'reviewed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'detected_at'   => 'datetime',
            'raw_response'  => 'array',
            'range_start'   => 'datetime',
            'range_end'     => 'datetime',
            'reviewed_at'   => 'datetime',
            'created_at'    => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function affectedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affected_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * True si ya existe una anomalía (de cualquier status) cubriendo exactamente esta ventana
     * de audit_logs — usado por SecurityAnalysisJob para no duplicar si el job corre dos veces
     * sobre el mismo rango (ver ADR-004).
     */
    public static function existsForRange(\DateTimeInterface $start, \DateTimeInterface $end): bool
    {
        return self::where('range_start', $start)
            ->where('range_end', $end)
            ->exists();
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return 'security_anomalies';
        }

        return "{$tenant->slug}_auth.security_anomalies";
    }
}
