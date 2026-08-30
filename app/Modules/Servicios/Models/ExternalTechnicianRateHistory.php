<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4 — Servicios, Batch 5 (REQ-266 RN2) — entrada inmutable del historial de tarifa de un
 * técnico externo. Solo consulta, nunca se edita ni se borra (por eso sin UPDATED_AT, mismo
 * patrón que TicketAttachment).
 *
 * @property int         $id
 * @property int         $external_technician_id
 * @property string      $tarifa_dia
 * @property int|null    $changed_by
 * @property \Carbon\Carbon $created_at
 * @property-read ExternalTechnician $externalTechnician
 * @property-read User|null $changedByUser
 */
class ExternalTechnicianRateHistory extends Model
{
    use LogsAuditTrail;

    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $fillable = [
        'external_technician_id', 'tarifa_dia', 'changed_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'external_technician_rate_history';
        }

        return "{$tenant->slug}_servicios.external_technician_rate_history";
    }

    /** @return BelongsTo<ExternalTechnician, $this> */
    public function externalTechnician(): BelongsTo
    {
        return $this->belongsTo(ExternalTechnician::class);
    }

    /** @return BelongsTo<User, $this> */
    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
