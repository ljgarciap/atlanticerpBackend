<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Servicios\Exceptions\ServiceQuoteAccessDeniedException;
use App\Modules\Servicios\Exceptions\ServiceQuoteActionBlockedException;
use App\Modules\Servicios\Http\Requests\StoreServiceQuoteItemRequest;
use App\Modules\Servicios\Http\Requests\UpdateServiceQuoteItemRequest;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\ServiceQuoteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Fase 4 — Servicios, Batch 11 (REQ-230/231/232) — ítems Producto/Mano de obra/Subcontratado. */
final class ServiceQuoteItemController extends Controller
{
    public function __construct(private readonly ServiceQuoteService $quotes) {}

    /** POST /api/servicios/tickets/{id}/quote/items */
    public function store(StoreServiceQuoteItemRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $quote = $this->quotes->current($ticket);
        if ($quote === null) {
            return response()->json(['message' => 'Este ticket todavía no tiene una cotización generada.'], 404);
        }

        /** @var array{tipo: string, catalog_product_id?: ?int, is_custom?: bool, external_technician_id?: ?int, description?: ?string, quantity: float|string, unit_price?: float|string|null, margin_percent?: float|string|null} $validated */
        $validated = $request->validated();

        try {
            $item = $this->quotes->addItem($quote, $validated, $user);
        } catch (ServiceQuoteAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ServiceQuoteActionBlockedException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $item->id], 201);
    }

    /** PATCH /api/servicios/tickets/{id}/quote/items/{itemId} */
    public function update(UpdateServiceQuoteItemRequest $request, int $id, int $itemId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $quote = $this->quotes->current($ticket);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $item = $quote->items()->find($itemId);
        if ($item === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $this->quotes->updateItem($quote, $item, $request->validated(), $user);
        } catch (ServiceQuoteAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ServiceQuoteActionBlockedException|\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['id' => $item->id]);
    }

    /** DELETE /api/servicios/tickets/{id}/quote/items/{itemId} */
    public function destroy(Request $request, int $id, int $itemId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $quote = $this->quotes->current($ticket);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $item = $quote->items()->find($itemId);
        if ($item === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $this->quotes->deleteItem($quote, $item, $user);
        } catch (ServiceQuoteAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ServiceQuoteActionBlockedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(null, 204);
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
