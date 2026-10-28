<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-428/447/448/449 (REQ-358/377/378/379) — una bodega dentro de una solicitud de ajuste,
 * con su propio estado. RN de REQ-449: aprobar dispara `KardexService::registrar()`
 * (tipo Ajuste); rechazar no toca el inventario.
 *
 * @property int $id
 * @property int $adjustment_request_id
 * @property int $warehouse_id
 * @property string $tipo
 * @property int $cantidad
 * @property string $motivo
 * @property string|null $descripcion
 * @property string|null $responsable
 * @property string|null $evidencia_storage_key
 * @property string $estado
 * @property string|null $motivo_rechazo
 * @property int|null $resuelto_por
 * @property Carbon|null $resuelto_at
 * @property int|null $replaced_by_id SCRUM-797 (RN5→RN7) — seteado cuando el usuario confirma
 *                                    reemplazar esta línea (todavía Pendiente) por una nueva sobre el mismo producto+bodega.
 * @property Carbon $created_at
 * @property-read AdjustmentRequest $request
 * @property-read Warehouse|null $warehouse
 * @property-read User|null $resolvedBy
 * @property-read AdjustmentRequestLine|null $replacedBy
 */
class AdjustmentRequestLine extends Model
{
    use LogsAuditTrail;

    public const TIPO_SUMAR = 'Sumar';

    public const TIPO_RESTAR = 'Restar';

    public const ESTADO_PENDIENTE = 'Pendiente';

    public const ESTADO_APROBADA = 'Aprobada';

    public const ESTADO_RECHAZADA = 'Rechazada';

    /** SCRUM-797 RN7 — reemplazada por confirmación explícita del usuario, nunca automático. */
    public const ESTADO_REEMPLAZADA = 'Reemplazada';

    // SCRUM-428 (REQ-358, rebote de Gerencia Test 2026-08-13) — Motivo pasa de texto libre a un
    // desplegable de exactamente 6 opciones fijas. Keys en snake_case/mayúscula (mismo criterio
    // que CatalogProduct::ROTATIONS y el catálogo `product_category`) — el frontend resuelve la etiqueta en español,
    // el backend nunca hardcodea el texto visible.
    public const MOTIVO_CONTEO_FISICO_NO_COINCIDE = 'CONTEO_FISICO_NO_COINCIDE';

    public const MOTIVO_PRODUCTO_DANADO_ROTO = 'PRODUCTO_DANADO_ROTO';

    public const MOTIVO_PRODUCTO_VENCIDO_OBSOLETO = 'PRODUCTO_VENCIDO_OBSOLETO';

    public const MOTIVO_ERROR_DE_REGISTRO = 'ERROR_DE_REGISTRO';

    public const MOTIVO_ROBO_O_PERDIDA = 'ROBO_O_PERDIDA';

    public const MOTIVO_OTRO_ESPECIFICAR = 'OTRO_ESPECIFICAR';

    /** @var list<string> */
    public const MOTIVOS = [
        self::MOTIVO_CONTEO_FISICO_NO_COINCIDE,
        self::MOTIVO_PRODUCTO_DANADO_ROTO,
        self::MOTIVO_PRODUCTO_VENCIDO_OBSOLETO,
        self::MOTIVO_ERROR_DE_REGISTRO,
        self::MOTIVO_ROBO_O_PERDIDA,
        self::MOTIVO_OTRO_ESPECIFICAR,
    ];

    /** @var list<string> */
    protected $fillable = [
        'adjustment_request_id', 'warehouse_id', 'tipo', 'cantidad', 'motivo', 'descripcion',
        'responsable', 'evidencia_storage_key', 'estado', 'motivo_rechazo', 'resuelto_por',
        'resuelto_at', 'replaced_by_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cantidad' => 'integer', 'resuelto_at' => 'datetime'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'adjustment_request_lines';
        }

        return "{$tenant->slug}_bodega.adjustment_request_lines";
    }

    public function isPending(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    /** @return BelongsTo<AdjustmentRequest, $this> */
    public function request(): BelongsTo
    {
        return $this->belongsTo(AdjustmentRequest::class, 'adjustment_request_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }

    /** @return BelongsTo<AdjustmentRequestLine, $this> */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }
}
