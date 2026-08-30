<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Models;

use App\Models\User;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Fase 4 — Servicios, Grupo D parte 2 (REQ-274/277, SCRUM-344/347) — puente local entre un insumo
 * de la Reserva Servicios y la `PurchaseOrder` REAL generada en Compras para reponerlo. Ver
 * docblock de la migración de creación: a diferencia de `ToolPurchaseRequest`, esta tabla NUNCA
 * reemplaza el ciclo real de Compras — `estado` acá solo refleja si la orden real ya llegó a
 * "Recibido" (`App\Modules\Servicios\Listeners\SyncInsumoReservationOnPurchaseOrderReceived` es
 * quien lo actualiza, nunca a mano).
 *
 * @property int         $id
 * @property int         $catalog_product_id
 * @property int         $cantidad_solicitada
 * @property string      $estado
 * @property int|null    $purchase_order_id
 * @property int|null    $solicitado_por
 * @property \Carbon\Carbon|null $received_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read CatalogProduct|null $catalogProduct
 * @property-read PurchaseOrder|null $purchaseOrder
 * @property-read User|null $solicitante
 */
class InsumoPurchaseRequest extends Model
{
    use LogsAuditTrail;

    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_RECIBIDA = 'recibida';

    /** @var list<string> */
    protected $fillable = [
        'catalog_product_id', 'cantidad_solicitada', 'estado', 'purchase_order_id',
        'solicitado_por', 'received_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'cantidad_solicitada' => 'integer',
            'received_at'         => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'insumo_purchase_requests';
        }

        return "{$tenant->slug}_servicios.insumo_purchase_requests";
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class);
    }

    /** @return BelongsTo<User, $this> */
    public function solicitante(): BelongsTo
    {
        return $this->belongsTo(User::class, 'solicitado_por');
    }
}
