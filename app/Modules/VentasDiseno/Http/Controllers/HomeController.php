<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Services\HomeService;
use App\Shared\Support\ApprovalsPendingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class HomeController extends Controller
{
    public function __construct(
        private readonly HomeService $service,
        private readonly ApprovalsPendingService $approvalsPendingService,
    ) {}

    /**
     * SCRUM-908 — GET /api/ventas-diseno/home/approvals-pending. Ver docblock análogo en
     * AdminContHomeController — hoy siempre devuelve lista vacía (ningún tipo de aprobación
     * pertenece a Ventas & Diseño en el inventario de SCRUM-908), el endpoint existe solo para
     * que los 6 módulos sean consistentes.
     */
    public function approvalsPending(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json(['data' => $this->approvalsPendingService->forModule($user, $this->jwtPermissions($request), 'ventas_diseno')]);
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

    /**
     * REQ-056: lista de vendedores para el selector — vacía para quien no puede ver
     * "Equipo" (Diseñador), ya que el propio selector queda oculto en ese caso.
     */
    public function vendors(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        // ADR-006, Fase C Task 4 — canViewTeamV2() reemplaza hasRole(MANAGEMENT/SUPERADMIN)
        if (! $user->canViewTeamV2('ventas_diseno')) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->service->vendors()->values()]);
    }

    public function summary(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $raw = $request->only(['scope', 'owner_id']);
        $filters = array_filter([
            'scope'    => isset($raw['scope']) ? (string) $raw['scope'] : null,
            'owner_id' => isset($raw['owner_id']) && is_numeric($raw['owner_id']) ? (int) $raw['owner_id'] : null,
        ], fn (mixed $v): bool => $v !== null);

        return response()->json($this->service->summary($user, $filters));
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
