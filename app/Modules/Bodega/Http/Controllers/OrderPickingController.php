<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Bodega\Exceptions\OrderTransitionException;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\InventoryReviewService;
use App\Modules\Bodega\Services\PickingConsolidationService;
use App\Modules\Bodega\Services\PickingService;
use App\Modules\Bodega\Services\StockReservationService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-329 Oleada A / Batch A2 — flujo de picking (REQ-313/314/315/316/317/318).
 */
final class OrderPickingController extends Controller
{
    public function __construct(
        private readonly PickingService $picking,
        private readonly InventoryReviewService $inventoryReview,
        private readonly PickingConsolidationService $consolidation,
        private readonly StockReservationService $reservations,
    ) {}

    /**
     * GET /api/bodega/orders/consolidated-picking?picker_id=X
     *
     * SCRUM-391 (REQ-321) — ver `PickingConsolidationService` para las reglas de negocio
     * (RN1: solo `en_picking`; RN2: solo lectura).
     */
    public function consolidatedPicking(Request $request): JsonResponse
    {
        $validated = $request->validate(['picker_id' => ['required', 'integer']]);

        return response()->json($this->consolidation->consolidatedForPicker((int) $validated['picker_id']));
    }

    /** POST /api/bodega/orders/{id}/assign-picker */
    public function assignPicker(Request $request, int $id): JsonResponse
    {
        $resolved = $this->resolve($request, $id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$user, $order] = $resolved;

        $validated = $request->validate(['picker_id' => ['required', 'integer']]);

        try {
            $order = $this->picking->assignPicker($order, (int) $validated['picker_id'], $user);
        } catch (OrderTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json(['id' => $order->id, 'stage' => $order->stage, 'picker_id' => $order->picker_id]);
    }

    /** POST /api/bodega/orders/{id}/start-picking */
    public function startPicking(Request $request, int $id): JsonResponse
    {
        $resolved = $this->resolve($request, $id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$user, $order] = $resolved;

        try {
            $order = $this->picking->startPicking($order, $user);
        } catch (OrderTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json(['id' => $order->id, 'stage' => $order->stage]);
    }

    /** GET /api/bodega/orders/{id}/picking-sheet — REQ-315: ordenada por ubicación. */
    public function pickingSheet(int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $order->loadMissing('items.catalogProduct', 'items.foundConfirmedBy', 'items.allocations');
        $items = $order->items->sortBy(fn (OrderItem $i) => $i->location_snapshot ?? '')->values();

        // SCRUM-1196 — bodegas de venta que ofrece el selector de la hoja (Central y Showroom Obarrio; nunca Reclamos, Merma, etc.), con su saldo.
        /** @var \Illuminate\Support\Collection<int, Warehouse> $saleWarehouses */
        $saleWarehouses = Warehouse::paraVenta()->orderByDesc('despacho_por_defecto')->orderBy('id')->get();
        $productIds = $items->pluck('catalog_product_id')->filter()->unique()->values()->all();
        $stockByProduct = $productIds === [] ? collect() : ProductWarehouseStock::whereIn('catalog_product_id', $productIds)
            ->whereIn('warehouse_id', $saleWarehouses->pluck('id'))->get()->groupBy('catalog_product_id');

        return response()->json([
            'order_id'  => $order->id,
            'editable'  => $order->stage === Order::STAGE_EN_PICKING,
            'sale_warehouses' => $saleWarehouses->map(fn (Warehouse $w): array => ['id' => $w->id, 'name' => $w->name, 'is_default' => $w->despacho_por_defecto])->values()->all(),
            'items'     => $items->map(function (OrderItem $i) use ($saleWarehouses, $stockByProduct): array {
                /** @var CatalogProduct|null $product */
                $product = $i->catalogProduct;
                /** @var \Illuminate\Support\Collection<int, ProductWarehouseStock> $stockRows */
                $stockRows = $i->catalog_product_id !== null ? $stockByProduct->get($i->catalog_product_id, collect()) : collect();

                return [
                    'id'                => $i->id,
                    'reference'         => $product !== null ? $product->reference : $i->reference_snapshot,
                    'factory_reference' => $product !== null ? $product->factory_reference : null,
                    'description'       => $product !== null ? $product->name : $i->description_snapshot,
                    // REQ-315/318 (corrección 2026-08-11, SCRUM-388) — `location` es SIEMPRE la
                    // ubicación original, nunca sobrescrita por la Revisión de Inventario;
                    // `found_note` es la nota "Nueva ubicación: X — Confirmado por Y" (null si el
                    // ítem nunca pasó por una revisión resuelta como "Sí hay"), ver
                    // `OrderItem::foundNote()`. `found_at`/`found_location` quedan expuestos crudos
                    // además, por si el frontend los necesita sueltos en vez de la nota ya armada.
                    'location'          => $i->location_snapshot,
                    'found_at'          => $i->found_at_location,
                    'found_location'    => $i->found_location,
                    'found_note'        => $i->foundNote(),
                    'qty_requested'     => $i->qty_requested,
                    'qty_picked'        => $i->qty_picked,
                    'qty_reserved'      => $i->qty_reserved,
                    'picking_notes'     => $i->picking_notes,
                    // SCRUM-1196 — de qué bodega(s) sale la línea y cuánto, y el saldo de cada bodega de venta (`available`: lo que Bodega puede alistar
                    // desde allí; en la de por defecto, lo reservado para este pedido).
                    'allocations'       => $i->allocations->map(fn ($al): array => [
                        'warehouse_id' => $al->warehouse_id, 'qty_reserved' => $al->qty_reserved, 'qty_picked' => $al->qty_picked, 'manual' => $al->manual,
                    ])->values()->all(),
                    'warehouse_options' => $saleWarehouses->map(function (Warehouse $w) use ($i, $stockRows): array {
                        $stockRow = $stockRows->firstWhere('warehouse_id', $w->id);
                        $stock = $stockRow !== null ? (int) $stockRow->quantity : 0;
                        if ($w->despacho_por_defecto) {
                            $auto = $i->allocations->first(fn ($al): bool => ! $al->manual && $al->warehouse_id === $w->id);
                            $available = $auto !== null ? (int) $auto->qty_reserved : 0;
                        } else {
                            $available = ($i->catalog_product_id !== null && $stock > 0)
                                ? $this->reservations->availableAtWarehouse((int) $i->catalog_product_id, $w->id, $i->id)
                                : 0;
                        }

                        return ['warehouse_id' => $w->id, 'name' => $w->name, 'is_default' => $w->despacho_por_defecto, 'stock' => $stock, 'available' => $available];
                    })->values()->all(),
                ];
            })->values()->all(),
        ]);
    }

    /** PATCH /api/bodega/orders/{id}/picking-sheet */
    public function updatePickingSheet(Request $request, int $id): JsonResponse
    {
        $resolved = $this->resolve($request, $id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$user, $order] = $resolved;

        $validated = $request->validate([
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.order_item_id'    => ['required', 'integer'],
            'items.*.qty_picked'       => ['required', 'integer', 'min:0'],
            'items.*.picking_notes'    => ['nullable', 'string', 'max:500'],
            // SCRUM-1196 — de qué bodega(s) de venta se alista la línea; sin esto, todo sale de la bodega de despacho por defecto.
            'items.*.allocations'                => ['nullable', 'array'],
            'items.*.allocations.*.warehouse_id' => ['required_with:items.*.allocations', 'integer'],
            'items.*.allocations.*.qty_picked'   => ['required_with:items.*.allocations', 'integer', 'min:0'],
        ]);

        try {
            $order = $this->picking->updatePickingSheet($order, $validated['items'], $user);
        } catch (OrderTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json(['id' => $order->id, 'stage' => $order->stage]);
    }

    /** POST /api/bodega/orders/{id}/complete-picking */
    public function completePicking(Request $request, int $id): JsonResponse
    {
        $resolved = $this->resolve($request, $id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$user, $order] = $resolved;

        $validated = $request->validate([
            'items'                    => ['required', 'array', 'min:1'],
            'items.*.order_item_id'    => ['required', 'integer'],
            'items.*.qty_picked'       => ['required', 'integer', 'min:0'],
            'items.*.allocations'                => ['nullable', 'array'],
            'items.*.allocations.*.warehouse_id' => ['required_with:items.*.allocations', 'integer'],
            'items.*.allocations.*.qty_picked'   => ['required_with:items.*.allocations', 'integer', 'min:0'],
        ]);

        try {
            $result = $this->picking->completePicking($order, $validated['items'], $user);
        } catch (OrderTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json([
            'order'  => ['id' => $result['order']->id, 'stage' => $result['order']->stage],
            'review' => $result['review'] !== null ? ['id' => $result['review']->id, 'order_number' => $result['review']->order_number] : null,
        ]);
    }

    /** POST /api/bodega/orders/{id}/resolve-inventory-review */
    public function resolveInventoryReview(Request $request, int $id): JsonResponse
    {
        $resolved = $this->resolve($request, $id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$user, $order] = $resolved;

        $validated = $request->validate([
            'decisions'                 => ['required', 'array', 'min:1'],
            'decisions.*.order_item_id' => ['required', 'integer'],
            'decisions.*.found'         => ['required', 'boolean'],
            'decisions.*.location'      => ['nullable', 'string'],
        ]);

        try {
            $result = $this->inventoryReview->resolve($order, $validated['decisions'], $user);
        } catch (OrderTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json([
            'review'    => ['id' => $result['review']->id, 'stage' => $result['review']->stage, 'order_type' => $result['review']->order_type],
            'new_order' => $result['newOrder'] !== null ? ['id' => $result['newOrder']->id, 'stage' => $result['newOrder']->stage] : null,
        ]);
    }

    /** @return array{0: User, 1: Order}|JsonResponse */
    private function resolve(Request $request, int $orderId): array|JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = Order::find($orderId);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return [$user, $order];
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');
        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }
}
