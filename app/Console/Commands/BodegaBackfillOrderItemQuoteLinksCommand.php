<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Modules\Bodega\Models\OrderItem;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteItem;
use App\Shared\Multitenancy\Tenant;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * SCRUM-929 (B1a) — backfill best-effort de `order_items.quote_item_id` (y, de paso, de
 * `unit_price` cuando también está en NULL) para ítems creados ANTES de que Bodega propagara el
 * vínculo `OrderItem` → `QuoteItem`. Pedidos nuevos no necesitan este comando.
 *
 * Conservador a propósito — dejar un ítem sin vínculo es seguro (la facturación degrada a modo
 * `completo` para ese pedido, ver ADR-SCRUM929); un vínculo adivinado mal podría cobrar de más o de
 * menos. Un ítem se vincula solo si TODO lo siguiente se cumple:
 *  1. Cotización "vigente al crear el pedido": la más reciente con folio del proyecto cuyo
 *     `generated_at` sea <= la fecha de creación de la tarjeta RAÍZ de la familia. NO la más
 *     reciente a secas: una versión posterior de la cotización puede tener otra cantidad u otro
 *     precio (Senior Review MEDIO-1). Se usa la raíz y no la tarjeta del ítem porque las tarjetas
 *     derivadas (Faltante, Revisión) nacen días después que el pedido.
 *  2. Exactamente UN `QuoteItem` candidato por `catalog_product_id` (o por `reference` en ítems
 *     personalizados) después de aplicar los filtros 3 y 4.
 *  3. Cantidad consistente: el ítem nunca pide más de lo cotizado (`qty_requested <= cantidad`).
 *  4. Precio consistente: si el ítem YA trae `unit_price`, debe coincidir (±0.01) con el precio
 *     efectivo del QuoteItem candidato.
 *
 * Idempotente: solo toca filas con `quote_item_id IS NULL`. Como en SCRUM-786, `--dry-run` es
 * opt-in: la PRIMERA corrida en test/prod debe hacerse con `--dry-run` y revisar la salida a mano.
 * Para entregas parciales de SCRUM-909, `PipelineCardProductDelivery` sería la fuente exacta del
 * reparto; no se usa acá — los filtros de arriba alcanzan y lo ambiguo simplemente se omite.
 */
final class BodegaBackfillOrderItemQuoteLinksCommand extends Command
{
    /** @var string */
    protected $signature = 'bodega:backfill-order-item-quote-links
        {--tenant=atlantic : Slug del tenant a corregir}
        {--dry-run : Solo reporta lo que backfillearía, sin escribir (usar SIEMPRE en la primera corrida de test/prod)}';

    /** @var string */
    protected $description = 'SCRUM-929 — backfill best-effort de quote_item_id (y unit_price nulo) en order_items de pedidos creados antes del vínculo';

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

        /** @var array<int, Quote|null> $quoteCache id de la tarjeta RAÍZ => cotización vigente al crear el pedido (o null) */
        $quoteCache = [];

        $linked           = 0;
        $pricesFilled     = 0;
        $skippedNoQuote   = 0;
        $skippedAmbiguous = 0;

        /** @var OrderItem $item */
        foreach (OrderItem::whereNull('quote_item_id')->with('order.pipelineCard')->get() as $item) {
            $card = $item->order->pipelineCard;

            if ($card === null) {
                $skippedNoQuote++;

                continue;
            }

            $root = $item->order->familyRoot();
            if (! array_key_exists($root->id, $quoteCache)) {
                $quoteCache[$root->id] = $this->quoteAsOfOrder($card, $root->created_at);
            }
            $quote = $quoteCache[$root->id];

            if ($quote === null) {
                $skippedNoQuote++;

                continue;
            }

            $candidates = $this->matchingQuoteItems($item, $quote, $settings);

            if ($candidates->count() !== 1) {
                $skippedAmbiguous++;

                continue;
            }

            /** @var QuoteItem $quoteItem */
            $quoteItem = $candidates->first();
            $update    = ['quote_item_id' => $quoteItem->id];

            if ($item->unit_price === null) {
                $update['unit_price'] = $this->effectivePrice($quoteItem, $quote, $settings);
                $pricesFilled++;
            }

            $this->line("OrderItem #{$item->id} (order #{$item->order_id}) — quote_item_id={$quoteItem->id} (cotización {$quote->folio})"
                .(isset($update['unit_price']) ? " unit_price={$update['unit_price']}" : ''));

            if (! $dryRun) {
                $item->update($update);
            }

            $linked++;
        }

        $this->info(
            ($dryRun ? 'Dry-run — ' : '')
            ."order_items: {$linked} vinculados ({$pricesFilled} con unit_price rellenado), "
            ."{$skippedNoQuote} sin cotización vigente resoluble, {$skippedAmbiguous} ambiguos o inconsistentes (omitidos)."
        );

        return self::SUCCESS;
    }

    /** Cotización con folio más reciente cuyo `generated_at` no supera la creación del pedido. */
    private function quoteAsOfOrder(PipelineCard $card, CarbonInterface $orderCreatedAt): ?Quote
    {
        if ($card->sales_project_id === null) {
            return null;
        }

        return Quote::with(['parts.items.catalogProduct'])
            ->where('sales_project_id', $card->sales_project_id)
            ->whereNotNull('folio')
            ->where('generated_at', '<=', $orderCreatedAt)
            ->orderByDesc('generated_at')
            ->first();
    }

    /** @return Collection<int, QuoteItem> */
    private function matchingQuoteItems(OrderItem $item, Quote $quote, PricingSettings $settings): Collection
    {
        $allItems = $quote->parts->flatMap(fn ($part) => $part->items);

        $byProduct = $item->catalog_product_id !== null
            ? $allItems->filter(fn (QuoteItem $qi) => $qi->catalog_product_id === $item->catalog_product_id)
            : $allItems->filter(fn (QuoteItem $qi) => $qi->catalog_product_id === null && $qi->reference === $item->reference_snapshot);

        return $byProduct
            // Cantidad: un ítem de pedido (o de una tarjeta derivada) nunca pide más de lo cotizado.
            ->filter(fn (QuoteItem $qi): bool => $item->qty_requested <= (int) round($qi->quantity))
            // Precio: si el ítem ya trae snapshot, tiene que coincidir con el QuoteItem candidato.
            ->filter(function (QuoteItem $qi) use ($item, $quote, $settings): bool {
                if ($item->unit_price === null) {
                    return true;
                }

                return abs($this->effectivePrice($qi, $quote, $settings) - $item->unit_price) < 0.01;
            })
            ->values();
    }

    private function effectivePrice(QuoteItem $quoteItem, Quote $quote, PricingSettings $settings): float
    {
        return (float) $quoteItem->unitPriceAfterDiscount(
            $quote->discount_mode,
            $quote->global_discount_percent,
            $quote->price_type,
            $settings,
        );
    }
}
