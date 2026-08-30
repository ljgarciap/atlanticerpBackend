<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Bodega\Models\WarehouseAssistantMapping;
use App\Modules\Bodega\Services\WarehouseAssistantMappingService;
use App\Modules\Bodega\Support\BodegaRoles;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-329 Oleada A / Batch A2 (REQ-307 RN3) — CRUD del mapeo vendedor→Asistente de Bodega.
 * PERMISOS (tal cual el ticket): "editable solo por el Jefe de Bodega o un administrador del
 * sistema" — `superadmin.all` ya bypassa el middleware `permission:bodega.write`, así que acá solo
 * hace falta el gate adicional de rol para excluir a los Asistentes (que sí tienen bodega.write
 * para operar el tablero, pero no deben poder reasignarse su propia carga).
 */
final class WarehouseAssistantMappingController extends Controller
{
    public function __construct(private readonly WarehouseAssistantMappingService $service) {}

    /** GET /api/bodega/warehouse-assistant-mapping */
    public function index(): JsonResponse
    {
        $mappings = $this->service->active();

        return response()->json(['data' => $mappings->map(fn (WarehouseAssistantMapping $m) => [
            'id'              => $m->id,
            'seller_id'       => $m->seller_user_id,
            'seller_name'     => trim("{$m->seller->first_name} {$m->seller->last_name}"),
            'assistant_id'    => $m->assistant_user_id,
            'assistant_name'  => trim("{$m->assistant->first_name} {$m->assistant->last_name}"),
        ])->values()]);
    }

    /** POST /api/bodega/warehouse-assistant-mapping */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if ($user->role?->key !== BodegaRoles::LIDER_BODEGA && ! in_array('superadmin.all', $user->effectivePermissionsV2(), true)) {
            return response()->json(['message' => 'Solo el Jefe de Bodega o un administrador pueden editar este mapeo.'], 403);
        }

        // "users" SIN calificar — el search_path del tenant ya resuelve el schema correcto; una
        // tabla calificada ("illuminations_auth.users") rompe la regla `exists:` porque Laravel
        // interpreta lo que va antes del punto como nombre de CONEXIÓN, no de schema (mismo gotcha
        // ya documentado para `Rule::unique`, ver memory/feedback_rule_unique_schema_qualified_table.md).
        $validated = $request->validate([
            'seller_id'    => ['required', 'integer', 'exists:users,id'],
            'assistant_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $mapping = $this->service->setMapping((int) $validated['seller_id'], (int) $validated['assistant_id']);

        return response()->json(['id' => $mapping->id], 201);
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
