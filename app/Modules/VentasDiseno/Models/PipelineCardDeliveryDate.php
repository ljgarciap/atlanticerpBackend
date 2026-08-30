<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int             $id
 * @property int             $pipeline_card_id
 * @property \Carbon\Carbon  $date
 */
class PipelineCardDeliveryDate extends Model
{
    /** @var list<string> */
    protected $fillable = ['pipeline_card_id', 'date'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['date' => 'date'];
    }

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'pipeline_card_delivery_dates';
        }

        return "{$tenant->slug}_ventas_diseno.pipeline_card_delivery_dates";
    }

    /** @return BelongsTo<PipelineCard, $this> */
    public function pipelineCard(): BelongsTo
    {
        return $this->belongsTo(PipelineCard::class);
    }
}
