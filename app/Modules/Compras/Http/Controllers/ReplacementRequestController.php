<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Compras\Http\Requests\StoreReplacementRequestRequest;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Models\ReplacementRequest;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-248/249 (REQ-185/186) — tabla de solicitudes de reemplazo + "Generar orden" del producto
 * original en solicitudes rechazadas. SCRUM-247 (REQ-184, Oleada 2) agrega store(): "Generar
 * solicitud" desde la tabla de resultados de ReplacementSearchController (REQ-183).
 *
 * SCRUM-818 — approve()/reject() (nunca implementados pese a que el modelo ya tenía
 * `approved_by` sin usar). Matriz de aprobación por margen (RN dura del ticket, ver
 * `ComprasSettings::replacement_margin_threshold`):
 *   margen < umbral  → status pendiente_gerencia → SOLO Gerencia (permission:gerencia.view)
 *   margen ≥ umbral  → status pendiente          → Gerencia O el vendedor/diseñador responsable
 *                                                    del proyecto (owner_id/compartido_con de
 *                                                    PipelineCard, nunca el creador de la
 *                                                    solicitud si no es el responsable)
 * `index()`/`store()` scopean por proyecto propio para quien no tiene acceso completo a Compras
 * (mismo patrón que `InventoryController::resolveAccess()`) — un vendedor/diseñador nunca ve ni
 * gestiona solicitudes de proyectos ajenos.
 */
final class ReplacementRequestController extends Controller
{
    /**
     * REQ-184 — Regla de Negocio literal del Excel: "Si el margen del sustituto es MENOR a 15%,
     * la solicitud escala a Pendiente Gerencia (notifica a Mark)". El 15% es
     * `compras_settings.replacement_margin_threshold` (nunca hardcodeado, ver ADR-SCRUM245 gap #3
     * de Cybersecurity), mismo criterio de margen que `QuoteItem::violatesMinMargin()`
     * ((precio-costo)/precio × 100). Nunca crea la orden ni la aprueba sola — siempre nace
     * "Pendiente" o "Pendiente Gerencia", un humano decide después (ver generateOrder()).
     */
    public function store(StoreReplacementRequestRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        $jwtPermissions = $this->jwtPermissions($request);

        /** @var array{original_catalog_product_id: int, proposed_catalog_product_id: int, sales_project_id: int} $validated */
        $validated = $request->validated();

        // SCRUM-818 — quien no tiene acceso completo a Compras solo puede generar solicitudes
        // para proyectos de los que es responsable (owner_id/compartido_con), nunca a nombre de
        // un proyecto ajeno aunque conozca el id.
        if (! $this->hasFullComprasWriteAccess($jwtPermissions) && ! $this->isProjectResponsible($user, (int) $validated['sales_project_id'])) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $proposedProduct = CatalogProduct::find($validated['proposed_catalog_product_id']);
        if ($proposedProduct === null) {
            return response()->json(['message' => 'Producto sustituto no encontrado.'], 404);
        }
        if (CatalogProduct::find($validated['original_catalog_product_id']) === null) {
            return response()->json(['message' => 'Producto original no encontrado.'], 404);
        }

        $marginPercent = $this->computeMarginPercent($proposedProduct);
        $threshold = ComprasSettings::current()->replacement_margin_threshold;
        // Sin costo/precio de referencia no se puede verificar que el margen cumpla el umbral —
        // el default seguro es escalar a Gerencia, nunca asumir que el margen es aceptable.
        $status = ($marginPercent !== null && $marginPercent >= $threshold)
            ? ReplacementRequest::STATUS_PENDIENTE
            : ReplacementRequest::STATUS_PENDIENTE_GERENCIA;

        $replacementRequest = ReplacementRequest::create([
            'original_catalog_product_id' => $validated['original_catalog_product_id'],
            'proposed_catalog_product_id' => $validated['proposed_catalog_product_id'],
            'sales_project_id'            => $validated['sales_project_id'],
            'margin_percent'              => $marginPercent,
            'status'                      => $status,
            'requested_by'                => $user->id,
        ]);

        $replacementRequest->load(['originalProduct', 'proposedProduct', 'requester', 'salesProject']);

        return response()->json($this->format($replacementRequest, $user, $jwtPermissions), 201);
    }

    /**
     * SCRUM-818 (REQ-818 rebote) — matriz de aprobación por margen, ver docblock de la clase.
     * Rechazo usa la MISMA autorización que aprobación (ambas columnas de la matriz del ticket
     * dicen "Aprobar/Rechazar" juntas, ningún actor puede una acción sin la otra).
     */
    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, ReplacementRequest::STATUS_APROBADA);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        return $this->decide($request, $id, ReplacementRequest::STATUS_RECHAZADA);
    }

    private function decide(Request $request, int $id, string $newStatus): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        $jwtPermissions = $this->jwtPermissions($request);

        $replacementRequest = ReplacementRequest::find($id);
        if ($replacementRequest === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! in_array($replacementRequest->status, [ReplacementRequest::STATUS_PENDIENTE, ReplacementRequest::STATUS_PENDIENTE_GERENCIA], true)) {
            return response()->json(['message' => 'Esta solicitud ya fue decidida.'], 422);
        }

        if (! $this->canDecide($user, $replacementRequest, $jwtPermissions)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $replacementRequest->update($newStatus === ReplacementRequest::STATUS_APROBADA
            ? ['status' => $newStatus, 'approved_by' => $user->id]
            : ['status' => $newStatus, 'rejected_by' => $user->id]);

        $replacementRequest->load(['originalProduct', 'proposedProduct', 'requester', 'salesProject']);

        return response()->json($this->format($replacementRequest, $user, $jwtPermissions));
    }

    /**
     * RN dura del ticket (matriz de aprobación):
     *   pendiente_gerencia (margen < umbral) → SOLO Gerencia.
     *   pendiente (margen ≥ umbral)          → Gerencia O el vendedor/diseñador responsable del
     *                                            proyecto — nunca el creador de la solicitud si
     *                                            no es también el responsable.
     *
     * SCRUM-818 (rebote 2026-09-03) — `gerencia.view` se evalúa contra el JWT (`$jwtPermissions`,
     * poblado por `effectivePermissionsV2()`/`RoleModuleVisibility`), NUNCA contra
     * `$user->hasPermission()` (v1, `security_levels.permissions ∪ extra_permissions`). David/
     * Mark tienen `management` con acceso real a Gerencia vía v2 (rol → módulo), pero jamás
     * tuvieron `gerencia.view` cargado en v1 — por eso nunca podían decidir nada pese a ver la
     * pantalla. Mismo patrón ya usado en `InventoryController::resolveAccess()`/
     * `CatalogProductSearchController` para este mismo problema en otras pantallas de Compras.
     *
     * @param list<string> $jwtPermissions
     */
    private function canDecide(User $user, ReplacementRequest $r, array $jwtPermissions): bool
    {
        if (in_array('superadmin.all', $jwtPermissions, true) || in_array('gerencia.view', $jwtPermissions, true)) {
            return true;
        }

        if ($r->status === ReplacementRequest::STATUS_PENDIENTE_GERENCIA) {
            return false;
        }

        return $r->sales_project_id !== null && $this->isProjectResponsible($user, $r->sales_project_id);
    }

    /** @return list<int> */
    private function ownedSalesProjectIds(User $user): array
    {
        // SCRUM-818 — delega a PipelineCard::projectIdsOwnedBy(), reusado también por
        // QuoteListService::searchApprovedProjects() (picker filtrado por producto).
        return PipelineCard::projectIdsOwnedBy($user);
    }

    private function isProjectResponsible(User $user, int $salesProjectId): bool
    {
        return in_array($salesProjectId, $this->ownedSalesProjectIds($user), true);
    }

    /**
     * Lectura: acceso completo al LISTADO (no implica poder decidir, ver canDecide()).
     *
     * SCRUM-818 (rebote 2026-09-03) — antes usaba `$user->hasPermission()` (v1), que Gerencia y
     * Compras nunca tuvieron cargado para `compras.read`/`compras.sustitutos.manage` pese a tener
     * acceso real al módulo vía su rol (v2/`RoleModuleVisibility`) — ver docblock de canDecide().
     *
     * @param list<string> $jwtPermissions
     */
    private function hasFullComprasReadAccess(array $jwtPermissions): bool
    {
        return in_array('superadmin.all', $jwtPermissions, true)
            || in_array('compras.read', $jwtPermissions, true)
            || in_array('compras.sustitutos.manage', $jwtPermissions, true);
    }

    /**
     * Escritura: acceso completo para CREAR una solicitud a nombre de cualquier proyecto.
     * A propósito NO incluye `compras.read` (solo lectura) — mismo criterio que separaba
     * `permission:compras.read` de `permission:compras.write` en las rutas antes de este ticket;
     * ver `test_generar_solicitud_sin_permiso_write_retorna_403`.
     *
     * @param list<string> $jwtPermissions
     */
    private function hasFullComprasWriteAccess(array $jwtPermissions): bool
    {
        return in_array('superadmin.all', $jwtPermissions, true)
            || in_array('compras.write', $jwtPermissions, true)
            || in_array('compras.sustitutos.manage', $jwtPermissions, true);
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

    private function computeMarginPercent(CatalogProduct $product): ?float
    {
        if ($product->cost === null) {
            return null;
        }

        // SCRUM-237 — margen sobre Costo Total (cost + desglose), no solo `cost`, para no
        // subestimar el costo real de un producto no local y escalar a Gerencia de menos.
        return $product->marginPercent();
    }

    /** REQ-185 — listado con filtro rápido por estado. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }
        $jwtPermissions = $this->jwtPermissions($request);

        $query = ReplacementRequest::with(['originalProduct', 'proposedProduct', 'requester', 'salesProject']);

        // SCRUM-818 — sin acceso completo a Compras (vendedor/diseñador), solo ve solicitudes de
        // proyectos de los que es responsable (owner_id/compartido_con de PipelineCard) — nunca
        // el listado completo. Un vendedor sin ningún proyecto propio ve la lista vacía, no 403:
        // sigue teniendo acceso legítimo a la pantalla, simplemente no hay nada que le pertenezca.
        if (! $this->hasFullComprasReadAccess($jwtPermissions)) {
            $ownProjectIds = $this->ownedSalesProjectIds($user);
            $query->whereIn('sales_project_id', $ownProjectIds);
        }

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $requests = $query->orderByDesc('created_at')->get();

        // SCRUM-754 — sin sort expuesto en esta pantalla hoy, solo per_page/page.
        $pagination = Pagination::params($request, [], 'created_at');
        $paginator  = Pagination::ofArray(array_values($requests->values()->all()), $pagination['per_page'], $pagination['page']);

        return response()->json([
            'data' => array_values(array_map(fn (ReplacementRequest $r) => $this->format($r, $user, $jwtPermissions), $paginator->items())),
            'meta' => Pagination::meta($paginator),
        ]);
    }

    /**
     * SCRUM-818 (CA13/CA14) — Generar Orden de Compra es EXCLUSIVO de Líder de Compras, por
     * role_key (nunca por permission: Gerencia y Líder de Operaciones también tienen
     * compras.write/compras.sustitutos.manage respectivamente, pero la matriz del ticket los
     * excluye a los dos de esta acción puntual). Mismo patrón que
     * `BodegaZonaLibreRequestController::COMPRAS_APPROVER_ROLE_KEY`.
     */
    private const GENERATE_ORDER_ROLE_KEY = 'lider_compras';

    /**
     * REQ-186 — solo en solicitudes "Rechazadas", genera la orden del producto ORIGINAL
     * (nunca el sustituto que se había propuesto), precargada y "Por aprobar".
     */
    public function generateOrder(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (! $user->hasPermission('superadmin.all') && $user->role?->key !== self::GENERATE_ORDER_ROLE_KEY) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        $replacementRequest = ReplacementRequest::with('originalProduct')->find($id);
        if ($replacementRequest === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($replacementRequest->status !== ReplacementRequest::STATUS_RECHAZADA) {
            return response()->json(['message' => 'Solo se puede generar la orden de una solicitud rechazada.'], 422);
        }
        if ($replacementRequest->generated_order_id !== null) {
            return response()->json(['message' => 'Esta solicitud ya generó una orden.'], 422);
        }

        $product = $replacementRequest->originalProduct;
        if ($product === null) {
            return response()->json(['message' => 'El producto original ya no existe en el catálogo.'], 422);
        }
        if ($product->provider_id === null) {
            return response()->json(['message' => 'El producto original no tiene un proveedor asignado en el catálogo.'], 422);
        }

        $order = DB::transaction(function () use ($product, $replacementRequest, $user): PurchaseOrder {
            $order = PurchaseOrder::create([
                'provider_id'        => $product->provider_id,
                'status'             => PurchaseOrder::STATUS_POR_APROBAR,
                'status_changed_at'  => now(),
                'currency'           => 'USD',
                'total_amount'       => $product->cost,
                'created_by'         => $user->id,
            ]);

            PurchaseOrderLine::create([
                'purchase_order_id'  => $order->id,
                'catalog_product_id' => $product->id,
                'quantity'           => 1,
                'unit_cost'          => $product->cost,
                'subtotal'           => $product->cost,
            ]);

            $replacementRequest->update(['generated_order_id' => $order->id]);

            return $order;
        });

        return response()->json(['order_id' => $order->id]);
    }

    /**
     * SCRUM-818 — `can_decide` se calcula server-side con la MISMA regla de `canDecide()` (nunca
     * reimplementada en el frontend) para que el botón Aprobar/Rechazar aparezca exactamente
     * cuando el backend de verdad lo va a aceptar.
     *
     * @param list<string> $jwtPermissions
     * @return array<string, mixed>
     */
    private function format(ReplacementRequest $r, ?User $currentUser, array $jwtPermissions): array
    {
        $isPending = in_array($r->status, [ReplacementRequest::STATUS_PENDIENTE, ReplacementRequest::STATUS_PENDIENTE_GERENCIA], true);

        return [
            'id'                     => $r->id,
            'original_product_id'    => $r->original_catalog_product_id,
            'original_product_name'  => $r->originalProduct?->name,
            'proposed_product_id'    => $r->proposed_catalog_product_id,
            'proposed_product_name'  => $r->proposedProduct?->name,
            'sales_project_id'       => $r->sales_project_id,
            'sales_project_name'     => $r->salesProject?->name,
            'margin_percent'         => $r->margin_percent,
            'status'                 => $r->status,
            'requested_by_name'      => $this->requesterName($r),
            'generated_order_id'     => $r->generated_order_id,
            'created_at'             => $r->created_at->toIso8601String(),
            'can_decide'             => $isPending && $currentUser !== null && $this->canDecide($currentUser, $r, $jwtPermissions),
            // SCRUM-818 (rebote) — el frontend nunca gateaba el botón "Generar orden" por rol
            // (ver ReplacementRequestsPage.tsx), así que Vendedor/Operaciones lo veían habilitado
            // y solo el backend los frenaba tras el click ("No tienes permiso"). Ahora el backend
            // informa explícitamente si ESTE usuario puede generar la orden, mismo criterio que
            // `can_decide` — el frontend deja de inventar su propia regla.
            'can_generate_order'     => $r->status === ReplacementRequest::STATUS_RECHAZADA
                && $r->generated_order_id === null
                && $currentUser !== null
                && (in_array('superadmin.all', $jwtPermissions, true) || $currentUser->role?->key === self::GENERATE_ORDER_ROLE_KEY),
        ];
    }

    private function requesterName(ReplacementRequest $r): ?string
    {
        /** @var User|null $requester */
        $requester = $r->requester;

        return $requester !== null ? trim("{$requester->first_name} {$requester->last_name}") : null;
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
