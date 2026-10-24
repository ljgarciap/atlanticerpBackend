<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\VentasDiseno\Services\QuoteListService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * REQ-133 — buscador de "Pedidos Aprobados" para asignar una línea de Nueva Orden. Endpoint
 * propio de Compras (no cuelga de /api/ventas-diseno/quotes) porque un usuario de Compras no
 * tiene `ventas_diseno.read` — ver QuoteListService::searchApprovedProjects().
 */
final class ApprovedProjectController extends Controller
{
    public function __construct(private readonly QuoteListService $service) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $search = trim((string) $request->query('search', ''));

        // SCRUM-818 — catalog_product_id opcional: picker de "Proyecto de venta" en Comparación de
        // Referencias. Sin este parámetro (Nueva Orden), searchApprovedProjects() se comporta
        // exactamente igual que antes — ver docblock del método.
        $catalogProductId = $request->query('catalog_product_id');
        $catalogProductId = is_numeric($catalogProductId) ? (int) $catalogProductId : null;

        return response()->json([
            'data' => $this->service->searchApprovedProjects($search, $catalogProductId, $user, $this->jwtPermissions($request)),
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

    /** @return list<string> */
    private function jwtPermissions(Request $request): array
    {
        /** @var array{permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');

        return is_array($payload['permissions'] ?? null)
            ? array_values(array_filter($payload['permissions'], 'is_string'))
            : [];
    }
}
