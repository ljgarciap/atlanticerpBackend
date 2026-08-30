<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Bodega\Models\CustomerReturnLine;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Services\OrderService;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * SCRUM-786 — backfill best-effort de `unit_price` para `order_items`/`customer_return_lines`
 * creados ANTES del fix (`OrderService::createItemsFromQuote()` ahora lo snapshotea al crear el
 * pedido — ver esa clase). Pedidos nuevos no necesitan este comando.
 *
 * No hay FK directa `OrderItem` → `QuoteItem` de origen (nunca existió) — acá se hace lo mismo que
 * `OrderService::resolveApprovedQuote()` para encontrar la cotización probable (convención de
 * negocio Order→PipelineCard→sales_project_id→Quote más reciente, NO garantizada) y dentro de esa
 * cotización se intenta emparejar cada `OrderItem` con exactamente un `QuoteItem` por
 * `catalog_product_id` (o `reference_snapshot` para ítems personalizados). Si el emparejamiento no
 * es único (0 o 2+ candidatos) se OMITE esa fila — mejor dejarla en NULL (mismo comportamiento que
 * hoy, $0.00 en Notas de Crédito) que adivinar un precio incorrecto.
 *
 * Idempotente: solo toca filas con `unit_price IS NULL`; correrlo de nuevo tras una corrida
 * exitosa no encuentra nada que rehacer en esas filas (las que quedaron ambiguas siguen
 * ambiguas — no hay forma de que una segunda corrida resuelva la ambigüedad).
 */
final class BodegaBackfillOrderItemPricesCommand extends Command
{
    /** @var string */
    protected $signature = 'bodega:backfill-order-item-prices
        {--tenant=atlantic : Slug del tenant a corregir}
        {--dry-run : Solo reporta lo que backfillearía, sin escribir}';

    /** @var string */
    protected $description = 'SCRUM-786 — backfill best-effort de unit_price en order_items/customer_return_lines de pedidos creados antes del fix';

    public function __construct(private readonly OrderService $orders)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $tenantSlug = (string) $this->option('tenant');
        $dryRun     = (bool) $this->option('dry-run');

        $tenant = Tenant::where('slug', $tenantSlug)->first();
        if ($tenant === null) {
            $this->error("Tenant '{$tenantSlug}' no existe.");

            return self::FAILURE;
        }

        Tenant::forgetCurrent();
        $tenant->makeCurrent();

        $settings = PricingSettings::current();

        /** @var array<int, Quote|null> $quoteCache pipeline_card_id => cotización resuelta (o null), para no repetir la búsqueda por cada OrderItem del mismo pedido */
        $quoteCache = [];

        $backfilled = 0;
        $skippedNoQuote = 0;
        $skippedAmbiguous = 0;

        /** @var OrderItem $item */
        foreach (OrderItem::whereNull('unit_price')->with('order.pipelineCard')->get() as $item) {
            $card = $item->order->pipelineCard;

            if ($card === null) {
                $skippedNoQuote++;

                continue;
            }

            if (! array_key_exists($card->id, $quoteCache)) {
                $quoteCache[$card->id] = $this->orders->resolveApprovedQuote($card);
            }
            $quote = $quoteCache[$card->id];

            if ($quote === null) {
                $skippedNoQuote++;

                continue;
            }

            $candidates = $this->matchingQuoteItems($item, $quote);

            if ($candidates->count() !== 1) {
                $skippedAmbiguous++;

                continue;
            }

            /** @var QuoteItem $quoteItem */
            $quoteItem = $candidates->first();
            $unitPrice = $quoteItem->unitPriceAfterDiscount(
                $quote->discount_mode,
                $quote->global_discount_percent,
                $quote->price_type,
                $settings,
            );

            $this->line("OrderItem #{$item->id} (order #{$item->order_id}) — unit_price={$unitPrice} (desde QuoteItem #{$quoteItem->id})");

            if (! $dryRun) {
                $item->update(['unit_price' => $unitPrice]);
            }

            $backfilled++;
        }

        $propagated = 0;
        /** @var CustomerReturnLine $line */
        foreach (CustomerReturnLine::whereNull('unit_price')->whereHas('orderItem', fn ($q) => $q->whereNotNull('unit_price'))->with('orderItem')->get() as $line) {
            $this->line("CustomerReturnLine #{$line->id} — unit_price={$line->orderItem->unit_price} (heredado de OrderItem #{$line->order_item_id})");

            if (! $dryRun) {
                $line->update(['unit_price' => $line->orderItem->unit_price]);
            }

            $propagated++;
        }

        $this->info(
            ($dryRun ? 'Dry-run — ' : '')
            ."order_items: {$backfilled} backfilleados, {$skippedNoQuote} sin cotización resoluble, {$skippedAmbiguous} con emparejamiento ambiguo (omitidos). "
            ."customer_return_lines: {$propagated} heredados de su OrderItem."
        );

        return self::SUCCESS;
    }

    /** @return Collection<int, QuoteItem> */
    private function matchingQuoteItems(OrderItem $item, Quote $quote): Collection
    {
        $allItems = $quote->parts->flatMap(fn ($part) => $part->items);

        if ($item->catalog_product_id !== null) {
            return $allItems->filter(fn (QuoteItem $qi) => $qi->catalog_product_id === $item->catalog_product_id)->values();
        }

        return $allItems->filter(fn (QuoteItem $qi) => $qi->catalog_product_id === null && $qi->reference === $item->reference_snapshot)->values();
    }
}
