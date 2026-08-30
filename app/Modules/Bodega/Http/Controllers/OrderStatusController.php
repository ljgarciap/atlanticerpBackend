<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Support\ModuleCatalog;
use App\Modules\Auth\Services\UatVisibilityService;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Services\OrderDocumentService;
use App\Modules\Bodega\Services\OrderStatusService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * SCRUM-329 Oleada A / Batch A4 (REQ-337 a 343) — pantalla "Status de Pedidos": consulta de solo
 * lectura, compartida entre Bodega (ve todos los pedidos) y Ventas & Diseño (ve solo los propios).
 *
 * Sin middleware `permission:` de ruta a propósito (ver `routes/bodega.php`): el gate real es
 * `resolveAccess()`, mismo patrón que `App\Modules\Compras\Http\Controllers\InventoryController`
 * (ver su docblock y el comentario en `routes/compras.php`) — no se puede expresar "bodega.read O
 * ventas_diseno.read con restricción a lo propio" con un solo string del middleware `permission:`.
 */
final class OrderStatusController extends Controller
{
    public function __construct(
        private readonly OrderStatusService $status,
        private readonly OrderDocumentService $documents,
    ) {}

    /** GET /api/bodega/orders/status */
    public function index(Request $request): JsonResponse
    {
        $access = $this->resolveAccess($request);
        if ($access === null) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }
        [, $restrictToOwnerId] = $access;

        return response()->json($this->status->list($request, $restrictToOwnerId));
    }

    /** GET /api/bodega/orders/status/{id} */
    public function show(Request $request, int $id): JsonResponse
    {
        $access = $this->resolveAccess($request);
        if ($access === null) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }
        [, $restrictToOwnerId] = $access;

        $root = $this->status->resolveRoot($id);
        if ($root === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccessOrder($root, $restrictToOwnerId)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        return response()->json($this->status->detailPayload($root));
    }

    /**
     * GET /api/bodega/orders/status/{id}/document
     *
     * REQ-341 — genera (siempre una fila nueva, mismo criterio append-only que Guía de Entrega) y
     * devuelve de una sola vez la URL firmada del documento "Estatus de Pedido", para no obligar
     * al frontend a un segundo round-trip al endpoint de descarga genérico
     * (`GET /api/bodega/orders/{id}/documents/{documentId}/download`) — aunque ese endpoint
     * también sirve para descargar este documento después, por su `order_id` (la tarjeta raíz).
     */
    public function document(Request $request, int $id): JsonResponse
    {
        $access = $this->resolveAccess($request);
        if ($access === null) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }
        [$user, $restrictToOwnerId] = $access;

        $root = $this->status->resolveRoot($id);
        if ($root === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccessOrder($root, $restrictToOwnerId)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $payload  = $this->status->detailPayload($root);
        $document = $this->documents->generateEstatusPedido($root, $payload, $user);

        return response()->json([
            'document_id' => $document->id,
            'order_id'    => $root->id,
            'url'         => $this->documents->presignUrl($document->storage_key),
        ]);
    }

    /**
     * REQ-342 — Bodega (`bodega.read`, o `superadmin.all`) ve TODOS los pedidos, sin filtro.
     * Ventas & Diseño (`ventas_diseno.read`) ve SOLO los pedidos cuya `PipelineCard.owner_id` sea
     * el propio usuario — devuelve `[$user, null]` para Bodega (sin restricción) o
     * `[$user, $user->id]` para Ventas & Diseño (restringido). `null` de retorno = sin acceso.
     *
     * @return array{0: User, 1: int|null}|null
     */
    private function resolveAccess(Request $request): ?array
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return null;
        }

        /** @var list<string> $permissions */
        $permissions = is_array($request->attributes->get('jwt')['permissions'] ?? null)
            ? array_values(array_filter($request->attributes->get('jwt')['permissions'], 'is_string'))
            : [];

        $isSuperadmin = in_array('superadmin.all', $permissions, true);

        // SCRUM-741 (decisión de Luis, tras hallazgo de Pre-QA: David/Mark tienen bodega.read REAL
        // vía su rol Gerencia) — mismo criterio que InventoryController::resolveAccess(): mientras
        // Bodega esté oculto por la etapa de UAT actual, NADIE no-superadmin accede de verdad, ni
        // siquiera con bodega.read real.
        if (! $isSuperadmin && app(UatVisibilityService::class)->isModuleHidden(ModuleCatalog::BODEGA)) {
            return null;
        }

        if ($isSuperadmin || in_array('bodega.read', $permissions, true)) {
            return [$user, null];
        }

        if (in_array('ventas_diseno.read', $permissions, true)) {
            return [$user, $user->id];
        }

        return null;
    }

    private function canAccessOrder(Order $root, ?int $restrictToOwnerId): bool
    {
        if ($restrictToOwnerId === null) {
            return true;
        }

        $root->loadMissing('pipelineCard');

        return $root->pipelineCard?->owner_id === $restrictToOwnerId;
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
