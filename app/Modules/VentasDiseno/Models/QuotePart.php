<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\LogsAuditTrail;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * REQ-035: Partida — agrupador de ítems dentro de una cotización.
 *
 * @property int    $id
 * @property int    $quote_id
 * @property string $name
 * @property int    $position
 * @property Quote  $quote
 * @property Collection<int, QuoteItem> $items
 */
class QuotePart extends Model
{
    use LogsAuditTrail;

    /** @var list<string> */
    protected $fillable = ['quote_id', 'name', 'position'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'quote_parts';
        }

        return "{$tenant->slug}_ventas_diseno.quote_parts";
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }

    /** @return HasMany<QuoteItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(QuoteItem::class);
    }

    /**
     * REQ-035: subtotal de la partida — suma de los total_linea de sus ítems.
     */
    public function subtotal(string $discountMode, float $globalDiscountPercent, string $quotePriceType, PricingSettings $settings): float
    {
        return round(
            $this->items->sum(fn (QuoteItem $item) => $item->lineTotal($discountMode, $globalDiscountPercent, $quotePriceType, $settings)),
            2,
        );
    }
}
