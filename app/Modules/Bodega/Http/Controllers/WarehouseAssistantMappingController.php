<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Models\WarehouseAssistantMapping;
use App\Modules\Bodega\Services\WarehouseAssistantMappingService;
use App\Modules\Auth\Support\RoleKeys;
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
        // tabla calificada ("atlantic_auth.users") rompe la regla `exists:` porque Laravel
        // interpreta lo que va antes del punto como nombre de CONEXIÓN, no de schema (mismo gotcha
        // ya documentado para `Rule::unique`, ver memory/feedback_rule_unique_schema_qualified_table.md).
        $validated = $request->validate([
            'seller_id'    => ['required', 'integer', 'exists:users,id'],
            'assistant_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        // Fase 29: el mapeo solo acepta un vendedor con rol de ventas y un asistente con rol de Bodega.
        $errores = $this->erroresDeRol((int) $validated['seller_id'], (int) $validated['assistant_id']);
        if ($errores !== []) {
            return response()->json(['message' => 'El vendedor o el asistente no tiene el rol que corresponde.', 'errors' => $errores], 422);
        }

        $mapping = $this->service->setMapping((int) $validated['seller_id'], (int) $validated['assistant_id']);

        return response()->json(['id' => $mapping->id], 201);
    }

    /** DELETE /api/bodega/warehouse-assistant-mapping/{id} — quita (desactiva) el mapeo; los pedidos ya creados no cambian. */
    public function destroy(Request $request, int $id): JsonResponse
    {
        if (($denegado = $this->soloLiderOSuperadmin($request)) !== null) {
            return $denegado;
        }

        $mapping = WarehouseAssistantMapping::where('id', $id)->where('is_active', true)->first();
        if ($mapping === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        $mapping->update(['is_active' => false]);

        return response()->json(['id' => $mapping->id, 'is_active' => false]);
    }

    /**
     * POST /api/bodega/warehouse-assistant-mapping/reassign-pending — acción EXPLÍCITA (Fase 29): pasa al asistente
     * mapeado hoy los pedidos pendientes (no entregados) de un vendedor que tienen otro asistente o ninguno.
     */
    public function reassignPending(Request $request): JsonResponse
    {
        if (($denegado = $this->soloLiderOSuperadmin($request)) !== null) {
            return $denegado;
        }

        $sellerId = (int) $request->validate(['seller_id' => ['required', 'integer', 'exists:users,id']])['seller_id'];
        $mapping = WarehouseAssistantMapping::where('seller_user_id', $sellerId)->where('is_active', true)->first();
        if ($mapping === null) {
            return response()->json(['message' => 'Ese vendedor no tiene un asistente mapeado.'], 422);
        }

        $reasignados = Order::query()
            ->where('stage', '!=', Order::STAGE_ENTREGADO)
            ->whereHas('pipelineCard', fn ($q) => $q->where('owner_id', $sellerId))
            ->where(fn ($q) => $q->whereNull('assistant_id')->orWhere('assistant_id', '!=', $mapping->assistant_user_id))
            ->update(['assistant_id' => $mapping->assistant_user_id]);

        return response()->json(['reasignados' => $reasignados, 'assistant_id' => $mapping->assistant_user_id]);
    }

    private function soloLiderOSuperadmin(Request $request): ?JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        if ($user->role?->key !== BodegaRoles::LIDER_BODEGA && ! in_array('superadmin.all', $user->effectivePermissionsV2(), true)) {
            return response()->json(['message' => 'Solo el Jefe de Bodega o un administrador pueden editar este mapeo.'], 403);
        }

        return null;
    }

    /** @return array<string, list<string>> */
    private function erroresDeRol(int $sellerId, int $assistantId): array
    {
        $errores = [];
        if (User::find($sellerId)?->role?->key !== RoleKeys::VENDEDOR) {
            $errores['seller_id'] = ['El vendedor debe tener el rol de ventas.'];
        }
        if (User::find($assistantId)?->role?->key !== BodegaRoles::ASISTENTE_BODEGA) {
            $errores['assistant_id'] = ['El asistente debe tener el rol de Asistente de Bodega.'];
        }

        return $errores;
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
