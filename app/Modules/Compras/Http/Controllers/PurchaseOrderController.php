<?php

declare(strict_types=1);

namespace App\Modules\Compras\Http\Controllers;

use App\Models\User;
use App\Modules\Compras\Events\OrderLiquidationAssigned;
use App\Modules\Compras\Events\PurchaseOrderReceived;
use App\Modules\Compras\Http\Requests\StorePurchaseOrderRequest;
use App\Modules\Compras\Http\Requests\UpdatePurchaseOrderRequest;
use App\Modules\Compras\Jobs\SendPurchaseOrderEmailJob;
use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\LiquidationAgency;
use App\Modules\Compras\Models\Provider;
use App\Modules\Compras\Models\PurchaseOrder;
use App\Modules\Compras\Models\PurchaseOrderLine;
use App\Modules\Compras\Services\GoodsReceiptConfirmationService;
use App\Modules\Compras\Services\InventoryKpiService;
use App\Modules\Compras\Services\PurchaseOrderPdfService;
use App\Modules\Compras\Services\PurchaseOrderTranslationService;
use App\Modules\Compras\Support\OrderMoneyVisibility;
use App\Modules\Compras\Support\TranslationException;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Shared\Http\Pagination;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use App\Shared\Support\PrimaryApprovers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * REQ-128→133 (Nueva Orden, 2H) + REQ-138→149 (Ver Órdenes, 2A, sprint2). `index`/`show` sirven a
 * ambas pantallas. Recepción por línea (REQ-145), liquidación Zona Libre (REQ-147), validación de
 * documento del proveedor (REQ-148) y sincronización con Ingreso de Mercancía (REQ-150) quedan
 * diferidos — dependen de pantallas/capacidades que no existen todavía (ver docs/specs/
 * compras-proveedores-sprint2.md).
 */
final class PurchaseOrderController extends Controller
{
    /** @var list<string> */
    private const ALLOWED_SORTS = ['created_at', 'status', 'total_amount', 'estimated_arrival_date'];

    public function __construct(
        private readonly PurchaseOrderPdfService $pdfService,
        private readonly PurchaseOrderTranslationService $translations,
        private readonly InventoryKpiService $inventoryKpis,
        private readonly GoodsReceiptConfirmationService $goodsReceiptConfirmation,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        // REQ-140/146 (SCRUM-203/209): la tabla principal necesita el proyecto asignado por línea
        // para resumirlo por orden — eager load para no generar N+1 al formatear cada fila.
        // REQ-187 (SCRUM-250) — 'payments' para paymentStatus()/paidAmount() en formatSummary().
        $query = PurchaseOrder::with(['provider', 'creator', 'approvedByUser', 'liquidationAgency', 'lines.salesProject', 'payments']);

        // REQ-138: filtros combinables por proveedor/estado/responsable/proyecto.
        $providerId = $request->query('provider_id');
        if (is_numeric($providerId)) {
            $query->where('provider_id', (int) $providerId);
        }
        $status = $request->query('status');
        if (is_string($status) && $status !== '') {
            $query->where('status', $status);
        }
        $createdBy = $request->query('created_by');
        if (is_numeric($createdBy)) {
            $query->where('created_by', (int) $createdBy);
        }
        $salesProjectId = $request->query('sales_project_id');
        if (is_numeric($salesProjectId)) {
            $query->whereHas('lines', fn ($q) => $q->where('sales_project_id', (int) $salesProjectId));
        }

        // REQ-187 (SCRUM-250) — pantalla "Pagos a Proveedores": solo órdenes que todavía no
        // llegan a "Ordenado" (texto literal del ticket: "Por aprobar y Pendiente/Por liquidar").
        if ($request->boolean('payment_pending')) {
            $query->whereIn('status', [PurchaseOrder::STATUS_POR_APROBAR, PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR]);
        }

        // SCRUM-201 (hallazgo QA 2026-07-16): buscador de texto libre — número de orden exacto,
        // nombre de proveedor o proyecto asignado (contiene). No es fuzzy search (a diferencia de
        // ProviderController) porque acá se busca dentro de relaciones (provider.name,
        // lines.salesProject.name), no un array en memoria.
        // REQ-151 (SCRUM-214, Logística) — extendido a proyecto: el mismo buscador es compartido
        // con Ver Órdenes, así que ambas pantallas ganan la búsqueda por proyecto.
        $search = trim((string) $request->query('search', ''));
        if ($search !== '') {
            $query->where(function ($q) use ($search): void {
                if (is_numeric($search)) {
                    $q->orWhere('id', (int) $search);
                }
                $q->orWhereHas('provider', fn ($p) => $p->where('name', 'ilike', "%{$search}%"));
                $q->orWhereHas('lines.salesProject', fn ($sp) => $sp->where('name', 'ilike', "%{$search}%"));
            });
        }

        // REQ-151 (Logística): solo envíos "en movimiento" — Ordenado en adelante, hasta
        // Recibido inclusive. Excluye Por aprobar/Pendiente por liquidar, que no son un envío
        // real todavía.
        if ($request->boolean('active_shipments')) {
            $query->whereIn('status', PurchaseOrder::activeShipmentStatuses());
        }

        // REQ-139: chips rápidos. "Críticas" no es un filtro de columna — se evalúa en PHP
        // (isCritical() combina 2 condiciones distintas, no traducible a un solo WHERE simple).
        // REQ-152 (Logística, "⚠️ Retrasados") reusa este mismo chip=critical — mismo criterio
        // exacto, sin una segunda definición de "atraso".
        $chip = $request->query('chip');
        if ($chip === 'zona_libre') {
            $query->where('modality', PurchaseOrder::MODALITY_ZONA_LIBRE);
        }

        // REQ-187 (SCRUM-250, hallazgo de Gerencia Test 2026-08-09) — 5 chips exactos: Todas,
        // Sin enviar, Por pagar, Pagado parcial, Pagado completo. "Sin enviar"/"Por pagar" son
        // los 2 estados en los que antes vivía un único "Pendiente": "Sin enviar" = todavía no se
        // usó "Enviar a pago" (payment_requested_at null); "Por pagar" = ya se envió pero sigue
        // sin ningún pago registrado. payment_status()/payment_requested_at no son columnas (se
        // calculan), así que se filtra en PHP sobre el set ya acotado — mismo patrón que el chip
        // "critical" de arriba.
        $paymentStatus = $request->query('payment_status');
        $validPaymentStatuses = ['sin_enviar', 'por_pagar', PurchaseOrder::PAYMENT_STATUS_PARCIAL, PurchaseOrder::PAYMENT_STATUS_COMPLETO];

        // SCRUM-1013 (Pre-QA H2) — quien solo tiene compras.logistica.view (vendedor) no ve costos ni montos:
        // tampoco puede ordenar por monto (el orden revelaría cuánto cuesta cada orden).
        $hidesMoney = $this->hidesMoney($request);
        $allowedSorts = $hidesMoney ? array_values(array_diff(self::ALLOWED_SORTS, ['total_amount'])) : self::ALLOWED_SORTS;
        $pagination = Pagination::params($request, $allowedSorts, 'created_at', 'desc');

        // REQ-187 — los 5 KPIs se calculan siempre sobre el universo COMPLETO de "payment_pending"
        // (con los demás filtros combinables ya aplicados a $query — proveedor/búsqueda/etc, pero
        // SIN el chip de estado de pago activo), nunca sobre la página ya paginada.
        $paymentKpis = null;
        if ($request->boolean('payment_pending')) {
            /** @var list<PurchaseOrder> $allForKpis */
            $allForKpis = (clone $query)->get()->all();
            $paymentKpis = [
                'total_pending_balance' => $hidesMoney ? null : round(array_sum(array_map(
                    fn (PurchaseOrder $o): float => $o->payableTotal() - $o->paidAmount(), $allForKpis
                )), 2),
                'sin_enviar' => count(array_filter($allForKpis, fn (PurchaseOrder $o): bool => $o->payment_requested_at === null && $o->paymentStatus() === PurchaseOrder::PAYMENT_STATUS_PENDIENTE)),
                'por_pagar' => count(array_filter($allForKpis, fn (PurchaseOrder $o): bool => $o->payment_requested_at !== null && $o->paymentStatus() === PurchaseOrder::PAYMENT_STATUS_PENDIENTE)),
                'parcial' => count(array_filter($allForKpis, fn (PurchaseOrder $o): bool => $o->paymentStatus() === PurchaseOrder::PAYMENT_STATUS_PARCIAL)),
                'completo' => count(array_filter($allForKpis, fn (PurchaseOrder $o): bool => $o->paymentStatus() === PurchaseOrder::PAYMENT_STATUS_COMPLETO)),
            ];
        }

        if ($chip === 'critical') {
            // isCritical() no es una columna — se pagina en PHP sobre el set ya filtrado.
            /** @var list<PurchaseOrder> $all */
            $all = $query->get()->all();
            $critical = array_values(array_filter($all, fn (PurchaseOrder $o): bool => $o->isCritical()));
            usort($critical, fn (PurchaseOrder $a, PurchaseOrder $b) => $b->created_at <=> $a->created_at);
            $paginator = Pagination::ofArray($critical, $pagination['per_page'], $pagination['page']);
        } elseif (is_string($paymentStatus) && in_array($paymentStatus, $validPaymentStatuses, true)) {
            /** @var list<PurchaseOrder> $all */
            $all = $query->get()->all();
            $filtered = array_values(array_filter($all, fn (PurchaseOrder $o): bool => match ($paymentStatus) {
                'sin_enviar' => $o->payment_requested_at === null && $o->paymentStatus() === PurchaseOrder::PAYMENT_STATUS_PENDIENTE,
                'por_pagar' => $o->payment_requested_at !== null && $o->paymentStatus() === PurchaseOrder::PAYMENT_STATUS_PENDIENTE,
                default => $o->paymentStatus() === $paymentStatus,
            }));
            usort($filtered, fn (PurchaseOrder $a, PurchaseOrder $b) => $b->created_at <=> $a->created_at);
            $paginator = Pagination::ofArray($filtered, $pagination['per_page'], $pagination['page']);
        } else {
            $paginator = $query
                ->orderBy($pagination['sort_by'], $pagination['sort_dir'])
                ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);
        }

        // REQ-145/158/160 — 1 sola query batch para el "Recepción" de todas las órdenes de esta
        // página, en vez de 1 query por orden dentro de formatSummary().
        $pageOrders = collect($paginator->items());
        /** @var list<int> $pageOrderIds */
        $pageOrderIds = array_map('intval', $pageOrders->pluck('id')->values()->all());
        $receivedByOrder = PurchaseOrder::receivedQuantitiesForOrders($pageOrderIds);

        return response()->json([
            'data' => $pageOrders->map(
                // `?? collect()` (no `?? null`): una orden sin registros de ingreso YA está
                // resuelta por el batch (0 recibido, ver Recepción "pendiente") — pasar null acá
                // dispararía otra query individual dentro de formatSummary(), justo lo que el
                // batch de arriba busca evitar.
                fn (PurchaseOrder $o) => $hidesMoney
                    ? $this->maskMoney($this->formatSummary($o, $receivedByOrder[$o->id] ?? collect()))
                    : $this->formatSummary($o, $receivedByOrder[$o->id] ?? collect())
            )->values(),
            'meta' => Pagination::meta($paginator),
            // SCRUM-201/250 — lista de responsables/proveedores reales (para los <select> de
            // filtro), independiente de los filtros ya aplicados, igual que Provider::categories()
            // en ProviderController. "providers" nuevo para el filtro de Pagos a Proveedores
            // (hallazgo de Gerencia Test) — no tiene costo agregarlo para el resto de pantallas
            // que comparten este mismo endpoint, simplemente no lo consumen.
            'filters' => [
                'creators' => $this->distinctCreators(),
                'providers' => Provider::orderBy('name')->get(['id', 'name']),
            ],
            'payment_kpis' => $paymentKpis,
        ]);
    }

    /**
     * REQ-151 (SCRUM-214, hallazgo Pre-QA 2026-08-05) — el "Filtro de Proyecto" de Logística
     * reusaba `comprasApi.approvedProjects.search()` (`QuoteListService::searchApprovedProjects()`),
     * un endpoint construido para REQ-133 (Nueva Orden) que solo devuelve proyectos con una Quote
     * en `documentStatus()==='approved'`. Un envío activo real puede estar ligado a un proyecto sin
     * cotización vigente en ese estado exacto (dato de antes de esa validación, corrección de
     * cotización, etc.) — ese proyecto sigue siendo visible en la "ruta" de la tarjeta
     * (`lines.salesProject`, sin gate) pero era invisible en este buscador. Reproducido en vivo:
     * `/compras/approved-projects?search=...` devolvía `[]` para un proyecto con envío activo real
     * mientras el buscador de texto libre (mismo término, mismo endpoint `index()` de acá arriba)
     * sí lo encontraba. Fuente de datos propia: proyectos realmente referenciados por líneas de
     * órdenes, sin ningún gate de cotización.
     *
     * SCRUM-733 (mismo gap, pantalla "Ver Órdenes"/2A) — Logística de verdad quiere acotar a
     * "envíos en movimiento" (`activeShipmentStatuses()`, excluye Por aprobar/Pendiente por
     * liquidar a propósito, ver REQ-151), pero Ver Órdenes lista órdenes en CUALQUIER estado —
     * filtrar acá por el mismo status set dejaría un proyecto invisible en el filtro mientras su
     * orden "Por aprobar" sí aparece en la tabla. Mismo endpoint, filtro de status ahora opcional
     * vía `active_shipments` (mismo nombre de query param que `index()` arriba, mismo significado):
     * Logística lo manda `true`, Ver Órdenes no lo manda (ve proyectos de órdenes en cualquier
     * estado).
     */
    public function searchShipmentProjects(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $search = trim((string) $request->query('search', ''));

        $projectIdsQuery = PurchaseOrderLine::query()->whereNotNull('sales_project_id');
        if ($request->boolean('active_shipments')) {
            $projectIdsQuery->whereHas('purchaseOrder', fn ($q) => $q->whereIn('status', PurchaseOrder::activeShipmentStatuses()));
        }
        $projectIds = $projectIdsQuery->distinct()->pluck('sales_project_id');

        $query = SalesProject::with('subClient')->whereIn('id', $projectIds);
        if ($search !== '') {
            $query->where('name', 'ilike', "%{$search}%");
        }

        $rows = $query->orderBy('name')->limit(10)->get()->map(fn (SalesProject $p): array => [
            'sales_project_id' => $p->id,
            'folio' => null,
            'project_name' => $p->name,
            'client_name' => $p->subClient?->business_name,
        ])->all();

        return response()->json(['data' => $rows]);
    }

    /** @return list<array{id: int, name: string}> */
    private function distinctCreators(): array
    {
        $creators = PurchaseOrder::with('creator')
            ->get()
            ->pluck('creator')
            ->filter()
            ->unique('id')
            ->map(fn (User $u): array => ['id' => $u->id, 'name' => trim("{$u->first_name} {$u->last_name}")])
            ->sortBy('name')
            ->all();

        return array_values($creators);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::with(['provider', 'creator', 'approvedByUser', 'liquidationAgency', 'lines.catalogProduct', 'lines.salesProject', 'payments'])->find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $detail = $this->formatDetail($order);

        return response()->json($this->hidesMoney($request) ? $this->maskMoney($detail) : $detail);
    }

    /**
     * SCRUM-1013 (Pre-QA H2, decisión de Luis 2026-10-02) — el vendedor entra a Logística y Envío con
     * `compras.logistica.view` y nunca ve costos de compra (regla SCRUM-818): con costo unitario y
     * montos de la orden se deduce el costo del producto y se anula el enmascarado de Inventario.
     * Quien tenga cualquier permiso "de verdad" de Compras/Bodega (o superadmin) ve todo igual que antes.
     */
    private function hidesMoney(Request $request): bool
    {
        return OrderMoneyVisibility::hiddenFor($request);
    }

    /**
     * @param  array<string, mixed>  $order  resumen o detalle de una orden
     * @return array<string, mixed>
     */
    private function maskMoney(array $order): array
    {
        foreach (['total_amount', 'shipping_cost', 'import_cost', 'freight_cost', 'handling_cost', 'other_cost', 'additional_costs_total', 'payable_total', 'paid_amount', 'pending_amount_change'] as $key) {
            if (array_key_exists($key, $order)) {
                $order[$key] = null;
            }
        }

        if (isset($order['lines']) && is_iterable($order['lines'])) {
            $order['lines'] = collect($order['lines'])->map(function (array $line): array {
                foreach (['unit_cost', 'subtotal', 'additional_cost_percent', 'additional_cost_amount', 'additional_cost_type'] as $key) {
                    if (array_key_exists($key, $line)) {
                        $line[$key] = null;
                    }
                }

                return $line;
            })->values();
        }

        return $order;
    }

    /**
     * SCRUM-1145 — costos adicionales opcionales (importación, flete, manejo, otros): un valor
     * vacío/ausente se persiste como NULL.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, float|null>
     */
    private function additionalCostFields(array $validated): array
    {
        $fields = [];
        foreach (['import_cost', 'freight_cost', 'handling_cost', 'other_cost'] as $key) {
            $fields[$key] = isset($validated[$key]) ? (float) $validated[$key] : null;
        }

        return $fields;
    }

    public function store(StorePurchaseOrderRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /**
         * @var array{
         *   provider_id: int, shipping_type?: string|null, who_pays_shipping?: string|null,
         *   shipping_cost?: float|null,
         *   modality?: string|null, estimated_arrival_date?: string|null, notes?: string|null,
         *   lines: list<array{
         *     catalog_product_id?: int|null,
         *     new_product?: array{reference: string, factory_reference?: string|null, name: string,
         *       description?: string|null, brand?: string|null, price_full: float, cost: float,
         *       reorder_point?: int|null, category?: string|null, rotation?: string|null}|null,
         *     quantity: int, unit_cost?: float|null, additional_cost_percent?: float|null,
         *     additional_cost_amount?: float|null, additional_cost_type?: string|null,
         *     sales_project_id?: int|null,
         *   }>,
         * } $validated
         */
        $validated = $request->validated();

        $order = DB::transaction(function () use ($validated, $user): PurchaseOrder {
            $requiresApproval = PurchaseOrder::computeRequiresPrimaryApproval(
                $validated['shipping_type'] ?? null,
                $validated['who_pays_shipping'] ?? null,
            );
            // SCRUM-954 — no persistir who_pays_shipping/shipping_cost si el tipo de envío no es
            // Aéreo, sin importar lo que traiga el payload.
            $shippingFields = PurchaseOrder::normalizeShippingFields(
                $validated['shipping_type'] ?? null,
                $validated['who_pays_shipping'] ?? null,
                $validated['shipping_cost'] ?? null,
            );

            // SCRUM-204 (REQ-141, 2026-08-06 — hallazgo Gerencia Test): antes toda orden nacía en
            // "Por aprobar" sin importar si de verdad requería la aprobación de Gerencia (RN3/REQ-118)
            // — $requiresApproval ya se calculaba arriba pero nunca se usaba para el estado
            // inicial. Ahora solo las que la requieren nacen en "Por aprobar"; el resto arranca
            // directo en "Pendiente/Por liquidar" (misma etiqueta interna, texto derivado de
            // `modality`, derivado del lado del cliente — ver
            // `atlanticerp-frontend/src/utils/orderStatusLabel.ts`).
            $order = PurchaseOrder::create([
                'provider_id' => $validated['provider_id'],
                'status' => $requiresApproval
                    ? PurchaseOrder::STATUS_POR_APROBAR
                    : PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR,
                'status_changed_at' => now(),
                'shipping_type' => $validated['shipping_type'] ?? null,
                'who_pays_shipping' => $shippingFields['who_pays_shipping'],
                'shipping_cost' => $shippingFields['shipping_cost'],
                ...$this->additionalCostFields($validated),
                'modality' => $validated['modality'] ?? PurchaseOrder::MODALITY_DIRECTO,
                'estimated_arrival_date' => $validated['estimated_arrival_date'] ?? null,
                'requires_primary_approval' => $requiresApproval,
                'currency' => 'USD',
                'notes' => $validated['notes'] ?? null,
                'created_by' => $user->id,
            ]);

            $this->replaceLines($order, $validated);

            return $order;
        });

        // 'payments' — REQ-140 (SCRUM-203): formatSummary() ahora lee $order->payments para
        // 'last_payment_date'; sin eager-load acá dispararía una lazy-load por mutación en vez de
        // reusar la ya cargada por index()/show().
        $order->load(['provider', 'creator', 'approvedByUser', 'liquidationAgency', 'lines.catalogProduct', 'lines.salesProject', 'payments']);

        return response()->json($this->formatDetail($order), 201);
    }

    /** REQ-144 — solo mientras "Por aprobar"; reemplaza líneas completas (más simple y correcto que diff línea por línea). */
    public function update(UpdatePurchaseOrderRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        // SCRUM-852 — se amplía de un único estado a los 2 previos a "Ordenado" (edición sigue
        // bloqueada desde ahí en adelante, es donde el proveedor ya recibió/confirmó la orden).
        if (! in_array($order->status, [PurchaseOrder::STATUS_POR_APROBAR, PurchaseOrder::STATUS_PENDIENTE_LIQUIDAR], true)) {
            return response()->json(['message' => 'Solo se puede editar una orden antes de "Ordenado".'], 422);
        }

        /**
         * @var array{
         *   provider_id: int, shipping_type?: string|null, who_pays_shipping?: string|null,
         *   shipping_cost?: float|null,
         *   modality?: string|null, estimated_arrival_date?: string|null, notes?: string|null,
         *   lines: list<array{
         *     catalog_product_id?: int|null,
         *     new_product?: array{reference: string, factory_reference?: string|null, name: string,
         *       description?: string|null, brand?: string|null, price_full: float, cost: float,
         *       reorder_point?: int|null, category?: string|null, rotation?: string|null}|null,
         *     quantity: int, unit_cost?: float|null, additional_cost_percent?: float|null,
         *     additional_cost_amount?: float|null, additional_cost_type?: string|null,
         *     sales_project_id?: int|null,
         *   }>,
         * } $validated
         */
        $validated = $request->validated();

        DB::transaction(function () use ($validated, $order): void {
            $requiresApproval = PurchaseOrder::computeRequiresPrimaryApproval(
                $validated['shipping_type'] ?? null,
                $validated['who_pays_shipping'] ?? null,
            );
            // SCRUM-954 — no persistir who_pays_shipping/shipping_cost si el tipo de envío no es
            // Aéreo, sin importar lo que traiga el payload.
            $shippingFields = PurchaseOrder::normalizeShippingFields(
                $validated['shipping_type'] ?? null,
                $validated['who_pays_shipping'] ?? null,
                $validated['shipping_cost'] ?? null,
            );

            $order->update([
                'shipping_type' => $validated['shipping_type'] ?? null,
                'who_pays_shipping' => $shippingFields['who_pays_shipping'],
                'shipping_cost' => $shippingFields['shipping_cost'],
                ...$this->additionalCostFields($validated),
                'modality' => $validated['modality'] ?? PurchaseOrder::MODALITY_DIRECTO,
                'estimated_arrival_date' => $validated['estimated_arrival_date'] ?? null,
                'requires_primary_approval' => $requiresApproval,
                'notes' => $validated['notes'] ?? null,
            ]);

            $order->lines()->delete();
            $this->replaceLines($order, $validated);
        });

        // 'payments' — REQ-140 (SCRUM-203): formatSummary() ahora lee $order->payments para
        // 'last_payment_date'; sin eager-load acá dispararía una lazy-load por mutación en vez de
        // reusar la ya cargada por index()/show().
        $order->load(['provider', 'creator', 'approvedByUser', 'liquidationAgency', 'lines.catalogProduct', 'lines.salesProject', 'payments']);

        return response()->json($this->formatDetail($order));
    }

    /** RN3 de REQ-118 — gate de aprobación de Gerencia. */
    public function approve(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $order->requires_primary_approval) {
            return response()->json(['message' => 'Esta orden no requiere aprobación.'], 422);
        }
        if ($order->approved_by !== null) {
            return response()->json(['message' => 'Esta orden ya fue aprobada.'], 422);
        }

        // SCRUM-206 (hallazgo QA): la spec de REQ-143 pide la aprobación de Gerencia específicamente,
        // no de cualquier usuario con el permiso compras.approve — sin este chequeo, cualquier
        // Gerencia podía aprobar y quedaba registrada con trazabilidad correcta en `approved_by`
        // pero la UI mostraba el texto fijo "Aprobada por Gerencia" igual, sin importar quién aprobó.
        // superadmin.all bypassa este gate (decisión de Luis, 2026-08-22) — mismo criterio que
        // el resto de la app, ver EnsureIsPrimaryApprover en Admin&Cont.
        $primaryApproverId = ComprasSettings::current()->primary_approver_user_id;
        if ($primaryApproverId !== null && ! PrimaryApprovers::isApprover($user->id, $primaryApproverId) && ! $user->hasPermission('superadmin.all')) {
            return response()->json(['message' => 'Solo el aprobador configurado puede aprobar esta orden.'], 403);
        }

        $order->update(['approved_by' => $user->id, 'approved_at' => now()]);
        // 'payments' — REQ-140 (SCRUM-203): formatSummary() ahora lee $order->payments para
        // 'last_payment_date'; sin eager-load acá dispararía una lazy-load por mutación en vez de
        // reusar la ya cargada por index()/show().
        $order->load(['provider', 'creator', 'approvedByUser', 'liquidationAgency', 'lines.catalogProduct', 'lines.salesProject', 'payments']);

        return response()->json($this->formatDetail($order));
    }

    /**
     * SCRUM-210 (REQ-147, alcance reducido) — asigna/cambia la agencia de liquidación de una
     * orden Zona Libre y dispara el evento de dominio para que Bodega lo consuma (RN6 del
     * ticket, "avisar a Tecnico Garantias", queda para cuando ese módulo exista — ver docblock del evento).
     * No cambia `status` — la secuencia de estados no se toca en este alcance reducido.
     */
    public function liquidate(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::with('provider')->find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if ($order->modality !== PurchaseOrder::MODALITY_ZONA_LIBRE) {
            return response()->json(['message' => 'Solo las órdenes de modalidad Zona Libre pueden liquidarse.'], 422);
        }

        $validated = $request->validate([
            'agency_id' => ['required', 'integer', Rule::exists('liquidation_agencies', 'id')],
        ]);

        /** @var LiquidationAgency $agency */
        $agency = LiquidationAgency::findOrFail($validated['agency_id']);

        $order->update(['liquidation_agency_id' => $agency->id]);
        // 'payments' — REQ-140 (SCRUM-203): formatSummary() ahora lee $order->payments para
        // 'last_payment_date'; sin eager-load acá dispararía una lazy-load por mutación en vez de
        // reusar la ya cargada por index()/show().
        $order->load(['provider', 'creator', 'approvedByUser', 'liquidationAgency', 'lines.catalogProduct', 'lines.salesProject', 'payments']);

        event(new OrderLiquidationAssigned(
            orderId: $order->id,
            agencyId: $agency->id,
            agencyName: $agency->name,
            assignedBy: $user->id,
            tenant: (string) Tenant::current()?->slug,
        ));

        return response()->json($this->formatDetail($order));
    }

    /**
     * REQ-141/142/143 — avanza al siguiente estado de la secuencia (según origen del proveedor),
     * bloqueando de verdad si falta la aprobación de Gerencia (REQ-143, ya no es solo un aviso).
     * El efecto "auto-confirma productos Por ingresar en Inventario" (REQ-142 Escenario 1) — fix
     * Pre-QA 2026-07-20, rechazado por qa: quedaba diferido desde que se escribió este método
     * porque Ingreso de Mercancía no existía. Ya existe (SCRUM-220→230); al llegar a "Recibido" se
     * confirman ahora todas las líneas pendientes de ingresos registrados contra ESTA orden
     * (`GoodsReceiptConfirmationService::confirmPendingForOrder()`, mismo efecto de stock que
     * confirmar manualmente desde Inventario).
     *
     * REQ-153/154 (SCRUM-216/217, batch fusionado 2026-08-05, RN1/RN3 de los comentarios de
     * Gerencia) — además del `status`/`status_changed_at` de siempre, este método ahora deja un
     * registro histórico permanente de CUÁNDO se alcanzó cada etapa
     * (`PurchaseOrder::STAGE_DATE_COLUMNS`), consumido por el timeline visual de Logística
     * (REQ-154). Al llegar a "Recibido" (etapa terminal, sin columna propia en el mapa) se
     * auto-llena `actual_arrival_date` con la fecha real de HOY — antes de este fix ese campo
     * dependía por completo de que alguien lo escribiera a mano en `updateShippingInfo()`, lectura
     * incorrecta de REQ-156 ya revertida (ver docblock de `updateShippingInfo()`).
     *
     * Pre-QA 2026-08-06 (SCRUM-216/217, hallazgo CRÍTICO real, no hipotético) — reproducido con 5
     * llamadas concurrentes reales (`curl` en paralelo, no simulado) contra la misma orden: sin
     * lock, 3-4 de las 5 leían `status`/`nextStatus()` ANTES de que cualquiera escribiera el
     * `update()`, así que todas calificaban como "avance válido" y las 4 devolvían 200 con
     * `status=recibido` — cuadriplicando el efecto de `confirmPendingForOrder()` (stock real 10 →
     * 40, ver fix en `GoodsReceiptConfirmationService`) y disparando `PurchaseOrderReceived` 4
     * veces para la misma orden. El chequeo de estado + el `update()` ahora viven DENTRO de una
     * transacción, contra la fila re-obtenida con `lockForUpdate()` — mismo patrón exacto ya
     * usado en `AdjustmentRequestController::approve()/reject()` y
     * `ComprasZonaLibreRequestController::approve()` para el mismo tipo de carrera.
     */
    public function advance(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /**
         * @var array{status: int, message: string|null, order: PurchaseOrder|null} $result
         */
        $result = DB::transaction(function () use ($id, $user): array {
            /** @var PurchaseOrder|null $order */
            $order = PurchaseOrder::with(['provider', 'creator', 'approvedByUser', 'liquidationAgency'])
                ->lockForUpdate()
                ->find($id);
            if ($order === null) {
                return ['status' => 404, 'message' => null, 'order' => null];
            }

            if ($order->isBlockedByPrimaryApproval()) {
                return ['status' => 422, 'message' => 'Esta orden requiere la aprobación del aprobador configurado antes de avanzar.', 'order' => null];
            }

            $next = $order->nextStatus();
            if ($next === null) {
                return ['status' => 422, 'message' => 'Esta orden no tiene un siguiente estado disponible.', 'order' => null];
            }

            // SCRUM-208 (2026-08-06, hallazgo Gerencia Test): `advance()` avanzaba el `status` de
            // la orden sin importar si una recepción parcial dejó un remanente pendiente
            // (`pending_remainder_status`, REQ-165) — una orden podía llegar a "Recibido" con
            // mercancía todavía en tránsito/aduana, sin ninguna señal visible de que faltaba algo.
            // `pending_remainder_status` se limpia solo cuando un ingreso nuevo cubre lo que
            // faltaba (GoodsReceiptController::saveReceipt) — mientras siga con valor, la orden no
            // puede darse por completamente recibida.
            //
            // SCRUM-347 (rebote QA 2026-08-14): ese chequeo solo cubre el caso de una recepción
            // PARCIAL ya empezada — si NUNCA se registró ningún Ingreso de Mercancía para la
            // orden, `pending_remainder_status` nunca se toca y queda NULL para siempre, así que
            // el gate de arriba no frenaba nada: la orden llegaba a "Recibido" sin una sola línea
            // confirmada, sin movimiento de Kardex y sin ningún error visible. Se agrega
            // `isFullyReceived()` (fuente única, compartida con
            // `GoodsReceiptController::receivedByProduct()`) para exigir que TODO lo esperado ya
            // se haya recibido, no solo que no quede un remanente ya conocido.
            if ($next === PurchaseOrder::STATUS_RECIBIDO
                && ($order->pending_remainder_status !== null || ! $this->goodsReceiptConfirmation->isFullyReceived($order))) {
                return [
                    'status' => 422,
                    'message' => 'No se puede marcar como Recibido: falta registrar el Ingreso de Mercancía de esta orden antes de avanzar.',
                    'order' => null,
                ];
            }

            $updateData = ['status' => $next, 'status_changed_at' => now()];
            if ($next === PurchaseOrder::STATUS_RECIBIDO) {
                // `date` cast (no timestampTz) — mismo formato que el resto de este campo, ver casts().
                $updateData['actual_arrival_date'] = now()->toDateString();
            } elseif (isset(PurchaseOrder::STAGE_DATE_COLUMNS[$next])) {
                $updateData[PurchaseOrder::STAGE_DATE_COLUMNS[$next]] = now();
            }
            $order->update($updateData);

            if ($next === PurchaseOrder::STATUS_RECIBIDO) {
                $this->goodsReceiptConfirmation->confirmPendingForOrder($order, $user->id);
            }

            return ['status' => 200, 'message' => null, 'order' => $order];
        });

        if ($result['order'] === null) {
            return response()->json(['message' => $result['message'] ?? 'Not found'], $result['status']);
        }

        $order = $result['order'];

        if ($order->status === PurchaseOrder::STATUS_RECIBIDO) {
            // SCRUM-406 RN2 (ADR docs/adr/ADR-SCRUM406-compras-bodega-stock-bridge.md) — avisa a
            // Bodega para que libere las tarjetas "Sin stock" cuyo stock ya alcanza. product_ids
            // sale de las líneas de ESTA orden, no de los ingresos confirmados arriba (el
            // producto puede ya tener stock suficiente por otra vía). Disparado fuera de la
            // transacción de arriba, a propósito — el lock de `advance()` ya garantiza que este
            // bloque solo se alcanza una vez por transición real a "Recibido" (el `lockForUpdate`
            // serializa los requests concurrentes; el segundo en obtener el lock ya encuentra
            // `nextStatus() === null` y sale por la rama 422 de arriba, nunca llega acá).
            /** @var list<int> $productIds */
            $productIds = $order->lines()->pluck('catalog_product_id')->filter()->unique()->values()
                ->map(fn ($id) => (int) $id)->all();

            event(new PurchaseOrderReceived(
                purchaseOrderId: $order->id,
                productIds: $productIds,
                tenant: (string) Tenant::current()?->slug,
            ));
        }

        // 'payments' — REQ-140 (SCRUM-203): formatSummary() ahora lee $order->payments para
        // 'last_payment_date'; sin eager-load acá dispararía una lazy-load por mutación en vez de
        // reusar la ya cargada por index()/show().
        $order->load(['provider', 'creator', 'approvedByUser', 'liquidationAgency', 'lines.catalogProduct', 'lines.salesProject', 'payments']);

        return response()->json($this->formatDetail($order));
    }

    /**
     * SCRUM-208 (rediseño 2026-08-15, docs/architecture/scrum208-recepcion-parcial-rediseno.md) —
     * avanza el REMANENTE (`pending_remainder_status`) por sus propias etapas, independiente del
     * `status` de la orden completa. Antes de este endpoint, `pending_remainder_status` era
     * puramente informativo — nada lo avanzaba (root cause del reporte de Gerencia Test, "el botón
     * Completar etapa está clickeable pero no genera ninguna acción").
     *
     * Mismo patrón de lock/transacción que `advance()` — incluso si esto no compite por el mismo
     * recurso de negocio (avanzar el `status` real), reusar el patrón evita reintroducir la misma
     * condición de carrera que `advance()` ya tuvo que corregir (Pre-QA 2026-08-06).
     */
    public function advanceRemainder(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /**
         * @var array{status: int, message: string|null, order: PurchaseOrder|null} $result
         */
        $result = DB::transaction(function () use ($id): array {
            /** @var PurchaseOrder|null $order */
            $order = PurchaseOrder::with(['provider', 'creator', 'approvedByUser', 'liquidationAgency'])
                ->lockForUpdate()
                ->find($id);
            if ($order === null) {
                return ['status' => 404, 'message' => null, 'order' => null];
            }

            if ($order->pending_remainder_status === null) {
                return ['status' => 422, 'message' => 'Esta orden no tiene ningún remanente pendiente.', 'order' => null];
            }

            $next = $order->nextRemainderStatus();
            if ($next === null) {
                return [
                    'status' => 422,
                    'message' => 'El remanente ya está en su última etapa. Registrá el ingreso para cerrarlo.',
                    'order' => null,
                ];
            }

            $updateData = ['pending_remainder_status' => $next, 'remainder_status_changed_at' => now()];
            if (isset(PurchaseOrder::REMAINDER_STAGE_DATE_COLUMNS[$next])) {
                $updateData[PurchaseOrder::REMAINDER_STAGE_DATE_COLUMNS[$next]] = now();
            }
            $order->update($updateData);

            return ['status' => 200, 'message' => null, 'order' => $order];
        });

        if ($result['order'] === null) {
            return response()->json(['message' => $result['message'] ?? 'Not found'], $result['status']);
        }

        $order = $result['order'];
        $order->load(['provider', 'creator', 'approvedByUser', 'liquidationAgency', 'lines.catalogProduct', 'lines.salesProject', 'payments']);

        return response()->json($this->formatDetail($order));
    }

    /**
     * SCRUM-208 (rediseño 2026-08-15) — confirma a Inventario TODAS las líneas "Por ingresar" de
     * ESTA orden puntual, sin esperar a que el `status` de la orden llegue a "Recibido". La
     * capacidad de confirmar ya existía (`GoodsReceiptConfirmationService::confirmPendingForOrder()`,
     * usada por `advance()`), pero la única forma de dispararla manualmente era
     * `GoodsReceiptController::confirmPendingForProduct()` — acotado por PRODUCTO a través de
     * TODAS las órdenes, y solo alcanzable desde la pantalla genérica `/inventario`, desconectada
     * del contexto de la orden que Compras está mirando (root cause real del reporte de Gerencia
     * Test: la unidad ya recibida no aparecía en Inventario porque nadie la confirmaba desde acá,
     * no porque la plataforma no pudiera hacerlo).
     */
    public function confirmPendingReceipts(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var PurchaseOrder|null $order */
        $order = PurchaseOrder::with(['provider', 'creator', 'approvedByUser', 'liquidationAgency'])->find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $confirmedCount = $this->goodsReceiptConfirmation->confirmPendingForOrder($order, $user->id);
        if ($confirmedCount === 0) {
            return response()->json(['message' => 'Esta orden no tiene líneas "Por ingresar" pendientes de confirmar.'], 422);
        }

        $order->refresh();
        $order->load(['provider', 'creator', 'approvedByUser', 'liquidationAgency', 'lines.catalogProduct', 'lines.salesProject', 'payments']);

        return response()->json($this->formatDetail($order));
    }

    /** REQ-149/136 — PDF real (dompdf), 2 versiones vía `include_cost` (default true). */
    public function pdf(Request $request, int $id): Response|JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $includeCost = $request->query('include_cost', '1') !== '0';
        $lang = $this->resolveLang($request->query('lang'));
        if ($lang instanceof JsonResponse) {
            return $lang;
        }

        // SCRUM-1127 — `lang=en` nunca genera ni llama a la IA: sirve la versión guardada; si no existe o quedó desactualizada, 409 y el usuario decide.
        try {
            $pdf = $this->pdfService->generate($order, $includeCost, $lang);
        } catch (TranslationException $e) {
            return $this->translationError($e);
        }

        // SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría) — documento formal, incluye costo real
        // cuando include_cost=1.
        app(AuditService::class)->log(
            action: AuditService::COMPRAS_PURCHASE_ORDER_PDF_DOWNLOADED,
            entityType: 'purchase_order',
            entityId: $order->id,
            metadata: ['include_cost' => $includeCost, 'lang' => $lang],
        );

        return $pdf->stream($lang === 'en' ? "purchase-order-{$order->id}.pdf" : "orden-compra-{$order->id}.pdf");
    }

    /**
     * SCRUM-1127 — genera (o regenera) la versión en inglés de UNA orden, a pedido del usuario. Síncrono por lote, todo o nada. La traducción
     * queda guardada: ver/descargar/enviar después no llama a la IA. Si falla, 503 y el frontend ofrece el PDF en español.
     */
    public function translate(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $this->translations->generate($order, $user->id);
        } catch (TranslationException $e) {
            return $this->translationError($e);
        }

        return response()->json(['translations' => ['en' => $this->translations->status($order)]]);
    }

    /** @return 'es'|'en'|JsonResponse */
    private function resolveLang(mixed $value): string|JsonResponse
    {
        if ($value === null || $value === '' || $value === 'es') {
            return 'es';
        }

        return $value === 'en' ? 'en' : response()->json([
            'message' => 'Idioma no soportado.', 'errors' => ['lang' => ['Idioma no soportado.']],
        ], 422);
    }

    private function translationError(TranslationException $e): JsonResponse
    {
        $status = match ($e->reason) {
            'failed' => 503,
            'busy' => 409,
            default => 409, // missing | stale
        };

        return response()->json(['message' => $e->getMessage(), 'code' => 'translation_'.$e->reason, 'fallback_lang' => 'es'], $status);
    }

    /** REQ-136 — envío por correo al proveedor, PDF real adjunto (job encolado, no síncrono). */
    public function sendEmail(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::with(['provider', 'creator', 'approvedByUser', 'liquidationAgency'])->find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }
        if ($order->provider === null || $order->provider->email === null) {
            return response()->json(['message' => 'El proveedor no tiene un correo registrado.'], 422);
        }

        $includeCost = $request->boolean('include_cost', true);
        $lang = $this->resolveLang($request->input('lang'));
        if ($lang instanceof JsonResponse) {
            return $lang;
        }

        // SCRUM-1127 — un idioma por envío. En inglés la traducción debe existir y estar vigente ANTES de encolar: el job nunca traduce, así que
        // ningún reintento de Horizon cobra IA ni manda una versión vieja.
        if ($lang === 'en') {
            try {
                $this->translations->linesForRender($order);
            } catch (TranslationException $e) {
                return $this->translationError($e);
            }
        }

        SendPurchaseOrderEmailJob::dispatch($order->id, $includeCost, $lang);
        app(AuditService::class)->log(
            action: AuditService::COMPRAS_PURCHASE_ORDER_EMAIL_SENT,
            entityType: 'purchase_order',
            entityId: $order->id,
            metadata: ['include_cost' => $includeCost, 'lang' => $lang],
        );

        return response()->json(['message' => 'Envío en proceso.'], 202);
    }

    /**
     * REQ-156 — contenedor/naviera, editables desde la tarjeta de Logística mientras el envío
     * está en curso. A propósito NO restringido a status='por_aprobar' (a diferencia de
     * `update()`/REQ-144): un envío "En tránsito" es justo cuando estos datos empiezan a existir,
     * editar la orden completa ya está bloqueada en ese punto.
     *
     * REQ-153/154 (SCRUM-216, RN3/RN4 del comentario de Gerencia 2026-08-05) — `actual_arrival_date`
     * ("Llegada real") YA NO se acepta acá. El fix de SCRUM-216 del 2026-08-05 (commit f5b42a6) lo
     * había dejado editable a mano justificándolo con REQ-156, lectura incorrecta: el AC real de
     * REQ-156 es sobre auto-guardado de contenedor/naviera, nunca mencionó "Llegada real". Con
     * `advance()` ahora auto-llenando este campo al alcanzar "Recibido" (RN3: "nunca se escribe a
     * mano en ningún lado") y siendo inmutable una vez seteado (RN4), no existe ningún camino
     * legítimo para escribirlo desde el cliente.
     */
    public function updateShippingInfo(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $order = PurchaseOrder::find($id);
        if ($order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'container_number' => ['nullable', 'string', 'max:100'],
            'carrier' => ['nullable', 'string', 'max:255'],
        ]);

        $order->update($validated);
        // 'payments' — REQ-140 (SCRUM-203): formatSummary() ahora lee $order->payments para
        // 'last_payment_date'; sin eager-load acá dispararía una lazy-load por mutación en vez de
        // reusar la ya cargada por index()/show().
        $order->load(['provider', 'creator', 'approvedByUser', 'liquidationAgency', 'lines.catalogProduct', 'lines.salesProject', 'payments']);

        return response()->json($this->formatDetail($order));
    }

    /**
     * @param array{
     *   lines: list<array{
     *     catalog_product_id?: int|null,
     *     new_product?: array{reference: string, factory_reference?: string|null, name: string,
     *       description?: string|null, brand?: string|null, price_full: float, cost: float,
     *       reorder_point?: int|null, category?: string|null, rotation?: string|null}|null,
     *     quantity: int, unit_cost?: float|null, additional_cost_percent?: float|null,
     *     additional_cost_amount?: float|null, additional_cost_type?: string|null,
     *     sales_project_id?: int|null,
     *   }>, provider_id: int,
     * } $validated
     */
    private function replaceLines(PurchaseOrder $order, array $validated): void
    {
        $totalAmount = 0.0;

        // SCRUM-265: precalculado una sola vez para todos los productos existentes del payload,
        // no por línea dentro del loop — evita una query por línea en órdenes multi-producto.
        $existingProductIds = array_values(array_filter(array_map(
            static fn (array $line): ?int => $line['catalog_product_id'] ?? null,
            $validated['lines'],
        )));
        $porServirMap = $this->inventoryKpis->porServirMap($existingProductIds);
        $enExcluidaMap = $this->inventoryKpis->enBodegaExcluidaMap($existingProductIds);

        foreach ($validated['lines'] as $line) {
            if (isset($line['catalog_product_id'])) {
                $product = CatalogProduct::findOrFail($line['catalog_product_id']);
                $porServir = $porServirMap[$product->id] ?? 0;
                $enExcluida = $enExcluidaMap[$product->id] ?? 0;
            } else {
                $newProduct = $line['new_product'] ?? null;
                if ($newProduct === null) {
                    // StorePurchaseOrderRequest::withValidator() ya bloqueó esto con 422 —
                    // esta rama es inalcanzable en producción, solo satisface a PHPStan.
                    throw new \LogicException('Línea sin catalog_product_id ni new_product.');
                }
                $product = $this->createProduct($validated['provider_id'], $newProduct);
                $porServir = 0; // producto recién creado en este mismo request: no puede tener líneas previas
                $enExcluida = 0;
            }

            // REQ-130 Escenario 2 (SCRUM-193, hallazgo de Gerencia Test): el costo precargado del
            // catálogo es editable por línea (ej. precio renegociado para esta orden puntual) — si
            // el cliente no manda `unit_cost` (compatibilidad), sigue tomando el costo real del
            // producto, mismo criterio que antes.
            $unitCost = isset($line['unit_cost']) ? (float) $line['unit_cost'] : (float) $product->cost;
            $quantity = (int) $line['quantity'];
            $subtotal = $unitCost * $quantity;
            $totalAmount += $subtotal;

            // SCRUM-265 (REQ-202) — se captura EN ESTE MOMENTO, no se puede derivar después: el
            // estado de stock del producto cambia con el tiempo. update() borra y recrea las
            // líneas (ver caller), así que una edición mientras la orden sigue "Por aprobar"
            // vuelve a capturar el estado actual — se trata como "cuándo se comprometió esta
            // línea a la orden", no como un dato inmutable del primer guardado.
            PurchaseOrderLine::create([
                'purchase_order_id' => $order->id,
                'catalog_product_id' => $product->id,
                'quantity' => $quantity,
                'unit_cost' => $unitCost,
                'subtotal' => $subtotal,
                'additional_cost_percent' => $line['additional_cost_percent'] ?? null,
                // SCRUM-194 (REQ-131) — toggle $ monto / % del costo del mockup.
                'additional_cost_amount' => $line['additional_cost_amount'] ?? null,
                'additional_cost_type' => $line['additional_cost_type'] ?? null,
                'sales_project_id' => $line['sales_project_id'] ?? null,
                'stock_state_at_order' => $this->inventoryKpis->stockState($product, $porServir, $enExcluida),
            ]);
        }

        $order->update(['total_amount' => $totalAmount]);
    }

    /**
     * REQ-131: crea el producto en el Catálogo (Ventas & Diseño) Y la línea de esta orden al
     * mismo tiempo — la unicidad de referencias ya se validó en StorePurchaseOrderRequest (contra
     * BD y, desde SCRUM-194, también contra líneas hermanas del mismo request).
     *
     * @param array{reference: string, factory_reference?: string|null, name: string,
     *   description?: string|null, brand?: string|null, price_full: float, cost: float,
     *   reorder_point?: int|null, category?: string|null, rotation?: string|null} $newProduct
     */
    private function createProduct(int $providerId, array $newProduct): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => $newProduct['reference'],
            'factory_reference' => $newProduct['factory_reference'] ?? null,
            'name' => $newProduct['name'],
            'description' => $newProduct['description'] ?? '',
            'brand' => $newProduct['brand'] ?? null,
            // SCRUM-194 (REQ-131, hallazgo de Gerencia Test 2026-07-30) — mockup 2H.
            'category' => $newProduct['category'] ?? null,
            'rotation' => $newProduct['rotation'] ?? null,
            'price_full' => $newProduct['price_full'],
            'cost' => $newProduct['cost'],
            'reorder_point' => $newProduct['reorder_point'] ?? null,
            'provider_id' => $providerId,
            'is_active' => true,
        ]);
    }

    /**
     * @param  Collection<int, int>|null  $receivedByProduct  ver PurchaseOrder::receptionSummary()
     *                                                        — pasar el mapa precalculado en batch desde index() para no disparar 1 query extra por orden.
     * @return array<string, mixed>
     */
    private function formatSummary(PurchaseOrder $order, ?Collection $receivedByProduct = null): array
    {
        $projectSummary = $this->resolveProjectSummary($order);
        // REQ-145/158/160 — mismo cálculo consumido por Ver Órdenes y Logística (esta pantalla
        // reusa index()/formatSummary() con active_shipments=true, no hay controller separado).
        $reception = $order->receptionSummary($receivedByProduct);

        return [
            'id' => $order->id,
            'provider_id' => $order->provider_id,
            'provider_name' => $order->provider?->name,
            // REQ-154 (SCRUM-217) — el timeline visual necesita saber si este proveedor salta
            // aduana/tránsito internacional (Provider::ORIGIN_LOCAL) para dibujar la secuencia
            // reducida de 3 pasos en vez de la de 5 — mismo criterio que `statusSequence()`.
            'provider_origin' => $order->provider?->origin,
            'created_by_name' => $this->creatorName($order),
            // REQ-274 RN3 (Servicios, SCRUM-344/347 Grupo D parte 2) — Pre-QA 2026-08-13: la orden
            // ya nacía marcada `origin_module=servicios` en BD, pero nunca se exponía acá ni se
            // pintaba en el frontend — Lider Compras no tenía forma real de "diferenciarla de solicitudes
            // que vienen de Ventas & Diseño u otras áreas" (RN3 explícita del ticket) pese a que el
            // dato ya existía. `null` para el resto de órdenes (creadas a mano en Compras/Ventas &
            // Diseño) — ver `PurchaseOrder::ORIGIN_SERVICIOS`, único origen automático hoy.
            'origin_module' => $order->origin_module,
            'status' => $order->status,
            'next_status' => $order->nextStatus(),
            'is_critical' => $order->isCritical(),
            'modality' => $order->modality,
            // REQ-140 (SCRUM-203) — "Tipo de envío"/"Costo de envío" de la tabla principal: mismo
            // dato ya expuesto en formatDetail() para la tarjeta de Logística, faltaba en el
            // listado (columnas confirmadas ausentes vs. mockup 2A/Excel de requerimientos).
            'shipping_type' => $order->shipping_type,
            'shipping_cost' => $order->shipping_cost,
            'estimated_arrival_date' => $order->estimated_arrival_date?->toDateString(),
            'requires_primary_approval' => $order->requires_primary_approval,
            'blocked_by_primary_approval' => $order->isBlockedByPrimaryApproval(),
            'approved_by' => $order->approved_by,
            // SCRUM-206 — nombre real de quien aprobó, para que la UI no siga mostrando el
            // texto fijo "Aprobada por Gerencia" sin importar quién aprobó de verdad.
            'approved_by_name' => $this->approvedByName($order),
            'total_amount' => $order->total_amount,
            // SCRUM-1145 — costos adicionales + total a pagar al proveedor (total_amount NO los incluye).
            'import_cost' => $order->import_cost,
            'freight_cost' => $order->freight_cost,
            'handling_cost' => $order->handling_cost,
            'other_cost' => $order->other_cost,
            'additional_costs_total' => $order->additionalCostsTotal(),
            'payable_total' => $order->payableTotal(),
            'currency' => $order->currency,
            // REQ-187→190 (SCRUM-250→253) — Pagos a Proveedores.
            'payment_status' => $order->paymentStatus(),
            'paid_amount' => $order->paidAmount(),
            'payment_requested_at' => $order->payment_requested_at?->toIso8601String(),
            // REQ-140 (SCRUM-203) — "Fecha de pago" de la tabla principal: fecha del pago más
            // reciente ya registrado (payments viene ordenado por antigüedad ascendente, ver
            // PurchaseOrder::payments()) — null/"—" si la orden todavía no tiene ningún pago.
            // No existe un campo "fecha de pago" propio de la orden: se deriva del historial real
            // de purchase_order_payments, mismo dato que ya alimenta paidAmount()/paymentStatus().
            'last_payment_date' => $order->payments->last()?->created_at?->toDateString(),
            'sales_project_summary' => $projectSummary['summary'],
            'has_multiple_projects' => $projectSummary['has_multiple_projects'],
            'sales_project_count' => $projectSummary['project_count'],
            // REQ-140/145/158 — "Recepción" consolidada de la orden.
            'reception_status' => $reception['status'],
            // REQ-157 — enlace "Ingreso de mercancía" (Logística). SCRUM-861 punto 2: manda la
            // completitud real de la recepción, no solo la etapa/remanente — ver docblock del
            // método. Reusa el mismo `$receivedByProduct` ya calculado en batch para
            // `receptionSummary()` arriba, sin disparar una query extra por orden.
            //
            // `hasTrackableLines` — `isFullyReceived()` es `true` por vacuidad (Collection::every()
            // sobre colección vacía) cuando la orden no tiene ninguna línea con catalog_product_id
            // (fixture de test sin líneas, o único caso real: producto borrado tras la orden,
            // FK nullOnDelete). Ese vacío NO debe leerse como "ya se recibió todo" para efectos de
            // ocultar el enlace — solo tiene sentido esconderlo cuando de verdad había algo
            // esperado y se completó. No se toca `isFullyReceived()` en sí (fuente única
            // compartida con `advance()`/`eligibleOrders()`, cuyo propósito — nada que bloquee el
            // avance de estado — sí es correcto tratar "nada esperado" como satisfecho).
            'shows_goods_receipt_link' => $order->showsGoodsReceiptLink(
                $order->lines->contains(fn ($l) => $l->catalog_product_id !== null)
                    && $this->goodsReceiptConfirmation->isFullyReceived($order, $receivedByProduct)
            ),
            'pending_remainder_status' => $order->pending_remainder_status,
            // SCRUM-208 (rediseño 2026-08-15) — próxima etapa del remanente, análogo a
            // `next_status` de arriba pero para `advanceRemainder()`. El frontend (ya
            // implementado 2026-08-15, docs/architecture/scrum208-recepcion-parcial-rediseno.md)
            // asume este campo — no quitarlo sin coordinar ese lado.
            'next_remainder_status' => $order->nextRemainderStatus(),
            'created_at' => $order->created_at->toIso8601String(),
            'status_changed_at' => $order->status_changed_at->toIso8601String(),
            // REQ-154 (SCRUM-217) — 1 fecha por etapa ya alcanzada, para el timeline visual de
            // Logística (`PurchaseOrder::STAGE_DATE_COLUMNS`). NULL en cualquiera de las 4 para
            // una orden creada antes de este fix (2026-08-05) o, en el caso de
            // en_transito_at/en_aduana_at, para CUALQUIER orden de proveedor local (esas 2 etapas
            // no existen en su secuencia, ver `statusSequence()`) — el timeline debe tratar ambos
            // casos como "sin dato", nunca asumir que null es un bug.
            'ordenado_at' => $order->ordenado_at?->toDateString(),
            'en_transito_at' => $order->en_transito_at?->toDateString(),
            'en_aduana_at' => $order->en_aduana_at?->toDateString(),
            'en_transito_local_at' => $order->en_transito_local_at?->toDateString(),
            // Etapa terminal — reusa esta columna ya existente en vez de una 5ta STAGE_DATE_COLUMN
            // (ver docblock de la migración). Ya estaba expuesta en formatDetail(); se sube acá
            // para que Logística (que renderiza el timeline desde el PurchaseOrderSummary de la
            // lista, no desde el detalle) no dependa de un 2do round-trip solo para esta fecha.
            'actual_arrival_date' => $order->actual_arrival_date?->toDateString(),
        ];
    }

    /**
     * REQ-140/146 (SCRUM-203/209, hallazgo de reconciliación 2026-07-16 —
     * docs/specs/compras-proveedores-sprint2.md#9): resume el/los proyecto(s) asignado(s) a las
     * líneas de la orden para la tabla principal de Ver Órdenes. Se deriva siempre de
     * `lines.sales_project_id` — `purchase_orders` NO tiene columna `sales_project_id` propia (ver
     * docblock de la migración `create_compras_purchase_orders_table`), la asignación real vive
     * por línea (REQ-133). Null/0 cuando ninguna línea tiene proyecto asignado — el frontend
     * decide cómo mostrar ese caso, acá no se hardcodea ningún string tipo "Sin proyecto".
     *
     * @return array{summary: string|null, has_multiple_projects: bool, project_count: int}
     */
    private function resolveProjectSummary(PurchaseOrder $order): array
    {
        $distinctProjects = $order->lines
            ->filter(fn (PurchaseOrderLine $line): bool => $line->sales_project_id !== null)
            ->unique('sales_project_id');

        $count = $distinctProjects->count();

        return [
            'summary' => $count === 1 ? $distinctProjects->first()?->salesProject?->name : null,
            'has_multiple_projects' => $count > 1,
            'project_count' => $count,
        ];
    }

    /** @return array<string, mixed> */
    private function formatDetail(PurchaseOrder $order): array
    {
        // REQ-145 — Recepción por línea (no solo la consolidada de formatSummary()). 1 sola query
        // de recibido, reusada acá y pasada a formatSummary() para no duplicarla.
        $received = $order->receivedQuantitiesByProduct();
        $receptionByLine = collect($order->receptionSummary($received)['lines'])->keyBy('catalog_product_id');

        return [
            // shipping_type/shipping_cost ya vienen de formatSummary() (REQ-140/SCRUM-203) — no
            // repetirlos acá.
            ...$this->formatSummary($order, $received),
            'who_pays_shipping' => $order->who_pays_shipping,
            // SCRUM-210 (REQ-147, alcance reducido) — solo relevante para órdenes Zona Libre.
            'liquidation_agency_id' => $order->liquidation_agency_id,
            'liquidation_agency_name' => $order->liquidationAgency?->name,
            // actual_arrival_date ya viene de formatSummary() (REQ-154, arriba) — no repetirlo acá.
            'container_number' => $order->container_number,
            'carrier' => $order->carrier,
            'approved_at' => $order->approved_at?->toIso8601String(),
            'pending_amount_change' => $order->pending_amount_change,
            'amount_change_requested_by' => $order->amount_change_requested_by,
            'notes' => $order->notes,
            // SCRUM-1127 — versión en inglés guardada de la orden (la muestra el detalle para ofrecer ver/descargar/regenerar).
            'translations' => ['en' => $this->translations->status($order)],
            'lines' => $order->lines->map(function (PurchaseOrderLine $line) use ($receptionByLine): array {
                /** @var array{received: int, status: string}|null $reception */
                $reception = $receptionByLine[$line->catalog_product_id] ?? null;

                return [
                    'id' => $line->id,
                    'catalog_product_id' => $line->catalog_product_id,
                    'reference' => $line->catalogProduct?->reference,
                    'factory_reference' => $line->catalogProduct?->factory_reference,
                    'description' => $line->catalogProduct?->name,
                    'quantity' => $line->quantity,
                    'unit_cost' => $line->unit_cost,
                    'subtotal' => $line->subtotal,
                    'additional_cost_percent' => $line->additional_cost_percent,
                    // SCRUM-194 (REQ-131) — toggle $ monto / % del costo del mockup.
                    'additional_cost_amount' => $line->additional_cost_amount,
                    'additional_cost_type' => $line->additional_cost_type,
                    'sales_project_id' => $line->sales_project_id,
                    'sales_project_name' => $line->salesProject?->name,
                    'received_quantity' => $reception['received'] ?? 0,
                    'reception_status' => $reception['status'] ?? PurchaseOrder::RECEPTION_PENDIENTE,
                ];
            })->values(),
        ];
    }

    private function creatorName(PurchaseOrder $order): ?string
    {
        /** @var User|null $creator */
        $creator = $order->creator;

        return $creator !== null ? trim("{$creator->first_name} {$creator->last_name}") : null;
    }

    private function approvedByName(PurchaseOrder $order): ?string
    {
        /** @var User|null $approver */
        $approver = $order->approvedByUser;

        return $approver !== null ? trim("{$approver->first_name} {$approver->last_name}") : null;
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
