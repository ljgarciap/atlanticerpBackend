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
    /**
     * SCRUM-897 — mismos roles que gatean Facturación/Cobros de Administración & Contabilidad
     * (`role:superadmin,lider_admin_contab,asistente_administrativa,management` en
     * `routes/admin-contab.php`). `superadmin` no hace falta acá porque ya cae en la rama
     * `$isSuperadmin` (`superadmin.all`) de `resolveAccess()` antes de llegar a este chequeo.
     *
     * @var list<string>
     * SCRUM-1139 — `lider_operaciones` espeja a la asistente (mismos grupos `role:` de `routes/admin-contab.php`).
     */
    private const ADMIN_CONTAB_ROLES = ['lider_admin_contab', 'asistente_administrativa', 'lider_operaciones', 'management'];

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

        // SCRUM-1171 — un pedido AJENO responde igual que uno inexistente (404): con 403 vs 404 un vendedor podía enumerar qué ids existen.
        if (! $this->canAccessOrder($root, $restrictToOwnerId)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->status->detailPayload($root));
    }

    /**
     * GET /api/bodega/orders/status/{id}/document
     *
     * REQ-341 — genera (o reutiliza, ver SCRUM-1171 más abajo) y
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
            return response()->json(['message' => 'Not found'], 404);
        }

        // SCRUM-1171 — antes cada clic en «Ver/Descargar» creaba un documento nuevo (fila + objeto en S3) aunque nada hubiera cambiado: ahora se reutiliza
        // el que ya tiene exactamente el mismo contenido (huella del payload) y se genera uno nuevo solo si el estatus cambió.
        $payload  = $this->status->detailPayload($root);
        $document = $this->documents->findOrGenerateEstatusPedido($root, $payload, $user);

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
     * SCRUM-897 — Administración & Contabilidad replica el botón "Status de Pedidos" en
     * Facturación (frontend, en paralelo) contra este MISMO endpoint, sin duplicar lógica acá. Ve
     * TODOS los pedidos igual que Bodega (Administración no tiene noción de "pedidos propios").
     * Mismos roles que ya gatean Facturación/Cobros — `role:superadmin,lider_admin_contab,
     * asistente_administrativa,management` (ver `routes/admin-contab.php`, rutas de `invoices`/
     * `payments`) — leídos del claim `role` del JWT, igual que `EnforceRole`. `superadmin` y
     * `management` (Gerencia) ya tenían acceso real de hecho antes de este cambio, vía
     * `superadmin.all`/`bodega.read` (ver SCRUM-741 más abajo) — se listan igual acá por
     * explicitud, sin depender de esa coincidencia.
     *
     * @return array{0: User, 1: int|null}|null
     */
    private function resolveAccess(Request $request): ?array
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return null;
        }

        /** @var array{permissions?: mixed, role?: mixed}|null $jwt */
        $jwt = $request->attributes->get('jwt');

        /** @var list<string> $permissions */
        $permissions = is_array($jwt['permissions'] ?? null)
            ? array_values(array_filter($jwt['permissions'], 'is_string'))
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

        /** @var string|null $role */
        $role = is_string($jwt['role'] ?? null) ? $jwt['role'] : null;
        // SCRUM-1150 — el rol de Admin&Cont va ANTES de la restricción por propietario de Ventas & Diseño: Félix
        // (`lider_admin_contab`) ahora también tiene `ventas_diseno.read` y no debe pasar a ver solo sus pedidos.
        if ($role !== null && in_array($role, self::ADMIN_CONTAB_ROLES, true)) {
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
