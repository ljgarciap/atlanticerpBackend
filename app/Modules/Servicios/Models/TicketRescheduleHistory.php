<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-804 — una fila por cada reagendamiento real de un ticket (nunca por el primer
 * agendamiento). Ver docblock de la migración y de `TicketService::schedule()`.
 *
 * @property int         $id
 * @property int         $ticket_id
 * @property \Carbon\Carbon $previous_scheduled_at
 * @property \Carbon\Carbon|null $previous_scheduled_ends_at
 * @property \Carbon\Carbon $new_scheduled_at
 * @property \Carbon\Carbon|null $new_scheduled_ends_at
 * @property string      $motivo
 * @property int|null    $rescheduled_by
 * @property \Carbon\Carbon $created_at
 * @property-read Ticket $ticket
 * @property-read User|null $reschedulerUser
 */
class TicketRescheduleHistory extends Model
{
    use LogsAuditTrail;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'ticket_id', 'previous_scheduled_at', 'previous_scheduled_ends_at',
        'new_scheduled_at', 'new_scheduled_ends_at', 'motivo', 'rescheduled_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'previous_scheduled_at'      => 'datetime',
            'previous_scheduled_ends_at' => 'datetime',
            'new_scheduled_at'           => 'datetime',
            'new_scheduled_ends_at'      => 'datetime',
            'created_at'                 => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'ticket_reschedule_history';
        }

        return "{$tenant->slug}_servicios.ticket_reschedule_history";
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function reschedulerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rescheduled_by');
    }
}
