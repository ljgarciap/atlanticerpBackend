<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Models\User;
use App\Modules\CRM\Http\Requests\StoreProjectRequest;
use App\Modules\CRM\Http\Requests\UpdateProjectRequest;
use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Models\ProjectReminder;
use App\Modules\CRM\Services\ProjectService;
use App\Shared\Support\ParsesIdListQueryParam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

final class ProjectController extends Controller
{
    use ParsesIdListQueryParam;

    public function __construct(private readonly ProjectService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $raw = $request->only([
            'search', 'etapa', 'urgency', 'freshness',
            'area', 'tipo', 'despacho', 'ubicacion', 'user_id', 'requires_technical_study', 'per_page',
        ]);

        $filters = array_filter([
            'search' => isset($raw['search']) ? (string) $raw['search'] : null,
            'etapa' => isset($raw['etapa']) ? (string) $raw['etapa'] : null,
            'urgency' => isset($raw['urgency']) ? (string) $raw['urgency'] : null,
            'freshness' => isset($raw['freshness']) ? (string) $raw['freshness'] : null,
            'area' => isset($raw['area']) ? (string) $raw['area'] : null,
            'tipo' => isset($raw['tipo']) ? (string) $raw['tipo'] : null,
            'despacho' => isset($raw['despacho']) ? (string) $raw['despacho'] : null,
            'ubicacion' => isset($raw['ubicacion']) ? (string) $raw['ubicacion'] : null,
            'user_id' => isset($raw['user_id']) ? (int) $raw['user_id'] : null,
            // SCRUM-854 — multi-selección Vendedor/Diseñador (OR entre seleccionados).
            'user_ids' => $this->parseIdListParam($request, 'user_ids'),
            'requires_technical_study' => isset($raw['requires_technical_study']) && $raw['requires_technical_study'] !== ''
                             ? filter_var($raw['requires_technical_study'], FILTER_VALIDATE_BOOLEAN)
                             : null,
            'per_page' => isset($raw['per_page']) ? (int) $raw['per_page'] : null,
        ], fn (mixed $v): bool => $v !== null);

        $paginator = $this->service->list($user, $filters);

        return response()->json([
            'data' => collect($paginator->items())
                ->map(fn (Project $p) => $this->format($p))
                ->values(),
            'meta' => [
                'total' => $paginator->total(),
                'per_page' => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function store(StoreProjectRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /**
         * @var array{
         *   nombre: string,
         *   tipo: string,
         *   etapa?: string,
         *   contacto?: string|null,
         *   email?: string|null,
         *   celular?: string|null,
         *   encargado?: string|null,
         *   developer?: string|null,
         *   arquitecto?: string|null,
         *   requires_technical_study?: bool,
         *   area?: string|null,
         *   despacho?: string|null,
         *   ubicacion?: string|null,
         *   fecha_entrega?: string|null,
         *   valor?: float|null,
         *   notas?: string|null,
         *   assignee_ids?: list<int>,
         * } $validated
         */
        $validated = $request->validated();

        $project = $this->service->create($user, $validated);

        return response()->json($this->formatDetail($project), 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $project = Project::with(['assignees', 'latestActivity', 'reminders'])->withCount('documents')->find($id);
        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($project, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        return response()->json($this->formatDetail($project));
    }

    public function update(UpdateProjectRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $project = Project::with(['assignees', 'latestActivity', 'reminders'])->withCount('documents')->find($id);
        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($project, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        /**
         * @var array{
         *   nombre?: string,
         *   tipo?: string,
         *   contacto?: string|null,
         *   email?: string|null,
         *   celular?: string|null,
         *   encargado?: string|null,
         *   developer?: string|null,
         *   arquitecto?: string|null,
         *   requires_technical_study?: bool,
         *   area?: string|null,
         *   despacho?: string|null,
         *   ubicacion?: string|null,
         *   fecha_entrega?: string|null,
         *   valor?: float|null,
         *   notas?: string|null,
         *   assignee_ids?: list<int>,
         * } $validated
         */
        $validated = $request->validated();

        $project = $this->service->update($project, $validated);

        return response()->json($this->formatDetail($project));
    }

    public function changeStage(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $project = Project::with(['assignees', 'latestActivity'])->withCount('documents')->find($id);
        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($project, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        /** @var array{etapa: string} $validated */
        $validated = $request->validate([
            'etapa' => ['required', 'string', Rule::in(['lead', 'diseno', 'cotizacion', 'propuesta', 'cerrado', 'perdido'])],
        ]);

        $project = $this->service->changeStage($project, $user, $validated['etapa']);

        return response()->json($this->format($project));
    }

    public function destroy(int $id): JsonResponse
    {
        $project = Project::find($id);
        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $this->service->delete($project);

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

    private function canAccess(Project $project, User $user): bool
    {
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        return $project->assignees->contains('id', $user->id);
    }

    /** @return array<string, mixed> */
    private function format(Project $project): array
    {
        $latestActivity = $project->latestActivity;

        return [
            'id' => $project->id,
            'nombre' => $project->nombre,
            'contacto' => $project->contacto,
            'email' => $project->email,
            'celular' => $project->celular,
            'encargado' => $project->encargado,
            'developer' => $project->developer,
            'arquitecto' => $project->arquitecto,
            'tipo' => $project->tipo,
            'requires_technical_study' => $project->requires_technical_study,
            'area' => $project->area,
            'despacho' => $project->despacho,
            'ubicacion' => $project->ubicacion,
            'etapa' => $project->etapa,
            'fecha_entrega' => $project->fecha_entrega?->toDateString(),
            'valor' => $project->valor,
            'notas' => $project->notas,
            'urgency' => $this->service->getUrgency($project),
            'freshness' => $this->service->getFreshness($project),
            'assignees' => $project->assignees
                ->map(fn (User $u) => [
                    'id' => $u->id,
                    'first_name' => $u->first_name,
                    'last_name' => $u->last_name,
                ])
                ->values()
                ->all(),
            'latest_activity' => $latestActivity !== null ? [
                'tipo' => $latestActivity->tipo,
                'fecha' => $latestActivity->fecha->toDateTimeString(),
                'notas' => $latestActivity->notas,
            ] : null,
            'documents_count' => $project->documents_count,
            'created_by' => $project->created_by,
            'created_at' => $project->created_at->toIso8601String(),
            'updated_at' => $project->updated_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatDetail(Project $project): array
    {
        $base = $this->format($project);

        $base['reminders'] = $project->reminders
            ->map(fn (ProjectReminder $r) => [
                'id' => $r->id,
                'tipo' => $r->tipo,
                'fecha' => $r->fecha->toDateString(),
                'nota' => $r->nota,
                'completado' => $r->completado,
                'created_at' => $r->created_at->toIso8601String(),
            ])
            ->values()
            ->all();

        return $base;
    }
}
