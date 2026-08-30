<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Models\User;
use App\Modules\CRM\Http\Requests\StoreActivityRequest;
use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Models\ProjectActivity;
use App\Modules\CRM\Services\ProjectService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ActivityController extends Controller
{
    public function __construct(private readonly ProjectService $service) {}

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

        $perPage = min((int) $request->query('per_page', 20), 100);

        $paginator = $project->activities()
            ->with('user')
            ->orderByDesc('fecha')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (ProjectActivity $a) => $this->format($a))
                ->values(),
            'meta' => [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StoreActivityRequest $request, int $projectId): JsonResponse
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

        /** @var array{tipo: string, fecha: string, notas?: string|null, siguiente?: string|null} $validated */
        $validated = $request->validated();

        $activity = $project->activities()->create([
            'tipo'      => $validated['tipo'],
            'fecha'     => $validated['fecha'],
            'notas'     => $validated['notas'] ?? null,
            'siguiente' => $validated['siguiente'] ?? null,
            'user_id'   => $user->id,
        ]);

        $activity->load('user');

        $this->service->dispatchActivityAdded(
            activityId: $activity->id,
            project:    $project,
            tipo:       $activity->tipo,
            fecha:      $activity->fecha->toDateTimeString(),
            userId:     $user->id,
        );

        return response()->json($this->format($activity), 201);
    }

    public function destroy(Request $request, int $projectId, int $actId): JsonResponse
    {
        $user     = $this->resolveUser($request);
        $project  = Project::with('assignees')->find($projectId);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($request, $project, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $activity = $project->activities()->find($actId);
        if ($activity === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Non-management users can only delete their own activities
        $role = $this->activeRole($request);
        $isManagement = in_array($role, [User::ROLE_SUPERADMIN, User::ROLE_MANAGEMENT], true);

        if (! $isManagement && $activity->user_id !== $user->id) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $activity->delete();

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
    private function format(ProjectActivity $activity): array
    {
        $u = $activity->user;

        return [
            'id'         => $activity->id,
            'project_id' => $activity->project_id,
            'tipo'       => $activity->tipo,
            'fecha'      => $activity->fecha->toIso8601String(),
            'notas'      => $activity->notas,
            'siguiente'  => $activity->siguiente?->toDateString(),
            'user'       => [
                'id'         => $u->id,
                'first_name' => $u->first_name,
                'last_name'  => $u->last_name,
            ],
            'created_at' => $activity->created_at->toIso8601String(),
        ];
    }
}
