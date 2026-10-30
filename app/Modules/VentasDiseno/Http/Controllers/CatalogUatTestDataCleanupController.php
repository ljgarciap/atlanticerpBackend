<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\CatalogProductFamily;
use App\Shared\Multitenancy\Tenant;
use Database\Seeders\CatalogUatTestDataSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-745 — borra la data de prueba UAT del Catálogo (reference `UAT-%`,
 * familias `[PRUEBA UAT] %`) una vez terminado el UAT. Mismo precedente que
 * tenía DemoCleanupController (Auth, removido 2026-09-17), adaptado a las FKs reales de
 * `catalog_products`: a diferencia de `users`, varias tablas de
 * Bodega/Compras usan `restrictOnDelete()` sobre `catalog_product_id`
 * (replacement_requests) — si un producto UAT
 * quedó referenciado ahí, se excluye de la borrada en vez de reventar toda
 * la transacción; se reporta en `skipped_products` para que alguien lo
 * resuelva a mano si hace falta. Sin limpieza de storage — `photo_url` de
 * estos productos apunta a un endpoint que genera la imagen al vuelo
 * (CatalogUatPlaceholderPhotoController), no hay ningún archivo real que
 * borrar.
 */
class CatalogUatTestDataCleanupController
{
    public function __invoke(Request $request): JsonResponse
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            return response()->json(['message' => 'No active tenant.'], 400);
        }

        $catalogSchema = "{$tenant->slug}_ventas_diseno";

        $productIds = CatalogProduct::query()
            ->where('reference', 'like', 'UAT-%')
            ->pluck('id');

        if ($productIds->isEmpty()) {
            return response()->json([
                'message'          => 'No UAT test data found.',
                'deleted_products' => 0,
                'deleted_families' => 0,
                'skipped_products' => [],
            ]);
        }

        $blockedIds = $this->findRestrictedProductIds($tenant->slug, $catalogSchema, $productIds->all());
        $deletableIds = $productIds->diff($blockedIds);

        $deletedProducts = 0;
        $deletedFamilies = 0;

        DB::transaction(function () use ($deletableIds, &$deletedProducts, &$deletedFamilies): void {
            // Pivot + FKs con cascadeOnDelete se limpian solas (families, quote_items
            // nullOnDelete, product_warehouse_stock cascadeOnDelete, etc).
            $deletedProducts = CatalogProduct::query()->whereIn('id', $deletableIds)->delete();
            $deletedFamilies = CatalogProductFamily::query()
                ->where('name', 'like', CatalogUatTestDataSeeder::FAMILY_PREFIX . '%')
                ->delete();
        });

        return response()->json([
            'message'          => 'UAT test data removed.',
            'deleted_products' => $deletedProducts,
            'deleted_families' => $deletedFamilies,
            'skipped_products' => $blockedIds->values(),
        ]);
    }

    /**
     * Mismo enfoque dinámico que DemoCleanupController: lee el grafo de FKs reales
     * apuntando a catalog_products en vez de mantener una lista hardcodeada de
     * tablas Bodega/Compras que rota con cada módulo nuevo. A diferencia de
     * `users` (siempre en `{tenant}_auth`), `catalog_products` vive en
     * `{tenant}_ventas_diseno` pero está referenciado desde OTROS schemas del
     * mismo tenant (bodega, compras) — `tc.table_schema` busca en cualquier
     * schema `{tenant}_%`, no solo en el de catalog_products.
     *
     * @param array<int, int> $productIds
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function findRestrictedProductIds(string $tenantSlug, string $catalogSchema, array $productIds): \Illuminate\Support\Collection
    {
        if ($productIds === []) {
            return collect();
        }

        $foreignKeys = DB::select(
            <<<'SQL'
                SELECT tc.table_schema, tc.table_name, kcu.column_name, rc.delete_rule
                FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu
                    ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
                JOIN information_schema.referential_constraints rc
                    ON tc.constraint_name = rc.constraint_name AND tc.constraint_schema = rc.constraint_schema
                JOIN information_schema.constraint_column_usage ccu
                    ON rc.unique_constraint_name = ccu.constraint_name AND rc.unique_constraint_schema = ccu.constraint_schema
                WHERE tc.constraint_type = 'FOREIGN KEY'
                    AND ccu.table_schema = ?
                    AND ccu.table_name = 'catalog_products'
                    AND tc.table_schema LIKE ?
            SQL,
            [$catalogSchema, "{$tenantSlug}_%"],
        );

        $blocked = collect();

        foreach ($foreignKeys as $fk) {
            if (! in_array($fk->delete_rule, ['NO ACTION', 'RESTRICT'], true)) {
                continue;
            }

            $ids = DB::table("{$fk->table_schema}.{$fk->table_name}")
                ->whereIn($fk->column_name, $productIds)
                ->pluck($fk->column_name);

            $blocked = $blocked->merge($ids);
        }

        return $blocked->unique()->values();
    }
}
