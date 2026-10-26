<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Services;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Compras\Services\InventoryKpiService;
use Illuminate\Support\Collection;

/**
 * SCRUM-403 (REQ-333) — extraído de `AdjustmentRequestController::productWarehouseStock()`
 * (SCRUM-428, sin tocar su comportamiento/contrato) para reusar el mismo desglose por bodega
 * desde el nuevo endpoint agregado por pedido (`OrderBoardController::warehouseBreakdown()`,
 * "Ver bodegas" del tablero de Pedidos) sin duplicar la query — un solo lugar calcula "cantidad
 * por bodega + por_servir" para un producto.
 */
final class WarehouseStockService
{
    public function __construct(private readonly InventoryKpiService $inventoryKpis) {}

    /** @return array{por_servir: int, warehouses: list<array{warehouse_id: int, name: string, quantity: int}>} */
    public function breakdownForProduct(int $productId): array
    {
        return $this->breakdownForProducts([$productId])[$productId]
            ?? ['por_servir' => 0, 'warehouses' => $this->emptyWarehouseRows()];
    }

    /**
     * REQ-333 — mismo desglose que `breakdownForProduct()`, para todos los productos de un pedido
     * a la vez (evita que el frontend haga N requests, una por artículo, al abrir "Ver bodegas").
     *
     * @param list<int> $productIds
     * @return array<int, array{por_servir: int, warehouses: list<array{warehouse_id: int, name: string, quantity: int}>}> keyed by catalog_product_id
     */
    public function breakdownForProducts(array $productIds): array
    {
        /** @var list<int> $productIds */
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $porServirMap = $this->inventoryKpis->porServirMap($productIds);

        /** @var Collection<int, Warehouse> $warehouses */
        $warehouses = Warehouse::query()->orderBy('id')->get();

        /** @var Collection<int, Collection<int, ProductWarehouseStock>> $stockByProduct */
        $stockByProduct = ProductWarehouseStock::whereIn('catalog_product_id', $productIds)
            ->get()
            ->groupBy('catalog_product_id');

        $result = [];
        foreach ($productIds as $productId) {
            /** @var Collection<int, ProductWarehouseStock> $rows */
            $rows             = $stockByProduct->get($productId, collect());
            $stockByWarehouse = $rows->pluck('quantity', 'warehouse_id');

            $result[$productId] = [
                'por_servir' => (int) ($porServirMap[$productId] ?? 0),
                'warehouses' => array_values($warehouses->map(fn (Warehouse $w) => [
                    'warehouse_id' => $w->id,
                    'name'         => $w->name,
                    'quantity'     => (int) ($stockByWarehouse[$w->id] ?? 0),
                ])->all()),
            ];
        }

        return $result;
    }

    /** @return list<array{warehouse_id: int, name: string, quantity: int}> */
    private function emptyWarehouseRows(): array
    {
        return array_values(Warehouse::query()->orderBy('id')->get()->map(fn (Warehouse $w) => [
            'warehouse_id' => $w->id,
            'name'         => $w->name,
            'quantity'     => 0,
        ])->all());
    }
}
