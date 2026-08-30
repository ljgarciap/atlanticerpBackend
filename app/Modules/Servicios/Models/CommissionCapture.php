<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4 — Servicios, Batch 7 (REQ-292) — captura mensual de comisión de un técnico interno.
 *
 * @property int      $id
 * @property int      $internal_technician_id
 * @property int      $year
 * @property int      $month
 * @property string   $satisfaccion_promedio
 * @property int      $incidencias_puntualidad
 * @property string   $calificacion_actitud
 * @property bool     $licencia_medica
 * @property int|null $calidad_pct
 * @property int|null $captured_by
 * @property int|null $updated_by
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read InternalTechnician $internalTechnician
 * @property-read User|null $capturedByUser
 * @property-read User|null $updatedByUser
 */
class CommissionCapture extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = [
        'internal_technician_id', 'year', 'month', 'satisfaccion_promedio',
        'incidencias_puntualidad', 'calificacion_actitud', 'licencia_medica', 'calidad_pct',
        'captured_by', 'updated_by',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'licencia_medica' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'commission_captures';
        }

        return "{$tenant->slug}_servicios.commission_captures";
    }

    /** @return BelongsTo<InternalTechnician, $this> */
    public function internalTechnician(): BelongsTo
    {
        return $this->belongsTo(InternalTechnician::class);
    }

    /** @return BelongsTo<User, $this> */
    public function capturedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'captured_by');
    }

    /** @return BelongsTo<User, $this> */
    public function updatedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
