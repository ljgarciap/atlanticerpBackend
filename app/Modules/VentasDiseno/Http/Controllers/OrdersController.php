<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Services\OrdersService;
use App\Shared\Http\Pagination;
use App\Shared\Support\ParsesIdListQueryParam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-703→710 (REQ-623→630, Epic CRM Batch F) — "Pedidos": cotizaciones aprobadas con
 * comparación de margen cotizado vs. facturado. Ver OrdersService para el cálculo completo.
 */
final class OrdersController extends Controller
{
    use ParsesIdListQueryParam;

    public function __construct(private readonly OrdersService $ordersService) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $filters = [
            'scope'  => $request->query('scope'),
            'search' => $request->query('search'),
            'estado' => $request->query('estado'),
            // SCRUM-854 — multi-selección Vendedor/Diseñador (OR entre seleccionados).
            'owner_ids' => $this->parseIdListParam($request, 'owner_ids'),
        ];

        // SCRUM-754 — sin sort expuesto en esta pantalla hoy, solo per_page/page.
        $pagination = Pagination::params($request, [], 'approved_at');

        return response()->json($this->ordersService->list($user, array_filter($filters, fn ($v) => $v !== null), [
            'per_page' => $pagination['per_page'],
            'page'     => $pagination['page'],
        ], verEquipo: true));
    }

    // SCRUM-1119 — las rutas ya filtran por rol (Compras, Gerencia, superadmin): todo el que llega acá ve el
    // equipo completo, incluida Compras, que no tiene vista de equipo en Ventas y Diseño.
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $detail = $this->ordersService->detail($user, $id, verEquipo: true);
        if ($detail === null) {
            return response()->json(['message' => 'Pedido no encontrado.'], 404);
        }

        return response()->json($detail);
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
