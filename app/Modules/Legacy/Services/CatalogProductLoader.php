<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Legacy\Data\MappedProducto;
use App\Modules\Legacy\Models\Producto;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * SCRUM-750 — "Loader-B" (destino). Upsert idempotente en `ventas_diseno.catalog_products` por
 * `reference` — CRITERIO CONSERVADOR (decisión de diseño, spec del ticket lo pide explícitamente):
 *
 *  - `reference` NO existe todavía → INSERT, y `legacy.productos.catalog_product_id` queda
 *    seteado al nuevo id (marca "esto lo cargó este pipeline").
 *  - `reference` YA existe Y `legacy.productos.catalog_product_id` de este artículo apunta a ESE
 *    mismo `catalog_products.id` → es un re-run del mismo pipeline sobre el mismo artículo, UPDATE
 *    normal (idempotencia real, no solo en el staging).
 *  - `reference` YA existe pero NO fue cargada por este pipeline antes (nadie linkeó ese
 *    `catalog_product_id` desde `legacy.productos`) → NUNCA se pisa. Se cuenta como excluido con
 *    motivo explícito — un producto cargado a mano o por otra vía nunca se sobrescribe a ciegas.
 */
final class CatalogProductLoader
{
    /**
     * @param  list<MappedProducto> $mapped
     * @return array{
     *   loaded: int,
     *   excluded: int,
     *   errors: int,
     *   details: list<array{icg_codarticulo: int, reference: string, action: string, reason: string|null}>,
     * }
     */
    public function load(array $mapped): array
    {
        $loaded   = 0;
        $excluded = 0;
        $errors   = 0;
        $details  = [];

        foreach ($mapped as $item) {
            if ($item->isExcluded()) {
                $excluded++;
                $details[] = [
                    'icg_codarticulo' => $item->icgCodarticulo,
                    'reference'       => $item->reference,
                    'action'          => 'excluded',
                    'reason'          => $item->excludedReason,
                ];
                continue;
            }

            try {
                $action = $this->loadOne($item);
            } catch (Throwable $e) {
                $errors++;
                $details[] = [
                    'icg_codarticulo' => $item->icgCodarticulo,
                    'reference'       => $item->reference,
                    'action'          => 'error',
                    'reason'          => $e->getMessage(),
                ];
                continue;
            }

            if ($action === null) {
                $excluded++;
                $details[] = [
                    'icg_codarticulo' => $item->icgCodarticulo,
                    'reference'       => $item->reference,
                    'action'          => 'excluded',
                    'reason'          => 'reference ya existe en catalog_products, no cargada por este pipeline previamente — nunca se pisa',
                ];
                continue;
            }

            $loaded++;
            $details[] = [
                'icg_codarticulo' => $item->icgCodarticulo,
                'reference'       => $item->reference,
                'action'          => $action,
                'reason'          => null,
            ];
        }

        return compact('loaded', 'excluded', 'errors', 'details');
    }

    /** @return 'created'|'updated'|null */
    private function loadOne(MappedProducto $item): ?string
    {
        return DB::transaction(function () use ($item): ?string {
            $producto = Producto::query()->where('icg_codarticulo', $item->icgCodarticulo)->first();
            $existing = CatalogProduct::query()->where('reference', $item->reference)->first();

            if ($existing !== null) {
                $ownedByPipeline = $producto !== null && $producto->catalog_product_id === $existing->id;

                if (! $ownedByPipeline) {
                    return null;
                }

                // SCRUM-237/240 — `item->description` (ARTICULOS.DESCRIPCION en ICG) siempre fue
                // el nombre visible del producto, nunca una descripción larga aparte — pasa a
                // `name` (NOT NULL desde la migración 2026_08_15_100000_...), no a `description`
                // (ver docblock de la migración: vacía por default, nunca se pisa acá).
                //
                // `factory_reference` NO se actualiza acá (SCRUM-799, 2026-08-27) — desde el fix
                // de `ProductoMapper` este pipeline nunca vuelve a mandar un valor real para este
                // campo (`item->factoryReference` siempre es `null`), así que pisarlo en cada
                // re-corrida borraría en silencio cualquier valor que alguien haya completado a
                // mano en Inventario después de la migración. Solo se setea al CREAR (abajo).
                $existing->fill([
                    'name'       => $item->description,
                    'brand'      => $item->brand,
                    'price_full' => (float) $item->priceFull,
                    'cost'       => $item->cost,
                ]);
                $existing->save();

                return 'updated';
            }

            $catalogProduct = CatalogProduct::query()->create([
                'reference'         => $item->reference,
                'name'              => $item->description,
                'brand'             => $item->brand,
                'factory_reference' => $item->factoryReference,
                'price_full'        => (float) $item->priceFull,
                'cost'              => $item->cost,
                'is_active'         => true,
            ]);

            if ($producto !== null) {
                $producto->catalog_product_id = $catalogProduct->id;
                $producto->save();
            }

            return 'created';
        });
    }
}
