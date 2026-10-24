<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Servicios\Exceptions\TicketAttachmentLimitExceededException;
use App\Modules\Servicios\Exceptions\TicketCloseBlockedException;
use App\Modules\Servicios\Http\Requests\CancelTicketRequest;
use App\Modules\Servicios\Http\Requests\ScheduleTicketRequest;
use App\Modules\Servicios\Http\Requests\StoreTicketRequest;
use App\Modules\Servicios\Http\Requests\UpdateTicketRequest;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Models\TicketAttachment;
use App\Modules\Servicios\Models\TicketProduct;
use App\Modules\Servicios\Services\ServiciosSettingsService;
use App\Modules\Servicios\Services\TicketAttachmentService;
use App\Modules\Servicios\Services\TicketPdfService;
use App\Modules\Servicios\Services\TicketService;
use App\Shared\Services\AuditService;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Validation\Rule;

/**
 * Fase 4 — Servicios, Batch 1 (REQ-216→221, REQ-249).
 */
final class TicketController extends Controller
{
    // REQ-248 — "fotos, videos o archivos de referencia". Extensiones propias de este endpoint
    // (no reutiliza CRM\DocumentController::ALLOWED_MIMES porque ese set no incluye video).
    public const ALLOWED_ATTACHMENT_EXTENSIONS = [
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'heic',
        'mp4', 'mov', 'avi', 'webm',
        'pdf', 'doc', 'docx',
    ];

    public function __construct(
        private readonly TicketService $tickets,
        private readonly TicketPdfService $pdf,
        private readonly TicketAttachmentService $attachments,
        private readonly ServiciosSettingsService $settings,
    ) {}

    /** GET /api/servicios/tickets — REQ-216/REQ-217, vista Tabla (paginada). */
    public function index(Request $request): JsonResponse
    {
        $filters = $this->filtersFromRequest($request);
        $filters['per_page'] = (int) $request->query('per_page', 20);

        return response()->json($this->tickets->list($filters));
    }

    /** GET /api/servicios/tickets/board — REQ-221, vista Tablero (sin paginar). */
    public function board(Request $request): JsonResponse
    {
        return response()->json($this->tickets->board($this->filtersFromRequest($request)));
    }

    /** GET /api/servicios/tickets/stats — REQ-222, 4 tarjetas de estadísticas (nunca filtradas). */
    public function stats(): JsonResponse
    {
        return response()->json($this->tickets->stats());
    }

    /** GET /api/servicios/tickets/{id} — REQ-224, modal de detalle. */
    public function show(int $id): JsonResponse
    {
        $ticket = $this->tickets->find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->tickets->detail($ticket));
    }

    /** PATCH /api/servicios/tickets/{id} — REQ-225, edición global (RN1: solo lider_servicios/superadmin, ver ruta). */
    public function update(UpdateTicketRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{tipo: string, subtipo: ?string, tipo_instalacion: string, requerimientos_especiales: ?array{catalog?: list<string>, otros?: list<string>}} $validated */
        $validated = $request->validated();

        $result = $this->tickets->update($ticket, $validated, $user);

        return response()->json($this->tickets->detail($result['ticket']) + [
            'technician_unassigned' => $result['technician_unassigned'],
        ]);
    }

    /**
     * POST /api/servicios/tickets/{id}/products — SCRUM-781 (punto 1, REQ-225 RN2). Agregar un
     * producto reclamado/afectado desde la edición del ticket. Mismo rol que update() (ver la ruta).
     */
    public function storeProduct(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{catalog_product_id: int, cantidad_reclamo: int} $validated */
        $validated = $request->validate([
            'catalog_product_id' => ['required', 'integer', Rule::exists('catalog_products', 'id')],
            'cantidad_reclamo'   => ['required', 'integer', 'min:1'],
        ]);

        $this->tickets->addProduct($ticket, $validated['catalog_product_id'], $validated['cantidad_reclamo']);

        return response()->json($this->tickets->detail($ticket->refresh()), 201);
    }

    /**
     * PATCH /api/servicios/tickets/{id}/products/{productId} — SCRUM-781 (punto 1). Editar la
     * cantidad reclamada de una fila ya existente. Mismo rol que update() (ver la ruta).
     */
    public function updateProduct(Request $request, int $id, int $productId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $product = TicketProduct::where('ticket_id', $id)->find($productId);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{cantidad_reclamo: int} $validated */
        $validated = $request->validate([
            'cantidad_reclamo' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $this->tickets->updateProductQuantity($product, $validated['cantidad_reclamo']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->tickets->detail($ticket->refresh()));
    }

    /**
     * DELETE /api/servicios/tickets/{id}/products/{productId} — SCRUM-781 (punto 1). Quitar una
     * fila. Mismo rol que update() (ver la ruta).
     */
    public function destroyProduct(Request $request, int $id, int $productId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $product = TicketProduct::where('ticket_id', $id)->find($productId);
        if ($product === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $this->tickets->removeProduct($product);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->tickets->detail($ticket->refresh()));
    }

    /** PATCH /api/servicios/tickets/{id}/agendar — REQ-226, Agendar/Reagendar (RN1: solo lider_servicios/superadmin, ver ruta). */
    public function schedule(ScheduleTicketRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{internal_technician_id: int, scheduled_at: string, scheduled_ends_at?: string|null, motivo_reagendamiento?: string|null} $validated */
        $validated = $request->validated();

        try {
            $ticket = $this->tickets->schedule($ticket, $validated, $user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->tickets->detail($ticket));
    }

    /** POST /api/servicios/tickets — REQ-245/246/247/249, formulario completo de Nuevo ticket. */
    public function store(StoreTicketRequest $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var array{
         *   tipo: string, subtipo?: string|null, tipo_instalacion: string, descripcion: string,
         *   sales_project_id: int|null, contacto?: string|null,
         *   telefono?: string|null, email?: string|null, direccion?: string|null,
         *   requerimientos_especiales?: array{catalog?: list<string>, otros?: list<string>}|null,
         *   productos?: list<array{catalog_product_id: int, cantidad_reclamo: int}>,
         * } $validated
         */
        $validated = $request->validated();

        $ticket = $this->tickets->create($validated, $user);

        return response()->json($this->tickets->detail($ticket), 201);
    }

    /**
     * POST /api/servicios/tickets/{id}/attachments — REQ-248, sube una foto/video/archivo de
     * referencia. RN2: máximo N archivos por ticket (configurable, ver ServiciosSettingsService)
     * — el conteo se verifica dentro de `TicketAttachmentService::upload()`, con el ticket
     * bloqueado, no acá (ver docblock del servicio). Mismo criterio de rol que crear el ticket
     * (ver la ruta).
     */
    public function storeAttachment(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $maxKb      = $this->settings->adjuntosMaxSizeMb() * 1024;
        $allowedExt = implode(',', TicketController::ALLOWED_ATTACHMENT_EXTENSIONS);

        $validated = $request->validate([
            // extensions (client filename), no mimes: — mismo motivo que
            // CRM\DocumentController::store() (rvt/fbx no resuelven bien vía sniffing de MIME).
            'file' => "required|file|max:{$maxKb}|extensions:{$allowedExt}",
        ]);

        /** @var \Illuminate\Http\UploadedFile $file */
        $file = $validated['file'];

        try {
            $attachment = $this->attachments->upload($file, $ticket, $user->id, $this->settings->adjuntosMaxArchivos());
        } catch (CircuitOpenException) {
            return response()->json([
                'message' => 'Storage service temporarily unavailable. Please try again in a few minutes.',
            ], 503);
        } catch (TicketAttachmentLimitExceededException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'id'             => $attachment->id,
            'nombre_archivo' => $attachment->nombre_archivo,
            'size_bytes'     => $attachment->size_bytes,
            'mime_type'      => $attachment->mime_type,
            'created_at'     => $attachment->created_at->toIso8601String(),
        ], 201);
    }

    /** GET /api/servicios/tickets/attachments/{attachmentId}/url — REQ-248, URL firmada de 15 min. */
    public function attachmentUrl(int $attachmentId): JsonResponse
    {
        $attachment = TicketAttachment::find($attachmentId);
        if ($attachment === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json([
            'url'      => $this->attachments->presignedUrl($attachment),
            'filename' => $attachment->nombre_archivo,
        ]);
    }

    /**
     * PATCH /api/servicios/tickets/{id}/estado — REQ-218/REQ-221 RN3, select inline o arrastre en
     * el tablero (misma validación). RN7 — solo `lider_servicios`/superadmin llegan acá, ver
     * `role:superadmin,lider_servicios` en `routes/servicios.php`.
     */
    public function updateStage(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{estado: string} $validated */
        $validated = $request->validate([
            'estado' => ['required', 'string', Rule::in(Ticket::ESTADOS)],
        ]);

        try {
            $ticket = $this->tickets->changeStage($ticket, $validated['estado'], $user);
        } catch (TicketCloseBlockedException $e) {
            return response()->json(['message' => $e->getMessage(), 'errors' => $e->errors()], $e->status());
        }

        return response()->json($ticket);
    }

    /** GET /api/servicios/tickets/{id}/pdf — REQ-228, RN2: mismo permiso que ver el detalle. */
    public function pdf(int $id): Response
    {
        $ticket = Ticket::find($id);
        if ($ticket === null) {
            abort(404);
        }

        $detail = $this->tickets->detail($ticket);
        $pdf    = $this->pdf->generate($ticket, $detail);

        // SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría) — documento formal con datos reales
        // del cliente, no un GET de solo lectura del recurso.
        app(AuditService::class)->log(
            action:     AuditService::SERVICIOS_TICKET_PDF_DOWNLOADED,
            entityType: 'ticket',
            entityId:   $ticket->id,
        );

        return $pdf->download("Ticket-{$ticket->numero}.pdf");
    }

    /**
     * PATCH /api/servicios/tickets/{id}/cancelar — REQ-227 (RN1: solo lider_servicios/superadmin,
     * ver `role:` en la ruta).
     */
    public function cancel(CancelTicketRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{motivo: string} $validated */
        $validated = $request->validated();

        try {
            $ticket = $this->tickets->cancel($ticket, $validated['motivo'], $user);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->tickets->detail($ticket));
    }

    /** @return array{search?: string, tipo?: string, tecnico_id?: int|string, estado?: string} */
    private function filtersFromRequest(Request $request): array
    {
        $filters = [];

        $search = $request->query('search');
        if (is_string($search) && trim($search) !== '') {
            $filters['search'] = $search;
        }

        $tipo = $request->query('tipo');
        if (is_string($tipo) && $tipo !== '') {
            $filters['tipo'] = $tipo;
        }

        $estado = $request->query('estado');
        if (is_string($estado) && $estado !== '') {
            $filters['estado'] = $estado;
        }

        $tecnicoId = $request->query('tecnico_id');
        if (is_string($tecnicoId) && $tecnicoId !== '') {
            $filters['tecnico_id'] = $tecnicoId;
        }

        return $filters;
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
