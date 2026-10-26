<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Http\Controllers;

use App\Models\User;
use App\Modules\AdminCont\Services\CreditNotePdfService;
use App\Modules\AdminCont\Services\CreditNoteService;
use App\Modules\Bodega\Exceptions\CustomerReturnException;
use App\Modules\Bodega\Models\CustomerReturn;
use App\Modules\Bodega\Models\CustomerReturnLine;
use App\Modules\Bodega\Models\Order;
use App\Modules\Bodega\Services\CustomerReturnDocumentService;
use App\Modules\Bodega\Services\CustomerReturnService;
use App\Modules\Bodega\Services\OrderDocumentService;
use App\Shared\Http\Pagination;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;
use App\Modules\Bodega\Models\Warehouse;

/**
 * SCRUM-473→489 (REQ-403→419, Bloque B6 "Devoluciones") — ver
 * ADR-SCRUM473-489-devoluciones.md. 10 endpoints: bandeja, buscador de guía de entrega origen,
 * creación, detalle (historial reconstruido), carga de documento firmado, formulario PDF, guía
 * de entrega original (reusa `OrderDocumentService`), confirmar recepción física, rechazar,
 * finalizar (simulado).
 */
final class CustomerReturnController extends Controller
{
    public function __construct(
        private readonly CustomerReturnService $returns,
        private readonly CustomerReturnDocumentService $documents,
        private readonly OrderDocumentService $orderDocuments,
        private readonly CreditNoteService $creditNotes,
        private readonly CreditNotePdfService $creditNotePdf,
    ) {}

    /** GET /api/bodega/returns — bandeja, 5 chips de filtro por status. */
    public function index(Request $request): JsonResponse
    {
        $query = CustomerReturn::query()->with(['order.pipelineCard.salesProject', 'destinationWarehouse', 'lines']);

        $status = $request->query('status');
        if (is_string($status) && $status !== '' && $status !== 'todas') {
            $query->where('status', $status);
        }

        $pagination = Pagination::params($request, ['created_at'], 'created_at', 'desc');
        $paginator = $query
            ->orderBy('created_at', $pagination['sort_dir'])
            ->orderBy('id', $pagination['sort_dir'])
            ->paginate($pagination['per_page'], ['*'], 'page', $pagination['page']);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (CustomerReturn $r) => $this->formatRow($r))->values(),
            'meta' => Pagination::meta($paginator),
        ]);
    }

    /**
     * GET /api/bodega/returns/search-orders?q= — REQ-414/415: único punto de partida de una
     * devolución. Cada resultado ya trae cliente/proyecto/vendedor/productos realmente
     * entregados (REQ-415), para que el frontend autocomplete sin una segunda llamada.
     */
    public function searchOrders(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('q', ''));

        $orders = $this->returns->searchOrders($search);

        return response()->json([
            'data' => $orders->map(fn (Order $order) => $this->returns->orderDetail($order))->values(),
        ]);
    }

    /** POST /api/bodega/returns — REQ-419. */
    public function store(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $validated = $request->validate([
            'order_id'                     => ['required', 'integer', 'exists:orders,id'],
            'contact_name'                 => ['required', 'string', 'max:255'],
            'contact_phone'                => ['required', 'string', 'max:50'],
            'items'                        => ['required', 'array', 'min:1'],
            'items.*.order_item_id'        => ['required', 'integer'],
            'items.*.qty_requested'        => ['required', 'integer', 'min:1'],
            'items.*.reason'               => ['required', 'string', Rule::in(CustomerReturnLine::REASONS)],
            'items.*.reason_detail'        => ['nullable', 'string'],
        ]);

        try {
            /** @var array{order_id: int, contact_name: string, contact_phone: string, items: list<array{order_item_id: int, qty_requested: int, reason: string, reason_detail?: string|null}>} $validated */
            $customerReturn = $this->returns->create($validated, $user);
        } catch (CustomerReturnException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json(['id' => $customerReturn->id, 'return_number' => $customerReturn->return_number], 201);
    }

    /** GET /api/bodega/returns/{id} — detalle (REQ-475/405), historial cronológico completo. */
    public function show(int $id): JsonResponse
    {
        $customerReturn = CustomerReturn::with(['order.pipelineCard.salesProject', 'destinationWarehouse', 'lines.orderItem.catalogProduct'])->find($id);
        if ($customerReturn === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->formatDetail($customerReturn));
    }

    /** POST /api/bodega/returns/{id}/signed-document — REQ-408 (multipart). */
    public function uploadSignedDocument(Request $request, int $id): JsonResponse
    {
        $customerReturn = CustomerReturn::find($id);
        if ($customerReturn === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'file' => ['required', 'file', 'max:20480', 'extensions:jpg,jpeg,png,pdf'],
        ]);

        $customerReturn = $this->returns->uploadSignedDocument($customerReturn, $validated['file']);

        return response()->json(['id' => $customerReturn->id, 'signed_document_uploaded_at' => $customerReturn->signed_document_uploaded_at?->toIso8601String()]);
    }

    /**
     * GET /api/bodega/returns/{id}/signed-document — SCRUM-478 (rebote 2026-08-16). A diferencia
     * de `form()`/`deliveryGuide()`, acá no se genera nada: el archivo ya fue subido por
     * `uploadSignedDocument()`, solo se presigna la key existente en `signed_document_path`.
     */
    public function signedDocumentUrl(int $id): JsonResponse
    {
        $customerReturn = CustomerReturn::find($id);
        if ($customerReturn === null || $customerReturn->signed_document_path === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json(['url' => $this->documents->presignUrl($customerReturn->signed_document_path)]);
    }

    /**
     * GET /api/bodega/returns/{id}/credit-note-pdf — SCRUM-865 punto 2, dirección inversa del
     * mismo problema que `signedDocumentUrl()` de AdminCont resuelve para el sentido contrario:
     * una vez Finalizada, la fila de Devoluciones necesita "Ver nota de crédito", pero un usuario
     * de Bodega no tiene (ni debería necesitar) `admin_contab.*` para verla. Gateado por
     * `permission:bodega.read` (mismo permiso que el resto de este controller), no por el permiso
     * de Notas de Crédito.
     */
    public function creditNotePdf(int $id): \Illuminate\Http\Response|JsonResponse
    {
        $customerReturn = CustomerReturn::find($id);
        if ($customerReturn === null || $customerReturn->credit_note_id === null) {
            return response()->json(['message' => 'Esta devolución no tiene nota de crédito generada.'], 404);
        }

        $detail = $this->creditNotes->show($customerReturn->credit_note_id);
        if ($detail === null) {
            return response()->json(['message' => 'Nota de crédito no encontrada.'], 404);
        }

        return $this->creditNotePdf->generate($detail)->stream("nota-credito-{$detail['numero']}.pdf");
    }

    /** POST /api/bodega/returns/{id}/form — REQ-406 (PDF nuevo). Genera y sube el PDF a S3 en cada llamada (objeto nuevo cada vez); sin cuerpo. SCRUM-1180: era GET. */
    public function form(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $customerReturn = CustomerReturn::find($id);
        if ($customerReturn === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $key = $this->documents->generateFormulario($customerReturn, $user);

        return response()->json(['url' => $this->documents->presignUrl($key)]);
    }

    /** POST /api/bodega/returns/{id}/delivery-guide — REQ-407: reusa `OrderDocumentService` tal cual (cada llamada crea un documento y un objeto S3 nuevos); sin cuerpo. SCRUM-1180: era GET. */
    public function deliveryGuide(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $customerReturn = CustomerReturn::with('order')->find($id);
        if ($customerReturn === null || $customerReturn->order === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $document = $this->orderDocuments->generateGuiaEntrega($customerReturn->order, $user);

        return response()->json(['url' => $this->orderDocuments->presignUrl($document->storage_key)]);
    }

    /** POST /api/bodega/returns/{id}/confirm-reception — REQ-409/410. */
    public function confirmReception(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $customerReturn = CustomerReturn::find($id);
        if ($customerReturn === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate([
            'destination_warehouse_id'          => ['required', 'integer', Warehouse::existsRule()],
            'lines'                              => ['required', 'array', 'min:1'],
            'lines.*.customer_return_line_id'   => ['required', 'integer'],
            'lines.*.qty_received'               => ['required', 'integer', 'min:0'],
        ]);

        try {
            /** @var list<array{customer_return_line_id: int, qty_received: int}> $lines */
            $lines = $validated['lines'];
            $customerReturn = $this->returns->confirmReception($customerReturn, $lines, (int) $validated['destination_warehouse_id'], $user);
        } catch (CustomerReturnException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json($this->formatDetail($customerReturn));
    }

    /** POST /api/bodega/returns/{id}/reject — REQ-411, potestad exclusiva de Bodega. */
    public function reject(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $customerReturn = CustomerReturn::find($id);
        if ($customerReturn === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $validated = $request->validate(['rejection_reason' => ['required', 'string', 'min:1']]);

        try {
            $customerReturn = $this->returns->reject($customerReturn, (string) $validated['rejection_reason'], $user);
        } catch (CustomerReturnException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json($this->formatDetail($customerReturn));
    }

    /** @return array<string, mixed> */
    private function formatRow(CustomerReturn $customerReturn): array
    {
        return [
            'id'                     => $customerReturn->id,
            'return_number'          => $customerReturn->return_number,
            'order_id'               => $customerReturn->order_id,
            'order_number'           => $customerReturn->order->order_number,
            'customer_name'          => $customerReturn->order->customer_name,
            'project'                => $customerReturn->order->pipelineCard?->salesProject?->name,
            'status'                 => $customerReturn->status,
            'has_signed_document'    => $customerReturn->hasSignedDocument(),
            'contact_name'           => $customerReturn->contact_name,
            'contact_phone'          => $customerReturn->contact_phone,
            'destination_warehouse'  => $customerReturn->destinationWarehouse?->name,
            'created_at'             => $customerReturn->created_at->toIso8601String(),
            'received_at'            => $customerReturn->received_at?->toIso8601String(),
            'finalized_at'           => $customerReturn->finalized_at?->toIso8601String(),
            // SCRUM-865 punto 2 — habilita el enlace "Ver nota de crédito" una vez Finalizada.
            'credit_note_id'         => $customerReturn->credit_note_id,
            'rejected_at'            => $customerReturn->rejected_at?->toIso8601String(),
            'products'               => $customerReturn->lines->map(fn (CustomerReturnLine $l) => $this->formatLine($l))->values(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatDetail(CustomerReturn $customerReturn): array
    {
        return [
            ...$this->formatRow($customerReturn),
            'rejection_reason' => $customerReturn->rejection_reason,
            'history'          => $this->returns->historyPayload($customerReturn),
        ];
    }

    /** @return array<string, mixed> */
    private function formatLine(CustomerReturnLine $line): array
    {
        return [
            'id'            => $line->id,
            'order_item_id' => $line->order_item_id,
            'reference'     => $line->reference_snapshot,
            'description'   => $line->description_snapshot,
            'qty_requested' => $line->qty_requested,
            'qty_received'  => $line->qty_received,
            'reason'        => $line->reasonCode(),
            'reason_detail' => $line->reasonDetail(),
        ];
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
