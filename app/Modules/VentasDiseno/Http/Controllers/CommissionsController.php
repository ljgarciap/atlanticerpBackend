<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Services\CommissionService;
use App\Modules\VentasDiseno\Services\ReportsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-073 — Panel "Comisiones". Ver CommissionService para el detalle de la
 * tabla escalonada, el "cierre" mensual y el marcado manual de pago.
 */
final class CommissionsController extends Controller
{
    public function __construct(
        private readonly CommissionService $service,
        private readonly ReportsService $reports,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $raw = $request->only(['scope', 'period', 'owner_id']);
        $filters = array_filter([
            'scope'    => isset($raw['scope'])  ? (string) $raw['scope']  : null,
            'period'   => isset($raw['period']) ? (string) $raw['period'] : null,
            'owner_id' => isset($raw['owner_id']) && is_numeric($raw['owner_id']) ? (int) $raw['owner_id'] : null,
        ], fn (mixed $v): bool => $v !== null);

        $scope  = $this->reports->resolveScope($user, $filters);
        $period = $filters['period'] ?? 'month';

        return response()->json($this->service->summary($user, $scope, $period, $filters['owner_id'] ?? null));
    }

    public function markPaid(Request $request, int $cohortId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $cohort = $this->service->markPaid($cohortId);
        if ($cohort === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'id' => $cohort->id, 'paid_at' => $cohort->paid_at?->toIso8601String(),
        ]);
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
