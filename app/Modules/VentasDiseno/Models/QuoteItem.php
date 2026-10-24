<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * REQ-035/036/038/040/041/042 — ítem de una partida. Puede ser personalizado
 * (`is_custom=true`, sin `catalog_product_id`) o venir de un producto real
 * del Catálogo (`is_custom=false`) — en ambos casos `unit_price`/`cost` son
 * columnas propias del ítem: para un producto de catálogo se COPIAN una sola
 * vez al seleccionarlo (QuoteItemController), nunca se recalculan en vivo
 * desde `catalogProduct`. `unit_price` representa el Precio Público del ítem;
 * a partir de ahí REQ-033 deriva el resto de los tipos de precio con
 * `effectiveUnitPrice()`, con los porcentajes/divisores configurables vía
 * PricingSettings (no hardcodeados).
 *
 * @property int         $id
 * @property int          $quote_part_id
 * @property int          $position
 * @property int|null      $catalog_product_id
 * @property bool           $is_custom
 * @property string        $reference
 * @property string        $description
 * @property float          $quantity
 * @property float          $unit_price
 * @property float|null     $cost
 * @property string|null $price_type_override
 * @property float|null   $discount_percent
 * @property string|null  $photo_key
 * @property string|null  $photo_filename
 * @property \Carbon\Carbon|null $photo_uploaded_at
 * @property QuotePart    $quotePart
 * @property CatalogProduct|null $catalogProduct
 */
class QuoteItem extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = [
        'quote_part_id', 'position', 'catalog_product_id', 'is_custom', 'reference', 'description', 'quantity', 'unit_price', 'cost',
        'price_type_override', 'discount_percent',
        // SCRUM-849.6 — foto ad-hoc, solo poblada por QuoteItemPhotoService::uploadPhoto().
        'photo_key', 'photo_filename', 'photo_uploaded_at',
    ];

    /**
     * SCRUM-1161 — un ítem nuevo entra al final de su partida (orden de ingreso intacto); el
     * drag and drop solo reescribe `position` después. Cubre alta suelta, en lote, duplicar partida
     * y nueva versión sin tocar esos flujos: todos crean los ítems en orden.
     */
    protected static function booted(): void
    {
        static::creating(function (QuoteItem $item): void {
            if ($item->position === null) {
                $max = static::query()->where('quote_part_id', $item->quote_part_id)->max('position');
                $item->position = $max === null ? 0 : ((int) $max) + 1;
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'quantity' => 'float', 'unit_price' => 'float', 'cost' => 'float', 'discount_percent' => 'float',
            'is_custom' => 'boolean', 'photo_uploaded_at' => 'datetime',
        ];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'quote_items';
        }

        return "{$tenant->slug}_ventas_diseno.quote_items";
    }

    /** @return BelongsTo<QuotePart, $this> */
    public function quotePart(): BelongsTo
    {
        return $this->belongsTo(QuotePart::class);
    }

    /** @return BelongsTo<CatalogProduct, $this> */
    public function catalogProduct(): BelongsTo
    {
        return $this->belongsTo(CatalogProduct::class);
    }

    /**
     * REQ-033/042: precio derivado del tipo de precio efectivo del ítem
     * (`price_type_override` si está seteado, si no el de la cotización).
     * "public" se usa `unit_price` tal cual (es la base de la que parten
     * Proyecto/Especial). "partner"/"premium" parten de `cost` en vez de
     * `unit_price` — sin `cost` cargado no pueden derivarse, caen a
     * `unit_price` en vez de reventar el cálculo del documento. "partner" solo
     * llega acá desde el price_type general de la cotización — el override por
     * ítem nunca acepta "partner" (REQ-042, gateado por permiso). `$settings` se
     * recibe en vez de llamar `PricingSettings::current()` acá adentro para no
     * volver a consultarla por cada ítem de la cotización (N+1).
     */
    public function effectiveUnitPrice(string $quotePriceType, PricingSettings $settings): float
    {
        $priceType = $this->price_type_override ?? $quotePriceType;

        return match ($priceType) {
            'project' => round($this->unit_price * (1 - $settings->project_discount_percent / 100), 2),
            'special' => round(
                $this->unit_price
                * (1 - $settings->project_discount_percent / 100)
                * (1 - $settings->special_additional_discount_percent / 100),
                2,
            ),
            'partner' => $this->cost !== null ? round($this->cost / $settings->partner_divisor, 2) : $this->unit_price,
            'premium' => $this->cost !== null ? round($this->cost / $settings->premium_divisor, 2) : $this->unit_price,
            default => $this->unit_price,
        };
    }

    /**
     * REQ-041: en modo "line" usa el descuento propio de la línea; en modo
     * "global" usa el descuento general de la cotización, ignorando el de línea
     * (que queda en 0 de todas formas — ver QuoteService::update()).
     */
    public function lineTotal(string $discountMode, float $globalDiscountPercent, string $quotePriceType, PricingSettings $settings): float
    {
        $discountPercent = $discountMode === 'global' ? $globalDiscountPercent : ($this->discount_percent ?? 0);

        return round($this->effectiveUnitPrice($quotePriceType, $settings) * $this->quantity * (1 - $discountPercent / 100), 2);
    }

    /**
     * REQ-040/045 (Pre-QA SCRUM-132/137): precio por unidad ya con el
     * descuento aplicado — la misma base que usa `lineTotal()` pero sin
     * multiplicar por `quantity`, porque el margen se mide contra el costo
     * por unidad, no contra el total de la línea.
     */
    public function unitPriceAfterDiscount(string $discountMode, float $globalDiscountPercent, string $quotePriceType, PricingSettings $settings): float
    {
        $discountPercent = $discountMode === 'global' ? $globalDiscountPercent : ($this->discount_percent ?? 0);

        return round($this->effectiveUnitPrice($quotePriceType, $settings) * (1 - $discountPercent / 100), 2);
    }

    /**
     * REQ-040: true si, con el precio/descuento/tipo de precio resultantes, el
     * margen por unidad ((precio-costo)/precio) cae por debajo de
     * `PricingSettings::min_margin_percent`. Criterio 3: un ítem personalizado
     * SIN costo de catálogo (`cost === null`) nunca dispara esta validación —
     * no hay costo de referencia contra el cual proteger nada.
     *
     * "partner" (Precio Socio, REQ-034) también queda exento — decisión de
     * Luis 2026-07-15 tras encontrar el conflicto en Pre-QA: la fórmula
     * costo÷partner_divisor da un margen FIJO (hoy 15% con divisor 0.85,
     * matemáticamente el mismo sea cual sea el costo), y ya está gateada por
     * su propio permiso angosto `ventas_diseno.select_partner_price` — es un
     * descuento deliberado y aprobado para socios, no un override manual
     * arbitrario que el margen mínimo deba atajar. "premium" no necesita esta
     * excepción: con el divisor default (0.65) ya rinde ~35%, por encima del
     * mínimo default (30%) — solo dejaría de cumplir si alguien bajara mucho
     * `premium_divisor` desde Configuración, caso en el que SÍ debe bloquear.
     */
    public function violatesMinMargin(string $discountMode, float $globalDiscountPercent, string $quotePriceType, PricingSettings $settings): bool
    {
        if ($this->cost === null) {
            return false;
        }

        if (($this->price_type_override ?? $quotePriceType) === 'partner') {
            return false;
        }

        $price = $this->unitPriceAfterDiscount($discountMode, $globalDiscountPercent, $quotePriceType, $settings);

        if ($price <= 0) {
            return true;
        }

        $marginPercent = ($price - $this->cost) / $price * 100;

        return $marginPercent < $settings->min_margin_percent;
    }
}
