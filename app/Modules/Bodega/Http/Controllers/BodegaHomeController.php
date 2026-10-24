<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Support\ModuleCatalog;
use App\Modules\Bodega\Services\BodegaHomeService;
use App\Shared\Support\ApprovalsPendingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** SCRUM-329 Oleada A / Batch B1 (REQ-294→304, SCRUM-363→374) — pantalla "Home de Bodega". */
final class BodegaHomeController extends Controller
{
    public function __construct(
        private readonly BodegaHomeService $service,
        private readonly ApprovalsPendingService $approvalsPendingService,
    ) {}

    /**
     * SCRUM-365/366 (REQ-295/296) — lista de personas del equipo para el selector, vacía para
     * quien no puede ver "Equipo" (Picker/Repartidor) ya que el propio selector queda oculto en
     * ese caso — mismo criterio que `HomeController::vendors()` (Ventas & Diseño).
     */
    public function team(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! $user->canViewTeamV2(ModuleCatalog::BODEGA)) {
            return response()->json(['data' => []]);
        }

        return response()->json(['data' => $this->service->team()->values()]);
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

    /** SCRUM-171 RN2 — "Bodega → Rutas de entrega del día completo" (sin scope Mío/Equipo, es la vista operativa completa del día). */
    public function rutasDia(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json(['data' => $this->service->rutasDia()]);
    }

    /** SCRUM-908 — GET /api/bodega/home/approvals-pending. Ver docblock análogo en AdminContHomeController. */
    public function approvalsPending(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        return response()->json(['data' => $this->approvalsPendingService->forModule($user, $this->jwtPermissions($request), 'bodega')]);
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
