<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\StoreCalendarEventRequest;
use App\Modules\VentasDiseno\Models\CalendarEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-058 — calendario nativo (sin integración Outlook, ver nota en la migración).
 * Alcance Mío/Equipo igual que Pipeline (REQ-002/056): Diseñador solo ve/edita lo
 * propio; Gerencia/Superadmin ve equipo y puede crear/editar a nombre de otro vendedor.
 */
final class CalendarEventController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        $canSeeTeam = $user->canViewTeamV2('ventas_diseno');

        $query = CalendarEvent::query()->orderBy('start_at');

        $ownerId = $request->query('owner_id');
        if ($canSeeTeam && $request->query('scope') === 'team' && ! is_numeric($ownerId)) {
            // Equipo completo — sin filtro de owner.
        } elseif ($canSeeTeam && is_numeric($ownerId)) {
            $query->where('owner_id', (int) $ownerId);
        } else {
            $query->where('owner_id', $user->id);
        }

        $from = $request->query('from');
        $to   = $request->query('to');
        if (is_string($from) && $from !== '') {
            $query->where('start_at', '>=', $from);
        }
        if (is_string($to) && $to !== '') {
            $query->where('start_at', '<=', $to);
        }

        $events = $query->with('owner')->get();

        return response()->json(['data' => $events->map(fn (CalendarEvent $e) => $this->format($e))->values()]);
    }

    public function store(StoreCalendarEventRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /**
         * @var array{
         *   owner_id?: int|null, title: string, description?: string|null,
         *   start_at: string, end_at?: string|null, all_day?: bool,
         * } $validated
         */
        $validated = $request->validated();

        $ownerId = $this->resolveOwnerId($validated['owner_id'] ?? null, $user);
        if ($ownerId === null) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $event = CalendarEvent::create([
            'owner_id'    => $ownerId,
            'title'       => $validated['title'],
            'description' => $validated['description'] ?? null,
            'start_at'    => $validated['start_at'],
            'end_at'      => $validated['end_at'] ?? null,
            'all_day'     => $validated['all_day'] ?? false,
        ]);

        return response()->json($this->format($event->load('owner')), 201);
    }

    public function update(StoreCalendarEventRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $event = CalendarEvent::find($id);
        if ($event === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($event, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        /**
         * @var array{
         *   owner_id?: int|null, title: string, description?: string|null,
         *   start_at: string, end_at?: string|null, all_day?: bool,
         * } $validated
         */
        $validated = $request->validated();

        $event->update([
            'title'       => $validated['title'],
            'description' => $validated['description'] ?? null,
            'start_at'    => $validated['start_at'],
            'end_at'      => $validated['end_at'] ?? null,
            'all_day'     => $validated['all_day'] ?? false,
        ]);

        /** @var CalendarEvent $fresh */
        $fresh = $event->fresh(['owner']);

        return response()->json($this->format($fresh));
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $event = CalendarEvent::find($id);
        if ($event === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($event, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $event->delete();

        return response()->json(null, 204);
    }

    private function canAccess(CalendarEvent $event, User $user): bool
    {
        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        return $event->owner_id === $user->id;
    }

    private function resolveOwnerId(?int $requested, User $actor): ?int
    {
        if ($requested === null || $requested === $actor->id) {
            return $actor->id;
        }

        $canActForOthers = $actor->canViewTeamV2('ventas_diseno');

        return $canActForOthers ? $requested : null;
    }

    /** @return array<string, mixed> */
    private function format(CalendarEvent $event): array
    {
        return [
            'id'          => $event->id,
            'owner'       => ['id' => $event->owner->id, 'name' => trim("{$event->owner->first_name} {$event->owner->last_name}")],
            'title'       => $event->title,
            'description' => $event->description,
            'start_at'    => $event->start_at->toIso8601String(),
            'end_at'      => $event->end_at?->toIso8601String(),
            'all_day'     => $event->all_day,
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
