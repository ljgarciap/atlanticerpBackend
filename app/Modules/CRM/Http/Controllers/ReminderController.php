<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Models\User;
use App\Modules\CRM\Http\Requests\StoreReminderRequest;
use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Models\ProjectReminder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ReminderController extends Controller
{
    public function index(Request $request, int $projectId): JsonResponse
    {
        $user    = $this->resolveUser($request);
        $project = Project::with('assignees')->find($projectId);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($request, $project, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $reminders = $project->reminders()
            ->orderBy('fecha')
            ->get();

        return response()->json([
            'data' => $reminders->map(fn (ProjectReminder $r) => $this->format($r))->values(),
        ]);
    }

    public function store(StoreReminderRequest $request, int $projectId): JsonResponse
    {
        $user    = $this->resolveUser($request);
        $project = Project::with('assignees')->find($projectId);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($request, $project, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        /** @var array{tipo: string, fecha: string, nota?: string|null} $validated */
        $validated = $request->validated();

        $reminder = $project->reminders()->create([
            'tipo'       => $validated['tipo'],
            'fecha'      => $validated['fecha'],
            'nota'       => $validated['nota'] ?? null,
            'completado' => false,
        ]);

        return response()->json($this->format($reminder), 201);
    }

    public function toggle(Request $request, int $projectId, int $remId): JsonResponse
    {
        $user    = $this->resolveUser($request);
        $project = Project::with('assignees')->find($projectId);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($request, $project, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $reminder = $project->reminders()->find($remId);
        if ($reminder === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $reminder->completado = ! $reminder->completado;
        $reminder->save();

        return response()->json($this->format($reminder));
    }

    public function destroy(Request $request, int $projectId, int $remId): JsonResponse
    {
        $user    = $this->resolveUser($request);
        $project = Project::with('assignees')->find($projectId);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($request, $project, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $reminder = $project->reminders()->find($remId);
        if ($reminder === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $reminder->delete();

        return response()->json(null, 204);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }

    private function activeRole(Request $request): string
    {
        /** @var array{role?: string}|null $payload */
        $payload = $request->attributes->get('jwt');

        return isset($payload['role']) ? $payload['role'] : '';
    }

    private function canAccess(Request $request, Project $project, User $user): bool
    {
        $role = $this->activeRole($request);

        if (in_array($role, [User::ROLE_SUPERADMIN, User::ROLE_MANAGEMENT], true)) {
            return true;
        }

        return $project->assignees->contains('id', $user->id);
    }

    /** @return array<string, mixed> */
    private function format(ProjectReminder $reminder): array
    {
        return [
            'id'         => $reminder->id,
            'project_id' => $reminder->project_id,
            'tipo'       => $reminder->tipo,
            'fecha'      => $reminder->fecha->toDateString(),
            'nota'       => $reminder->nota,
            'completado' => $reminder->completado,
            'created_at' => $reminder->created_at->toIso8601String(),
        ];
    }
}
