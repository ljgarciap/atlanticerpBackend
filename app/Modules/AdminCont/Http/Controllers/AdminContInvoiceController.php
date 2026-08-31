<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Exceptions\InvoiceStateException;
use App\Modules\AdminCont\Http\Requests\InvoiceOrderIdsRequest;
use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Modules\AdminCont\Services\InvoiceExportService;
use App\Modules\AdminCont\Services\InvoicePdfService;
use App\Modules\AdminCont\Services\InvoiceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Batch 2 de Facturación (SCRUM-513→518, REQ-436→441) + Batch 3 (SCRUM-519→523, REQ-442→446) +
 * Batch 4 (SCRUM-524→528, REQ-447→451), último de esta área de la épica Admin&Cont.
 */
class AdminContInvoiceController
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly InvoiceExportService $export,
        private readonly InvoicePdfService $invoicePdf,
    ) {}

    /** GET /api/admin-contab/invoices/summary — REQ-437, nunca se filtra por tab/vista/filtros (RN4). */
    public function summary(): JsonResponse
    {
        return response()->json($this->invoices->summary());
    }

    /** GET /api/admin-contab/invoices — REQ-438/439, tabs cobrable/incobrable + vista agrupada/plana.
     *  Batch 3 (REQ-445) — agrega passthrough completo de filtros + `total_en_tab` (RN4, denominador
     *  de "Mostrando X de Y", sin ningún otro filtro aplicado). */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filtersFromRequest($request);
        $view = $filters['view'];
        $result = $this->invoices->list($filters);
        $totalEnTab = $this->invoices->countInTab($filters['tab']);

        return response()->json($view === 'plana'
            ? ['rows' => $result, 'total_en_tab' => $totalEnTab]
            : ['groups' => $result, 'total_en_tab' => $totalEnTab]);
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

    /** @return array{tab: string, view: string, cliente: ?string, proyecto: ?string, estado: ?string, desde: ?string, hasta: ?string, search: ?string} */
    private function filtersFromRequest(Request $request): array
    {
        return [
            'tab' => $request->string('tab', 'cobrable')->toString(),
            'view' => $request->string('view', 'agrupado')->toString(),
            'cliente' => $request->filled('cliente') ? $request->string('cliente')->toString() : null,
            'proyecto' => $request->filled('proyecto') ? $request->string('proyecto')->toString() : null,
            'estado' => $request->filled('estado') ? $request->string('estado')->toString() : null,
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

    /** POST /api/admin-contab/invoices/preview — REQ-441 Escenarios 1/2, REQ-442/443 (saldo a
     *  favor + desglose fiscal). */
    public function preview(InvoiceOrderIdsRequest $request): JsonResponse
    {
        return response()->json($this->invoices->previewBatch(
            $request->validated('order_ids'),
            $request->aplicarSaldoFavor(),
        ));
    }

    /** POST /api/admin-contab/invoices — REQ-441 Escenario 3, confirma 1..N facturas de una vez.
     *  REQ-442 — aplica saldo a favor solo sobre la primera entrega del lote. */
    public function store(InvoiceOrderIdsRequest $request): JsonResponse
    {
        $actor = $this->resolveUser($request);
        if ($actor === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $result = $this->invoices->confirmBatch(
            $request->validated('order_ids'),
            $actor,
            $request->aplicarSaldoFavor(),
        );

        if ($result['invoices'] === [] && $result['errors'] !== []) {
            return response()->json(['message' => 'No se pudo generar ninguna factura.', 'errors' => $result['errors']], 422);
        }

        return response()->json($result, 201);
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
        $detail = $this->invoices->detail($order);

        /** @var array{sub?: int, permissions?: mixed}|null $payload */
        $payload = $request->attributes->get('jwt');

        /** @var list<string> $permissions */
        $permissions = is_array($payload['permissions'] ?? null)
            ? array_values(array_filter($payload['permissions'], 'is_string'))
            : [];

        // superadmin.all bypassa el gate de Mark (decisión de Luis, 2026-08-22) — mismo criterio
        // que EnsureIsPrimaryApprover, que ya lo aplica sobre la acción real de decidir.
        $primaryApproverId = AdminContFiscalSettings::current()->primary_approver_user_id;
        $isMark = in_array('superadmin.all', $permissions, true)
            || ($payload !== null && isset($payload['sub']) && $primaryApproverId !== null
                && (int) $payload['sub'] === $primaryApproverId);

        $detail['puede_decidir_incobrable'] = $detail['cobrabilidad'] === 'pendiente_aprobacion' && $isMark;

        return response()->json($detail);
    }

    /** GET /api/admin-contab/invoices/{order}/pdf — REQ-449, documento fiscal real. */
    public function pdf(int $order): Response
    {
        $detail = $this->invoices->detail($order);
        $numero = $detail['numero_factura'] ?? "orden-{$order}";

        return $this->invoicePdf->generate($detail)->stream("factura-{$numero}.pdf");
    }

    /** POST /api/admin-contab/invoices/{order}/mark-uncollectible — REQ-448, propone (Felix/Mark/Gerencia). */
    public function markUncollectible(int $order, Request $request): JsonResponse
    {
        $request->validate(['motivo' => ['required', 'string', 'max:1000']]);

        try {
            $invoice = $this->invoices->proposeUncollectible($order, $request->string('motivo')->toString());
        } catch (InvoiceStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($invoice);
    }

    /** PUT /api/admin-contab/invoices/{order}/uncollectible-decision — REQ-448, exclusivo de Mark (primary_approver_only). */
    public function uncollectibleDecision(int $order, Request $request): JsonResponse
    {
        $request->validate(['approve' => ['required', 'boolean']]);

        try {
            $invoice = $this->invoices->decideUncollectible($order, $request->boolean('approve'));
        } catch (InvoiceStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($invoice);
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
