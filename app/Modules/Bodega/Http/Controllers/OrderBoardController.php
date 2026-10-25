<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Services\UatVisibilityService;
use App\Modules\Auth\Support\ModuleCatalog;
use App\Modules\Auth\Support\RoleKeys;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Services\OrderBoardService;
use App\Modules\Bodega\Services\WarehouseStockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-329 Oleada A / Batch A2 — tablero Kanban de Pedidos (REQ-305/306/309/310/311/312/332).
 */
final class OrderBoardController extends Controller
{
    /**
     * SCRUM-930 — Pre-QA encontró `GuiaEntregaModal` (reusado desde Estado de Cuenta) colgado en
     * "Cargando..." para siempre para Admin&Contab: `show()` exigía `bodega.read` vía middleware de
     * ruta, que ese rol nunca tiene, y el modal no distingue 403 de "todavía cargando" (ver
     * `GuiaEntregaModal.tsx`). Mismos roles y mismo patrón ya establecido y revisado en
     * `OrderStatusController::resolveAccess()` (que resuelve el mismo problema para "Status de
     * Pedidos", el botón del punto 6 de este mismo ticket) — sin restricción de owner, porque a
     * diferencia de Ventas & Diseño acá no hay noción de "pedidos propios" para Admin&Contab.
     *
     * @var list<string>
     * SCRUM-1139 — `lider_operaciones` espeja a la asistente (mismos grupos `role:` de `routes/admin-contab.php`).
     */
    private const ADMIN_CONTAB_ROLES = [RoleKeys::LIDER_ADMIN_CONTAB, RoleKeys::ASISTENTE_ADMINISTRATIVA, RoleKeys::LIDER_OPERACIONES, RoleKeys::MANAGEMENT];

    public function __construct(
        private readonly OrderBoardService $board,
        private readonly WarehouseStockService $warehouseStock,
    ) {}

    /** GET /api/bodega/orders */
    public function index(Request $request): JsonResponse
    {
        return response()->json($this->board->board($request));
    }

    /** GET /api/bodega/orders/assistant-load */
    public function assistantLoad(): JsonResponse
    {
        return response()->json(['data' => $this->board->assistantLoad()]);
    }

    /**
     * GET /api/bodega/orders/sellers
     *
     * SCRUM-854, rebote de Pre-QA — vendedores/diseñadores para el filtro `seller_ids`, gateado
     * por `bodega.read` (el permiso real de esta pantalla), no `ventas_diseno.read` como el
     * endpoint viejo que reusaba el frontend.
     */
    public function sellers(): JsonResponse
    {
        return response()->json(['data' => $this->board->sellers()]);
    }

    /**
     * GET /api/bodega/orders/{id}
     *
     * SCRUM-930 — sin middleware `permission:` de ruta a propósito (ver `routes/bodega.php`, mismo
     * criterio que `OrderStatusController`): el gate real es `resolveAccess()` acá abajo.
     */
    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->resolveAccess($request)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $order = Order::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->board->detail($order));
    }

    private function resolveAccess(Request $request): bool
    {
        /** @var array{sub?: int, permissions?: mixed, role?: mixed}|null $jwt */
        $jwt = $request->attributes->get('jwt');
        if ($jwt === null || ! isset($jwt['sub'])) {
            return false;
        }

        if (User::find((int) $jwt['sub']) === null) {
            return false;
        }

        /** @var list<string> $permissions */
        $permissions = is_array($jwt['permissions'] ?? null)
            ? array_values(array_filter($jwt['permissions'], 'is_string'))
            : [];

        $isSuperadmin = in_array('superadmin.all', $permissions, true);

        // Mismo criterio que `OrderStatusController::resolveAccess()` — mientras Bodega esté
        // oculto por la etapa de UAT actual, nadie no-superadmin accede de verdad, ni siquiera con
        // bodega.read real ni con uno de los roles de Admin&Contab de abajo.
        if (! $isSuperadmin && app(UatVisibilityService::class)->isModuleHidden(ModuleCatalog::BODEGA)) {
            return false;
        }

        if ($isSuperadmin || in_array('bodega.read', $permissions, true)) {
            return true;
        }

        /** @var string|null $role */
        $role = is_string($jwt['role'] ?? null) ? $jwt['role'] : null;

        return $role !== null && in_array($role, self::ADMIN_CONTAB_ROLES, true);
    }

    /**
     * GET /api/bodega/orders/{id}/warehouse-breakdown
     *
     * SCRUM-403 (REQ-333) — "Ver bodegas" para TODOS los artículos de un pedido a la vez (mismo
     * desglose por bodega que `AdjustmentRequestController::productWarehouseStock()`, pero
     * agregado por pedido para que el frontend no haga N requests, una por línea, al abrir el
     * modal). Ver `WarehouseStockService`, fuente única de la query.
     */
    public function warehouseBreakdown(int $id): JsonResponse
    {
        $order = Order::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $order->loadMissing('items');
        /** @var list<int> $productIds */
        $productIds = $order->items->pluck('catalog_product_id')->filter()->unique()->values()->map(fn ($id) => (int) $id)->all();

        return response()->json([
            'order_id' => $order->id,
            'products' => $this->warehouseStock->breakdownForProducts($productIds),
        ]);
    }
}
