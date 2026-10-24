<?php

declare(strict_types=1);

namespace App\Modules\Soporte\Http\Controllers;

use App\Models\User;
use App\Modules\Soporte\Services\TicketsIdentityBridgeService;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-923. Visible a cualquier usuario autenticado (Luis, 2026-09-16) — sin gate de permiso,
 * el grupo `api` ya exige JWT válido (AuthenticateJwt).
 */
final class SoporteWidgetController extends Controller
{
    public function __construct(private readonly TicketsIdentityBridgeService $service) {}

    public function issueCode(Request $request): JsonResponse
    {
        [$user, $tenant] = $this->resolveUserAndTenant($request);
        if ($user === null || $tenant === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        try {
            return response()->json($this->service->issueCode($user, $tenant->slug));
        } catch (CircuitOpenException|\RuntimeException $e) {
            report($e);

            return response()->json(['message' => 'Soporte no disponible en este momento.'], 503);
        }
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

        /** @var User|null $user */
        $user = User::find((int) $payload['sub']);

        return [$user, $tenant];
    }
}
