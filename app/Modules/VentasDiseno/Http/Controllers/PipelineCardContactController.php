<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\StorePipelineCardContactRequest;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\PipelineCardContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class PipelineCardContactController extends Controller
{
    public function store(StorePipelineCardContactRequest $request, int $cardId): JsonResponse
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

        /** @var array{name: string, role: string, phone?: string|null, email?: string|null} $validated */
        $validated = $request->validated();

        $contact = $card->contacts()->create($validated);

        return response()->json($this->format($contact), 201);
    }

    public function update(StorePipelineCardContactRequest $request, int $cardId, int $contactId): JsonResponse
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

        $contact = $card->contacts()->find($contactId);
        if ($contact === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{name: string, role: string, phone?: string|null, email?: string|null} $validated */
        $validated = $request->validated();
        $contact->update($validated);

        return response()->json($this->format($contact));
    }

    public function destroy(Request $request, int $cardId, int $contactId): JsonResponse
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

        $contact = $card->contacts()->find($contactId);
        if ($contact === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $contact->delete();

        return response()->json(null, 204);
    }

    private function canAccess(PipelineCard $card, User $user): bool
    {
        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        return $card->owner_id === $user->id;
    }

    /** @return array<string, mixed> */
    private function format(PipelineCardContact $contact): array
    {
        return [
            'id'    => $contact->id,
            'name'  => $contact->name,
            'role'  => $contact->role,
            'phone' => $contact->phone,
            'email' => $contact->email,
        ];
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
