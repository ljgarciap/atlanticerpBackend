<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\UpdateProductDeliveryRequest;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Services\ProductDeliveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-909 — modal de Pipeline "asignar productos a cada fecha de entrega parcial" (completa
 * SCRUM-875/REQ-319). Toda la lógica real vive en `ProductDeliveryService` — mismo patrón que
 * `PipelineCardContactController` delegando a `PipelineService`.
 */
final class PipelineCardProductDeliveryController extends Controller
{
    public function __construct(private readonly ProductDeliveryService $service) {}

    public function index(Request $request, int $cardId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $card = PipelineCard::find($cardId);
        if ($card === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($card, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        // SCRUM-1027 — `?dates[]=YYYY-MM-DD` (borrador de la edición, solo para sugerir fecha).
        $validated = $request->validate([
            'dates'   => ['sometimes', 'array'],
            'dates.*' => ['date_format:Y-m-d'],
        ]);
        /** @var list<string>|null $draftDates */
        $draftDates = isset($validated['dates']) ? array_values($validated['dates']) : null;

        return response()->json(['data' => $this->service->listForCard($card, $draftDates)]);
    }

    public function update(UpdateProductDeliveryRequest $request, int $cardId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $card = PipelineCard::find($cardId);
        if ($card === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($card, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        if (! $card->isEditableBy($user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        if ($card->delivery_type !== 'partial') {
            return response()->json(
                ['message' => 'Solo se pueden asignar fechas por producto en tarjetas con entrega Parcial.'],
                422,
            );
        }

        /** @var array{assignments: list<array{quote_item_id: int, delivery_date: string|null}>} $validated */
        $validated = $request->validated();

        try {
            $this->service->saveAssignments($card, $validated['assignments']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $this->service->listForCard($card)]);
    }

    private function canAccess(PipelineCard $card, User $user): bool
    {
        // Mismo criterio que PipelineController::canAccess() — ADR-006 Fase C Task 4 +
        // SCRUM-849.4 (compartido_con también cuenta como responsable).
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        // SCRUM-1139 — alcance de equipo SOLO dentro del Pipeline para `ventas_diseno.pipeline.edit`.
        if (PipelineCard::userHasPipelineEdit($user)) {
            return true;
        }

        return $card->owner_id === $user->id || in_array($user->id, $card->compartido_con ?? [], true);
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
