<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Bodega\Models\InventoryMovement;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Bodega\Models\RelocationRequest;
use App\Modules\Bodega\Models\Warehouse;
use App\Modules\Bodega\Services\KardexService;
use App\Shared\Http\Pagination;
use App\Shared\Support\AprobacionGate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-454/457/458/459 (REQ-384/387/388/389, Bloque B4 "Reubicación entre bodegas") — ver
 * ADR-SCRUM454-459-reubicacion-bodegas.md. Mismo patrón que `AdjustmentRequestController`
 * (Bodega solicita → Gerencia aprueba/rechaza → automático), simplificado a una sola tabla porque
 * cada solicitud es siempre 1 producto/1 origen/1 destino (nunca N bodegas a la vez).
 */
final class RelocationRequestController extends Controller
{
    public function __construct(private readonly KardexService $kardex) {}

    /** SCRUM-459 (REQ-389) — bandeja de solicitudes, con filtro por estado. */
    public function index(Request $request): JsonResponse
    {
        $query = RelocationRequest::query()
            ->with(['catalogProduct', 'originWarehouse', 'destinationWarehouse', 'solicitante', 'resolvedBy']);

        $estado = $request->query('estado');
        if (is_string($estado) && $estado !== '' && $estado !== 'todas') {
            $query->where('estado', $estado);
        }

        $pagination = Pagination::params($request, ['created_at'], 'created_at', 'desc');
        $paginator = $query
            ->orderBy('created_at', $pagination['sort_dir'])
            ->orderBy('id', $pagination['sort_dir'])
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (RelocationRequest $r) => $this->formatRequest($r))->values(),
            'meta' => Pagination::meta($paginator),
        ]);
    }

    /**
     * SCRUM-457 (REQ-387) — crea la solicitud. RN1: la bodega destino nunca puede ser la misma
     * que la de origen (validado server-side vía `different:origin_warehouse_id`, no solo
     * confiando en que el frontend ya excluye la bodega actual del selector). RN2: cantidad,
     * bodega destino y motivo son obligatorios.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'catalog_product_id' => ['required', 'integer', 'exists:catalog_products,id'],
            'origin_warehouse_id' => ['required', 'integer', Warehouse::existsRule()],
            'destination_warehouse_id' => ['required', 'integer', Warehouse::existsRule(), 'different:origin_warehouse_id'],
            'cantidad' => ['required', 'integer', 'min:1'],
            'motivo' => ['required', 'string'],
        ], [
            'destination_warehouse_id.different' => 'La bodega destino no puede ser la misma que la bodega origen.',
        ]);

        // Visual Review 2026-07-24 (CRÍTICO): el mockup nunca ofrece "Reubicar" en bodegas modo
        // "pendiente" (sin ubicación física confirmada aún, ej. Zona Libre) — el frontend ya
        // oculta el botón ahí, pero eso no alcanza: alguien podría llamar este endpoint directo.
        $originWarehouse = Warehouse::find((int) $validated['origin_warehouse_id']);
        if ($originWarehouse?->modo_detalle === Warehouse::MODO_PENDIENTE) {
            return response()->json([
                'message' => 'No se puede reubicar producto desde una bodega sin ubicación física confirmada.',
            ], 422);
        }

        // Mejora SCRUM-752 (RN1, Gerencia Test 2026-08-13) — mismo criterio que arriba, ahora
        // también como DESTINO: Zona Libre es "pendiente" (sin ubicación física), no debe recibir
        // reubicaciones. El frontend ya la excluye del selector, pero eso no alcanza acá tampoco.
        $destinationWarehouse = Warehouse::find((int) $validated['destination_warehouse_id']);
        if ($destinationWarehouse?->modo_detalle === Warehouse::MODO_PENDIENTE) {
            return response()->json([
                'message' => 'No se puede reubicar producto hacia una bodega sin ubicación física confirmada.',
            ], 422);
        }

        // Mejora SCRUM-752 (RN4/RN5) — tope real de unidades disponibles en la bodega de ORIGEN
        // (no el stock total de la empresa), mismo dato que ya expone la columna nueva "Unidades
        // en [bodega]" (`WarehouseController::formatRow()`).
        $availableAtOrigin = (int) (ProductWarehouseStock::where('catalog_product_id', $validated['catalog_product_id'])
            ->where('warehouse_id', $validated['origin_warehouse_id'])
            ->value('quantity') ?? 0);
        if ((int) $validated['cantidad'] > $availableAtOrigin) {
            return response()->json([
                'message' => "Estás intentando mover más unidades de las que hay en esta bodega. La cantidad máxima es de {$availableAtOrigin} unidades.",
            ], 422);
        }

        $relocationRequest = RelocationRequest::create([
            'catalog_product_id' => $validated['catalog_product_id'],
            'origin_warehouse_id' => $validated['origin_warehouse_id'],
            'destination_warehouse_id' => $validated['destination_warehouse_id'],
            'cantidad' => $validated['cantidad'],
            'motivo' => $validated['motivo'],
            'solicitado_por' => $user->id,
            'estado' => RelocationRequest::ESTADO_PENDIENTE,
        ]);

        return response()->json(['id' => $relocationRequest->id], 201);
    }

    /**
     * SCRUM-458 (REQ-388) — Gerencia aprueba: ajusta el inventario automáticamente, dos movimientos
     * de Kardex (`TIPO_REUBICACION`) atómicos con la resolución de la solicitud. Mismo fix de
     * concurrencia que `AdjustmentRequestController::approve()` (Pre-QA 2026-07-21, CRÍTICO):
     * el lock (`lockForUpdate()`) y el chequeo de `isPending()` viven DENTRO de la transacción,
     * para que un segundo approve concurrente vea `estado` ya escrito por el primero y devuelva
     * 422 en vez de aplicar el movimiento otra vez.
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        $access = $this->resolveMarkAccess($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$user] = $access;

        $result = DB::transaction(function () use ($id, $user): array {
            /** @var RelocationRequest|null $relocationRequest */
            $relocationRequest = RelocationRequest::lockForUpdate()->find($id);
            if ($relocationRequest === null) {
                return ['status' => 404];
            }
            if (! $relocationRequest->isPending()) {
                return ['status' => 422];
            }

            $this->kardex->registrar(
                catalogProductId: $relocationRequest->catalog_product_id,
                warehouseId: $relocationRequest->origin_warehouse_id,
                tipo: InventoryMovement::TIPO_REUBICACION,
                cantidad: -$relocationRequest->cantidad,
                referenciaTexto: "Reubicación aprobada — {$relocationRequest->motivo}",
                referenciaEntidadTipo: 'REUBICACION',
                referenciaEntidadId: $relocationRequest->id,
                realizadoPor: $user->id,
            );
            $this->kardex->registrar(
                catalogProductId: $relocationRequest->catalog_product_id,
                warehouseId: $relocationRequest->destination_warehouse_id,
                tipo: InventoryMovement::TIPO_REUBICACION,
                cantidad: $relocationRequest->cantidad,
                referenciaTexto: "Reubicación aprobada — {$relocationRequest->motivo}",
                referenciaEntidadTipo: 'REUBICACION',
                referenciaEntidadId: $relocationRequest->id,
                realizadoPor: $user->id,
            );

            $relocationRequest->update([
                'estado' => RelocationRequest::ESTADO_APROBADA,
                'resuelto_por' => $user->id,
                'resuelto_at' => now(),
            ]);

            return ['status' => 200, 'request' => $relocationRequest];
        });

        if ($result['status'] === 404) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result['status'] === 422) {
            return response()->json(['message' => 'Esta solicitud ya fue resuelta.'], 422);
        }

        /** @var RelocationRequest $relocationRequest */
        $relocationRequest = $result['request'];
        /** @var RelocationRequest $fresh */
        $fresh = $relocationRequest->fresh(['catalogProduct', 'originWarehouse', 'destinationWarehouse', 'solicitante', 'resolvedBy']);

        return response()->json($this->formatRequest($fresh));
    }

    /**
     * SCRUM-458/459 (REQ-388, REQ-389 RN1) — Gerencia rechaza, motivo obligatorio, no toca
     * inventario. Mismo lock + chequeo de estado dentro de la transacción que `approve()`.
     */
    public function reject(Request $request, int $id): JsonResponse
    {
        $access = $this->resolveMarkAccess($request);
        if ($access instanceof JsonResponse) {
            return $access;
        }
        [$user] = $access;

        $validated = $request->validate(['motivo_rechazo' => ['required', 'string', 'min:1']]);

        $result = DB::transaction(function () use ($id, $user, $validated): array {
            /** @var RelocationRequest|null $relocationRequest */
            $relocationRequest = RelocationRequest::lockForUpdate()->find($id);
            if ($relocationRequest === null) {
                return ['status' => 404];
            }
            if (! $relocationRequest->isPending()) {
                return ['status' => 422];
            }

            $relocationRequest->update([
                'estado' => RelocationRequest::ESTADO_RECHAZADA,
                'motivo_rechazo' => $validated['motivo_rechazo'],
                'resuelto_por' => $user->id,
                'resuelto_at' => now(),
            ]);

            return ['status' => 200, 'request' => $relocationRequest];
        });

        if ($result['status'] === 404) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($result['status'] === 422) {
            return response()->json(['message' => 'Esta solicitud ya fue resuelta.'], 422);
        }

        /** @var RelocationRequest $relocationRequest */
        $relocationRequest = $result['request'];
        /** @var RelocationRequest $fresh */
        $fresh = $relocationRequest->fresh(['catalogProduct', 'originWarehouse', 'destinationWarehouse', 'solicitante', 'resolvedBy']);

        return response()->json($this->formatRequest($fresh));
    }

    /**
     * Gate "exclusivo de Gerencia" — mismo mecanismo que
     * `AdjustmentRequestController::resolveMarkAccess()` (`ComprasSettings::primary_approver_user_id`,
     * no un permiso de rol nuevo).
     *
     * SCRUM-161/162 (2026-08-26): delega en `AprobacionGate`, que además de Gerencia acepta cualquier
     * usuario con una `ReglaAprobacion` activa para tipo=relocation (SCRUM-163) — ver docblock de
     * `AprobacionGate` para el porqué.
     *
     * @return array{0: User}|JsonResponse
     */
    private function resolveMarkAccess(Request $request): array|JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! app(AprobacionGate::class)->canAct($user, 'relocation')) {
            return response()->json(['message' => 'No tienes permiso para aprobar o rechazar esta solicitud.'], 403);
        }

        return [$user];
    }

    /** @return array<string, mixed> */
    private function formatRequest(RelocationRequest $relocationRequest): array
    {
        return [
            'id' => $relocationRequest->id,
            'producto' => [
                'id' => $relocationRequest->catalogProduct?->id,
                'reference' => $relocationRequest->catalogProduct?->reference,
                'description' => $relocationRequest->catalogProduct?->name,
            ],
            'bodega_origen' => $relocationRequest->originWarehouse?->name,
            'bodega_destino' => $relocationRequest->destinationWarehouse?->name,
            'cantidad' => $relocationRequest->cantidad,
            'motivo' => $relocationRequest->motivo,
            'solicitado_por' => $relocationRequest->solicitante !== null
                ? trim("{$relocationRequest->solicitante->first_name} {$relocationRequest->solicitante->last_name}") : null,
            'fecha' => $relocationRequest->created_at->toIso8601String(),
            'estado' => $relocationRequest->estado,
            'motivo_rechazo' => $relocationRequest->motivo_rechazo,
            'resuelto_por' => $relocationRequest->resolvedBy !== null
                ? trim("{$relocationRequest->resolvedBy->first_name} {$relocationRequest->resolvedBy->last_name}") : null,
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
