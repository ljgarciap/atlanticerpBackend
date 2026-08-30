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
 * SCRUM-329 Oleada A / Batch A1 (REQ-308 RN1, REQ-312, REQ-340) — ítem de un pedido, copiado
 * desde los `quote_items` de la cotización aprobada al crear el `Order` (una copia, no una
 * referencia en vivo — mismo criterio que `QuoteItem.unit_price`/`cost`).
 *
 * `catalog_product_id` es nullable porque `QuoteItem.is_custom=true` (ítem personalizado sin
 * producto de Catálogo) es un caso real en Ventas & Diseño — `reference_snapshot`/
 * `description_snapshot` guardan lo pedido en ambos casos, para no depender de un join a
 * `catalog_products` (que además puede cambiar de descripción después de vendido).
 *
 * @property int         $id
 * @property int         $order_id
 * @property int|null    $catalog_product_id
 * @property int|null    $warehouse_id
 * @property string|null $reference_snapshot
 * @property string|null $description_snapshot
 * @property float|null  $unit_price
 * @property int         $qty_requested
 * @property int         $qty_reserved
 * @property int         $qty_picked
 * @property int         $qty_delivered
 * @property string|null $location_snapshot
 * @property bool|null   $found_at_location
 * @property string|null $found_location
 * @property int|null    $found_confirmed_by_id
 * @property \Carbon\Carbon|null $found_confirmed_at
 * @property string|null $shortage_reason
 * @property string|null $picking_notes
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property-read Order  $order
 * @property-read CatalogProduct|null $catalogProduct
 * @property-read Warehouse|null $warehouse
 * @property-read User|null $foundConfirmedBy
 */
class OrderItem extends Model
{
    use LogsAuditTrail;

    /**
     * SCRUM-329 Oleada A / Batch A2 (REQ-324 RN1) — catálogo fijo de motivos de faltante,
     * capturado en `shortage_reason`. "Otra" exige texto libre — como la columna es un solo
     * string, se persiste como `"otra:<texto>"` (ver `setShortageReason()`), sin agregar una
     * columna nueva para esto (no es un umbral/margen de negocio parametrizable, es un catálogo
     * fijo de motivos operativos — no aplica la regla de "tabla paramétrica").
     */
    public const SHORTAGE_SIN_STOCK = 'sin_stock';

    public const SHORTAGE_NO_ENCONTRADO = 'no_encontrado';

    public const SHORTAGE_DANADO = 'danado';

    public const SHORTAGE_RESERVADO_OTRO_PEDIDO = 'reservado_otro_pedido';

    public const SHORTAGE_REFERENCIA_EQUIVOCADA = 'referencia_equivocada';

    public const SHORTAGE_OTRA = 'otra';

    /** @var list<string> */
    public const SHORTAGE_REASONS = [
        self::SHORTAGE_SIN_STOCK,
        self::SHORTAGE_NO_ENCONTRADO,
        self::SHORTAGE_DANADO,
        self::SHORTAGE_RESERVADO_OTRO_PEDIDO,
        self::SHORTAGE_REFERENCIA_EQUIVOCADA,
        self::SHORTAGE_OTRA,
    ];

    /** @var list<string> */
    protected $fillable = [
        'order_id', 'catalog_product_id', 'warehouse_id', 'reference_snapshot', 'description_snapshot', 'unit_price',
        'qty_requested', 'qty_reserved', 'qty_picked', 'qty_delivered',
        'location_snapshot', 'found_at_location', 'found_location', 'found_confirmed_by_id', 'found_confirmed_at',
        'shortage_reason', 'picking_notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'unit_price'         => 'float',
            'qty_requested'      => 'integer',
            'qty_reserved'       => 'integer',
            'qty_picked'         => 'integer',
            'qty_delivered'      => 'integer',
            'found_at_location'  => 'boolean',
            'found_confirmed_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'order_items';
        }

        return "{$tenant->slug}_bodega.order_items";
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /**
     * SCRUM-329 Oleada A / Batch A2 (decisión de Luis 2026-07-22) — bodega física real de la que
     * salió esta línea, poblada al completar el picking (ver `PickingService::completePicking()`).
     * Null hasta ese momento — ver docblock de la migración `..._100009_...` para el porqué.
     *
     * @return BelongsTo<Warehouse, $this>
     */
    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    /**
     * SCRUM-388 (REQ-318, corrección 2026-08-11) — quién confirmó `found_location` durante la
     * Revisión de Inventario. Ver docblock de la migración `..._100000_add_found_location_...`
     * para por qué esto vive en columnas propias en vez de sobrescribir `location_snapshot`.
     *
     * @return BelongsTo<User, $this>
     */
    public function foundConfirmedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'found_confirmed_by_id');
    }

    /**
     * SCRUM-388 — nota mostrada en la Hoja de Picking ("Encontrado en") cuando la Revisión de
     * Inventario confirmó una ubicación distinta de la original: "Nueva ubicación: <X> —
     * Confirmado por <Y>". `null` si el ítem nunca pasó por una revisión de inventario resuelta
     * como "Sí hay" (no hay nada que mostrar en esa columna).
     */
    public function foundNote(): ?string
    {
        if ($this->found_location === null) {
            return null;
        }

        $confirmedBy = $this->foundConfirmedBy;
        $name        = $confirmedBy !== null ? trim("{$confirmedBy->first_name} {$confirmedBy->last_name}") : null;

        return $name !== null && $name !== ''
            ? "Nueva ubicación: {$this->found_location} — Confirmado por {$name}"
            : "Nueva ubicación: {$this->found_location}";
    }

    /** REQ-324 RN1 — "Otra" persiste el texto libre embebido, ver docblock de las constantes. */
    public function setShortageReason(string $reasonCode, ?string $freeText = null): void
    {
        $value = $reasonCode === self::SHORTAGE_OTRA && $freeText !== null && trim($freeText) !== ''
            ? self::SHORTAGE_OTRA.':'.trim($freeText)
            : $reasonCode;

        $this->update(['shortage_reason' => $value]);
    }

    public function pendingQty(): int
    {
        return max(0, $this->qty_requested - $this->qty_delivered);
    }
}
