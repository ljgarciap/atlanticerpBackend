<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int             $id
 * @property int             $quote_id
 * @property \Carbon\Carbon  $date
 */
class QuoteDeliveryDate extends Model
{
    /** @var list<string> */
    protected $fillable = ['quote_id', 'date'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'quote_delivery_dates';
        }

        return "{$tenant->slug}_ventas_diseno.quote_delivery_dates";
    }

    /** @return BelongsTo<Quote, $this> */
    public function quote(): BelongsTo
    {
        return $this->belongsTo(Quote::class);
    }
}
