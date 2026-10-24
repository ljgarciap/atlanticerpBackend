<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Services\ReportsService;
use App\Shared\Support\ParsesIdListQueryParam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Pantalla Reportes (REQ-071 a 079), Grupo 4 — ver
 * memory/project_backlog_ventas_diseno_req.md para el desglose Reportes-A/B/C/D.
 */
final class ReportsController extends Controller
{
    use ParsesIdListQueryParam;

    public function __construct(private readonly ReportsService $service) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $raw = $request->only(['scope', 'period', 'owner_id']);
        $filters = array_filter([
            'scope'     => isset($raw['scope'])  ? (string) $raw['scope']  : null,
            'period'    => isset($raw['period']) ? (string) $raw['period'] : null,
            'owner_id'  => isset($raw['owner_id']) && is_numeric($raw['owner_id']) ? (int) $raw['owner_id'] : null,
            // SCRUM-854 — multi-selección Vendedor/Diseñador (OR entre seleccionados).
            'owner_ids' => $this->parseIdListParam($request, 'owner_ids'),
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
