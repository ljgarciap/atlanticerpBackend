<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Compras\Services\ComprasHomeService;
use App\Shared\Support\ApprovalsPendingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** REQ-111→118 — pantalla Inicio de Compras. Ver docblock de ComprasHomeService para el alcance. */
final class ComprasHomeController extends Controller
{
    public function __construct(
        private readonly ComprasHomeService $service,
        private readonly ApprovalsPendingService $approvalsPendingService,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json($this->service->summary($user));
    }

    /** SCRUM-908 — GET /api/compras/home/approvals-pending. Ver docblock análogo en AdminContHomeController. */
    public function approvalsPending(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json(['data' => $this->approvalsPendingService->forModule($user, $this->jwtPermissions($request), 'compras')]);
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
