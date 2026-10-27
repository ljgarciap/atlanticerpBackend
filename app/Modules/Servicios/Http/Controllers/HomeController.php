<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Servicios\Services\HomeService;
use App\Modules\Servicios\Services\InstallationGoalService;
use App\Shared\Support\ApprovalsPendingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Fase 4 — Servicios, Batch 15 (REQ-207/208/210/211) — pantalla "Inicio". Mismo patrón que
 * `BodegaHomeController`/`ComprasHomeController` (un único `summary()` con secciones anidadas).
 */
final class HomeController extends Controller
{
    public function __construct(
        private readonly HomeService $home,
        private readonly InstallationGoalService $goals,
        private readonly ApprovalsPendingService $approvalsPendingService,
    ) {}

    /** GET /api/servicios/home/summary */
    public function summary(): JsonResponse
    {
        return response()->json($this->home->summary());
    }

    /**
     * GET /api/servicios/home/estado-tickets — REQ-215. Query param `tipo` opcional (mismo
     * vocabulario que `TicketTypes::keys()`, validado por el filtro de `TicketService::board()`). Aparte
     * de `summary()` porque necesita este param, que el resto de las secciones no usa.
     */
    public function estadoTickets(Request $request): JsonResponse
    {
        $tipo = $request->query('tipo');

        return response()->json($this->home->estadoTickets(is_string($tipo) ? $tipo : null));
    }

    /**
     * PUT /api/servicios/home/installation-goal — REQ-211 RN2a(c), Gerencia sobrescribe la meta
     * de un mes puntual (RN: rol validado en la ruta — solo `role:superadmin,management`).
     */
    public function updateInstallationGoal(Request $request): JsonResponse
    {
        /** @var array{year: int, month: int, value: int} $validated */
        $validated = $request->validate([
            'year'  => ['required', 'integer', 'min:2020'],
            'month' => ['required', 'integer', 'min:1', 'max:12'],
            'value' => ['required', 'integer', 'min:0'],
        ]);

        $this->goals->setOverride($validated['year'], $validated['month'], $validated['value']);

        return response()->json($this->goals->goalForMonth($validated['year'], $validated['month']));
    }

    /** SCRUM-908 — GET /api/servicios/home/approvals-pending. Ver docblock análogo en AdminContHomeController. */
    public function approvalsPending(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json(['data' => $this->approvalsPendingService->forModule($user, $this->jwtPermissions($request), 'servicios')]);
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

    /** @return list<string> */
    private function jwtPermissions(Request $request): array
    {
        /** @var array{permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');

        return is_array($payload['permissions'] ?? null)
            ? array_values(array_filter($payload['permissions'], 'is_string'))
            : [];
    }
}
