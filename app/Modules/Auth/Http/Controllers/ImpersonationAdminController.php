<?php

declare(strict_types=1);

namespace App\Modules\Auth\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Models\ImpersonationSession;
use App\Modules\Auth\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;

/**
 * SCRUM-1158 — pantalla «Sesiones de soporte» (rutas bajo `permission:superadmin.all` en routes/admin.php): listado de las sesiones de
 * «Ver como usuario» y terminación forzada de una activa. Solo lectura del historial: nunca se edita ni se borra una sesión.
 */
class ImpersonationAdminController extends Controller
{
    public function __construct(private readonly ImpersonationService $service) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'actor_id'  => ['sometimes', 'integer', 'min:1'],
            'target_id' => ['sometimes', 'integer', 'min:1'],
            'status'    => ['sometimes', 'string', 'in:active,ended,expired'],
            'per_page'  => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $query = ImpersonationSession::query()->with(['actor:id,first_name,last_name', 'target:id,first_name,last_name'])->orderByDesc('started_at');

        if (isset($data['actor_id'])) {
            $query->where('actor_id', (int) $data['actor_id']);
        }
        if (isset($data['target_id'])) {
            $query->where('target_id', (int) $data['target_id']);
        }
        match ($data['status'] ?? null) {
            ImpersonationSession::STATUS_ACTIVE  => $query->whereNull('ended_at')->where('expires_at', '>', now()),
            ImpersonationSession::STATUS_EXPIRED => $query->whereNull('ended_at')->where('expires_at', '<=', now()),
            ImpersonationSession::STATUS_ENDED   => $query->whereNotNull('ended_at'),
            default                              => null,
        };

        $page = $query->paginate((int) ($data['per_page'] ?? 25));

        return response()->json([
            'data' => $page->getCollection()->map(fn (ImpersonationSession $s): array => [
                'id'           => $s->id,
                'actor'        => ['id' => $s->actor_id, 'name' => $this->name($s->actor)],
                'target'       => ['id' => $s->target_id, 'name' => $this->name($s->target)],
                'reason'       => $s->reason,
                'started_at'   => $s->started_at->toIso8601String(),
                'expires_at'   => $s->expires_at->toIso8601String(),
                'ended_at'     => $s->ended_at?->toIso8601String(),
                'ended_reason' => $s->ended_reason,
                'status'       => $s->status(),
            ])->values()->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'per_page'     => $page->perPage(),
                'total'        => $page->total(),
                'last_page'    => $page->lastPage(),
            ],
        ]);
    }

    public function revoke(Request $request, string $id): JsonResponse
    {
        // Un id que no es UUID jamás llega a la consulta (Postgres respondería 500).
        $session = Str::isUuid($id) ? ImpersonationSession::find($id) : null;
        if ($session === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $payload = $request->attributes->get('jwt');
        $revoker = is_array($payload) && isset($payload['sub']) ? User::find((int) $payload['sub']) : null;
        if ($revoker === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! $this->service->revoke($session, $revoker)) {
            return response()->json(['message' => trans('auth.impersonation_not_active'), 'code' => 'already_ended'], 409);
        }

        return response()->json(null, 204);
    }

    private function name(?User $user): string
    {
        return $user !== null ? trim($user->first_name . ' ' . $user->last_name) : '';
    }
}
