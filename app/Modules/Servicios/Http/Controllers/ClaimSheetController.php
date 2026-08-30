<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Servicios\Exceptions\ClaimSheetAccessDeniedException;
use App\Modules\Servicios\Exceptions\ClaimSheetLockedException;
use App\Modules\Servicios\Http\Requests\SaveClaimSheetRequest;
use App\Modules\Servicios\Models\ClaimSheet;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\ClaimSheetPdfService;
use App\Modules\Servicios\Services\ClaimSheetService;
use App\Modules\Servicios\Services\TicketService;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Fase 4 — Servicios, Batch 9 (REQ-278/279). Anidado bajo /servicios/tickets/{ticketId}/claim-sheet.
 */
final class ClaimSheetController extends Controller
{
    public function __construct(
        private readonly ClaimSheetService $sheets,
        private readonly ClaimSheetPdfService $pdf,
        private readonly TicketService $tickets,
    ) {}

    /**
     * GET /api/servicios/tickets/{ticketId}/claim-sheet — REQ-278, ver la hoja (o precarga vacía
     * si no existe todavía). Secciones 1-2 (reclamante/producto) siempre vienen del ticket, nunca
     * se capturan a mano — ver docblock de la migración de `claim_sheets`.
     */
    public function show(int $ticketId): JsonResponse
    {
        $ticket = $this->tickets->find($ticketId);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->sheets->isApplicable($ticket)) {
            return response()->json(['message' => 'Este tipo de ticket no genera Hoja de Reclamo.'], 422);
        }

        $sheet = $this->sheets->findByTicket($ticket);

        return response()->json($this->serialize($ticket, $sheet));
    }

    /**
     * PUT /api/servicios/tickets/{ticketId}/claim-sheet — REQ-278, "Guardar Hoja de Reclamo".
     * RN5: solo Aaron/Líder de Servicios o el técnico interno asignado; RN5 además bloquea
     * cualquier guardado si la hoja ya está Completada (`ClaimSheetLockedException`).
     */
    public function save(SaveClaimSheetRequest $request, int $ticketId): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = $this->tickets->find($ticketId);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->sheets->isApplicable($ticket)) {
            return response()->json(['message' => 'Este tipo de ticket no genera Hoja de Reclamo.'], 422);
        }

        /** @var array{descripcion_problema?: ?string, fecha_reclamo?: ?string, diagnostico: string, firma_responsable: string} $validated */
        $validated = $request->validated();

        try {
            $sheet = $this->sheets->save($ticket, $validated, $user);
        } catch (ClaimSheetAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ClaimSheetLockedException $e) {
            return response()->json(['message' => $e->getMessage()], 409);
        }

        return response()->json($this->serialize($ticket, $sheet));
    }

    /**
     * GET /api/servicios/tickets/{ticketId}/claim-sheet/pdf — REQ-279. Solo disponible con la
     * hoja ya Completada (RN2) — antes de eso no hay documento formal que emitir.
     */
    public function pdf(int $ticketId): Response
    {
        $ticket = $this->tickets->find($ticketId);
        if ($ticket === null) {
            abort(404);
        }

        $sheet = $this->sheets->findByTicket($ticket);
        if ($sheet === null || $sheet->estado !== ClaimSheet::ESTADO_COMPLETED) {
            abort(404);
        }

        $detail = $this->tickets->detail($ticket);

        // SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría) — documento formal con datos reales
        // del cliente, no un GET de solo lectura del recurso.
        app(AuditService::class)->log(
            action:     AuditService::SERVICIOS_CLAIM_SHEET_PDF_DOWNLOADED,
            entityType: 'claim_sheet',
            entityId:   $sheet->id,
        );

        return $this->pdf->generate($sheet, $ticket, $detail)->download("Hoja-Reclamo-Ticket-{$ticket->numero}.pdf");
    }

    /** @return array<string, mixed> */
    private function serialize(Ticket $ticket, ?ClaimSheet $sheet): array
    {
        $detail = $this->tickets->detail($ticket);
        $estado = $sheet !== null ? $sheet->estado : ClaimSheet::ESTADO_PENDING;

        return [
            'ticket_id'             => $ticket->id,
            'reclamante'            => [
                'cliente_master' => $detail['cliente_master'],
                'subcliente'     => $detail['subcliente'],
                'contacto'       => $detail['contacto'],
                'telefono'       => $detail['telefono'],
                'email'          => $detail['email'],
                'direccion'      => $detail['direccion'],
                // Visual Review (2026-08-11, Batch 9) — el mockup (Sección 1 "Datos del
                // reclamante") también muestra Proyecto y Responsable (el técnico interno
                // asignado al ticket, ver `t.tecnico` en `buildHojaReclamoFormHtml`/
                // `printHojaReclamo` del mockup) — faltaban acá pese a que ambos ya vienen
                // eager-loaded en `$ticket` (TicketService::find()).
                'proyecto'       => $detail['proyecto'],
                'responsable'    => $ticket->internalTechnician !== null
                    ? "{$ticket->internalTechnician->first_name} {$ticket->internalTechnician->last_name}"
                    : null,
            ],
            'productos'             => $detail['productos'],
            'id'                    => $sheet?->id,
            'descripcion_problema'  => $sheet?->descripcion_problema,
            'fecha_reclamo'         => $sheet?->fecha_reclamo?->toDateString(),
            'diagnostico'           => $sheet?->diagnostico,
            'firma_responsable'     => $sheet?->firma_responsable,
            'estado'                => $estado,
            'created_at'            => $sheet?->created_at?->toIso8601String(),
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
