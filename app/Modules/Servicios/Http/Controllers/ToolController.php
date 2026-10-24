<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Servicios\Exceptions\ToolPurchaseRequestAlreadyReceivedException;
use App\Modules\Servicios\Models\Tool;
use App\Modules\Servicios\Models\ToolPurchaseRequest;
use App\Modules\Servicios\Services\ToolService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/** Fase 4 — Servicios, Batch 13 parte 1 (REQ-268→272, Grupo D "Herramientas"). */
final class ToolController extends Controller
{
    public function __construct(private readonly ToolService $tools) {}

    /** GET /api/servicios/tools — REQ-268. */
    public function index(Request $request): JsonResponse
    {
        $filters = [];

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $filters['search'] = $search;
        }

        $estado = $request->query('estado');
        if (is_string($estado) && $estado !== '') {
            $filters['estado'] = $estado;
        }

        $assignedTo = $request->query('assigned_to');
        if (is_string($assignedTo) && $assignedTo !== '') {
            $filters['assigned_to'] = $assignedTo;
        }

        return response()->json(['data' => $this->tools->list($filters)]);
    }

    /**
     * GET /api/servicios/tools/purchase-requests — companion de REQ-271 RN2 ("visible como
     * pendiente de recibir, con botón 'Solicitado a Contabilidad'"). No estaba en la lista original de
     * endpoints del ticket — sin él, el frontend no tiene forma de listar solicitudes ni de
     * descubrir el {id} que `POST .../recibir` necesita, dejando REQ-271/272 no funcionales de
     * punta a punta. Agregado como decisión técnica dentro del diseño ya aprobado (mismo criterio
     * de "Decisiones técnicas dentro del diseño aprobado → agentes las resuelven solos" del
     * CLAUDE.md) — mismo permiso de lectura que el resto del módulo.
     */
    public function purchaseRequests(): JsonResponse
    {
        return response()->json(['data' => $this->tools->listPurchaseRequests()]);
    }

    /**
     * GET /api/servicios/tools/movements — REQ-276 (SCRUM-346, "Kardex de herramientas"). Feature
     * 100% frontend salvo este endpoint, ver docblock de `ToolService::listMovements()`. Filtros
     * combinables, todos opcionales.
     */
    public function movements(Request $request): JsonResponse
    {
        $filters = [];

        $toolId = $request->query('tool_id');
        if (is_numeric($toolId)) {
            $filters['tool_id'] = (int) $toolId;
        }

        $tipo = $request->query('tipo');
        if (is_string($tipo) && $tipo !== '') {
            $filters['tipo'] = $tipo;
        }

        $userId = $request->query('user_id');
        if (is_numeric($userId)) {
            $filters['user_id'] = (int) $userId;
        }

        return response()->json(['data' => $this->tools->listMovements($filters)]);
    }

    /** PATCH /api/servicios/tools/{id}/estado — REQ-269 (RN5: rol validado en la ruta). */
    public function updateEstado(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $tool = Tool::find($id);
        if ($tool === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{estado: string, detalle?: string} $validated */
        $validated = $request->validate([
            'estado'  => ['required', 'string', Rule::in(Tool::ESTADOS)],
            // SCRUM-779 — opcional acá a propósito (Buen estado no lo usa); RN de negocio de
            // "obligatorio para Dañada/Perdida/Desgaste" se exige en el frontend (mismo criterio
            // que TicketCancelModal — motivo obligatorio antes de habilitar el botón de confirmar).
            'detalle' => ['nullable', 'string', 'max:1000'],
        ]);

        $tool = $this->tools->updateEstado($tool, $validated['estado'], $user, $validated['detalle'] ?? null);

        return response()->json($this->tools->present($tool));
    }

    /** PATCH /api/servicios/tools/{id}/asignacion — REQ-270 (RN2: rol validado en la ruta). */
    public function updateAsignacion(Request $request, int $id): JsonResponse
    {
        $tool = Tool::find($id);
        if ($tool === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Rule::exists sin calificar el schema — el search_path del tenant ya resuelve
        // `internal_technicians` a `{tenant}_servicios.internal_technicians` (mismo gotcha de
        // Rule::unique en un modelo de tenant, ver memoria del proyecto).
        /** @var array{assigned_to_technician_id: int|null} $validated */
        $validated = $request->validate([
            'assigned_to_technician_id' => ['nullable', 'integer', Rule::exists('internal_technicians', 'id')->whereNull('deleted_at')],
        ]);

        $tool = $this->tools->updateAsignacion($tool, $validated['assigned_to_technician_id'] ?? null);

        return response()->json($this->tools->present($tool));
    }

    /**
     * POST /api/servicios/tools/purchase-requests — REQ-271 (alta nueva, body {nombre, cantidad})
     * / REQ-272 (reposición, body {tool_id, cantidad}). RN5: rol validado en la ruta.
     */
    public function storePurchaseRequest(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{nombre?: string, tool_id?: int, cantidad: int} $validated */
        $validated = $request->validate([
            'nombre'   => ['required_without:tool_id', 'nullable', 'string', 'max:255'],
            'tool_id'  => ['required_without:nombre', 'nullable', 'integer', Rule::exists('tools', 'id')],
            'cantidad' => ['required', 'integer', 'min:1'],
        ]);

        $purchaseRequest = $this->tools->createPurchaseRequest($validated, $user);

        return response()->json($this->tools->presentPurchaseRequest($purchaseRequest), 201);
    }

    /**
     * POST /api/servicios/tools/purchase-requests/{id}/recibir — REQ-271 RN3/RN4, REQ-272 RN3/RN4.
     * RN5: rol validado en la ruta.
     */
    public function receivePurchaseRequest(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $purchaseRequest = ToolPurchaseRequest::find($id);
        if ($purchaseRequest === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $result = $this->tools->receivePurchaseRequest($purchaseRequest, $user);
        } catch (ToolPurchaseRequestAlreadyReceivedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json([
            ...$this->tools->presentPurchaseRequest($result['purchase_request']),
            'tools' => array_map(fn (Tool $t): array => $this->tools->present($t), $result['tools']),
        ]);
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
