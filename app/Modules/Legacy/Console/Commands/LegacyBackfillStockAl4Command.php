<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Console\Commands;

use App\Modules\Legacy\Models\Producto;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Console\Command;

/**
 * SCRUM-921 (épica SCRUM-749) — backfill de UNA SOLA CORRIDA de `catalog_products.stock_quantity`
 * para los productos YA migrados antes de este ticket: `CatalogProductLoader` nunca seteó esta
 * columna en ningún camino hasta ahora, así que la rama de creación (que sí la trae desde
 * SCRUM-921) nunca vuelve a ejecutarse para ellos — solo este comando los alcanza.
 *
 * Guard de seguridad — `whereNull('stock_quantity')`: una vez corrido con éxito, todo
 * `catalog_product` tocado queda con la columna en NO-null (el valor real de AL4, aunque sea 0),
 * así que una segunda corrida accidental de este mismo comando no encuentra nada que tocar — nunca
 * pisa un stock que Bodega ya viene gestionando en vivo desde el momento en que dejó de ser NULL.
 *
 * Mismo criterio "nunca pisa" que ya usa `CatalogProductLoader` para `is_active=false`: un producto
 * desactivado localmente no se toca acá tampoco.
 *
 * Alcance: solo `legacy.productos` con `catalog_product_id` seteado (cargado por este pipeline,
 * mismo criterio "ownedByPipeline" de `CatalogProductLoader`) Y `stock_al4` no nulo (el artículo
 * tuvo alguna fila real en AL4 — sin eso no hay nada que backfillear, la columna se queda en su
 * NULL actual, "disponibilidad no controlada"). Negativos se preservan tal cual (decisión de Luis
 * 2026-09-20).
 */
final class LegacyBackfillStockAl4Command extends Command
{
    /** @var string */
    protected $signature = 'legacy:backfill-stock-al4
        {--tenant=atlantic : Slug del tenant a corregir}
        {--dry-run : Solo reporta lo que backfillearía, sin escribir}';

    /** @var string */
    protected $description = 'SCRUM-921 — backfill de una sola corrida de catalog_products.stock_quantity desde legacy.productos.stock_al4, para productos ya migrados antes de este ticket';

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

        $backfilled      = 0;
        $skippedInactive = 0;

        /** @var Producto $producto */
        foreach (
            Producto::query()
                ->whereNotNull('catalog_product_id')
                ->whereNotNull('stock_al4')
                ->with('catalogProduct')
                ->get() as $producto
        ) {
            $catalogProduct = $producto->catalogProduct;

            if ($catalogProduct === null || $catalogProduct->stock_quantity !== null) {
                continue;
            }

            if (! $catalogProduct->is_active) {
                $skippedInactive++;

                continue;
            }

            // whereNotNull('stock_al4') en la query ya garantiza esto — cast defensivo para PHPStan.
            $stockQuantity = (int) round((float) $producto->stock_al4);

            $this->line("catalog_product #{$catalogProduct->id} ({$catalogProduct->reference}) — stock_quantity={$stockQuantity} (icg_codarticulo={$producto->icg_codarticulo})");

            if (! $dryRun) {
                $catalogProduct->update(['stock_quantity' => $stockQuantity]);
            }

            $backfilled++;
        }

        $this->info(
            ($dryRun ? 'Dry-run — ' : '')
            ."{$backfilled} catalog_products backfilleados. {$skippedInactive} omitidos por is_active=false (desactivados localmente, nunca se resincronizan)."
        );

        return self::SUCCESS;
    }
}
