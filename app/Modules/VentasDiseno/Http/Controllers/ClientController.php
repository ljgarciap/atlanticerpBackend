<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\StoreMasterClientRequest;
use App\Modules\VentasDiseno\Http\Requests\StoreSubClientRequest;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-010: buscar o crear Cliente Master / Subcliente al vincularlos a una tarjeta
 * de Pipeline. La pantalla completa de Clientes (REQ-063 a REQ-070, con jerarquía y
 * filtros por categoría) es Grupo 2 — este controller solo cubre lo mínimo que
 * necesita el flujo de vinculación desde Pipeline.
 */
final class ClientController extends Controller
{
    public function masterClients(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $query = MasterClient::query()->orderBy('name');

        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $query->where('name', 'ilike', "%{$search}%");
        }

        $clients = $query->limit(20)->get();

        return response()->json([
            'data' => $clients->map(fn (MasterClient $c) => [
                'id' => $c->id, 'name' => $c->name, 'default_price_type' => $c->default_price_type,
            ])->values(),
        ]);
    }

    public function storeMasterClient(StoreMasterClientRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{name: string, default_price_type?: string|null} $validated */
        $validated = $request->validated();

        // REQ-034/067: mismo grant angosto que ClientsController::store() — el campo es
        // el mismo (MasterClient::default_price_type), así que la restricción debe
        // aplicar en ambos puntos de entrada o quedaría evitable desde este flujo.
        if (($validated['default_price_type'] ?? null) === 'partner' && ! $user->hasPermission('ventas_diseno.select_partner_price')) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $client = MasterClient::create($validated);

        return response()->json([
            'id' => $client->id, 'name' => $client->name, 'default_price_type' => $client->default_price_type,
        ], 201);
    }

    public function subClients(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $masterClientId = $request->query('master_client_id');
        if (! is_numeric($masterClientId)) {
            return response()->json(['message' => 'master_client_id es requerido'], 422);
        }

        $query = SubClient::query()->withCount('contacts')->where('master_client_id', (int) $masterClientId)->orderBy('business_name');

        $search = $request->query('search');
        if (is_string($search) && $search !== '') {
            $query->where('business_name', 'ilike', "%{$search}%");
        }

        $subClients = $query->limit(20)->get();

        return response()->json([
            // REQ-020: contacts_count permite advertir en el frontend antes de crear
            // un proyecto en Diseño vinculado a un Subcliente sin contactos.
            'data' => $subClients->map(fn (SubClient $c) => [
                'id' => $c->id, 'business_name' => $c->business_name, 'receptor_tipo' => $c->receptor_tipo ?? ReceptorTipo::CONTRIBUYENTE, 'tax_id' => $c->tax_id, 'tax_id_dv' => $c->tax_id_dv,
                'category' => $c->category, 'contacts_count' => $c->contacts_count,
            ])->values(),
        ]);
    }

    public function storeSubClient(StoreSubClientRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /**
         * @var array{
         *   master_client_id: int, business_name: string, receptor_tipo?: string|null, tax_id?: string|null, tax_id_dv?: string|null,
         *   delivery_address?: string|null, category?: string|null, from_design_context?: bool,
         * } $validated
         */
        $validated = $request->validated();

        // SCRUM-1003 — marcar un Consumidor Final exige el permiso angosto (Gerencia/Felix).
        if (($validated['receptor_tipo'] ?? null) === ReceptorTipo::CONSUMIDOR_FINAL && ! $user->hasPermission(ReceptorTipo::PERMISO_CONSUMIDOR_FINAL)) {
            return response()->json(['message' => ReceptorTipo::MENSAJE_SIN_PERMISO], 403);
        }

        // SCRUM-1011 — bandera de contexto, no es una columna de sub_clients; se descarta antes de crear.
        unset($validated['from_design_context']);

        // SCRUM-1187 (reemplaza REQ-068 «en sentido inverso», SCRUM-79): un Subcliente nuevo, venga del flujo de vinculación de un
        // Lead en Pipeline o del modal de Diseño, ya no crea proyecto ni tarjeta Lead extra. `from_design_context` (SCRUM-1011) se
        // sigue aceptando por compatibilidad con el frontend, pero ya no cambia nada.
        /** @var SubClient $subClient */
        $subClient = SubClient::create(ReceptorTipo::normalizar($validated));

        return response()->json([
            'id' => $subClient->id, 'business_name' => $subClient->business_name,
            'receptor_tipo' => $subClient->receptor_tipo ?? ReceptorTipo::CONTRIBUYENTE, 'tax_id' => $subClient->tax_id, 'tax_id_dv' => $subClient->tax_id_dv, 'category' => $subClient->category,
        ], 201);
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
