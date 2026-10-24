<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\StoreQuotePartRequest;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuotePart;
use App\Modules\VentasDiseno\Services\QuoteItemPhotoService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

// REQ-035: partidas de una cotización.
final class QuotePartController extends Controller
{
    public function __construct(private readonly QuoteItemPhotoService $photos) {}

    public function store(StoreQuotePartRequest $request, int $quoteId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $blocked = $this->ensureEditable($quote);
        if ($blocked !== null) {
            return $blocked;
        }

        /** @var array{name: string} $validated */
        $validated = $request->validated();

        $position = $quote->parts()->max('position');
        $part = $quote->parts()->create([
            'name'     => $validated['name'],
            'position' => $position === null ? 0 : $position + 1,
        ]);

        return response()->json($this->format($part), 201);
    }

    public function update(StoreQuotePartRequest $request, int $quoteId, int $partId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $part = $quote->parts()->find($partId);
        if ($part === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $blocked = $this->ensureEditable($quote);
        if ($blocked !== null) {
            return $blocked;
        }

        /** @var array{name: string} $validated */
        $validated = $request->validated();
        $part->update($validated);

        return response()->json($this->format($part));
    }

    /**
     * SCRUM-849.7 — copia una partida completa (productos/referencias/descripciones/
     * cantidades) a una nueva partida, independiente de la original: editar una nunca
     * afecta a la otra de ahí en más.
     */
    public function duplicate(Request $request, int $quoteId, int $partId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $part = $quote->parts()->with('items')->find($partId);
        if ($part === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $blocked = $this->ensureEditable($quote);
        if ($blocked !== null) {
            return $blocked;
        }

        $position = $quote->parts()->max('position');
        $copy = $quote->parts()->create([
            'name'     => "{$part->name} (copia)",
            'position' => $position === null ? 0 : $position + 1,
        ]);

        foreach ($part->items as $item) {
            $itemCopy = $copy->items()->create([
                'catalog_product_id'  => $item->catalog_product_id,
                'is_custom'           => $item->is_custom,
                'reference'           => $item->reference,
                'description'         => $item->description,
                'quantity'            => $item->quantity,
                'unit_price'          => $item->unit_price,
                'cost'                => $item->cost,
                'price_type_override' => $item->price_type_override,
                'discount_percent'    => $item->discount_percent,
            ]);

            // SCRUM-849.6/849.7 — copia el archivo a una key propia, nunca comparte la del
            // original (ver docblock de QuoteItemPhotoService::copyPhotoTo()).
            // Rebote de Gerencia (2026-09-11) — un fallo de S3 acá no debe tumbar la duplicación
            // de la partida entera (lo crítico del ticket es la copia de productos/referencias/
            // cantidades): se loguea y la partida queda duplicada sin foto en ese ítem puntual,
            // en vez de una respuesta 500 sin manejar.
            if ($item->photo_key !== null) {
                try {
                    $this->photos->copyPhotoTo($item, $itemCopy);
                } catch (\Throwable $e) {
                    Log::error('quote_part_controller.duplicate_photo_failed', [
                        'source_item_id' => $item->id, 'target_item_id' => $itemCopy->id, 'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        return response()->json($this->format($copy), 201);
    }

    public function destroy(Request $request, int $quoteId, int $partId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $part = $quote->parts()->find($partId);
        if ($part === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $blocked = $this->ensureEditable($quote);
        if ($blocked !== null) {
            return $blocked;
        }

        $part->delete();

        return response()->json(null, 204);
    }

    /**
     * SCRUM-1161 — reordenar partidas por drag and drop. Recibe TODAS las partidas de la cotización
     * en el nuevo orden; si el conjunto no coincide (otra pestaña agregó/borró una) responde 422 y la
     * pantalla recarga. La creación de partidas (siguiente posición al final) no cambia.
     */
    public function reorder(Request $request, int $quoteId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $quote = Quote::find($quoteId);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($quote, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $blocked = $this->ensureEditable($quote);
        if ($blocked !== null) {
            return $blocked;
        }

        $validated = $request->validate([
            'part_ids'   => ['required', 'array', 'list', 'min:1', 'max:500'],
            'part_ids.*' => ['integer', 'distinct'],
        ]);

        /** @var list<int> $ids */
        $ids = array_map('intval', $validated['part_ids']);
        $current = $quote->parts()->pluck('id')->map(fn ($id) => (int) $id)->all();
        $a = $ids;
        $b = $current;
        sort($a);
        sort($b);
        if ($a !== $b) {
            return response()->json(['message' => 'La lista de partidas cambió; recarga la cotización.'], 422);
        }

        DB::transaction(function () use ($quote, $ids): void {
            foreach ($ids as $position => $id) {
                $quote->parts()->whereKey($id)->where('position', '!=', $position)->update(['position' => $position]);
            }
        });

        return response()->json(['part_ids' => $ids]);
    }

    private function canAccess(Quote $quote, User $user): bool
    {
        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        return $quote->owner_id === $user->id;
    }

    /**
     * SCRUM-734 (RN2.2, Senior Review retroactivo 2026-08-06, hallazgo CRÍTICO
     * real, reproducido en vivo) — `QuoteService::update()` ya bloquea
     * `PUT /quotes/{id}` sobre una cotización confirmada, pero agregar/editar/
     * borrar partidas pasa por ESTE controller, que nunca lo chequeaba: un
     * `PUT`/`POST`/`DELETE` acá contra una cotización confirmada devolvía 200/201/204
     * sin tocar `confirmed_at` para nada — bypass completo del "una cotización
     * generada nunca se edita directo", y además desincroniza `PipelineCard.amount`
     * (que solo se actualiza en confirm()) del total real de la cotización.
     */
    private function ensureEditable(Quote $quote): ?JsonResponse
    {
        if ($quote->confirmed_at !== null) {

            return response()->json([
                'message' => 'Una cotización generada no se puede editar directamente. Use "Usar como base para nueva versión".',
            ], 422);
        }

        return null;
    }

    /** @return array<string, mixed> */
    private function format(QuotePart $part): array
    {
        return ['id' => $part->id, 'name' => $part->name, 'position' => $part->position];
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
