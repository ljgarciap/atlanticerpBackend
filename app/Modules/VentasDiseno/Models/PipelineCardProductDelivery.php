<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Models;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * SCRUM-909 — a qué fecha de entrega parcial (de las ya definidas en `PipelineCardDeliveryDate`
 * para esta misma tarjeta) queda asignado cada `QuoteItem` de la cotización del proyecto. Ver
 * docblock de la migración para el porqué de la clave por `quote_item_id` en vez de
 * `catalog_product_id`.
 *
 * @property int $id
 * @property int $pipeline_card_id
 * @property int $quote_item_id
 * @property int $pipeline_card_delivery_date_id
 */
class PipelineCardProductDelivery extends Model
{
    /** @var list<string> */
    protected $fillable = ['pipeline_card_id', 'quote_item_id', 'pipeline_card_delivery_date_id'];

    public function getTable(): string
    {
        $tenant = Tenant::current();
        if ($tenant === null) {
            return 'pipeline_card_product_deliveries';
        }

        return "{$tenant->slug}_ventas_diseno.pipeline_card_product_deliveries";
    }

    /** @return BelongsTo<PipelineCard, $this> */
    public function pipelineCard(): BelongsTo
    {
        return $this->belongsTo(PipelineCard::class);
    }

    /** @return BelongsTo<QuoteItem, $this> */
    public function quoteItem(): BelongsTo
    {
        return $this->belongsTo(QuoteItem::class);
    }

    /** @return BelongsTo<PipelineCardDeliveryDate, $this> */
    public function deliveryDate(): BelongsTo
    {
        return $this->belongsTo(PipelineCardDeliveryDate::class, 'pipeline_card_delivery_date_id');
    }
}
