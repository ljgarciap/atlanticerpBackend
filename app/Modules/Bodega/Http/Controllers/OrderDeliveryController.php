<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Bodega\Exceptions\OrderTransitionException;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\OrderDocument;
use App\Modules\Bodega\Services\DeliveryService;
use App\Modules\Bodega\Services\OrderDocumentService;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-329 Oleada A / Batch A2 — Packing -> Por despachar -> Despachado -> Entregado
 * (REQ-323/324/325/326/327/328/329), y descarga de la Guía de Entrega (REQ-330).
 */
final class OrderDeliveryController extends Controller
{
    public function __construct(
        private readonly DeliveryService $delivery,
        private readonly OrderDocumentService $documents,
    ) {}

    /** POST /api/bodega/orders/{id}/register-delivery */
    public function registerDelivery(Request $request, int $id): JsonResponse
    {
        $resolved = $this->resolve($request, $id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$user, $order] = $resolved;

        $validated = $request->validate([
            'items'                          => ['required', 'array', 'min:1'],
            'items.*.order_item_id'          => ['required', 'integer'],
            'items.*.qty_delivered'          => ['required', 'integer', 'min:0'],
            'items.*.motivo'                 => ['nullable', 'string'],
            'items.*.motivo_detalle'         => ['nullable', 'string'],
            'observacion_general'            => ['nullable', 'string'],
        ]);

        try {
            $order = $this->delivery->registerDelivery($order, $validated['items'], $validated['observacion_general'] ?? null, $user);
        } catch (OrderTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json(['id' => $order->id, 'stage' => $order->stage]);
    }

    /** POST /api/bodega/orders/{id}/assign-courier */
    public function assignCourier(Request $request, int $id): JsonResponse
    {
        $resolved = $this->resolve($request, $id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$user, $order] = $resolved;

        $validated = $request->validate(['courier_id' => ['required', 'integer']]);

        try {
            $order = $this->delivery->assignCourier($order, (int) $validated['courier_id'], $user);
        } catch (OrderTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json(['id' => $order->id, 'courier_id' => $order->courier_id]);
    }

    /** POST /api/bodega/orders/{id}/dispatch */
    public function dispatch(Request $request, int $id): JsonResponse
    {
        $resolved = $this->resolve($request, $id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$user, $order] = $resolved;

        try {
            $result = $this->delivery->dispatch($order, $user);
        } catch (OrderTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json([
            'order'               => ['id' => $result['order']->id, 'stage' => $result['order']->stage],
            'dispatched_siblings' => array_map(fn (Order $o) => ['id' => $o->id, 'stage' => $o->stage], $result['dispatchedSiblings']),
        ]);
    }

    /** POST /api/bodega/orders/{id}/register-signed-guide */
    public function registerSignedGuide(Request $request, int $id): JsonResponse
    {
        $resolved = $this->resolve($request, $id);
        if ($resolved instanceof JsonResponse) {
            return $resolved;
        }
        [$user, $order] = $resolved;

        $validated = $request->validate([
            'delivered_by_courier_id' => ['required', 'integer'],
            'received_by_name'        => ['required', 'string', 'max:255'],
            'file'                    => ['required', 'file', 'max:20480', 'extensions:jpg,jpeg,png,pdf'],
        ]);

        try {
            $order = $this->delivery->registerSignedGuide($order, $validated['file'], (int) $validated['delivered_by_courier_id'], (string) $validated['received_by_name'], $user);
        } catch (OrderTransitionException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json(['id' => $order->id, 'stage' => $order->stage]);
    }

    /** GET /api/bodega/orders/{id}/documents/{documentId}/download */
    public function downloadDocument(int $id, int $documentId): JsonResponse
    {
        $document = OrderDocument::where('order_id', $id)->find($documentId);
        if ($document === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría) — presigned URL a un documento real
        // de entrega, no un GET de metadata.
        app(AuditService::class)->log(
            action:     AuditService::BODEGA_ORDER_DOCUMENT_DOWNLOADED,
            entityType: 'order_document',
            entityId:   $document->id,
        );

        return response()->json(['url' => $this->documents->presignUrl($document->storage_key)]);
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
