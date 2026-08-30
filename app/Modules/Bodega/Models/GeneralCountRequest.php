<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-460→466 (REQ-390→396, Bloque B5 "Conteos generales de inventario") — la contenedora del
 * conteo (ver `GeneralCountRequestLine` para el detalle por producto). A diferencia de
 * Ajustes/Reubicaciones, "Mark aprueba" (resuelto_por/resuelto_at) y "se aplica al Kardex"
 * (aplicado_at, SCRUM-466) son dos pasos desacoplados — ver ADR-SCRUM460-466-conteos-generales.md.
 *
 * @property int         $id
 * @property int         $warehouse_id
 * @property int         $realizado_por
 * @property string      $estado
 * @property string|null $motivo_rechazo
 * @property int|null    $resuelto_por
 * @property \Carbon\Carbon|null $resuelto_at
 * @property \Carbon\Carbon|null $aplicado_at
 * @property \Carbon\Carbon|null $fecha_solicitud_aprobacion SCRUM-797 (RN8) — momento en que
 *   `submit()` movió el conteo a `pendiente_aprobacion`, null mientras sigue en borrador.
 * @property int|null    $replaced_by_id SCRUM-797 (RN5→RN7) — seteado cuando el usuario confirma
 *   reemplazar este conteo (todavía sin resolver) por uno nuevo sobre la misma bodega.
 * @property \Carbon\Carbon $created_at
 * @property-read int|null $lines_count `withCount('lines')` en `GeneralCountController::index()`.
 * @property-read int|null $diferencias_count `withCount(['lines as diferencias_count' => ...])`
 *                en `index()` (RN3 REQ-395, "Diferencias encontradas") — alias custom, no sigue
 *                la convención `{relation}_count` que Larastan reconoce automáticamente.
 * @property-read Warehouse|null $warehouse
 * @property-read User|null $realizadoPor
 * @property-read User|null $resolvedBy
 * @property-read GeneralCountRequest|null $replacedBy
 */
class GeneralCountRequest extends Model
{
    use LogsAuditTrail;

    public const ESTADO_PENDIENTE_EVALUACION = 'pendiente_evaluacion';
    public const ESTADO_EVALUADO = 'evaluado';
    public const ESTADO_PENDIENTE_APROBACION = 'pendiente_aprobacion';
    public const ESTADO_APROBADA = 'aprobada';
    public const ESTADO_RECHAZADA = 'rechazada';
    /**
     * SCRUM-797 RN7 (2026-08-26) — reemplazado por confirmación explícita del usuario, nunca
     * automático. SUPERADO desde el rebote de Daniela Amaya del día siguiente (2026-08-27,
     * "Validación de Conteos Pendientes por Producto y Bodega"): el reemplazo por confirmación
     * ahora vive por LÍNEA (`GeneralCountRequestLine::replaced_by_id`), no a nivel de todo el
     * conteo — `GeneralCountController` ya no vuelve a setear este estado ni `replaced_by_id` de
     * este modelo. Se mantiene el valor/columna sin borrar por las filas `reemplazada` que ya
     * existían en dev/test/prod antes de este ticket.
     */
    public const ESTADO_REEMPLAZADA = 'reemplazada';

    /**
     * @var list<string> SUPERADO junto con `ESTADO_REEMPLAZADA` (ver su docblock) — ya no se usa
     *   para el chequeo de duplicados (ahora por línea/producto, ver
     *   `GeneralCountController::findConflictingLines()`). Se mantiene por si algún reporte
     *   histórico todavía filtra por "estados en curso" a nivel de todo el conteo.
     */
    public const ESTADOS_SIN_RESOLVER = [
        self::ESTADO_PENDIENTE_EVALUACION, self::ESTADO_EVALUADO, self::ESTADO_PENDIENTE_APROBACION,
    ];

    /** @var list<string> */
    protected $fillable = [
        'warehouse_id', 'realizado_por', 'estado', 'motivo_rechazo',
        'resuelto_por', 'resuelto_at', 'aplicado_at', 'fecha_solicitud_aprobacion', 'replaced_by_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'resuelto_at' => 'datetime',
            'aplicado_at' => 'datetime',
            'fecha_solicitud_aprobacion' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'general_count_requests';
        }

        return "{$tenant->slug}_bodega.general_count_requests";
    }

    public function isEvaluated(): bool
    {
        return $this->estado === self::ESTADO_EVALUADO;
    }

    public function isPendingApproval(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE_APROBACION;
    }

    public function isApproved(): bool
    {
        return $this->estado === self::ESTADO_APROBADA;
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function realizadoPor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'realizado_por');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }

    /** @return HasMany<GeneralCountRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(GeneralCountRequestLine::class);
    }

    /** @return BelongsTo<GeneralCountRequest, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }
}
