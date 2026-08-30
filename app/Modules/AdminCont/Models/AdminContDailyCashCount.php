<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Batch 18 de Admin&Cont (SCRUM-597→601, REQ-524) — ver ADR-SCRUM597-601-batch18-arqueo-caja.md.
 * Fila creada de forma perezosa la primera vez que se escribe una observación (de fila o general)
 * para una fecha — nunca a mano, nunca al simplemente consultar `GET .../daily-count`. Batch 19
 * (SCRUM-602→606, REQ-525→529, ver ADR-SCRUM602-606-batch19-arqueo-caja-parte2.md) agrega el ciclo
 * de vida de cierre/aprobación (`numero`/`estado`/`cerrado_*`/`aprobado_*`).
 *
 * @property int         $id
 * @property \Carbon\Carbon $fecha
 * @property int|null    $numero
 * @property string      $estado
 * @property string|null $observacion_general
 * @property int|null    $cerrado_por
 * @property \Carbon\Carbon|null $cerrado_at
 * @property int|null    $aprobado_por
 * @property \Carbon\Carbon|null $aprobado_at
 */
class AdminContDailyCashCount extends Model
{
    public const ESTADO_ABIERTO              = 'abierto';
    public const ESTADO_PENDIENTE_APROBACION = 'pendiente_aprobacion';
    public const ESTADO_APROBADO             = 'aprobado';

    /** @var list<string> */
    protected $fillable = [
        'fecha', 'numero', 'estado', 'observacion_general',
        'cerrado_por', 'cerrado_at', 'aprobado_por', 'aprobado_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'fecha'      => 'date',
            'cerrado_at' => 'datetime',
            'aprobado_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'daily_cash_counts';
        }

        return "{$tenant->slug}_admin_contab.daily_cash_counts";
    }

    /** @return HasMany<AdminContDailyCashCountEntry, $this> */
    public function entries(): HasMany
    {
        return $this->hasMany(AdminContDailyCashCountEntry::class, 'daily_cash_count_id');
    }

    /** @return BelongsTo<User, $this> */
    public function cerradoPorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cerrado_por');
    }

    /** @return BelongsTo<User, $this> */
    public function aprobadoPorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'aprobado_por');
    }
}
