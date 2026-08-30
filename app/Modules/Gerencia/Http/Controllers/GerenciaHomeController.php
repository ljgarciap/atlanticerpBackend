<?php

declare(strict_types=1);

namespace App\Modules\Gerencia\Http\Controllers;

use App\Models\User;
use App\Modules\Gerencia\Services\GerenciaHomeService;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\Auth\Models\RoleModuleVisibility;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-156 — Pantalla de inicio del módulo Gerencia.
 *
 * GET /api/gerencia/home
 *   ?desde=YYYY-MM-DD  (default: inicio del mes actual)
 *   ?hasta=YYYY-MM-DD  (default: hoy)
 *   ?vendedor_id=int
 *   ?cliente_id=int
 */
final class GerenciaHomeController extends Controller
{
    public function __construct(private readonly GerenciaHomeService $service) {}

    public function home(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'desde'       => 'nullable|date_format:Y-m-d',
            'hasta'       => 'nullable|date_format:Y-m-d|after_or_equal:desde',
            'vendedor_id' => 'nullable|integer|min:1',
            'cliente_id'  => 'nullable|integer|min:1',
        ]);

        $filters = array_filter($validated, fn (mixed $v): bool => $v !== null);

        return response()->json($this->service->home($filters, $user->id));
    }

    /**
     * GET /api/gerencia/vendors
     *
     * Lista de vendedores (misma lógica que Ventas & Diseño HomeService::vendors()).
     */
    public function vendors(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $roleIds = RoleModuleVisibility::where('module', 'ventas_diseno')
            ->where('can_view', '>=', RoleModuleVisibility::VIEW_FULL)
            ->where('can_view_team', false)
            ->pluck('role_id');

        $vendors = User::whereIn('role_id', $roleIds)
            ->orderBy('first_name')
            ->get()
            ->map(fn (User $u) => [
                'id'   => $u->id,
                'name' => trim("{$u->first_name} {$u->last_name}"),
            ])
            ->values();

        return response()->json($vendors);
    }

    /**
     * GET /api/gerencia/clients?search=foo
     *
     * Lista de clientes (MasterClient) con búsqueda opcional.
     */
    public function clients(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $search = $request->input('search', '');

        $clients = MasterClient::query()
            ->when($search, fn ($q) => $q->where('name', 'ilike', "%{$search}%"))
            ->select('id', 'name')
            ->orderBy('name')
            ->limit(50)
            ->get();

        return response()->json($clients);
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
