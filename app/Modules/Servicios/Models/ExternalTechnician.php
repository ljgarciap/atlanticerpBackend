<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Fase 4 — Servicios, Batch 5 (REQ-263→267) — técnico externo (empresa/persona subcontratada).
 *
 * `tarifa_dia` es siempre el valor vigente; los montos históricos de cotizaciones ya generadas
 * viven en su propio ledger (`COTIZACION_ITEM.costo_referencia`, Batch 11 — no existe todavía),
 * nunca una referencia viva a este campo (REQ-266 RN4).
 *
 * @property int         $id
 * @property string      $nombre
 * @property string      $empresa
 * @property string|null $especialidad
 * @property string|null $telefono
 * @property string|null $email
 * @property string      $tarifa_dia
 * @property bool         $tarifa_visible
 * @property string      $estado
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, ExternalTechnicianRateHistory> $rateHistory
 */
class ExternalTechnician extends Model
{
    use LogsAuditTrail;

    public const ESTADO_ACTIVE = 'active';

    public const ESTADO_INACTIVE = 'inactive';

    /** @var list<string> */
    protected $fillable = [
        'nombre', 'empresa', 'especialidad', 'telefono', 'email',
        'tarifa_dia', 'tarifa_visible', 'estado',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'tarifa_visible' => 'boolean',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'external_technicians';
        }

        return "{$tenant->slug}_servicios.external_technicians";
    }

    /** @return HasMany<ExternalTechnicianRateHistory, $this> */
    public function rateHistory(): HasMany
    {
        return $this->hasMany(ExternalTechnicianRateHistory::class)->latest('created_at');
    }
}
