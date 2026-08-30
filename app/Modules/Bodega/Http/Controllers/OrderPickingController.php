<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Bodega\Exceptions\OrderTransitionException;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderItem;
use App\Modules\Bodega\Services\InventoryReviewService;
use App\Modules\Bodega\Services\PickingConsolidationService;
use App\Modules\Bodega\Services\PickingService;
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

        $order->loadMissing('items.catalogProduct', 'items.foundConfirmedBy');
        $items = $order->items->sortBy(fn (OrderItem $i) => $i->location_snapshot ?? '')->values();

        return response()->json([
            'order_id'  => $order->id,
            'editable'  => $order->stage === Order::STAGE_EN_PICKING,
            'items'     => $items->map(function (OrderItem $i): array {
                /** @var CatalogProduct|null $product */
                $product = $i->catalogProduct;

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
                    'picking_notes'     => $i->picking_notes,
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
