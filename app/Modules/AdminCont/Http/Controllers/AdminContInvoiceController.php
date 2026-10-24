<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\InvoiceStateException;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalCertificationService;
use App\Modules\AdminCont\FacturacionElectronica\Services\FiscalDocumentPreviewService;
use App\Modules\AdminCont\Http\Requests\InvoiceOrderIdsRequest;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\AdminCont\Services\InvoiceExportService;
use App\Modules\AdminCont\Services\InvoicePdfService;
use App\Modules\AdminCont\Services\InvoiceService;
use App\Modules\VentasDiseno\Support\ReceptorTipo;
use App\Shared\Http\Pagination;
use App\Shared\Services\AuditService;
use App\Shared\Support\ParsesIdListQueryParam;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch 2 de Facturación (SCRUM-513→518, REQ-436→441) + Batch 3 (SCRUM-519→523, REQ-442→446) +
 * Batch 4 (SCRUM-524→528, REQ-447→451), último de esta área de la épica Admin&Cont.
 */
class AdminContInvoiceController
{
    use ParsesIdListQueryParam;

    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceExportService $export,
        private readonly InvoicePdfService $invoicePdf,
        private readonly FiscalDocumentPreviewService $documentPreview,
        private readonly FiscalCertificationService $certification,
    ) {}

    /** GET /api/admin-contab/invoices/sellers — SCRUM-1175, opciones del filtro «Vendedor» (solo responsables de pedidos facturables). */
    public function sellers(): JsonResponse
    {
        return response()->json(['data' => $this->invoices->vendedoresDisponibles()]);
    }

    /** GET /api/admin-contab/invoices/summary — REQ-437, nunca se filtra por tab/vista/filtros (RN4). */
    public function summary(): JsonResponse
    {
        return response()->json($this->invoices->summary());
    }

    /**
     * GET /api/admin-contab/invoices — REQ-438/439, tabs cobrable/incobrable + vista agrupada/plana.
     * Batch 3 (REQ-445) — agrega passthrough completo de filtros + `total_en_tab` (RN4, denominador
     * de "Mostrando X de Y", sin ningún otro filtro aplicado).
     *
     * SCRUM-947 — paginación estándar SOLO en `plana`: es la única vista que consume el frontend
     * hoy (`FacturacionPage.tsx` arma el agrupado por proyecto ella misma sobre la lista plana, ver
     * su comentario "el fetch siempre trae la lista plana") — `agrupado` queda sin paginar, sin
     * consumidor real que lo necesite. `export()` sigue llamando a `list()` directo (sin pasar por
     * acá), nunca paginado — un export debe traer todo lo que matchea el filtro.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filtersFromRequest($request);
        $view = $filters['view'];
        $result = $this->invoices->list([...$filters, 'con_saldo_favor' => true]);
        $totalEnTab = $this->invoices->countInTab($filters['tab']);

        if ($view !== 'plana') {
            return response()->json(['groups' => $result, 'total_en_tab' => $totalEnTab]);
        }

        $pagination = Pagination::params($request, [], 'id', 'desc');
        $paginator = Pagination::ofArray(array_values($result), $pagination['per_page'], $pagination['page']);

        return response()->json([
            'rows' => $paginator->items(),
            'total_en_tab' => $totalEnTab,
            'meta' => Pagination::meta($paginator),
        ]);
    }

    /** GET /api/admin-contab/invoices/export?format=pdf|excel — REQ-446, mismos filtros que index(). */
    public function export(Request $request): StreamedResponse|JsonResponse
    {
        $format = $request->string('format')->toString();
        if (! in_array($format, ['pdf', 'excel'], true)) {
            return response()->json(['message' => 'format debe ser "pdf" o "excel".'], 422);
        }

        $filters = $this->filtersFromRequest($request);
        $rows = $this->invoices->list(['tab' => $filters['tab'], 'view' => 'plana'] + $filters);

        $bytes = $format === 'pdf' ? $this->export->toPdf($rows) : $this->export->toExcel($rows);
        $filename = 'facturacion-'.now()->format('Ymd-His').($format === 'pdf' ? '.pdf' : '.xlsx');
        $mime = $format === 'pdf'
            ? 'application/pdf'
            : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

        return response()->streamDownload(fn () => print $bytes, $filename, ['Content-Type' => $mime]);
    }

    /** @return array{tab: string, view: string, cliente: ?string, proyecto: ?string, vendedor_ids: list<int>, estado: ?string, cobro: ?string, desde: ?string, hasta: ?string, search: ?string} */
    private function filtersFromRequest(Request $request): array
    {
        return [
            'tab' => $request->string('tab', 'cobrable')->toString(),
            'view' => $request->string('view', 'agrupado')->toString(),
            'cliente' => $request->filled('cliente') ? $request->string('cliente')->toString() : null,
            'proyecto' => $request->filled('proyecto') ? $request->string('proyecto')->toString() : null,
            // SCRUM-1175 — ids de vendedor; el pedido coincide si cualquiera de sus responsables está entre ellos (vacío = todos).
            'vendedor_ids' => $this->parseIdListParam($request, 'vendedor_ids'),
            'estado' => $request->filled('estado') ? $request->string('estado')->toString() : null,
            // SCRUM-1147 — solo `cobradas` | `por_cobrar`; cualquier otro valor se ignora (= todas).
            'cobro' => match ($request->string('cobro')->toString()) {
                'cobradas' => 'cobrada',
                'por_cobrar' => 'por_cobrar',
                default => null,
            },
            'desde' => $request->filled('desde') ? $request->string('desde')->toString() : null,
            'hasta' => $request->filled('hasta') ? $request->string('hasta')->toString() : null,
            'search' => $request->filled('search') ? $request->string('search')->toString() : null,
        ];
    }

    /** GET /api/admin-contab/invoices/pending?sales_project_id= — paso 3 del wizard de "+ Crear Factura" (REQ-436). */
    public function pending(Request $request): JsonResponse
    {
        $request->validate(['sales_project_id' => ['required', 'integer']]);

        return response()->json($this->invoices->pendingForProject((int) $request->integer('sales_project_id')));
    }

    /** POST /api/admin-contab/invoices/preview — REQ-441 Escenarios 1/2 (desglose fiscal). Ya no
     *  incluye saldo a favor (retirado en SCRUM-902, ver `InvoiceOrderIdsRequest`). SCRUM-929 —
     *  acepta `modo: 'entrega'|'completo'`, ver ADR-SCRUM929 §4. */
    public function preview(InvoiceOrderIdsRequest $request): JsonResponse
    {
        return response()->json($this->invoices->previewBatch($request->validated('order_ids'), $this->modoFromRequest($request)));
    }

    /** POST /api/admin-contab/invoices — REQ-441 Escenario 3, confirma 1..N facturas de una vez
     *  (SCRUM-929: cada una de una entrega o de un pedido completo, según `modo`).
     *  SCRUM-902 — ya no aplica saldo a favor acá (ver `InvoiceOrderIdsRequest`); cada factura se
     *  genera por su monto completo y los abonos de nivel Proyecto se re-asocian sin modificarla. */
    public function store(InvoiceOrderIdsRequest $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        // SCRUM-1140 — facturar a Consumidor Final por decisión de quien factura exige el permiso angosto.
        /** @var list<int> $consumidorFinal */
        $consumidorFinal = array_values(array_map('intval', (array) ($request->validated('consumidor_final_order_ids') ?? [])));
        if ($consumidorFinal !== [] && ! $this->puedeFacturarAConsumidorFinal($request)) {
            app(AuditService::class)->log(
                action: AuditService::SECURITY_ACCESS_DENIED,
                entityType: 'route',
                entityId: $request->path(),
                metadata: ['required_permissions' => [AdminContInvoiceReceptorController::PERMISO_CONSUMIDOR_FINAL]],
            );

            return response()->json(['message' => 'No tienes permiso para facturar a Consumidor final.'], 403);
        }

        $result = $this->invoices->confirmBatch($request->validated('order_ids'), $actor, $this->modoFromRequest($request), $consumidorFinal);

        if ($result['invoices'] === [] && $result['errors'] !== []) {
            return response()->json(['message' => 'No se pudo generar ninguna factura.', 'errors' => $result['errors']], 422);
        }

        return response()->json($result, 201);
    }

    /** SCRUM-1140 — `superadmin.all` bypassa, igual que `CheckPermission`. */
    private function puedeFacturarAConsumidorFinal(Request $request): bool
    {
        /** @var array{permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');
        $permissions = is_array($payload) && is_array($payload['permissions'] ?? null) ? $payload['permissions'] : [];

        return in_array('superadmin.all', $permissions, true)
            || in_array(AdminContInvoiceReceptorController::PERMISO_CONSUMIDOR_FINAL, $permissions, true);
    }

    /** SCRUM-929 — `?invoice_id=` opcional en detail/pdf: la raíz puede tener a la vez su factura
     *  parcial y el `completo`, y `order_id` solo no distingue cuál. */
    private function invoiceIdFromRequest(Request $request): ?int
    {
        $request->validate(['invoice_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);

        return $request->filled('invoice_id') ? $request->integer('invoice_id') : null;
    }

    /** SCRUM-929 — `modo` opcional; ausente = `completo` (compatibilidad con el cliente anterior). */
    private function modoFromRequest(InvoiceOrderIdsRequest $request): string
    {
        return $request->validated('modo') ?? InvoiceService::MODO_COMPLETO;
    }

    /** GET /api/admin-contab/invoices/aging — REQ-450, panel "Antigüedad de cartera". */
    public function aging(): JsonResponse
    {
        return response()->json($this->invoices->agingBuckets());
    }

    /** GET /api/admin-contab/invoices/{order}/detail — REQ-447, modal de detalle/trazabilidad.
     *  `puede_decidir_incobrable` (REQ-448) es dependiente del actor, no de la factura — se calcula
     *  acá en vez de en el service, ya que este endpoint es de acceso general (Felix/Mark/Gerencia),
     *  no gateado por `primary_approver_only`; el frontend lo usa para mostrar Aprobar/Rechazar solo a Mark. */
    public function detail(int $order, Request $request): JsonResponse
    {
        $detail = $this->invoices->detail($order, $this->invoiceIdFromRequest($request));

        /** @var array{sub?: int, permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');

        // superadmin.all bypassa el gate de Mark (decisión de Luis, 2026-08-22) — mismo criterio
        // que EnsureIsPrimaryApprover, que ya lo aplica sobre la acción real de decidir.
        $isMark = AdminContFiscalSettings::actorEsMarkOSuperadmin($payload);

        $detail['puede_decidir_incobrable'] = $detail['cobrabilidad'] === 'pendiente_aprobacion' && $isMark;
        // El texto del rechazo del PAC es información de Configuración Fiscal: solo Mark/superadmin.
        // Se agrega acá (deny-by-default) y no en `InvoiceService::detail()`, que también sirven los
        // controllers de Bodega sin este gate.
        $detail['puede_ver_fe_transmisiones'] = $isMark;
        $facturaMark = $isMark && isset($detail['invoice_id']) ? AdminContInvoice::query()->whereKey($detail['invoice_id'])->first() : null;
        $detail['fe_detalle'] = $facturaMark?->fe_detalle;
        // SCRUM-1007 — 'reintentar' | 'actualizar_estado' | null; solo Mark/superadmin y solo si el documento lo admite.
        $detail['accion_fe'] = $facturaMark !== null ? $this->certification->accionDisponible($facturaMark, 'factura') : null;
        // SCRUM-1019 — "Reconciliar con MSL" disponible (rechazado/error_configuracion/pendiente); mismo gate Mark/superadmin.
        $detail['puede_reconciliar_fe'] = $facturaMark !== null && $this->certification->reconciliacionDisponible($facturaMark, 'factura');

        return response()->json($detail);
    }

    /**
     * GET /api/admin-contab/invoices/{order}/fe-preview — SCRUM-943 (pedido de Luis, 2026-09-25):
     * validar que la ESTRUCTURA del documento que se armaría para el PAC es correcta, sin
     * certificar nada — útil sobre todo en `pac_ambiente: apagado`, pero no gateado por eso
     * (sirve igual para depurar sandbox/producción). Nunca llama a MSL — solo ensambla y mapea.
     */
    public function fePreview(int $order, Request $request): JsonResponse
    {
        $invoice = $this->invoices->resolveInvoice($order, $this->invoiceIdFromRequest($request));
        if ($invoice === null) {
            return response()->json(['message' => 'No hay una factura generada para esta entrega.'], 404);
        }

        return response()->json($this->documentPreview->paraFactura($invoice));
    }

    /** GET /api/admin-contab/invoices/{order}/pdf — REQ-449, documento fiscal real.
     *  SCRUM-952 — bloquea la generación (no genera un PDF a medias) si falta el RUC del cliente o
     *  el vendedor de la cotización, ambos ahora impresos en el documento. */
    public function pdf(int $order, Request $request): Response
    {
        $detail = $this->invoices->detail($order, $this->invoiceIdFromRequest($request));

        $faltantes = [];
        // SCRUM-1003 — un Consumidor Final no tiene RUC: no se exige.
        if (($detail['cliente_receptor_tipo'] ?? null) !== ReceptorTipo::CONSUMIDOR_FINAL && trim((string) ($detail['cliente_ruc'] ?? '')) === '') {
            $faltantes[] = 'el RUC del cliente';
        }
        if (trim((string) ($detail['vendedor'] ?? '')) === '') {
            $faltantes[] = 'el vendedor asignado a la cotización';
        }
        if ($faltantes !== []) {
            return response()->json([
                'message' => 'No se puede generar la factura: falta '.implode(' y ', $faltantes).'.',
            ], 422);
        }

        $numero = $detail['numero_factura'] ?? "orden-{$order}";

        return $this->invoicePdf->generate($detail)->stream("factura-{$numero}.pdf");
    }

    /** POST /api/admin-contab/invoices/{order}/mark-uncollectible — REQ-448, propone (Felix/Mark/Gerencia).
     *  SCRUM-929 (B3a) — `invoice_id` opcional: obligatorio (422) si el pedido tiene varias facturas activas;
     *  una factura de otro pedido → 404. Ver ADR-SCRUM929 §4.8. */
    public function markUncollectible(int $order, Request $request): JsonResponse
    {
        $request->validate(['motivo' => ['required', 'string', 'max:1000'], 'invoice_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);

        try {
            $invoice = $this->invoices->proposeUncollectible($order, $request->string('motivo')->toString(), $this->bodyInvoiceId($request));
        } catch (InvoiceStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($invoice);
    }

    /** PUT /api/admin-contab/invoices/{order}/uncollectible-decision — REQ-448, exclusivo de Mark (primary_approver_only).
     *  SCRUM-929 (B3a) — mismo criterio de `invoice_id` que `markUncollectible()`. */
    public function uncollectibleDecision(int $order, Request $request): JsonResponse
    {
        $request->validate(['approve' => ['required', 'boolean'], 'invoice_id' => ['sometimes', 'nullable', 'integer', 'min:1']]);

        try {
            $invoice = $this->invoices->decideUncollectible($order, $request->boolean('approve'), $this->bodyInvoiceId($request));
        } catch (InvoiceStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($invoice);
    }

    /** `invoice_id` del cuerpo (ya validado); vacío = ausente. */
    private function bodyInvoiceId(Request $request): ?int
    {
        return $request->filled('invoice_id') ? $request->integer('invoice_id') : null;
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
