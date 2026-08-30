<?php

declare(strict_types=1);

namespace App\Modules\Compras\Services;

use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Support\Collection;

/**
 * REQ-169 (Inventario) — extraído de InventoryController para reusarlo byte-a-byte en
 * REQ-204 (Reportes: "Inventario: rotación y atención"), que exige los mismos números que ya
 * muestra Inventario, nunca un cálculo paralelo que pueda desincronizarse.
 */
final class InventoryKpiService
{
    public const STOCK_DISPONIBLE = 'disponible';
    public const STOCK_BAJO_STOCK = 'bajo_stock';
    public const STOCK_SIN_STOCK = 'sin_stock';

    /**
     * SCRUM-265 (REQ-202) — mismo umbral `disponible <= reorder_point` que `format()`/`kpis()`,
     * para un solo producto. Usado al crear una orden (`PurchaseOrderController::replaceLines()`)
     * para capturar en qué estado estaba el producto EN ESE MOMENTO — el estado actual no sirve
     * retroactivamente, por eso esto se llama en el momento de creación, no después.
     *
     * `$porServir` es opcional: si el caller ya tiene el mapa precalculado para varios productos
     * (ver `porServirMap()`), lo pasa para evitar una query por línea en un loop — mismo patrón
     * que `kpis()`. Si se omite, se calcula acá para un uso puntual de un solo producto.
     */
    public function stockState(CatalogProduct $product, ?int $porServir = null): string
    {
        $porServir ??= $this->porServirMap([$product->id])[$product->id] ?? 0;
        $disponible = ($product->stock_quantity ?? 0) - $porServir;

        return match (true) {
            $disponible <= 0 => self::STOCK_SIN_STOCK,
            $disponible <= ($product->reorder_point ?? 0) => self::STOCK_BAJO_STOCK,
            default => self::STOCK_DISPONIBLE,
        };
    }

    /** @return array{total_products: int, low_stock: int, out_of_stock: int, in_attention: int, total_value: float} */
    public function kpis(): array
    {
        /** @var Collection<int, CatalogProduct> $active */
        $active = CatalogProduct::where('is_active', true)->get();

        $porServirMap = $this->porServirMap(array_values(array_map('intval', $active->pluck('id')->all())));

        $lowStock = 0;
        $outOfStock = 0;
        $inAttention = 0;
        $totalValue = 0.0;

        foreach ($active as $product) {
            $disponible = ($product->stock_quantity ?? 0) - ($porServirMap[$product->id] ?? 0);
            if ($disponible <= 0) {
                $outOfStock++;
            } elseif ($disponible <= ($product->reorder_point ?? 0)) {
                $lowStock++;
            }
            if ($this->isInAttention($product, $porServirMap[$product->id] ?? 0)) {
                $inAttention++;
            }
            $totalValue += (float) $product->price_full * (float) ($product->stock_quantity ?? 0);
        }

        return [
            'total_products' => $active->count(),
            'low_stock'      => $lowStock,
            'out_of_stock'   => $outOfStock,
            'in_attention'   => $inAttention,
            'total_value'    => round($totalValue, 2),
        ];
    }

    /**
     * REQ-169 RN/Escenario 1: rotación "baja" nunca cuenta en "En atención", aunque sí en
     * "Sin stock"/"Bajo stock". Sin clasificar (`rotation === null`) SÍ cuenta — conservador.
     */
    public function isInAttention(CatalogProduct $product, int $porServir): bool
    {
        if ($product->rotation === CatalogProduct::ROTATION_BAJA) {
            return false;
        }

        $disponible = ($product->stock_quantity ?? 0) - $porServir;

        return $disponible <= ($product->reorder_point ?? 0);
    }

    /**
     * SCRUM-168 RN2 ("Bajo stock sin ordenar", tarjeta "Salud · Compras" de Gerencia,
     * 2026-08-26) — subconjunto de bajo_stock/sin_stock que además NO tiene ninguna unidad "en
     * camino" (`enCaminoMap()` — ninguna `PurchaseOrderLine` pendiente de recibir en una orden
     * abierta). Reusa `stockState()`/`enCaminoMap()`, nunca una clasificación paralela.
     */
    public function isLowStockWithoutOpenOrder(CatalogProduct $product, int $porServir, int $enCamino): bool
    {
        $state = $this->stockState($product, $porServir);

        return ($state === self::STOCK_BAJO_STOCK || $state === self::STOCK_SIN_STOCK) && $enCamino <= 0;
    }

    /** Conteo para la tarjeta "Salud · Compras" de Gerencia (SCRUM-168 RN2). */
    public function lowStockWithoutOpenOrderCount(): int
    {
        /** @var Collection<int, CatalogProduct> $active */
        $active = CatalogProduct::where('is_active', true)->get();
        $ids    = array_values(array_map('intval', $active->pluck('id')->all()));

        $porServirMap = $this->porServirMap($ids);
        $enCaminoMap  = $this->enCaminoMap($ids);

        $count = 0;
        foreach ($active as $product) {
            if ($this->isLowStockWithoutOpenOrder($product, $porServirMap[$product->id] ?? 0, $enCaminoMap[$product->id] ?? 0)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * REQ-171 RN3: "Por servir" = unidades con proyecto asignado en su línea de origen, sin
     * importar el estado de la orden.
     *
     * @param list<int> $productIds
     * @return array<int, int>
     */
    public function porServirMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return PurchaseOrderLine::query()
            ->whereIn('catalog_product_id', $productIds)
            ->whereNotNull('sales_project_id')
            ->selectRaw('catalog_product_id, SUM(quantity) as total')
            ->groupBy('catalog_product_id')
            ->pluck('total', 'catalog_product_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * REQ-172 RN1-RN4 (Ingreso de Mercancía) — unidades que YA llegaron físicamente pero faltan
     * confirmar en Inventario: línea de un ingreso (`purchase_order_receipt_lines`) con
     * `confirmed_at` NULL. No cuenta como "Disponible" hasta que se confirme
     * (`GoodsReceiptController::confirm()`).
     *
     * @param list<int> $productIds
     * @return array<int, int>
     */
    public function porIngresarMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return PurchaseOrderReceiptLine::query()
            ->whereIn('catalog_product_id', $productIds)
            ->whereNull('confirmed_at')
            ->selectRaw('catalog_product_id, SUM(quantity) as total')
            ->groupBy('catalog_product_id')
            ->pluck('total', 'catalog_product_id')
            ->map(fn ($v) => (int) $v)
            ->all();
    }

    /**
     * REQ-172 RN1-RN4 — unidades que TODAVÍA no llegaron de ninguna forma: para cada orden
     * abierta (no-Recibida), lo esperado menos TODO lo ya registrado para esa orden y producto
     * (confirmado o no — mismo cálculo que `GoodsReceiptController::receivedByProduct()`, acá
     * agregado a través de TODAS las órdenes abiertas a la vez en vez de una sola). También usado
     * por `InventoryController::orderPrefill()` (REQ-179), que antes de este método sumaba
     * directamente lo esperado sin restar lo ya registrado.
     *
     * @param list<int> $productIds
     * @return array<int, int>
     */
    public function enCaminoMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        /** @var Collection<string, int> $expected */
        $expected = PurchaseOrderLine::query()
            ->whereIn('catalog_product_id', $productIds)
            ->whereHas('purchaseOrder', fn ($q) => $q->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO))
            ->get()
            ->groupBy(fn (PurchaseOrderLine $l) => "{$l->purchase_order_id}:{$l->catalog_product_id}")
            ->map(fn (Collection $lines) => (int) $lines->sum('quantity'));

        /** @var Collection<string, int> $registered */
        $registered = PurchaseOrderReceiptLine::query()
            ->whereIn('catalog_product_id', $productIds)
            ->whereHas('receipt.purchaseOrder', fn ($q) => $q->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO))
            ->with('receipt:id,purchase_order_id')
            ->get()
            ->groupBy(fn (PurchaseOrderReceiptLine $l) => "{$l->receipt?->purchase_order_id}:{$l->catalog_product_id}")
            ->map(fn (Collection $lines) => (int) $lines->sum('quantity'));

        $enCamino = [];
        foreach ($expected as $key => $qty) {
            $productId = (int) explode(':', $key, 2)[1];
            $remaining = max(0, $qty - ($registered[$key] ?? 0));
            $enCamino[$productId] = ($enCamino[$productId] ?? 0) + $remaining;
        }

        return $enCamino;
    }

    /**
     * SCRUM-234/238 (hallazgo de Daniela Amaya 2026-08-09) — columna "Bodega(s)" de la tabla de
     * Inventario: para cada producto, la lista de bodegas donde tiene unidades físicas > 0 (nunca
     * las bodegas en 0 — mismo criterio que `InventoryController::warehouseBreakdown()`, que ya
     * resolvía esto para UN producto a la vez desde el modal de detalle). Este método agrega el
     * mismo dato para una PÁGINA completa de productos en una sola query, para no hacer N+1 al
     * pintar la tabla. Fuente única de verdad — el modal de detalle sigue usando el endpoint
     * `/warehouse-stock` existente, no se duplicó la query.
     *
     * @param list<int> $productIds
     * @return array<int, list<array{warehouse_id: int, warehouse_name: string|null, quantity: int}>>
     */
    public function warehousesMap(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $rows = ProductWarehouseStock::with('warehouse')
            ->whereIn('catalog_product_id', $productIds)
            ->where('quantity', '>', 0)
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[$row->catalog_product_id][] = [
                'warehouse_id'   => $row->warehouse_id,
                'warehouse_name' => $row->warehouse?->name,
                'quantity'       => $row->quantity,
            ];
        }

        return $map;
    }

    /**
     * SCRUM-329 Bloque B2 (REQ-354/424, Ver Inventario de Bodega) — mismo criterio que
     * `enCaminoMap()` (orden no-Recibida, remanente esperado menos ya registrado), pero exponiendo
     * el detalle por orden (proveedor + fecha estimada + cantidad) en vez de solo el agregado, para
     * el drill-down de la columna "En camino". `[]` si no hay nada en camino — el caller (endpoint)
     * lo traduce en "no hay órdenes en camino" en vez de abrir un modal vacío sin explicación
     * (REQ-354 Escenario 2).
     *
     * @return list<array{purchase_order_id: int, provider_name: string|null, quantity: int, estimated_arrival_date: string|null}>
     */
    public function enCaminoDetail(int $productId): array
    {
        /** @var Collection<int, PurchaseOrder> $purchaseOrders */
        $purchaseOrders = PurchaseOrder::query()
            ->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO)
            ->whereHas('lines', fn ($q) => $q->where('catalog_product_id', $productId))
            ->with(['provider', 'lines' => fn ($q) => $q->where('catalog_product_id', $productId)])
            ->orderBy('estimated_arrival_date')
            ->get();

        // Mismo patrón que `enCaminoMap()` — agrupar por un string interpolado (en vez de un int
        // devuelto directo) evita que Larastan se queje del tipo nullable de `$l->receipt`.
        /** @var Collection<string, int> $registeredByOrder */
        $registeredByOrder = PurchaseOrderReceiptLine::query()
            ->where('catalog_product_id', $productId)
            ->whereHas('receipt.purchaseOrder', fn ($q) => $q->where('status', '!=', PurchaseOrder::STATUS_RECIBIDO))
            ->with('receipt:id,purchase_order_id')
            ->get()
            ->groupBy(fn (PurchaseOrderReceiptLine $l) => "{$l->receipt?->purchase_order_id}")
            ->map(fn (Collection $lines) => (int) $lines->sum('quantity'));

        $rows = [];
        foreach ($purchaseOrders as $purchaseOrder) {
            $expected = (int) $purchaseOrder->lines->sum('quantity');
            $registered = (int) ($registeredByOrder["{$purchaseOrder->id}"] ?? 0);
            $remaining = max(0, $expected - $registered);

            if ($remaining <= 0) {
                continue;
            }

            $rows[] = [
                'purchase_order_id'      => $purchaseOrder->id,
                'provider_name'          => $purchaseOrder->provider?->name,
                'quantity'               => $remaining,
                'estimated_arrival_date' => $purchaseOrder->estimated_arrival_date?->toDateString(),
            ];
        }

        return $rows;
    }
}
