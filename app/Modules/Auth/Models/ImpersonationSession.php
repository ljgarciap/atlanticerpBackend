<?php

declare(strict_types=1);

namespace App\Modules\Auth\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-1158 — sesión de «Ver como usuario» (solo lectura). Ver ADR-010 y la migración que la crea.
 *
 * No usa `LogsAuditTrail` a propósito: los eventos de ciclo de vida (`auth.impersonation.started/ended/revoked`) los escribe
 * `ImpersonationService` con el motivo y el contexto completos; un evento ORM genérico solo duplicaría ruido.
 *
 * @property string                          $id
 * @property int                             $actor_id
 * @property int                             $target_id
 * @property string                          $reason
 * @property \Illuminate\Support\Carbon      $started_at
 * @property \Illuminate\Support\Carbon      $expires_at
 * @property \Illuminate\Support\Carbon|null $ended_at
 * @property string|null                     $ended_reason
 * @property int|null                        $ended_by
 * @property string|null                     $ip_address
 * @property string|null                     $user_agent
 * @property string|null                     $request_id
 */
class ImpersonationSession extends Model
{
    use HasUuids;

    public const ENDED_MANUAL         = 'manual';
    public const ENDED_EXPIRED        = 'expired';
    public const ENDED_REVOKED        = 'revoked';
    public const ENDED_TARGET_BLOCKED = 'target_blocked';

    public const STATUS_ACTIVE  = 'active';
    public const STATUS_ENDED   = 'ended';
    public const STATUS_EXPIRED = 'expired';

    public $timestamps = false;

    /** @var list<string> */
    protected $fillable = [
        'actor_id', 'target_id', 'reason', 'started_at', 'expires_at',
        'ended_at', 'ended_reason', 'ended_by', 'ip_address', 'user_agent', 'request_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'expires_at' => 'datetime',
            'ended_at'   => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            // Larastan llama getTable() en tiempo de análisis sin tenant activo; en runtime AuthenticateJwt siempre lo inicializa.
            return 'impersonation_sessions';
        }

        return "{$tenant->slug}_auth.impersonation_sessions";
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    /** @return BelongsTo<User, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id');
    }

    /** `active` = sin cerrar y vigente; `expired` = sin cerrar pero vencida; `ended` = cerrada (manual/revocada/objetivo bloqueado). */
    public function status(): string
    {
        if ($this->ended_at !== null) {
            return self::STATUS_ENDED;
        }

        return $this->expires_at->isFuture() ? self::STATUS_ACTIVE : self::STATUS_EXPIRED;
    }

    public function isOpen(): bool
    {
        return $this->ended_at === null && $this->expires_at->isFuture();
    }
}
