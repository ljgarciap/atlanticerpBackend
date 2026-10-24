<?php

declare(strict_types=1);

namespace App\Modules\Legacy\Services;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
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
 *  - `reference` YA existe, cargada por este pipeline, pero `is_active = false` (SCRUM-838,
 *    2026-09-14) → NUNCA se pisa tampoco, ni siquiera para refrescar name/brand/price_full/cost.
 *    Un producto desactivado en AtlanticERP (ej. limpieza de productos en desuso) queda desactivado
 *    para siempre de este lado, sin importar que ICG lo siga reportando activo — la limpieza es
 *    una decisión de negocio local, no algo que la sincronización deba deshacer sola. Requiere que
 *    la limpieza sea SIEMPRE desactivación lógica (is_active=false), nunca borrado físico de la
 *    fila: un borrado físico sí sería recreado por este Loader (no encuentra `reference`, cae al
 *    branch de INSERT como si fuera nuevo) — ver Illumination/migracion-icg-diseno.md, ticket
 *    SCRUM-838, sección "PROTECCIÓN — nunca resucitar un producto dado de baja localmente".
 */
final class CatalogProductLoader
{
    private const EXCLUDED_NOT_OWNED = 'excluded_not_owned';
    private const EXCLUDED_INACTIVE  = 'excluded_inactive';

    /** SCRUM-1016 — id de la "Bodega Central" operativa, resuelto UNA vez por `load()` (no una consulta por producto creado). `false` = aún sin resolver. */
    private int|false|null $bodegaCentralId = false;

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
        $this->bodegaCentralId = false;
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

            if ($action === self::EXCLUDED_NOT_OWNED || $action === self::EXCLUDED_INACTIVE) {
                $excluded++;
                $details[] = [
                    'icg_codarticulo' => $item->icgCodarticulo,
                    'reference'       => $item->reference,
                    'action'          => 'excluded',
                    'reason'          => $action === self::EXCLUDED_INACTIVE
                        ? 'producto desactivado localmente (is_active=false) — no se resincroniza'
                        : 'reference ya existe en catalog_products, no cargada por este pipeline previamente — nunca se pisa',
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

    /** @return 'created'|'updated'|'excluded_not_owned'|'excluded_inactive' */
    private function loadOne(MappedProducto $item): string
    {
        return DB::transaction(function () use ($item): string {
            $producto = Producto::query()->where('icg_codarticulo', $item->icgCodarticulo)->first();
            $existing = CatalogProduct::query()->where('reference', $item->reference)->first();

            if ($existing !== null) {
                $ownedByPipeline = $producto !== null && $producto->catalog_product_id === $existing->id;

                if (! $ownedByPipeline) {
                    return self::EXCLUDED_NOT_OWNED;
                }

                if (! $existing->is_active) {
                    return self::EXCLUDED_INACTIVE;
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
                // SCRUM-921 — seed de arranque desde AL4, SOLO al crear (nunca en el branch de
                // `update` de arriba). `null` = sin fila en AL4 (nunca tuvo movimiento ahí, mismo
                // significado que el resto de la columna, "disponibilidad no controlada"),
                // distinto de un stock real de 0. Negativos se preservan tal cual (decisión de
                // Luis 2026-09-20) — `stock_quantity` es integer, se redondea desde el float de ICG.
                'stock_quantity'    => $item->stockInicial !== null ? (int) round($item->stockInicial) : null,
            ]);

            if ($producto !== null) {
                $producto->catalog_product_id = $catalogProduct->id;
                // SCRUM-1114 — la línea base de la sincronización por delta nace con el stock inicial que se
                // acaba de reflejar (0 si ICG no tenía fila en AL4): el próximo delta parte de ahí.
                $producto->stock_al4_aplicado = $catalogProduct->stock_quantity ?? 0;
                $producto->save();
            }

            $this->sembrarBodegaCentral($catalogProduct, $producto);

            return 'created';
        });
    }

    /**
     * SCRUM-1016 — el stock inicial de ICG (AL4 = Bodega Central) se refleja también por bodega, en la MISMA transacción que crea el producto y con la misma
     * cantidad (negativos tal cual). SOLO al crear: en una re-sincronización o actualización nunca se toca (Bodega manda después). Si el tenant no tiene una
     * "Bodega Central" operativa no se hace nada (el comando `legacy:backfill-stock-bodega-central` cubre ese caso más adelante).
     */
    private function sembrarBodegaCentral(CatalogProduct $catalogProduct, ?Producto $producto): void
    {
        $stock = $catalogProduct->stock_quantity;
        // Un producto creado inactivo o descatalogado en ICG no recibe fila (mismo criterio que el comando de backfill).
        if ($stock === null || $stock === 0 || ! $catalogProduct->is_active || ($producto !== null && $producto->descatalogado)) {
            return;
        }

        if ($this->bodegaCentralId === false) {
            $id = Warehouse::query()->operativas()->where('name', 'Bodega Central')->value('id');
            $this->bodegaCentralId = $id === null ? null : (int) $id;
        }
        if ($this->bodegaCentralId === null) {
            return;
        }

        ProductWarehouseStock::query()->create(['catalog_product_id' => $catalogProduct->id, 'warehouse_id' => $this->bodegaCentralId, 'quantity' => $stock]);
    }
}
