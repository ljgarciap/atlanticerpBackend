<?php

declare(strict_types=1);

namespace App\Modules\AI\Http\Controllers;

use App\Models\User;
use App\Modules\AI\Events\AnalysisRequested;
use App\Modules\AI\Jobs\DraftMessageJob;
use App\Modules\AI\Jobs\MonthlyAnalysisJob;
use App\Modules\AI\Jobs\SuggestActionsJob;
use App\Modules\AI\Jobs\SummarizeProjectJob;
use App\Modules\AI\Models\AiAnalysis;
use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Support\ProjectAccess;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

final class AIController extends Controller
{
    /** POST /api/ai/summarize */
    public function summarize(Request $request): JsonResponse
    {
        [$user, $tenant] = $this->resolveUserAndTenant($request);
        if ($user === null || $tenant === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
        ]);

        if (($denied = $this->denyUnlessProjectAccess($request, $user, (int) $validated['project_id'])) !== null) {
            return $denied;
        }

        $analysis = $this->findOrCreate(
            entityType:   'project',
            entityId:     (int) $validated['project_id'],
            analysisType: 'summarize',
            requestedBy:  $user->id,
        );

        if ($analysis->wasRecentlyCreated) {
            SummarizeProjectJob::dispatch(
                $analysis->id,
                (int) $validated['project_id'],
                $tenant->slug,
            );
        }

        event(new AnalysisRequested($analysis->id, 'project', 'summarize'));

        return response()->json(['job_id' => $analysis->id], 202);
    }

    /** POST /api/ai/suggest-actions */
    public function suggestActions(Request $request): JsonResponse
    {
        [$user, $tenant] = $this->resolveUserAndTenant($request);
        if ($user === null || $tenant === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'project_id' => ['required', 'integer', 'min:1'],
        ]);

        if (($denied = $this->denyUnlessProjectAccess($request, $user, (int) $validated['project_id'])) !== null) {
            return $denied;
        }

        $analysis = $this->findOrCreate(
            entityType:   'project',
            entityId:     (int) $validated['project_id'],
            analysisType: 'suggest_actions',
            requestedBy:  $user->id,
        );

        if ($analysis->wasRecentlyCreated) {
            SuggestActionsJob::dispatch(
                $analysis->id,
                (int) $validated['project_id'],
                $tenant->slug,
            );
        }

        event(new AnalysisRequested($analysis->id, 'project', 'suggest_actions'));

        return response()->json(['job_id' => $analysis->id], 202);
    }

    /** POST /api/ai/draft-message */
    public function draftMessage(Request $request): JsonResponse
    {
        [$user, $tenant] = $this->resolveUserAndTenant($request);
        if ($user === null || $tenant === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $validated = $request->validate([
            'project_id'   => ['required', 'integer', 'min:1'],
            'message_type' => ['required', 'string', 'in:email,whatsapp,llamada'],
            'intent'       => ['required', 'string', 'max:200'],
        ]);

        if (($denied = $this->denyUnlessProjectAccess($request, $user, (int) $validated['project_id'])) !== null) {
            return $denied;
        }

        $extra = [
            'message_type' => $validated['message_type'],
            'intent'       => $validated['intent'],
        ];

        $analysis = AiAnalysis::create([
            'entity_type'  => 'project',
            'entity_id'    => (int) $validated['project_id'],
            'analysis_type'=> 'draft_message',
            'status'       => AiAnalysis::STATUS_PENDING,
            'requested_by' => $user->id,
            'extra'        => $extra,
        ]);

        DraftMessageJob::dispatch(
            $analysis->id,
            (int) $validated['project_id'],
            $tenant->slug,
            (string) $validated['message_type'],
            (string) $validated['intent'],
        );

        event(new AnalysisRequested($analysis->id, 'project', 'draft_message'));

        return response()->json(['job_id' => $analysis->id], 202);
    }

    /** POST /api/ai/monthly-analysis */
    public function monthlyAnalysis(Request $request): JsonResponse
    {
        [$user, $tenant] = $this->resolveUserAndTenant($request);
        if ($user === null || $tenant === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        if (! $user->canViewTeamV2('ventas_diseno')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validated = $request->validate([
            'month' => ['required', 'string', 'regex:/^\d{4}-\d{2}$/'],
        ]);

        $analysis = $this->findOrCreate(
            entityType:   'monthly_report',
            entityId:     null,
            analysisType: 'monthly_analysis',
            requestedBy:  $user->id,
            extra:        ['month' => $validated['month']],
        );

        if ($analysis->wasRecentlyCreated) {
            MonthlyAnalysisJob::dispatch(
                $analysis->id,
                (string) $validated['month'],
                $tenant->slug,
                $user->id,
            );
        }

        event(new AnalysisRequested($analysis->id, 'monthly_report', 'monthly_analysis'));

        return response()->json(['job_id' => $analysis->id], 202);
    }

    /** GET /api/ai/analyses/{id} */
    public function show(Request $request, string $id): JsonResponse
    {
        [$user] = $this->resolveUserAndTenant($request);
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // SCRUM-1056 (I-02): un id que no es UUID no puede existir (antes era un 500 de Postgres); y quien no puede ver el análisis recibe
        // el mismo 404 que si no existiera, para no confirmar que el id existe.
        /** @var AiAnalysis|null $analysis */
        $analysis = Str::isUuid($id) ? AiAnalysis::find($id) : null;

        if ($analysis === null || ! $this->canReadAnalysis($request, $user, $analysis)) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        return response()->json([
            'id'            => $analysis->id,
            'status'        => $analysis->status,
            'result'        => $analysis->result,
            'error'         => $analysis->error,
            'analysis_type' => $analysis->analysis_type,
        ]);
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $extra
     */
    private function findOrCreate(
        string  $entityType,
        ?int    $entityId,
        string  $analysisType,
        int     $requestedBy,
        array   $extra = [],
    ): AiAnalysis {
        // draft_message always creates a new job (no deduplication, covered by rate limit only)
        if ($analysisType !== 'draft_message') {
            $existing = AiAnalysis::where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('analysis_type', $analysisType)
                ->where(function ($q) {
                    // Reuse in-flight jobs only if not stale (< 5 min without progress)
                    $q->where(function ($q1) {
                          $q1->whereIn('status', [AiAnalysis::STATUS_PENDING, AiAnalysis::STATUS_RUNNING])
                             ->where('updated_at', '>=', now()->subMinutes(5));
                      })
                      ->orWhere(function ($q2) {
                          // 30-minute cooldown after completion
                          $q2->where('status', AiAnalysis::STATUS_COMPLETED)
                             ->where('updated_at', '>=', now()->subMinutes(30));
                      });
                })
                ->latest()
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        /** @var AiAnalysis $analysis */
        $analysis = AiAnalysis::create([
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'analysis_type' => $analysisType,
            'status'        => AiAnalysis::STATUS_PENDING,
            'requested_by'  => $requestedBy,
            'extra'         => $extra,
        ]);

        return $analysis;
    }

    /**
     * SCRUM-1056 (I-01, I-03): las funciones de IA de proyecto aplican la misma regla de acceso que el CRM, con sus mismas respuestas:
     * proyecto inexistente → 404, sin acceso → 403. Se evalúa ANTES de crear el análisis y de enviar nada al tercero.
     */
    private function denyUnlessProjectAccess(Request $request, User $user, int $projectId): ?JsonResponse
    {
        /** @var Project|null $project */
        $project = Project::with('assignees')->find($projectId);

        if ($project === null) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        if (! ProjectAccess::canAccess($project, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        return null;
    }

    /**
     * SCRUM-1056 (I-02): el solicitante siempre lee el suyo; si no, rige el acceso a la entidad: proyecto → regla del CRM; informe mensual →
     * quien ve a todo el equipo (Gerencia, superadmin); cualquier otro tipo (análisis de Compras, p. ej. datos bancarios extraídos) → solo superadmin.
     */
    private function canReadAnalysis(Request $request, User $user, AiAnalysis $analysis): bool
    {
        if ($analysis->requested_by === $user->id) {
            return true;
        }

        if ($analysis->entity_type === 'project') {
            /** @var Project|null $project */
            $project = Project::with('assignees')->find((int) $analysis->entity_id);

            return $project !== null && ProjectAccess::canAccess($project, $user);
        }

        if ($analysis->entity_type === 'monthly_report') {
            return $user->canViewTeamV2('ventas_diseno');
        }

        return in_array('superadmin.all', $user->effectivePermissionsV2(), true);
    }

    /** @return array{0: User|null, 1: Tenant|null} */
    private function resolveUserAndTenant(Request $request): array
    {
        /** @var array{sub?: int, tenant?: string}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'], $payload['tenant'])) {
            return [null, null];
        }

        $tenant = Tenant::where('slug', $payload['tenant'])->first();

        if ($tenant === null) {
            return [null, null];
        }

        $tenant->makeCurrent();

        /** @var User|null $user */
        $user = User::find((int) $payload['sub']);

        return [$user, $tenant];
    }
}
