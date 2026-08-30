<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\StoreQuotePartRequest;
use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuotePart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

// REQ-035: partidas de una cotización.
final class QuotePartController extends Controller
{
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
