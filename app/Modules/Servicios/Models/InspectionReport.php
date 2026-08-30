<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fase 4 — Servicios, Batch 8 (REQ-238→243, docs/architecture/servicios-fase4-diseno.md sección
 * 4/8) — Informe de Inspección de un ticket. 1:1 con `Ticket` (`ticket_id` UNIQUE).
 *
 * `findings` es un JSONB array de `{label, value, ticket_product_id, is_additional,
 * is_quote_recommendation}` — ver `InspectionReportService::FINDINGS_FIELD_CATALOG` para el
 * catálogo de campos por tipo/subtipo que asigna `is_quote_recommendation` (REQ-239 RN5).
 *
 * @property int         $id
 * @property string      $numero
 * @property int         $ticket_id
 * @property string      $modo
 * @property \Carbon\Carbon|null $fecha_inspeccion
 * @property string|null $hora_inicio
 * @property string|null $hora_fin
 * @property int|null    $internal_technician_id
 * @property list<array{label: string, value: string, ticket_product_id: int|null, is_additional: bool, is_quote_recommendation: bool}>|null $findings
 * @property string|null $conclusion
 * @property bool        $requiere_seguimiento
 * @property string|null $proximos_pasos
 * @property string|null $firma_tecnico_nombre
 * @property string|null $firma_cliente_nombre
 * @property string|null $firma_cliente_imagen
 * @property string|null $archivo_adjunto
 * @property string      $estado
 * @property int|null    $created_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Ticket $ticket
 * @property-read User|null $internalTechnician
 * @property-read User|null $creator
 */
class InspectionReport extends Model
{
    use LogsAuditTrail;

    public const MODO_FORMULARIO = 'formulario';

    public const MODO_ARCHIVO_SUBIDO = 'archivo_subido';

    /** @var list<string> */
    public const MODOS = [self::MODO_FORMULARIO, self::MODO_ARCHIVO_SUBIDO];

    public const ESTADO_PENDING = 'pending';

    public const ESTADO_COMPLETED = 'completed';

    /** @var list<string> */
    public const ESTADOS = [self::ESTADO_PENDING, self::ESTADO_COMPLETED];

    /** @var list<string> */
    protected $fillable = [
        'numero', 'ticket_id', 'modo', 'fecha_inspeccion', 'hora_inicio', 'hora_fin',
        'internal_technician_id', 'findings', 'conclusion', 'requiere_seguimiento', 'proximos_pasos',
        'firma_tecnico_nombre', 'firma_cliente_nombre', 'firma_cliente_imagen', 'archivo_adjunto',
        'estado', 'created_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha_inspeccion'     => 'date',
            'findings'             => 'array',
            'requiere_seguimiento' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'inspection_reports';
        }

        return "{$tenant->slug}_servicios.inspection_reports";
    }

    /** @return BelongsTo<Ticket, $this> */
    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    /** @return BelongsTo<User, $this> */
    public function internalTechnician(): BelongsTo
    {
        return $this->belongsTo(User::class, 'internal_technician_id')->select(['id', 'first_name', 'last_name']);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<InspectionReportMaterial, $this> */
    public function materials(): HasMany
    {
        return $this->hasMany(InspectionReportMaterial::class);
    }

    /** @return HasMany<InspectionReportPhoto, $this> */
    public function photos(): HasMany
    {
        return $this->hasMany(InspectionReportPhoto::class);
    }
}
