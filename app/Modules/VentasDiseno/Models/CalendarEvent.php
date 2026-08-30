<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-058 — calendario nativo, sin integración externa (ver nota en la migración).
 *
 * @property int         $id
 * @property int         $owner_id
 * @property string      $title
 * @property string|null $description
 * @property \Carbon\Carbon $start_at
 * @property \Carbon\Carbon|null $end_at
 * @property bool        $all_day
 * @property User        $owner
 */
class CalendarEvent extends Model
{
    /** @var list<string> */
    protected $fillable = ['owner_id', 'title', 'description', 'start_at', 'end_at', 'all_day'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'start_at' => 'datetime',
            'end_at'   => 'datetime',
            'all_day'  => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'calendar_events';
        }

        return "{$tenant->slug}_ventas_diseno.calendar_events";
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }
}
