<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Services\OrderBoardService;
use App\Modules\Bodega\Services\WarehouseStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-329 Oleada A / Batch A2 — tablero Kanban de Pedidos (REQ-305/306/309/310/311/312/332).
 */
final class OrderBoardController extends Controller
{
    public function __construct(
        private readonly OrderBoardService $board,
        private readonly WarehouseStockService $warehouseStock,
    ) {}

    /** GET /api/bodega/orders */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->board->board($request));
    }

    /** GET /api/bodega/orders/assistant-load */
    public function assistantLoad(): JsonResponse
    {
        return response()->json(['data' => $this->board->assistantLoad()]);
    }

    /** GET /api/bodega/orders/{id} */
    public function show(int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->board->detail($order));
    }

    /**
     * GET /api/bodega/orders/{id}/warehouse-breakdown
     *
     * SCRUM-403 (REQ-333) — "Ver bodegas" para TODOS los artículos de un pedido a la vez (mismo
     * desglose por bodega que `AdjustmentRequestController::productWarehouseStock()`, pero
     * agregado por pedido para que el frontend no haga N requests, una por línea, al abrir el
     * modal). Ver `WarehouseStockService`, fuente única de la query.
     */
    public function warehouseBreakdown(int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $order->loadMissing('items');
        /** @var list<int> $productIds */
        $productIds = $order->items->pluck('catalog_product_id')->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();

        return response()->json([
            'order_id' => $order->id,
            'products' => $this->warehouseStock->breakdownForProducts($productIds),
        ]);
    }
}
