<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Bodega\Models\ProductWarehouseStock;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderReceipt;
use App\Modules\Compras\Models\PurchaseOrderReceiptLine;
use App\Modules\Compras\Services\GoodsReceiptConfirmationService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use App\Modules\Bodega\Models\Warehouse;

/**
 * SCRUM-220→230 (REQ-157/159/161→167) — Ingreso de Mercancía. Ver
 * docs adjuntos a la migración de `purchase_order_receipts` para el porqué del modelo de datos
 * (sin versionado — REQ-167 corrige el mismo registro in-place; costeo local/no-local en
 * REQ-164; `pending_remainder_status` en `purchase_orders`, no acá, para REQ-165).
 *
 * SIMPLIFICACIÓN DOCUMENTADA (RN de REQ-166, "producto que nunca había existido en Inventario se
 * da de alta automáticamente"): dado que REQ-163 acota el buscador de productos de un ingreso
 * ÚNICAMENTE a los productos ya esperados de la orden vinculada (que por construcción ya existen
 * en `catalog_products`, porque así se creó la orden en Nueva Orden), este caso no debería
 * ocurrir en la práctica bajo el flujo normal — no se implementó alta automática especulativa
 * para un escenario que el resto del sistema ya previene.
 */
final class GoodsReceiptController extends Controller
{
    private const ITBMS_RATE_LOCAL = 0.07;

    public function __construct(
        private readonly GoodsReceiptConfirmationService $confirmation,
    ) {}

    /**
     * REQ-162 RN — buscador de órdenes, solo las que no llegaron a "Recibido" con TODO lo
     * esperado ya confirmado.
     *
     * SCRUM-347 (rebote QA 2026-08-14) — antes del fix del gate de `advance()`, una orden podía
     * llegar a `STATUS_RECIBIDO` sin una sola línea confirmada (bug real, ver
     * `PurchaseOrderController::advance()`); con el filtro viejo (`status != RECIBIDO`) esas
     * órdenes ya rotas quedaban invisibles acá para siempre — "una vez recibida, no hay forma de
     * confirmar tardíamente" (hallazgo explícito de QA). Se incluyen también las `RECIBIDO` que
     * `GoodsReceiptConfirmationService::isFullyReceived()` todavía no da por completas, para poder
     * repararlas con un Ingreso de Mercancía normal. El filtro por completitud no es expresable en
     * SQL directo (agrega contra `purchase_order_receipt_lines` de N órdenes) — se trae un lote
     * más amplio y se filtra en PHP antes de recortar a 20.
     */
    public function eligibleOrders(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $search = trim((string) $request->query('search', ''));

        $query = PurchaseOrder::query()->with(['provider', 'lines']);

        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                $q->where('id', 'like', "%{$search}%")
                    ->orWhereHas('provider', fn ($p) => $p->where('name', 'ilike', "%{$search}%"));
            });
        }

        $orders = $query->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->filter(fn (PurchaseOrder $o) => $o->status !== PurchaseOrder::STATUS_RECIBIDO
                || ! $this->confirmation->isFullyReceived($o))
            ->take(20);

        return response()->json([
            'data' => $orders->map(fn (PurchaseOrder $o) => [
                'id'            => $o->id,
                'provider_name' => $o->provider?->name,
                'status'        => $o->status,
            ])->values(),
        ]);
    }

    /**
     * REQ-162 RN — al vincular una orden, precarga TODOS sus productos esperados con la cantidad
     * COMPLETA pedida (no la que falta) — el usuario ajusta línea por línea lo que realmente
     * llegó. `remaining` se expone aparte, informativo, para que el frontend pueda sugerir un
     * valor de partida más útil si ya hubo ingresos previos de esta orden.
     */
    public function orderExpectedProducts(Request $request, int $orderId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var PurchaseOrder|null $order */
        $order = PurchaseOrder::with(['provider', 'lines.catalogProduct'])->find($orderId);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $alreadyReceived = $this->receivedByProduct($order);

        $products = $order->lines
            ->filter(fn ($line) => $line->catalog_product_id !== null)
            ->groupBy('catalog_product_id')
            ->map(function ($lines, $productId) use ($alreadyReceived) {
                /** @var \App\Modules\Compras\Models\PurchaseOrderLine $firstLine */
                $firstLine = $lines->first();
                $product = $firstLine->catalogProduct;
                $expected = (int) $lines->sum('quantity');

                return [
                    'catalog_product_id' => (int) $productId,
                    'reference'          => $product?->reference,
                    'description'        => $product?->name,
                    'unit_cost'          => (float) $firstLine->unit_cost,
                    'expected_quantity'  => $expected,
                    'remaining'          => max(0, $expected - ($alreadyReceived[$productId] ?? 0)),
                ];
            })
            ->values();

        return response()->json([
            'id'              => $order->id,
            'provider_id'     => $order->provider_id,
            'provider_name'   => $order->provider?->name,
            'provider_origin' => $order->provider?->origin,
            'status'          => $order->status,
            'products'        => $products,
        ]);
    }

    /** REQ-159 RN — lista de ingresos registrados, buscable. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $search = trim((string) $request->query('search', ''));

        $query = PurchaseOrderReceipt::query()->with(['purchaseOrder.provider', 'lines']);
        if ($search !== '') {
            // REQ-159 RN — "buscable por proveedor, orden o producto".
            $query->where(function ($q) use ($search): void {
                $q->whereHas('purchaseOrder', fn ($o) => $o->where('id', 'like', "%{$search}%")
                    ->orWhereHas('provider', fn ($p) => $p->where('name', 'ilike', "%{$search}%")))
                    ->orWhereHas('lines.catalogProduct', fn ($p) => $p->where('reference', 'ilike', "%{$search}%")
                        ->orWhere('name', 'ilike', "%{$search}%"));
            });
        }

        $receipts = $query->orderByDesc('created_at')->get();

        // SCRUM-754 — sin sort expuesto en esta pantalla hoy, solo per_page/page.
        $pagination = Pagination::params($request, [], 'created_at');
        $paginator  = Pagination::ofArray(array_values($receipts->values()->all()), $pagination['per_page'], $pagination['page']);

        return response()->json([
            'data' => array_values(array_map(fn (PurchaseOrderReceipt $r) => $this->formatSummary($r), $paginator->items())),
            'meta' => Pagination::meta($paginator),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var PurchaseOrderReceipt|null $receipt */
        $receipt = PurchaseOrderReceipt::with(['purchaseOrder.provider', 'lines.catalogProduct', 'lines.warehouse'])->find($id);
        if ($receipt === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->formatDetail($receipt));
    }

    /** REQ-163/164/165/166 — crear un ingreso nuevo. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $this->validateReceiptPayload($request);

        /** @var PurchaseOrder $order */
        $order = PurchaseOrder::with('provider')->findOrFail($validated['purchase_order_id']);

        return $this->saveReceipt($order, null, $validated, $user);
    }

    /**
     * REQ-167 — corrige un ingreso ya guardado (mismo registro, sin versionado). Revierte los
     * efectos de stock del ingreso ORIGINAL antes de aplicar la corrección, para no duplicar.
     */
    public function update(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var PurchaseOrderReceipt|null $receipt */
        $receipt = PurchaseOrderReceipt::with(['purchaseOrder.provider', 'lines'])->find($id);
        $order = $receipt?->purchaseOrder;
        if ($receipt === null || $order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($order->status === PurchaseOrder::STATUS_RECIBIDO) {
            return response()->json(['message' => 'No se puede corregir un ingreso de una orden ya Recibida.'], 422);
        }

        $validated = $this->validateReceiptPayload($request, requireOrder: false);

        return $this->saveReceipt($order, $receipt, $validated, $user);
    }

    /** @return array<string, mixed> */
    private function validateReceiptPayload(Request $request, bool $requireOrder = true): array
    {
        return $request->validate([
            'purchase_order_id'      => [$requireOrder ? 'required' : 'sometimes', 'integer', 'exists:purchase_orders,id'],
            'lines'                  => ['required', 'array', 'min:1'],
            'lines.*.catalog_product_id' => ['required', 'integer'],
            'lines.*.quantity'       => ['required', 'integer', 'min:1'],
            'lines.*.unit_cost'      => ['required', 'numeric', 'min:0'],
            'lines.*.warehouse_id'   => ['required', 'integer', Warehouse::operativaRule()],
            'import_cost_total'      => ['nullable', 'numeric', 'min:0'],
            'freight_total'          => ['nullable', 'numeric', 'min:0'],
            'handling_total'         => ['nullable', 'numeric', 'min:0'],
            'other_costs_total'      => ['nullable', 'numeric', 'min:0'],
            'invoice_storage_key'          => ['nullable', 'string'],
            'invoice_original_filename'    => ['nullable', 'string'],
            'pending_remainder_status'     => ['nullable', 'string', Rule::in(PurchaseOrder::REMAINDER_STATUSES)],
        ]);
    }

    /** @param array<string, mixed> $validated */
    private function saveReceipt(PurchaseOrder $order, ?PurchaseOrderReceipt $existing, array $validated, User $user): JsonResponse
    {
        $lines = $validated['lines'];
        $excludeReceiptId = $existing?->id;

        // RN de REQ-166 (validación confirmada con el cliente) — ninguna línea, sumada a lo ya
        // recibido antes para esa misma orden y producto, puede superar lo TOTAL esperado.
        $alreadyReceived = $this->receivedByProduct($order, $excludeReceiptId);
        $expectedByProduct = $order->lines->groupBy('catalog_product_id')
            ->map(fn ($l) => (int) $l->sum('quantity'));

        $partial = [];
        foreach ($lines as $line) {
            $productId = (int) $line['catalog_product_id'];
            $expected = (int) ($expectedByProduct[$productId] ?? 0);
            $receivedBefore = (int) ($alreadyReceived[$productId] ?? 0);
            $totalAfter = $receivedBefore + (int) $line['quantity'];

            if ($totalAfter > $expected) {
                $max = max(0, $expected - $receivedBefore);

                // Sin "adicionales": en una corrección (excludeReceiptId) el máximo ES el total
                // que este ingreso puede registrar — la palabra hizo creer a QA (SCRUM-230,
                // 2026-07-20) que la corrección se estaba SUMANDO a lo ya registrado.
                return response()->json([
                    'message' => "La cantidad supera lo esperado para este producto según la orden. Máximo permitido para este ingreso: {$max} unidades.",
                ], 422);
            }

            if ($totalAfter < $expected) {
                $partial[] = [
                    'catalog_product_id' => $productId,
                    'received'           => $totalAfter,
                    'expected'           => $expected,
                    'missing'            => $expected - $totalAfter,
                ];
            }
        }

        // REQ-165 — si queda algo incompleto y todavía no se eligió el estado del remanente,
        // se devuelve el detalle SIN persistir para que el frontend muestre el modal.
        if ($partial !== [] && ! isset($validated['pending_remainder_status'])) {
            return response()->json(['requires_pending_status' => true, 'partial' => $partial]);
        }

        // Si la orden ya está Recibida, o si el ingreso que se está corrigiendo ya tenía líneas
        // confirmadas (físicamente ya estaba en bodega), la corrección se confirma de inmediato
        // igual — no tiene sentido que una corrección "desconfirme" mercancía que ya se contó
        // como stock real, solo porque se está ajustando la cantidad (REQ-167 AC: cambiar 10→15
        // debe reflejar 15 en Inventario, no volver a "Por ingresar").
        $existingWasConfirmed = $existing !== null && $existing->lines->contains(fn ($l) => ! $l->isPending());
        $shouldConfirmNow = $order->status === PurchaseOrder::STATUS_RECIBIDO || $existingWasConfirmed;
        $providerIsLocal = $order->provider?->origin === Provider::ORIGIN_LOCAL;
        /** @var array<int, array{catalog_product_id: int, quantity: int, unit_cost: float, warehouse_id: int}> $lines */
        $totalLineValue = collect($lines)->sum(fn (array $l) => $l['quantity'] * $l['unit_cost']);
        $importCostTotal = $providerIsLocal ? 0.0 : (float) ($validated['import_cost_total'] ?? 0);
        $freightTotal    = $providerIsLocal ? 0.0 : (float) ($validated['freight_total'] ?? 0);
        $handlingTotal   = $providerIsLocal ? 0.0 : (float) ($validated['handling_total'] ?? 0);
        $otherCostsTotal = $providerIsLocal ? 0.0 : (float) ($validated['other_costs_total'] ?? 0);
        $shippingTotal   = $importCostTotal + $freightTotal + $handlingTotal + $otherCostsTotal;

        $receipt = DB::transaction(function () use (
            $order, $existing, $validated, $lines, $providerIsLocal, $totalLineValue, $shippingTotal,
            $importCostTotal, $freightTotal, $handlingTotal, $otherCostsTotal,
            $shouldConfirmNow, $user
        ): PurchaseOrderReceipt {
            if ($existing !== null) {
                $this->reverseConfirmedEffects($existing);
                $existing->lines()->delete();
                $existing->update([
                    'invoice_storage_key'       => $validated['invoice_storage_key'] ?? $existing->invoice_storage_key,
                    'invoice_original_filename' => $validated['invoice_original_filename'] ?? $existing->invoice_original_filename,
                    'import_cost_total'         => $providerIsLocal ? null : ($validated['import_cost_total'] ?? null),
                    'freight_total'             => $providerIsLocal ? null : ($validated['freight_total'] ?? null),
                    'handling_total'            => $providerIsLocal ? null : ($validated['handling_total'] ?? null),
                    'other_costs_total'         => $providerIsLocal ? null : ($validated['other_costs_total'] ?? null),
                ]);
                $receipt = $existing;
            } else {
                $receipt = PurchaseOrderReceipt::create([
                    'purchase_order_id'         => $order->id,
                    'invoice_storage_key'       => $validated['invoice_storage_key'] ?? null,
                    'invoice_original_filename' => $validated['invoice_original_filename'] ?? null,
                    'import_cost_total'         => $providerIsLocal ? null : ($validated['import_cost_total'] ?? null),
                    'freight_total'             => $providerIsLocal ? null : ($validated['freight_total'] ?? null),
                    'handling_total'            => $providerIsLocal ? null : ($validated['handling_total'] ?? null),
                    'other_costs_total'         => $providerIsLocal ? null : ($validated['other_costs_total'] ?? null),
                    'created_by'                => $user->id,
                ]);
            }

            foreach ($lines as $line) {
                $quantity = (int) $line['quantity'];
                $unitCost = (float) $line['unit_cost'];
                $lineValue = $quantity * $unitCost;

                // REQ-164 — ITBMS 7% por línea (local, se sigue plegando en `cost` como antes) o
                // prorrateo proporcional de los 4 gastos totales del header (no local). El TOTAL
                // asignado a esta línea (Escenario 1: "la primera línea recibe $200 de flete") se
                // persiste tal cual en la línea (fix Pre-QA 2026-07-20 — antes solo se pisaba como
                // snapshot per-unit en catalog_products, sin quedar nada observable por línea); el
                // equivalente per-unit sigue actualizando ese snapshot para SCRUM-237/REQ-174.
                $itbmsAmount = null;
                $effectiveUnitCost = $unitCost;
                $importCostShareTotal = null;
                $freightShareTotal = null;
                $handlingShareTotal = null;
                $otherCostShareTotal = null;
                $importCostSharePerUnit = 0.0;
                $freightSharePerUnit = 0.0;
                $handlingSharePerUnit = 0.0;
                $otherCostSharePerUnit = 0.0;
                if ($providerIsLocal) {
                    $itbmsAmount = round($lineValue * self::ITBMS_RATE_LOCAL, 2);
                    $effectiveUnitCost = $unitCost + ($itbmsAmount / $quantity);
                } elseif ($shippingTotal > 0 && $totalLineValue > 0) {
                    $ratio = $lineValue / $totalLineValue;
                    $importCostShareTotal = round($ratio * $importCostTotal, 2);
                    $freightShareTotal    = round($ratio * $freightTotal, 2);
                    $handlingShareTotal   = round($ratio * $handlingTotal, 2);
                    $otherCostShareTotal  = round($ratio * $otherCostsTotal, 2);
                    $importCostSharePerUnit = $importCostShareTotal / $quantity;
                    $freightSharePerUnit    = $freightShareTotal / $quantity;
                    $handlingSharePerUnit   = $handlingShareTotal / $quantity;
                    $otherCostSharePerUnit  = $otherCostShareTotal / $quantity;
                }

                $confirmedAt = $shouldConfirmNow ? now() : null;

                $receiptLine = PurchaseOrderReceiptLine::create([
                    'purchase_order_receipt_id' => $receipt->id,
                    'catalog_product_id'        => $line['catalog_product_id'],
                    'quantity'                  => $quantity,
                    'unit_cost'                 => $unitCost,
                    'itbms_amount'              => $itbmsAmount,
                    'import_cost_share'         => $importCostShareTotal,
                    'freight_cost_share'        => $freightShareTotal,
                    'handling_cost_share'       => $handlingShareTotal,
                    'other_cost_share'          => $otherCostShareTotal,
                    'warehouse_id'              => $line['warehouse_id'],
                    'confirmed_at'              => $confirmedAt,
                ]);

                // RN de REQ-166 — el costo real capturado acá (ITBMS o prorrateo por componente)
                // queda como la referencia de costo más reciente del producto en Inventario,
                // reemplazando cualquier estimado manual anterior (SCRUM-237).
                CatalogProduct::where('id', $line['catalog_product_id'])->update([
                    'cost'          => round($effectiveUnitCost, 2),
                    'import_cost'   => round($importCostSharePerUnit, 2),
                    'freight_cost'  => round($freightSharePerUnit, 2),
                    'handling_cost' => round($handlingSharePerUnit, 2),
                    'other_cost'    => round($otherCostSharePerUnit, 2),
                ]);

                if ($confirmedAt !== null) {
                    $this->confirmation->confirmLine($receiptLine, $user->id);
                }
            }

            // REQ-165 — se limpia el estado pendiente si esta corrección/ingreso ya no deja nada
            // incompleto; si sigue habiendo faltante, se guarda el estado elegido para el remanente.
            $stillPartial = $this->receivedByProduct($order)->some(function (int $received, $productId) use ($order) {
                $expected = (int) $order->lines->where('catalog_product_id', $productId)->sum('quantity');

                return $received < $expected;
            });

            $order->update([
                'pending_remainder_status' => $stillPartial ? ($validated['pending_remainder_status'] ?? $order->pending_remainder_status) : null,
            ]);

            /** @var PurchaseOrderReceipt $fresh */
            $fresh = $receipt->fresh(['lines.catalogProduct', 'lines.warehouse', 'purchaseOrder.provider']);

            return $fresh;
        });

        return response()->json($this->formatDetail($receipt), $existing !== null ? 200 : 201);
    }

    /** REQ-173 — confirma una línea "Por ingresar" (ya con su bodega elegida al ingresar). */
    public function confirm(Request $request, int $lineId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var PurchaseOrderReceiptLine|null $line */
        $line = PurchaseOrderReceiptLine::find($lineId);
        if ($line === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $line->isPending()) {
            return response()->json(['message' => 'Esta línea ya fue confirmada.'], 422);
        }

        DB::transaction(function () use ($line, $user): void {
            $line->update(['confirmed_at' => now()]);
            $this->confirmation->confirmLine($line, $user->id);
        });

        /** @var PurchaseOrderReceiptLine $fresh */
        $fresh = $line->fresh();

        return response()->json($this->formatLine($fresh));
    }

    /**
     * REQ-173 (botón "Ingresar a Inventario", detalle del producto) — confirma TODAS las líneas
     * "Por ingresar" de un producto de una sola vez, cada una a la bodega YA elegida al momento
     * del ingreso original (RN1 — "sin que se me pregunte la bodega otra vez"). Un producto puede
     * tener más de una línea pendiente si viene de ingresos parciales/receipts distintos.
     */
    public function confirmPendingForProduct(Request $request, int $productId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        if (CatalogProduct::find($productId) === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $lines = PurchaseOrderReceiptLine::where('catalog_product_id', $productId)
            ->whereNull('confirmed_at')
            ->get();

        if ($lines->isEmpty()) {
            return response()->json(['message' => 'Este producto no tiene unidades "Por ingresar".'], 422);
        }

        DB::transaction(function () use ($lines, $user): void {
            foreach ($lines as $line) {
                $line->update(['confirmed_at' => now()]);
                $this->confirmation->confirmLine($line, $user->id);
            }
        });

        return response()->json([
            'confirmed_lines' => $lines->pluck('id')->values(),
            'confirmed_units' => $lines->sum('quantity'),
        ]);
    }

    /**
     * REQ-167 — revierte el efecto de stock de las líneas YA confirmadas del ingreso original.
     * Mismo cuidado que `GoodsReceiptConfirmationService::confirmLine()` (fix Pre-QA 2026-07-20,
     * SCRUM-205): `decrement()` sobre `stock_quantity` NULL se queda en NULL en SQL, no en
     * negativo — usar COALESCE para no dejar el mismo bug en el camino inverso.
     */
    private function reverseConfirmedEffects(PurchaseOrderReceipt $receipt): void
    {
        foreach ($receipt->lines as $line) {
            if ($line->isPending()) {
                continue;
            }

            CatalogProduct::where('id', $line->catalog_product_id)
                ->update(['stock_quantity' => DB::raw('COALESCE(stock_quantity, 0) - ' . (int) $line->quantity)]);
            ProductWarehouseStock::where('catalog_product_id', $line->catalog_product_id)
                ->where('warehouse_id', $line->warehouse_id)
                ->decrement('quantity', $line->quantity);
        }
    }

    /**
     * RN de REQ-165/166 — total ya recibido por producto para esta orden, sumando TODOS sus
     * ingresos anteriores (confirmados o todavía "Por ingresar" — ambos cuentan). `$excludeReceiptId`
     * se usa al corregir (REQ-167), para no contar el propio ingreso que se está reemplazando.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    /**
     * SCRUM-347 — delega a `GoodsReceiptConfirmationService`, fuente única compartida con
     * `PurchaseOrderController::advance()`.
     *
     * @return \Illuminate\Support\Collection<int, int>
     */
    private function receivedByProduct(PurchaseOrder $order, ?int $excludeReceiptId = null): \Illuminate\Support\Collection
    {
        return $this->confirmation->receivedQuantitiesByProduct($order, $excludeReceiptId);
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

    /** @return array<string, mixed> */
    private function formatSummary(PurchaseOrderReceipt $receipt): array
    {
        return [
            'id'                 => $receipt->id,
            'purchase_order_id'  => $receipt->purchase_order_id,
            'provider_name'      => $receipt->purchaseOrder?->provider?->name,
            'order_status'       => $receipt->purchaseOrder?->status,
            'lines_count'        => $receipt->lines->count(),
            'total_units'        => $receipt->lines->sum('quantity'),
            'editable'           => $receipt->purchaseOrder?->status !== PurchaseOrder::STATUS_RECIBIDO,
            'created_at'         => $receipt->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatDetail(PurchaseOrderReceipt $receipt): array
    {
        return [
            ...$this->formatSummary($receipt),
            'invoice_storage_key'       => $receipt->invoice_storage_key,
            'invoice_original_filename' => $receipt->invoice_original_filename,
            'import_cost_total'         => $receipt->import_cost_total !== null ? (float) $receipt->import_cost_total : null,
            'freight_total'             => $receipt->freight_total !== null ? (float) $receipt->freight_total : null,
            'handling_total'            => $receipt->handling_total !== null ? (float) $receipt->handling_total : null,
            'other_costs_total'         => $receipt->other_costs_total !== null ? (float) $receipt->other_costs_total : null,
            'lines'                     => $receipt->lines->map(fn (PurchaseOrderReceiptLine $l) => $this->formatLine($l))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatLine(PurchaseOrderReceiptLine $line): array
    {
        return [
            'id'                  => $line->id,
            'catalog_product_id'  => $line->catalog_product_id,
            'reference'           => $line->catalogProduct?->reference,
            'description'         => $line->catalogProduct?->name,
            'quantity'            => $line->quantity,
            'unit_cost'           => $line->unit_cost,
            'itbms_amount'        => $line->itbms_amount,
            'import_cost_share'   => $line->import_cost_share,
            'freight_cost_share'  => $line->freight_cost_share,
            'handling_cost_share' => $line->handling_cost_share,
            'other_cost_share'    => $line->other_cost_share,
            'cost_total'          => $line->totalCost(),
            'warehouse_id'        => $line->warehouse_id,
            'warehouse_name'      => $line->warehouse?->name,
            'is_pending'          => $line->isPending(),
        ];
    }
}
