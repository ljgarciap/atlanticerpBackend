<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\ServiceQuote;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Models\TicketProduct;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Fase 4 — Servicios, Batch 15 (REQ-207/208/210/211) — pantalla "Inicio" del módulo Servicios.
 * REQ-206 (saludo dinámico, SCRUM-269) quedó fuera de este batch a propósito (fuera de alcance
 * explícito), cerrado en un batch aparte. REQ-209 (Mi calendario, SCRUM-272) quedó fuera de este
 * batch asumiendo que la integración con Outlook/Office 365 no estaba confirmada con el cliente —
 * esa asunción era incorrecta (la integración real vía Microsoft Graph ya existía desde
 * SCRUM-66/177, en uso en Ventas & Diseño/Compras/Bodega); implementado 2026-08-13/14 reusando el
 * mismo `OutlookCalendarController` genérico — ver `routes/servicios.php` prefix `calendar` y
 * `ServiciosMyCalendarPanel.tsx` en el frontend. No vive en este servicio porque no necesita
 * ningún dato propio de Servicios (a diferencia de `rutas_dia`, que sí cruza con `Ticket`).
 *
 * Mismo patrón que `BodegaHomeService`/`ComprasHomeService`: un único `summary()` con secciones
 * anidadas, consumido por `HomeController::summary()`.
 *
 * Grupo C — Home (REQ-212/213/215, SCRUM-275/276/278) — 2 secciones nuevas dentro de `summary()`
 * ("sin_responder", "insumos_pendientes") + `estadoTickets()`, consumido por un endpoint aparte
 * (`GET /servicios/home/estado-tickets`, no por `summary()`) porque acepta un query param `tipo`
 * que las demás secciones no necesitan.
 */
final class HomeService
{
    private const DEFAULT_ROUTES_LIMIT = 5;

    /** Mismas etiquetas en español que `TicketPdfService::TIPO_LABELS` — duplicado a propósito,
     *  mismo criterio documentado ahí (el backend no tiene infraestructura de i18n de dominio). */
    private const TIPO_LABELS = [
        Ticket::TIPO_INSTALLATION => 'Instalación',
        Ticket::TIPO_WARRANTY     => 'Garantía',
        Ticket::TIPO_CLAIM        => 'Reclamo',
        Ticket::TIPO_RETROFIT     => 'Retrofit',
    ];

    public function __construct(
        private readonly InternalTechnicianService $technicians,
        private readonly ServiciosSettingsService $settings,
        private readonly InstallationGoalService $goals,
        private readonly TicketService $tickets,
    ) {}

    /** @return array<string, mixed> */
    public function summary(): array
    {
        return [
            'rutas_dia'          => $this->rutasDia(),
            'pendientes'         => $this->pendientes(),
            'indicadores_mes'    => $this->indicadoresMes(),
            'sin_responder'      => $this->sinResponder(),
            'insumos_pendientes' => $this->insumosPendientes(),
        ];
    }

    /**
     * REQ-208 RN2 — máximo 5 visitas (las más próximas por hora), con `has_more`/`total` para que
     * el frontend muestre "Ver agenda completa" cuando corresponda.
     *
     * @return array{visitas: list<array<string, mixed>>, total: int, has_more: bool}
     */
    private function rutasDia(): array
    {
        $all = $this->technicians->todaysRoutes();

        return [
            'visitas'  => array_slice($all, 0, self::DEFAULT_ROUTES_LIMIT),
            'total'    => count($all),
            'has_more' => count($all) > self::DEFAULT_ROUTES_LIMIT,
        ];
    }

    /**
     * REQ-210 — tarjetas generadas automáticamente. RN2: "Ticket sin agendar" (umbral configurable,
     * MISMO que `TicketService::stats()`, nunca un umbral paralelo). RN3: "Repuesto sin llegar"
     * depende de un vínculo ticket↔orden de compra/cotización que todavía no existe en este
     * worktree (Cotización es Batch 11-12, Insumos/Herramientas es Batch 13-14) — stub documentado
     * (lista vacía), mismo criterio que `ExternalTechnicianService::activeProjectsCount()`. RN4 —
     * ninguna tarjeta tipo "Cliente sin confirmar cita": no existe ese `type` en este servicio, a
     * propósito (funcionalidad eliminada, ver REQ-210 Escenario 4).
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function pendientes(): array
    {
        $umbral = $this->settings->sinAgendarUmbralDias();

        $sinAgendar = Ticket::whereNull('scheduled_at')
            ->whereNotIn('estado', [Ticket::ESTADO_CLOSED, Ticket::ESTADO_CANCELLED])
            ->where('created_at', '<=', now()->subDays($umbral))
            ->orderBy('created_at')
            ->get();

        $items = $sinAgendar->map(function (Ticket $ticket): array {
            $dias = (int) $ticket->created_at->diffInDays(now());

            return [
                'type'      => 'ticket_sin_agendar',
                'ticket_id' => $ticket->id,
                'numero'    => $ticket->numero,
                'dias'      => $dias,
                'message'   => "Ticket {$ticket->numero} sin agendar hace {$dias} días",
            ];
        });

        // RN3 — "Repuesto sin llegar": sin tabla real que vincule ticket↔producto solicitado a un
        // proveedor con fecha estimada de llegada (esa relación nace en Batch 13/14), no hay datos
        // reales que leer todavía. Lista vacía documentada en vez de inventar un vínculo débil.
        /** @var Collection<int, array<string, mixed>> $repuestosSinLlegar */
        $repuestosSinLlegar = collect();

        $all = $items->merge($repuestosSinLlegar)->values();

        return [
            'count' => $all->count(),
            'items' => array_values($all->all()),
        ];
    }

    /**
     * REQ-212 RN1 — panel "Servicios sin responder": tickets que llevan >= N días (umbral
     * configurable, `sinResponderUmbralDias()`) SIN ningún avance — ni agendados ni ningún cambio
     * de estado desde que se reportaron. Deliberadamente más angosto que `pendientes()` (que acepta
     * cualquier estado no cerrado/cancelado): acá el único estado válido es `reported`.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function sinResponder(): array
    {
        $umbral = $this->settings->sinResponderUmbralDias();

        $tickets = Ticket::where('estado', Ticket::ESTADO_REPORTED)
            ->where('created_at', '<=', now()->subDays($umbral))
            ->orderBy('created_at')
            ->get();

        $items = $tickets->map(fn (Ticket $ticket): array => [
            'ticket_id'   => $ticket->id,
            'numero'      => $ticket->numero,
            'cliente'     => $ticket->cliente,
            'descripcion' => $ticket->descripcion,
            'tipo'        => $ticket->tipo,
            'tipo_label'  => self::TIPO_LABELS[$ticket->tipo] ?? $ticket->tipo,
            'dias'        => (int) $ticket->created_at->diffInDays(now()),
        ]);

        return [
            'count' => $items->count(),
            'items' => array_values($items->all()),
        ];
    }

    /**
     * REQ-213 — panel "Insumos pendientes de llegar": productos reclamados/afectados
     * (`ticket_products`, Batch 3 parte 2) con `cantidad_pendiente > 0`, de tickets todavía
     * activos (no `closed`/`cancelled`). Única fuente real disponible — no existe todavía un
     * vínculo real ticket↔orden de compra con fecha estimada de llegada (`InsumoPurchaseRequest`/
     * `ToolPurchaseRequest`, de Grupo D, son reposición general de stock de almacén, sin
     * `ticket_id`/`ticket_product_id`) — `llegada_estimada` queda `null` a propósito, mismo
     * criterio honesto que el stub de "Repuesto sin llegar" en `pendientes()`.
     *
     * @return array{count: int, items: list<array<string, mixed>>}
     */
    private function insumosPendientes(): array
    {
        $ticketProducts = TicketProduct::where('cantidad_pendiente', '>', 0)
            ->whereHas('ticket', function ($query): void {
                $query->whereNotIn('estado', [Ticket::ESTADO_CLOSED, Ticket::ESTADO_CANCELLED]);
            })
            ->with('ticket')
            ->get();

        $items = $ticketProducts->map(function (TicketProduct $ticketProduct): array {
            $ticket = $ticketProduct->ticket;

            return [
                'ticket_id'             => $ticket->id,
                'numero'                => $ticket->numero,
                'cliente'               => $ticket->cliente,
                'producto'              => [
                    'marca'       => $ticketProduct->marca,
                    'referencia'  => $ticketProduct->referencia,
                    'descripcion' => $ticketProduct->descripcion,
                ],
                'solicitado_hace_dias'  => (int) $ticketProduct->created_at->diffInDays(now()),
                // Sin fuente real de ETA por producto todavía (ver docblock del método) — el
                // frontend muestra "Por confirmar" cuando es null.
                'llegada_estimada'      => null,
            ];
        });

        return [
            'count' => $items->count(),
            'items' => array_values($items->all()),
        ];
    }

    /**
     * REQ-215 — distribución de tickets por estado (6 columnas del Tablero), consumido por el
     * endpoint aparte `GET /servicios/home/estado-tickets` (acepta `tipo`, a diferencia del resto
     * de `summary()`). Reusa `TicketService::board()` para 5 de los 6 estados (total histórico,
     * mismo criterio que el Tablero); RN4 acota "Cerrado" al mes en curso — `board()` no acota por
     * fecha, así que ese único valor se recalcula acá con una query separada.
     *
     * @return array{counts: array<string, int>, tipo: string|null}
     */
    public function estadoTickets(?string $tipo): array
    {
        $counts = $this->tickets->board($tipo !== null ? ['tipo' => $tipo] : [])['counts_by_estado'];

        $now          = now();
        $closedQuery  = Ticket::where('estado', Ticket::ESTADO_CLOSED)
            ->whereBetween('closed_at', [$now->copy()->startOfMonth(), $now->copy()->endOfMonth()]);
        if ($tipo !== null) {
            $closedQuery->where('tipo', $tipo);
        }
        $counts[Ticket::ESTADO_CLOSED] = $closedQuery->count();

        return [
            'counts' => $counts,
            'tipo'   => $tipo,
        ];
    }

    /**
     * REQ-211 — resumen del mes en curso.
     *
     * @return array<string, mixed>
     */
    private function indicadoresMes(): array
    {
        $now        = now();
        $monthStart = $now->copy()->startOfMonth();
        $monthEnd   = $now->copy()->endOfMonth();

        $instalacionesCompletadas = Ticket::where('tipo', Ticket::TIPO_INSTALLATION)
            ->whereIn('estado', Ticket::ESTADOS_REQUIRING_CLOSE_GATE)
            ->whereBetween('closed_at', [$monthStart, $monthEnd])
            ->count();

        $goal = $this->goals->goalForMonth((int) $now->year, (int) $now->month);

        $progresoPct = $goal['value'] > 0
            ? round(min(100, $instalacionesCompletadas / $goal['value'] * 100), 1)
            : 0.0;

        return [
            'instalaciones' => [
                'completadas'  => $instalacionesCompletadas,
                'meta'         => $goal['value'],
                'meta_source'  => $goal['source'],
                'progreso_pct' => $progresoPct,
            ],
            'resuelto_primera_visita_pct'     => $this->resueltoPrimeraVisitaPct($monthStart, $monthEnd),
            'tiempo_promedio_resolucion_dias' => $this->tiempoPromedioResolucionGarantiasDias($monthStart, $monthEnd),
            'ingresos_instalaciones' => [
                'value'     => $this->ingresosInstalaciones($monthStart, $monthEnd),
                'available' => true,
            ],
        ];
    }

    /**
     * REQ-211 RN5 — suma de cotizaciones APROBADAS de tickets tipo Instalación cerrados en el mes.
     * `total` es siempre el valor recalculado/persistido por ServiceQuoteService (nunca un cálculo
     * paralelo, ver docblock de ServiceQuote::computeTotals()). Reconciliado 2026-08-12 — el stub
     * (`available: false`) existía porque `service_quotes` (Batch 11) todavía no existía en el
     * worktree donde se construyó Batch 15; Batch 11 ya cerró.
     */
    private function ingresosInstalaciones(Carbon $monthStart, Carbon $monthEnd): float
    {
        return (float) ServiceQuote::where('estado', ServiceQuote::ESTADO_APPROVED)
            ->whereHas('ticket', function ($query) use ($monthStart, $monthEnd): void {
                $query->where('tipo', Ticket::TIPO_INSTALLATION)
                    ->whereIn('estado', Ticket::ESTADOS_REQUIRING_CLOSE_GATE)
                    ->whereBetween('closed_at', [$monthStart, $monthEnd]);
            })
            ->sum('total');
    }

    /**
     * REQ-211 RN3 — % de tickets Garantías/Reclamos cerrados este mes sin necesitar 2da visita
     * (visit_count <= 1). Público desde Reportes (REQ-280 RN2, SCRUM-350) — ese endpoint reusa
     * este mismo método para su tarjeta "% Resuelto en 1ra visita" en vez de reimplementar el
     * cálculo en paralelo (criterio de aceptación explícito de paridad, ver ReportsService).
     */
    public function resueltoPrimeraVisitaPct(Carbon $monthStart, Carbon $monthEnd): ?float
    {
        $cerrados = Ticket::whereIn('tipo', [Ticket::TIPO_WARRANTY, Ticket::TIPO_CLAIM])
            ->whereIn('estado', Ticket::ESTADOS_REQUIRING_CLOSE_GATE)
            ->whereBetween('closed_at', [$monthStart, $monthEnd])
            ->get();

        if ($cerrados->isEmpty()) {
            return null;
        }

        $primeraVisita = $cerrados->filter(fn (Ticket $t): bool => $t->visit_count <= 1)->count();

        return round($primeraVisita / $cerrados->count() * 100, 1);
    }

    /** REQ-211 RN4 — promedio de días entre reporte (created_at) y cierre (closed_at), SOLO Garantías. */
    private function tiempoPromedioResolucionGarantiasDias(Carbon $monthStart, Carbon $monthEnd): ?float
    {
        $cerrados = Ticket::where('tipo', Ticket::TIPO_WARRANTY)
            ->whereIn('estado', Ticket::ESTADOS_REQUIRING_CLOSE_GATE)
            ->whereBetween('closed_at', [$monthStart, $monthEnd])
            ->get();

        if ($cerrados->isEmpty()) {
            return null;
        }

        $dias = $cerrados->map(function (Ticket $t): float {
            \assert($t->closed_at !== null);

            return (float) $t->created_at->diffInDays($t->closed_at);
        });

        return round((float) $dias->avg(), 1);
    }
}
