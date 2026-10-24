<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Http\Controllers;

use App\Models\User;
use App\Modules\Servicios\Exceptions\ServiceQuoteAccessDeniedException;
use App\Modules\Servicios\Exceptions\ServiceQuoteActionBlockedException;
use App\Modules\Servicios\Http\Requests\DecideServiceQuoteRequest;
use App\Modules\Servicios\Http\Requests\SaveServiceQuoteRequest;
use App\Modules\Servicios\Models\ServiceQuote;
use App\Modules\Servicios\Models\ServiceQuoteItem;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\ServiceQuotePdfService;
use App\Modules\Servicios\Services\ServiceQuoteService;
use App\Modules\Servicios\Services\ServiciosSettingsService;
use App\Modules\Servicios\Services\TicketService;
use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;

/**
 * Fase 4 — Servicios, Batch 11 (REQ-229/233/234) — Cotización de Servicio: formulario, totales,
 * ciclo de vida. Ítems (REQ-230/231/232) viven en `ServiceQuoteItemController`.
 *
 * Batch 12 (REQ-235/236/237/250, SCRUM-298/299/300/313) — documento formal (`document()`),
 * historial por ticket (`history()`) e historial global transversal (`globalIndex()`).
 */
final class ServiceQuoteController extends Controller
{
    private const COST_BREAKDOWN_PERMISSION = 'servicios.quotes.view_cost_breakdown';

    public function __construct(
        private readonly ServiceQuoteService $quotes,
        private readonly ServiciosSettingsService $settings,
        private readonly ServiceQuotePdfService $pdf,
        private readonly TicketService $tickets,
    ) {}

    /** GET /api/servicios/tickets/{id}/quote — REQ-229, precarga + cotización más reciente (si existe). */
    public function show(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        return response()->json($this->detail($ticket, $user));
    }

    /** POST /api/servicios/tickets/{id}/quote — REQ-229 RN2 / REQ-234, "Generar (nueva) cotización". */
    public function store(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $this->quotes->startNew($ticket, $user);
        } catch (ServiceQuoteAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ServiceQuoteActionBlockedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->detail($ticket->refresh(), $user), 201);
    }

    /** PUT /api/servicios/tickets/{id}/quote — REQ-233, "Guardar" sobre el Borrador (descuento/observaciones). */
    public function save(SaveServiceQuoteRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $quote = $this->quotes->current($ticket);
        if ($quote === null) {
            return response()->json(['message' => 'Este ticket todavía no tiene una cotización generada.'], 404);
        }

        try {
            $this->quotes->save($quote, $request->validated(), $user);
        } catch (ServiceQuoteAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ServiceQuoteActionBlockedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->detail($ticket, $user));
    }

    /** PATCH /api/servicios/tickets/{id}/quote/enviar — REQ-234, único disparador de Borrador -> Enviada. */
    public function send(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $quote = $this->quotes->current($ticket);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $this->quotes->send($quote, $user);
        } catch (ServiceQuoteAccessDeniedException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (ServiceQuoteActionBlockedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->detail($ticket, $user));
    }

    /** PATCH /api/servicios/tickets/{id}/quote/decidir — REQ-234, Aprobar/Rechazar (role: en la ruta). */
    public function decide(DecideServiceQuoteRequest $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);
        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $quote = $this->quotes->current($ticket);
        if ($quote === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        /** @var array{estado: string} $validated */
        $validated = $request->validated();

        try {
            $this->quotes->decide($quote, $validated['estado'], $user);
        } catch (ServiceQuoteActionBlockedException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json($this->detail($ticket, $user));
    }

    /**
     * GET /api/servicios/tickets/{id}/quotes — REQ-236, historial completo del ticket (TODAS las
     * versiones, más reciente primero). Cada fila trae lo mínimo para la lista; abrir una versión
     * puntual en modo solo lectura usa `document()`/`show()` con su propio `quoteId` — acá no se
     * cargan ítems para no sobre-pedir en un listado.
     */
    public function history(Request $request, int $id): JsonResponse
    {
        $ticket = Ticket::find($id);
        if ($ticket === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $quotes = $this->quotes->historyForTicket($ticket)->map(fn (ServiceQuote $q): array => [
            'id'         => $q->id,
            'numero'     => $q->numero,
            'estado'     => $q->estado,
            'total'      => $q->total,
            'created_at' => $q->created_at->toIso8601String(),
            'sent_at'    => $q->sent_at?->toIso8601String(),
            'decided_at' => $q->decided_at?->toIso8601String(),
        ])->values()->all();

        return response()->json(['data' => $quotes]);
    }

    /**
     * GET /api/servicios/tickets/{id}/quote/{quoteId}/document — REQ-235, documento formal
     * "Ver/Imprimir" de una versión puntual (draft/sent/approved/rejected, cualquiera del historial
     * — REQ-236 abre cada fila con este mismo endpoint). `permission:servicios.read`, sin gate de
     * `servicios.quotes.view_cost_breakdown`: el PDF nunca expone costo/margen, ver docblock de
     * `ServiceQuotePdfService`.
     */
    public function document(int $id, int $quoteId): Response
    {
        $ticket = Ticket::find($id);
        if ($ticket === null) {
            abort(404);
        }

        $quote = $this->quotes->findForTicket($ticket, $quoteId);
        // SCRUM-292 — un borrador sin número todavía (nadie hizo un guardado real, ver
        // ServiceQuoteService::ensureFolioAssigned()) no tiene nada real que imprimir — se trata
        // igual que "no existe", mismo criterio que historyForTicket()/globalHistory().
        if ($quote === null || $quote->numero === null) {
            abort(404);
        }

        $detail = $this->tickets->detail($ticket);

        // SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría) — documento formal con datos reales
        // del cliente, no un GET de solo lectura del recurso.
        app(AuditService::class)->log(
            action:     AuditService::SERVICIOS_SERVICE_QUOTE_PDF_DOWNLOADED,
            entityType: 'service_quote',
            entityId:   $quote->id,
        );

        return $this->pdf->generate($quote, $ticket, $detail)
            ->download("Cotizacion-{$quote->numero}.pdf");
    }

    /**
     * GET /api/servicios/quotes — REQ-250, historial global transversal (todo el equipo, TODAS las
     * versiones incluidas las reemplazadas). `?estado=draft|sent|approved|rejected` filtra la
     * lista; `counts` siempre refleja el total sin filtrar (mismo criterio que REQ-222 RN5).
     */
    public function globalIndex(Request $request): JsonResponse
    {
        $estado = $request->query('estado');
        if ($estado !== null && ! in_array($estado, ServiceQuote::ESTADOS, true)) {
            return response()->json(['message' => 'Estado inválido.'], 422);
        }

        return response()->json($this->quotes->globalHistory($estado));
    }

    /** @return array<string, mixed> */
    private function detail(Ticket $ticket, User $user): array
    {
        $quote            = $this->quotes->current($ticket);
        $canEdit          = $this->userCanEdit($ticket, $user);
        $canViewCosts     = $user->hasPermission(self::COST_BREAKDOWN_PERMISSION);
        $canDecideRole    = in_array($user->role?->key, ['superadmin', 'lider_servicios'], true);

        return [
            'ticket_id'               => $ticket->id,
            'notes'                   => $this->quotes->notes($ticket),
            'min_margin_percent'      => PricingSettings::current()->min_margin_percent,
            'itbms_percent'           => $this->settings->itbmsPercent(),
            'can_view_cost_breakdown' => $canViewCosts,
            'can_generate'            => $canEdit && ($quote === null || $quote->estado === ServiceQuote::ESTADO_REJECTED),
            // REQ-237 — valor VIGENTE de Condiciones, para precarga/preview antes de enviar (nunca
            // se persiste hasta `send()`, ver docblock de la migración de `conditions`).
            'conditions_preview'     => $this->settings->conditionsCotizacionServicios(),
            'quote'                   => $quote === null ? null : [
                'id'                => $quote->id,
                'numero'            => $quote->numero,
                'estado'            => $quote->estado,
                'subtotal'          => $quote->subtotal,
                'discount_percent'  => $quote->discount_percent,
                'itbms'             => $quote->itbms,
                'total'             => $quote->total,
                'observations'      => $quote->observations,
                // `null` mientras está en Borrador (todavía no se congeló) — el frontend usa
                // `conditions_preview` (arriba) hasta que exista este valor.
                'conditions'        => $quote->conditions,
                'sent_at'           => $quote->sent_at?->toIso8601String(),
                'decided_at'        => $quote->decided_at?->toIso8601String(),
                'created_at'        => $quote->created_at->toIso8601String(),
                'can_edit'          => $canEdit && $quote->estado === ServiceQuote::ESTADO_DRAFT,
                'can_send'          => $canEdit && $quote->estado === ServiceQuote::ESTADO_DRAFT,
                'can_decide'        => $canDecideRole && $quote->estado === ServiceQuote::ESTADO_SENT,
                'cliente'           => $ticket->cliente,
                'contacto'          => $ticket->contacto,
                'telefono'          => $ticket->telefono,
                'direccion'         => $ticket->direccion,
                'items'             => $quote->items->map(fn (ServiceQuoteItem $item) => $this->formatItem($item, $canViewCosts))->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function formatItem(ServiceQuoteItem $item, bool $canViewCosts): array
    {
        $data = [
            'id'                      => $item->id,
            'tipo'                    => $item->tipo,
            'catalog_product_id'      => $item->catalog_product_id,
            'is_custom'               => $item->is_custom,
            'external_technician_id'  => $item->external_technician_id,
            'description'             => $item->description,
            'quantity'                => $item->quantity,
            'unit_price'              => $item->unit_price,
            // REQ-232 — desglose de costo real/margen SOLO para Lider Servicios/Mark/David/Whil
            // (`servicios.quotes.view_cost_breakdown`). `margin_percent` va gateado igual que
            // `cost_reference` (abajo): con `unit_price` ya visible para todos, exponer el margen
            // sin el gate permite recalcular `cost_reference` (tarifa_dia = unit_price / (1 +
            // margen/100)) — sería un bypass trivial del mismo gate que oculta `cost_reference`
            // dos líneas más abajo. Hallazgo de Senior Review, corregido en la misma revisión.
            'margin_percent'          => ($item->tipo === ServiceQuoteItem::TIPO_SUBCONTRACTED && $canViewCosts) ? $item->margin_percent : null,
            'subtotal'                => $item->subtotal,
        ];

        if ($canViewCosts) {
            $data['cost_reference'] = $item->cost_reference;
        }

        return $data;
    }

    private function userCanEdit(Ticket $ticket, User $user): bool
    {
        try {
            $this->quotes->assertCanEdit($ticket, $user);
            return true;
        } catch (ServiceQuoteAccessDeniedException) {
            return false;
        }
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
