<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Models\User;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-454/457/458/459 (REQ-384/387/388/389, Bloque B4) — solicitud de reubicación de un
 * producto entre dos bodegas. Bodega solicita → Gerencia aprueba/rechaza (mismo gate que
 * `AdjustmentRequestController::resolveApproverAccess()`) → aprobar ajusta el inventario
 * automáticamente vía `KardexService` (dos movimientos `TIPO_REUBICACION`, uno negativo en
 * origen y otro positivo en destino), rechazar no toca nada.
 *
 * @property int         $id
 * @property int         $catalog_product_id
 * @property int         $origin_warehouse_id
 * @property int         $destination_warehouse_id
 * @property int         $cantidad
 * @property string      $motivo
 * @property int         $solicitado_por
 * @property string      $estado
 * @property string|null $motivo_rechazo
 * @property int|null    $resuelto_por
 * @property \Carbon\Carbon|null $resuelto_at
 * @property \Carbon\Carbon $created_at
 * @property-read CatalogProduct|null $catalogProduct
 * @property-read Warehouse|null $originWarehouse
 * @property-read Warehouse|null $destinationWarehouse
 * @property-read User|null $solicitante
 * @property-read User|null $resolvedBy
 */
class RelocationRequest extends Model
{
    use LogsAuditTrail;

    public const ESTADO_PENDIENTE = 'pendiente';
    public const ESTADO_APROBADA = 'aprobada';
    public const ESTADO_RECHAZADA = 'rechazada';

    /** @var list<string> */
    protected $fillable = [
        'catalog_product_id', 'origin_warehouse_id', 'destination_warehouse_id',
        'cantidad', 'motivo', 'solicitado_por',
        'estado', 'motivo_rechazo', 'resuelto_por', 'resuelto_at',
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
            return 'relocation_requests';
        }

        return "{$tenant->slug}_bodega.relocation_requests";
    }

    public function isPending(): bool
    {
        return $this->estado === self::ESTADO_PENDIENTE;
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function originWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'origin_warehouse_id');
    }

    /** @return BelongsTo<Warehouse, $this> */
    public function destinationWarehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class, 'destination_warehouse_id');
    }

    /** @return BelongsTo<User, $this> */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }

    /** @return BelongsTo<User, $this> */
    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resuelto_por');
    }
}
