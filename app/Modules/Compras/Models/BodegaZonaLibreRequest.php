<?php

declare(strict_types=1);

namespace App\Modules\Compras\Models;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * SCRUM-433→445 (REQ-363→375) — solicitud de orden al proveedor fijo "Zona Libre de Colón",
 * creada por Bodega, resuelta por Compras (Lider Compras). Mismo patrón exacto que `ReplacementRequest`
 * (ver docblock de la migración de creación): `status`/`requested_by`/`approved_by`/
 * `generated_order_id` solo se llena al aprobar. Ver ADR-SCRUM433-445 sección "Aclaración de
 * ubicación de módulo" para por qué vive en `Compras\Models` pese a que Bodega es quien la crea.
 *
 * @property int         $id
 * @property int         $order_number
 * @property string      $status
 * @property int|null    $requested_by
 * @property int|null    $approved_by
 * @property int|null    $rejected_by
 * @property string|null $rejection_reason
 * @property int|null    $generated_order_id
 * @property string      $shipping_type
 * @property \Carbon\Carbon|null $estimated_arrival_date
 * @property string|null $notes
 * @property \Carbon\Carbon $created_at
 */
class BodegaZonaLibreRequest extends Model
{
    public const STATUS_PENDIENTE = 'pendiente';
    public const STATUS_APROBADA = 'aprobada';
    public const STATUS_RECHAZADA = 'rechazada';

    public const SHIPPING_TERRESTRE = 'terrestre';

    /** @var list<string> */
    protected $fillable = [
        'order_number', 'status', 'requested_by', 'approved_by', 'rejected_by',
        'rejection_reason', 'generated_order_id', 'shipping_type', 'estimated_arrival_date', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['estimated_arrival_date' => 'date'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'bodega_zona_libre_requests';
        }

        return "{$tenant->slug}_compras.bodega_zona_libre_requests";
    }

    /** @return HasMany<BodegaZonaLibreRequestLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(BodegaZonaLibreRequestLine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    /** @return BelongsTo<User, $this> */
    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** @return BelongsTo<User, $this> */
    public function rejecter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'rejected_by');
    }

    /** @return BelongsTo<PurchaseOrder, $this> */
    public function generatedOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'generated_order_id');
    }

    /** SCRUM-440 RN — una solicitud ya resuelta no se puede volver a aprobar/rechazar. */
    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDIENTE;
    }
}
