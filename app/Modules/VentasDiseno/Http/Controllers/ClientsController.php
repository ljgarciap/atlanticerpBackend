<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Http\Requests\StoreClientRequest;
use App\Modules\VentasDiseno\Http\Requests\StoreClientSubClientRequest;
use App\Modules\VentasDiseno\Http\Requests\UpdateSubClientCategoryRequest;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Modules\VentasDiseno\Services\ClientsService;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Pantalla Clientes (REQ-063 a REQ-070) — jerarquía Cliente Master → Subcliente →
 * Proyectos, con métricas agregadas desde Pipeline. Ver
 * memory/project_backlog_ventas_diseno_req.md (Grupo 2). Distinto del
 * ClientController singular (que solo cubre el flujo mínimo de buscar/crear cliente
 * al vincular desde Pipeline, REQ-010).
 */
final class ClientsController extends Controller
{
    public function __construct(private readonly ClientsService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $raw = $request->only(['search', 'category', 'stale']);

        // SCRUM-93 — el filtro de categoría admite selección múltiple (chips A/B/C/D
        // combinables); category[]=a&category[]=b llega como array, category=a (uso viejo,
        // un solo valor) sigue funcionando igual.
        $categories = match (true) {
            is_array($raw['category'] ?? null) => array_values(array_filter(
                array_map('strval', $raw['category']),
                fn (string $c): bool => $c !== '',
            )),
            isset($raw['category']) && $raw['category'] !== '' => [(string) $raw['category']],
            default => [],
        };

        $filters = array_filter([
            'search'     => isset($raw['search']) ? (string) $raw['search'] : null,
            'categories' => $categories !== [] ? $categories : null,
            'stale'      => isset($raw['stale']) ? filter_var($raw['stale'], FILTER_VALIDATE_BOOLEAN) : null,
        ], fn (mixed $v): bool => $v !== null);

        // SCRUM-754 — sin sort expuesto en esta pantalla hoy, solo per_page/page.
        $pagination = Pagination::params($request, [], 'name');

        return response()->json($this->service->list($filters, [
            'per_page' => $pagination['per_page'],
            'page'     => $pagination['page'],
        ]));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $client = $this->service->findWithHierarchy($id);
        if ($client === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->formatDetail($client));
    }

    public function projects(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $client = MasterClient::find($id);
        if ($client === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $cards = $this->service->projectsFor($id);

        return response()->json([
            'data' => $cards->map(fn (PipelineCard $c) => [
                'id'          => $c->id,
                'sub_client'  => $c->subClient !== null ? [
                    'id' => $c->subClient->id, 'business_name' => $c->subClient->business_name,
                ] : null,
                'project'     => ['id' => $c->salesProject->id, 'name' => $c->salesProject->name],
                'stage'       => $c->stage,
                'amount'      => $c->amount,
            ])->values(),
        ]);
    }

    public function store(StoreClientRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /**
         * @var array{
         *   name: string, default_price_type?: string|null,
         *   business_name: string, receptor_tipo?: string|null, tax_id?: string|null, tax_id_dv?: string|null, delivery_address?: string|null, category?: string|null,
         *   contacts: list<array{name: string, role: string, phone?: string|null, email?: string|null}>,
         *   from_quote_context?: bool,
         * } $validated
         */
        $validated = $request->validated();

        // SCRUM-1003 — marcar un Consumidor Final exige el permiso angosto (Gerencia/Contabilidad).
        if (($validated['receptor_tipo'] ?? null) === ReceptorTipo::CONSUMIDOR_FINAL && ! $user->hasPermission(ReceptorTipo::PERMISO_CONSUMIDOR_FINAL)) {
            return response()->json(['message' => ReceptorTipo::MENSAJE_SIN_PERMISO], 403);
        }

        // REQ-034/067: solo Gerencia (grant individual, no de rol) puede seleccionar Precio Socio.
        if (($validated['default_price_type'] ?? null) === 'partner' && ! $user->hasPermission('ventas_diseno.select_partner_price')) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        // SCRUM-1187 — crear un cliente NO crea proyecto ni Lead (reemplaza REQ-068). `from_quote_context` (SCRUM-873) se sigue aceptando
        // por compatibilidad con el frontend, pero ya no cambia nada: ningún contexto crea Lead.
        $client = $this->service->createClient($validated);

        return response()->json($this->formatDetail($client), 201);
    }

    public function storeSubClient(StoreClientSubClientRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $master = MasterClient::find($id);
        if ($master === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /**
         * @var array{
         *   business_name: string, receptor_tipo?: string|null, tax_id?: string|null, tax_id_dv?: string|null, delivery_address?: string|null, category?: string|null,
         *   contacts: list<array{name: string, role: string, phone?: string|null, email?: string|null}>,
         *   from_quote_context?: bool,
         * } $validated
         */
        $validated = $request->validated();

        // SCRUM-1003 — marcar un Consumidor Final exige el permiso angosto (Gerencia/Contabilidad).
        if (($validated['receptor_tipo'] ?? null) === ReceptorTipo::CONSUMIDOR_FINAL && ! $user->hasPermission(ReceptorTipo::PERMISO_CONSUMIDOR_FINAL)) {
            return response()->json(['message' => ReceptorTipo::MENSAJE_SIN_PERMISO], 403);
        }

        // SCRUM-1187 — ver nota equivalente en store() arriba: ningún contexto crea Lead.
        $subClient = $this->service->addSubClient($master, $validated);

        return response()->json($this->formatSubClient($subClient), 201);
    }

    public function updateSubClientCategory(UpdateSubClientCategoryRequest $request, int $subClientId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $subClient = SubClient::find($subClientId);
        if ($subClient === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{category?: string|null} $validated */
        $validated = $request->validated();

        $subClient = $this->service->updateSubClientCategory($subClient, $validated['category'] ?? null);

        return response()->json($this->formatSubClient($subClient));
    }

    /** @return array<string, mixed> */
    private function formatDetail(MasterClient $client): array
    {
        return [
            'id'                 => $client->id,
            'name'               => $client->name,
            'default_price_type' => $client->default_price_type,
            'sub_clients'        => $client->subClients->map(fn (SubClient $s) => $this->formatSubClient($s))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatSubClient(SubClient $subClient): array
    {
        return [
            'id'                => $subClient->id,
            'master_client_id'  => $subClient->master_client_id,
            'business_name'     => $subClient->business_name,
            'receptor_tipo'     => $subClient->receptor_tipo ?? ReceptorTipo::CONTRIBUYENTE,
            'tax_id'            => $subClient->tax_id,
            'tax_id_dv'         => $subClient->tax_id_dv,
            'delivery_address'  => $subClient->delivery_address,
            'category'          => $subClient->category,
            // SCRUM-1166 — para avisar en la ficha del cliente que está exento de ITBMS (solo lectura aquí).
            'itbms_exento'             => $subClient->itbms_exento,
            'itbms_exencion_documento' => $subClient->itbms_exencion_documento,
            'contacts'          => $subClient->contacts->map(fn ($c) => [
                'id' => $c->id, 'name' => $c->name, 'role' => $c->role, 'phone' => $c->phone, 'email' => $c->email,
            ])->values(),
        ];
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
