<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fase 4 — Servicios, Batch 13 parte 1 (REQ-268→272) — unidad de herramienta del equipo de
 * Servicios. Ver docblock de la migración `..._create_servicios_tools_table.php` para el
 * catálogo de 4 estados y por qué `assigned_to_technician_id` referencia `internal_technicians`
 * (no `users`).
 *
 * @property int         $id
 * @property string      $nombre
 * @property string      $codigo_unico
 * @property string      $estado
 * @property int|null    $assigned_to_technician_id
 * @property \Carbon\Carbon|null $assigned_since
 * @property string|null $responsable_incidente
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read InternalTechnician|null $assignedTechnician
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ToolMovement> $movements
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ToolPurchaseRequest> $replacementRequests
 */
class Tool extends Model
{
    use LogsAuditTrail;

    public const ESTADO_GOOD = 'good';

    public const ESTADO_DAMAGED = 'damaged';

    public const ESTADO_WORN = 'worn';

    public const ESTADO_LOST = 'lost';

    /** @var list<string> */
    public const ESTADOS = [self::ESTADO_GOOD, self::ESTADO_DAMAGED, self::ESTADO_WORN, self::ESTADO_LOST];

    /** REQ-269 RN1 — estos 2 destinos atribuyen `responsable_incidente` a quien tenía la herramienta. */
    public const ESTADOS_WITH_RESPONSABLE = [self::ESTADO_DAMAGED, self::ESTADO_LOST];

    public const BODEGA_LABEL = 'En bodega de herramientas';

    public const UNASSIGNED_INCIDENT_LABEL = 'Sin asignar al momento del incidente';

    /** @var list<string> */
    protected $fillable = [
        'nombre', 'codigo_unico', 'estado', 'assigned_to_technician_id', 'assigned_since',
        'responsable_incidente',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'assigned_since' => 'date',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'tools';
        }

        return "{$tenant->slug}_servicios.tools";
    }

    /** @return BelongsTo<InternalTechnician, $this> */
    public function assignedTechnician(): BelongsTo
    {
        return $this->belongsTo(InternalTechnician::class, 'assigned_to_technician_id')->withTrashed();
    }

    /** @return HasMany<ToolMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(ToolMovement::class);
    }

    /** REQ-268 RN2 — solicitudes de reposición (REQ-272) disparadas desde esta unidad puntual. */
    /** @return HasMany<ToolPurchaseRequest, $this> */
    public function replacementRequests(): HasMany
    {
        return $this->hasMany(ToolPurchaseRequest::class, 'source_tool_id');
    }
}
