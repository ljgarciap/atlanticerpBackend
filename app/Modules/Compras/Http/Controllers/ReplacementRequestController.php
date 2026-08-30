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
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;

/**
 * SCRUM-248/249 (REQ-185/186) — tabla de solicitudes de reemplazo + "Generar orden" del producto
 * original en solicitudes rechazadas. SCRUM-247 (REQ-184, Oleada 2) agrega store(): "Generar
 * solicitud" desde la tabla de resultados de ReplacementSearchController (REQ-183).
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

        /** @var array{original_catalog_product_id: int, proposed_catalog_product_id: int, sales_project_id: int} $validated */
        $validated = $request->validated();

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

        return response()->json($this->format($replacementRequest), 201);
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

        $query = ReplacementRequest::with(['originalProduct', 'proposedProduct', 'requester', 'salesProject']);

        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }

        $requests = $query->orderByDesc('created_at')->get();

        // SCRUM-754 — sin sort expuesto en esta pantalla hoy, solo per_page/page.
        $pagination = Pagination::params($request, [], 'created_at');
        $paginator  = Pagination::ofArray(array_values($requests->values()->all()), $pagination['per_page'], $pagination['page']);

        return response()->json([
            'data' => array_values(array_map(fn (ReplacementRequest $r) => $this->format($r), $paginator->items())),
            'meta' => Pagination::meta($paginator),
        ]);
    }

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

    /** @return array<string, mixed> */
    private function format(ReplacementRequest $r): array
    {
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
