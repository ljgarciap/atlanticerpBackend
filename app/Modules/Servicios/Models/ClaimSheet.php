<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4 — Servicios, Batch 9 (REQ-278/279, docs/architecture/servicios-fase4-diseno.md sección
 * 8) — Hoja de Reclamo de un ticket `tipo = claim`. 1:1 con `Ticket` (`ticket_id` UNIQUE).
 *
 * @property int         $id
 * @property int         $ticket_id
 * @property string|null $descripcion_problema
 * @property \Carbon\Carbon|null $fecha_reclamo
 * @property string|null $diagnostico
 * @property string|null $firma_responsable
 * @property string      $estado
 * @property int|null    $created_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Ticket $ticket
 * @property-read User|null $creator
 */
class ClaimSheet extends Model
{
    use LogsAuditTrail;

    public const DIAGNOSTICO_DEFECTUOSO = 'defectuoso';

    public const DIAGNOSTICO_NO_PROCEDE = 'no_procede';

    public const DIAGNOSTICO_USO_INADECUADO = 'uso_inadecuado';

    public const DIAGNOSTICO_PENDIENTE_EVALUACION = 'pendiente_evaluacion';

    /** @var list<string> */
    public const DIAGNOSTICOS = [
        self::DIAGNOSTICO_DEFECTUOSO,
        self::DIAGNOSTICO_NO_PROCEDE,
        self::DIAGNOSTICO_USO_INADECUADO,
        self::DIAGNOSTICO_PENDIENTE_EVALUACION,
    ];

    public const ESTADO_PENDING = 'pending';

    public const ESTADO_COMPLETED = 'completed';

    /** @var list<string> */
    public const ESTADOS = [self::ESTADO_PENDING, self::ESTADO_COMPLETED];

    /** @var list<string> */
    protected $fillable = [
        'ticket_id', 'descripcion_problema', 'fecha_reclamo', 'diagnostico',
        'firma_responsable', 'estado', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_reclamo' => 'date',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'claim_sheets';
        }

        return "{$tenant->slug}_servicios.claim_sheets";
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
