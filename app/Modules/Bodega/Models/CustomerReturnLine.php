<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-487/488 (REQ-417/418, Bloque B6 "Devoluciones") — una línea de producto dentro de una
 * devolución. `order_item_id` ata cada línea a lo realmente entregado (REQ-417 RN2: tope =
 * `order_item.qty_delivered`, validado en `CustomerReturnService::create()`).
 *
 * `qty_received` queda NULL hasta la confirmación de recepción física (REQ-409 RN1: puede diferir
 * de `qty_requested`).
 *
 * `unit_price` (SCRUM-786) — snapshot de `OrderItem.unit_price` al crear la línea, NULL en
 * devoluciones de pedidos creados antes de este fix (ver `bodega:backfill-order-item-prices`).
 * Alimenta el monto sugerido de Nota de Crédito (REQ-491).
 *
 * `reason` — catálogo fijo (REQ-418), mismo patrón que `OrderItem::SHORTAGE_REASONS`/
 * `setShortageReason()`: "otra" persiste el texto libre embebido como `"otra:<texto>"`.
 *
 * @property int         $id
 * @property int         $customer_return_id
 * @property int         $order_item_id
 * @property string|null $reference_snapshot
 * @property string|null $description_snapshot
 * @property float|null  $unit_price
 * @property int         $qty_requested
 * @property int|null    $qty_received
 * @property string      $reason
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read CustomerReturn $customerReturn
 * @property-read OrderItem      $orderItem
 */
class CustomerReturnLine extends Model
{
    use LogsAuditTrail;

    public const REASON_DANADO_DEFECTUOSO = 'danado_defectuoso';

    public const REASON_NO_CORRESPONDE = 'no_corresponde';

    public const REASON_CLIENTE_CAMBIO_OPINION = 'cliente_cambio_opinion';

    public const REASON_EXCEDENTE = 'excedente';

    public const REASON_ERROR_INSTALACION = 'error_instalacion';

    public const REASON_OTRA = 'otra';

    /** @var list<string> */
    public const REASONS = [
        self::REASON_DANADO_DEFECTUOSO,
        self::REASON_NO_CORRESPONDE,
        self::REASON_CLIENTE_CAMBIO_OPINION,
        self::REASON_EXCEDENTE,
        self::REASON_ERROR_INSTALACION,
        self::REASON_OTRA,
    ];

    /** @var list<string> */
    protected $fillable = [
        'customer_return_id', 'order_item_id', 'reference_snapshot', 'description_snapshot', 'unit_price',
        'qty_requested', 'qty_received', 'reason',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price'    => 'float',
            'qty_requested' => 'integer',
            'qty_received'  => 'integer',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'customer_return_lines';
        }

        return "{$tenant->slug}_bodega.customer_return_lines";
    }

    /** @return BelongsTo<CustomerReturn, $this> */
    public function customerReturn(): BelongsTo
    {
        return $this->belongsTo(CustomerReturn::class);
    }

    /** @return BelongsTo<OrderItem, $this> */
    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderItem::class);
    }

    /** REQ-418 — "Otra" persiste el texto libre embebido, ver docblock de las constantes. */
    public function setReason(string $reasonCode, ?string $freeText = null): void
    {
        $value = $reasonCode === self::REASON_OTRA && $freeText !== null && trim($freeText) !== ''
            ? self::REASON_OTRA.':'.trim($freeText)
            : $reasonCode;

        $this->update(['reason' => $value]);
    }

    /**
     * Hallazgo de Senior Reviewer 2026-07-26 — separa el código de motivo del texto libre
     * embebido de `setReason()`, para que la API nunca exponga el string crudo `"otra:<texto>"`
     * (el frontend espera `reason` como enum puro y `reason_detail` aparte).
     */
    public function reasonCode(): string
    {
        return explode(':', $this->reason, 2)[0];
    }

    public function reasonDetail(): ?string
    {
        $prefix = self::REASON_OTRA.':';
        if (! str_starts_with($this->reason, $prefix)) {
            return null;
        }

        return substr($this->reason, strlen($prefix));
    }
}
