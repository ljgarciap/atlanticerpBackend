<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\ClaimSheet;
use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\ServiceQuote;
use App\Modules\Servicios\Models\Ticket;
use App\Shared\Http\Pagination;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Fase 4 — Servicios, pantalla "Reportes" (SCRUM-350→356, REQ-280→286). 7 endpoints de solo
 * lectura, sin tablas nuevas — todo se calcula sobre lo que ya existe (Tickets, Cotizaciones,
 * Técnicos Internos, Informe de Inspección, Hoja de Reclamo).
 *
 * Regla dura de este batch (RN2 de REQ-280, RN1 de REQ-285): 2 de las 7 tarjetas/paneles NUNCA
 * reimplementan un cálculo que ya vive en otro módulo — reusan el método real
 * (`HomeService::resueltoPrimeraVisitaPct()`, `CommissionCaptureService::commissionForTechnician()`)
 * para que nunca puedan divergir del número que ya ve el usuario en Inicio/Técnicos Internos.
 *
 * Selector de período (decisión explícita del dueño de producto, sin RN propio en ninguna de las
 * 7 historias — tomada tras revisar el mockup real `5E__Servicios_Reportes.html`, adjunto de
 * SCRUM-356): `panoramaMes()`, `instalacionesCotizadasVsRealizadas()`, `distribucionTipo()` y
 * `distribucionTecnico()` aceptan `?int $year, ?int $month` (default mes/año en curso si se
 * omiten). `completadosAnio()` acepta `?int $year` (default año en curso) — RN1 de REQ-280
 * ("enero → mes actual, nunca ventana móvil") aplica SOLO cuando el año pedido es el año en
 * curso; un año pasado ya cerró completo y siempre devuelve los 12 meses.
 * `comisionTecnico()` no vive acá (ver `ReportsController::comisionTecnico()`) pero
 * ya soportaba `year`/`month` desde el diseño original — no se tocó. `biblioteca()` es atemporal
 * a propósito (REQ-286 no la ata a ningún período) — no acepta año/mes.
 *
 * Deliberadamente NO implementado en esta ronda: la opción "Últimos 3 meses" del selector del
 * mockup — ninguna RN (de REQ-280 a REQ-283) define qué agregación produce sobre un rango de 3
 * meses (¿promedio? ¿suma? — y `resuelto_primera_visita_pct`/`servicios_completados_pct` no son
 * sumables entre meses sin una regla de negocio explícita). Queda como ítem de backlog a
 * especificar con Luis antes de implementarse; Frontend Dev no debe ofrecer esa opción en el
 * selector todavía.
 */
final class ReportsService
{
    /**
     * REQ-282 — mismos colores que las etiquetas de tipo de ticket en el resto del módulo
     * (`atlanticerp-frontend/src/components/servicios/TicketBoard.tsx`, const `TYPE_COLORS`) — el
     * backend no puede importar ese archivo TS, así que el valor se duplica acá a propósito; si
     * el color de un tipo cambia en el frontend, este mapa debe actualizarse en el mismo commit.
     *
     * @var array<string, string>
     */
    private const TYPE_COLORS = [
        Ticket::TIPO_INSTALLATION => '#5BA5A0',
        Ticket::TIPO_WARRANTY     => '#9fc54d',
        Ticket::TIPO_CLAIM        => '#ef4444',
        Ticket::TIPO_RETROFIT     => '#8b5cf6',
    ];

    /** Zona horaria del negocio: la del tenant activo (Fase 6a, `TenantRegion`). */
    private static function businessTimezone(): string
    {
        return \App\Shared\Multitenancy\TenantRegion::timezone();
    }

    public function __construct(
        private readonly TicketService $tickets,
        private readonly HomeService $home,
    ) {}

    /**
     * GET .../reportes/panorama-mes (REQ-280) — 4 tarjetas del mes en curso.
     *
     * RN1 — "Servicios completados": tickets Resuelto/Cerrado ESTE MES (`closed_at` en el rango)
     * sobre el total de tickets REPORTADOS este mes (`created_at` en el rango). Se devuelven los 2
     * conteos crudos (`servicios_completados`/`servicios_completados_total`) además del `_pct`,
     * porque el Escenario 1 del ticket pide la tarjeta "34 de 52 tickets del mes" — el denominador
     * usa `created_at` porque no hay otra noción de "tickets del mes" en el resto del módulo más
     * que "cuándo se reportaron" (decisión de implementación dentro del diseño aprobado, sin
     * ambigüedad de negocio pendiente — documentada acá para que Pre-QA/Senior Review la validen
     * explícitamente).
     * RN2 — paridad exacta con Inicio, ver `HomeService::resueltoPrimeraVisitaPct()`.
     * RN3 — a diferencia de Inicio (solo Garantías), acá es TODOS los tipos de ticket; el
     * subtítulo `todos_los_tipos: true` en la respuesta es lo que el frontend usa para distinguir
     * esta cifra de la de Inicio (nunca un string libre que pueda desalinearse de una traducción).
     * RN4 — mismo cálculo centralizado que `TicketService::stats()`/`TicketController::stats()`.
     *
     * `informes_pendientes` (RN4) NUNCA se filtra por período — mismo número global que expone
     * Tickets (`TicketService::informesPendientesCount()`), independiente del mes/año pedido acá.
     *
     * @return array<string, mixed>
     */
    public function panoramaMes(?int $year = null, ?int $month = null): array
    {
        [$monthStart, $monthEnd] = $this->monthBounds($year, $month);

        [$completados, $totalMes] = $this->serviciosCompletadosCounts($monthStart, $monthEnd);

        return [
            'servicios_completados'           => $completados,
            'servicios_completados_total'     => $totalMes,
            'servicios_completados_pct'       => $totalMes === 0 ? null : round($completados / $totalMes * 100, 1),
            'resuelto_primera_visita_pct'     => $this->home->resueltoPrimeraVisitaPct($monthStart, $monthEnd),
            'tiempo_promedio_resolucion_dias' => $this->tiempoPromedioResolucionTodosLosTiposDias($monthStart, $monthEnd),
            'tiempo_promedio_resolucion_todos_los_tipos' => true,
            'informes_pendientes'             => $this->tickets->informesPendientesCount(),
        ];
    }

    /**
     * GET .../reportes/instalaciones-cotizadas-vs-realizadas (REQ-281).
     *
     * "Cotizadas" — tickets tipo Instalación con subtipo "Inspección de instalación" (el único
     * subtipo de Instalación que requiere cotización, ver `TicketService::deriveInitialStatuses()`
     * — el subtipo "Instalación" venta previa deriva `quote_status = not_applicable`, nunca genera
     * cotización) con `ServiceQuote` generada este mes. "Realizadas" sigue exactamente el patrón
     * de `HomeService::ingresosInstalaciones()`: tickets tipo Instalación Resuelto/Cerrado este
     * mes, sumando el total de sus cotizaciones APROBADAS.
     *
     * @return array{cotizadas: array{cantidad: int, total: float}, realizadas: array{cantidad: int, total: float}}
     */
    public function instalacionesCotizadasVsRealizadas(?int $year = null, ?int $month = null): array
    {
        [$monthStart, $monthEnd] = $this->monthBounds($year, $month);

        $cotizadas = ServiceQuote::whereBetween('created_at', [$monthStart, $monthEnd])
            ->whereHas('ticket', function (Builder $query): void {
                $query->where('tipo', Ticket::TIPO_INSTALLATION)
                    ->where('subtipo', Ticket::SUBTIPO_INSPECTION);
            })
            ->get(['id', 'total']);

        $realizadasTicketIds = Ticket::where('tipo', Ticket::TIPO_INSTALLATION)
            ->whereIn('estado', Ticket::ESTADOS_REQUIRING_CLOSE_GATE)
            ->whereBetween('closed_at', [$monthStart, $monthEnd])
            ->pluck('id');

        $realizadasTotal = ServiceQuote::whereIn('ticket_id', $realizadasTicketIds)
            ->where('estado', ServiceQuote::ESTADO_APPROVED)
            ->sum('total');

        return [
            'cotizadas' => [
                'cantidad' => $cotizadas->count(),
                'total'    => (float) $cotizadas->sum('total'),
            ],
            'realizadas' => [
                'cantidad' => $realizadasTicketIds->count(),
                'total'    => (float) $realizadasTotal,
            ],
        ];
    }

    /**
     * GET .../reportes/distribucion-tipo (REQ-282) — donut. Tickets REPORTADOS este mes
     * (`created_at`), agrupados por los 4 tipos vigentes, siempre los 4 presentes (0 si no hay
     * ninguno) para que el frontend no tenga que rellenar huecos.
     *
     * @return list<array{tipo: string, count: int, color: string}>
     */
    public function distribucionTipo(?int $year = null, ?int $month = null): array
    {
        [$monthStart, $monthEnd] = $this->monthBounds($year, $month);

        /** @var Collection<string, int> $counts */
        $counts = Ticket::whereBetween('created_at', [$monthStart, $monthEnd])
            ->selectRaw('tipo, count(*) as total')
            ->groupBy('tipo')
            ->pluck('total', 'tipo');

        return array_values(collect(Ticket::TIPOS)->map(fn (string $tipo): array => [
            'tipo'  => $tipo,
            'count' => (int) ($counts[$tipo] ?? 0),
            'color' => self::TYPE_COLORS[$tipo],
        ])->all());
    }

    /**
     * GET .../reportes/distribucion-tecnico (REQ-283) — barras. Tickets asignados este mes
     * (`scheduled_at` en el rango — la fecha real de la visita, no `created_at`) por cada técnico
     * interno "activo" (con `user_id`, es decir agendable — `InternalTechnician` no tiene un
     * campo `estado` propio como `ExternalTechnician`, ver docblock del modelo).
     *
     * @return list<array{id: int, nombre: string, color: string, count: int}>
     */
    public function distribucionTecnico(?int $year = null, ?int $month = null): array
    {
        [$monthStart, $monthEnd] = $this->monthBounds($year, $month);

        $technicians = InternalTechnician::whereNotNull('user_id')->orderBy('nombre')->get();

        /** @var Collection<int, int> $counts */
        $counts = Ticket::whereIn('internal_technician_id', $technicians->pluck('user_id'))
            ->whereBetween('scheduled_at', [$monthStart, $monthEnd])
            ->selectRaw('internal_technician_id, count(*) as total')
            ->groupBy('internal_technician_id')
            ->pluck('total', 'internal_technician_id');

        return array_values($technicians->map(fn (InternalTechnician $t): array => [
            'id'     => $t->id,
            'nombre' => $t->nombre,
            'color'  => $t->color,
            'count'  => (int) ($counts[$t->user_id] ?? 0),
        ])->all());
    }

    /**
     * GET .../reportes/completados-anio (REQ-284) — barras agrupadas. RN1: enero → mes actual,
     * nunca una ventana móvil de N meses — pero esa regla es específica del año EN CURSO. Cuando
     * `$year` pide un año pasado (decisión de producto agregada tras el selector de período del
     * mockup, ver docblock de la clase), ese año ya cerró completo: se devuelven los 12 meses.
     * RN2/RN4: cada mes trae los 4 valores individuales por tipo (no apilado) más el total del
     * mes. RN3 (grilla 6x2) es puramente de layout del frontend, no cambia el shape de acá.
     *
     * @return list<array{mes: int, por_tipo: array<string, int>, total: int}>
     */
    public function completadosAnio(?int $year = null): array
    {
        $now         = Carbon::now(self::businessTimezone());
        $targetYear  = $year ?? (int) $now->year;
        $lastMonth   = $targetYear === (int) $now->year ? (int) $now->month : 12;

        /** @var Collection<int, object{mes: int|string, tipo: string, total: int|string}> $rows */
        $rows = Ticket::whereIn('estado', Ticket::ESTADOS_REQUIRING_CLOSE_GATE)
            ->whereYear('closed_at', $targetYear)
            ->selectRaw('EXTRACT(MONTH FROM closed_at) as mes, tipo, count(*) as total')
            ->groupByRaw('EXTRACT(MONTH FROM closed_at), tipo')
            ->get();

        /** @var array<int, array<string, int>> $byMonthType */
        $byMonthType = [];
        foreach ($rows as $row) {
            $mes = (int) $row->mes;
            $byMonthType[$mes][(string) $row->tipo] = (int) $row->total;
        }

        $result = [];
        for ($m = 1; $m <= $lastMonth; $m++) {
            $porTipo = [];
            $total   = 0;
            foreach (Ticket::TIPOS as $tipo) {
                $count           = $byMonthType[$m][$tipo] ?? 0;
                $porTipo[$tipo]  = $count;
                $total          += $count;
            }

            $result[] = ['mes' => $m, 'por_tipo' => $porTipo, 'total' => $total];
        }

        return $result;
    }

    /**
     * GET .../reportes/biblioteca (REQ-286) — listado/buscador. RN1: únicamente Informe de
     * Inspección y Hoja de Reclamo (no existe un tercer tipo de documento). RN3: filtros
     * combinables (texto libre + tipo de documento + estado).
     *
     * Reusa la convención global de paginación (`App\Shared\Http\Pagination`, política de Luis
     * Sprint 2 — ver `Pagination::ofArray()`, ya usado por Compras/Bodega para paginar un array ya
     * filtrado/ordenado en PHP) en vez de `paginate()` de Eloquent, porque no hay una sola query
     * que una 2 modelos de 2 tablas distintas (`InspectionReport`/`ClaimSheet`) — se traen ambas
     * colecciones completas, se filtran/ordenan en memoria, y se pagina el array resultante con el
     * mismo helper que el resto del proyecto ya usa para este caso.
     *
     * @param array{search?: string, tipo_documento?: string, estado?: string, per_page?: int, page?: int} $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function biblioteca(array $filters): LengthAwarePaginator
    {
        $search        = trim((string) ($filters['search'] ?? ''));
        $tipoDocumento = $filters['tipo_documento'] ?? null;
        $estado        = $filters['estado'] ?? null;
        $perPage       = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 20;
        $page          = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = collect();

        if ($tipoDocumento === null || $tipoDocumento === 'inspection_report') {
            $reports = InspectionReport::with(['ticket', 'internalTechnician'])->get();
            foreach ($reports as $report) {
                $rows->push($this->inspectionReportRow($report));
            }
        }

        if ($tipoDocumento === null || $tipoDocumento === 'claim_sheet') {
            $sheets = ClaimSheet::with(['ticket.internalTechnician'])->get();
            foreach ($sheets as $sheet) {
                $rows->push($this->claimSheetRow($sheet));
            }
        }

        if ($estado !== null) {
            $rows = $rows->filter(fn (array $row): bool => $row['estado'] === $estado)->values();
        }

        if ($search !== '') {
            $term = mb_strtolower($search);
            $rows = $rows->filter(function (array $row) use ($term): bool {
                return str_contains(mb_strtolower((string) $row['ticket_numero']), $term)
                    || str_contains(mb_strtolower((string) $row['cliente']), $term)
                    || str_contains(mb_strtolower((string) ($row['tecnico'] ?? '')), $term);
            })->values();
        }

        $rows = $rows->sortByDesc('fecha')->values();

        return Pagination::ofArray(array_values($rows->all()), $perPage, $page);
    }

    /**
     * Selector de período (ver docblock de la clase) — `$year`/`$month` nulos caen al mes/año en
     * curso, mismo default que ya validó `ReportsController` antes de llegar acá.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function monthBounds(?int $year, ?int $month): array
    {
        $now    = Carbon::now(self::businessTimezone());
        $target = Carbon::create($year ?? $now->year, $month ?? $now->month, 1, 0, 0, 0, self::businessTimezone())
            ?? $now;

        return [$target->copy()->startOfMonth(), $target->copy()->endOfMonth()];
    }

    /**
     * RN1 (REQ-280) — completados este mes (`closed_at`) y reportados este mes (`created_at`).
     * Ambos conteos crudos se exponen tal cual, no solo su porcentaje: el Escenario 1 del ticket
     * pide literalmente la tarjeta "34 de 52 tickets del mes" (hallazgo de Pre-QA 2026-08-12 — la
     * primera versión solo devolvía el %, y el frontend no podía reconstruir el desglose).
     *
     * @return array{0: int, 1: int} [completados, total del mes]
     */
    private function serviciosCompletadosCounts(Carbon $monthStart, Carbon $monthEnd): array
    {
        $totalMes = Ticket::whereBetween('created_at', [$monthStart, $monthEnd])->count();

        $completadosMes = Ticket::whereIn('estado', Ticket::ESTADOS_REQUIRING_CLOSE_GATE)
            ->whereBetween('closed_at', [$monthStart, $monthEnd])
            ->count();

        return [$completadosMes, $totalMes];
    }

    /**
     * RN3 (REQ-280) — igual que `HomeService::tiempoPromedioResolucionGarantiasDias()` pero SIN el
     * filtro `tipo = warranty`, sobre todos los tickets cerrados/resueltos este mes.
     */
    private function tiempoPromedioResolucionTodosLosTiposDias(Carbon $monthStart, Carbon $monthEnd): ?float
    {
        $cerrados = Ticket::whereIn('estado', Ticket::ESTADOS_REQUIRING_CLOSE_GATE)
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

    /** @return array<string, mixed> */
    private function inspectionReportRow(InspectionReport $report): array
    {
        $technician = $report->internalTechnician;

        return [
            'id'             => $report->id,
            'tipo_documento' => 'inspection_report',
            'ticket_id'      => $report->ticket_id,
            'ticket_numero'  => $report->ticket->numero,
            'cliente'        => $report->ticket->cliente,
            'tecnico'        => $technician !== null ? trim("{$technician->first_name} {$technician->last_name}") : null,
            'fecha'          => ($report->fecha_inspeccion ?? $report->created_at)->toIso8601String(),
            'estado'         => $report->estado,
        ];
    }

    /** @return array<string, mixed> */
    private function claimSheetRow(ClaimSheet $sheet): array
    {
        $technician = $sheet->ticket->internalTechnician;

        return [
            'id'             => $sheet->id,
            'tipo_documento' => 'claim_sheet',
            'ticket_id'      => $sheet->ticket_id,
            'ticket_numero'  => $sheet->ticket->numero,
            'cliente'        => $sheet->ticket->cliente,
            'tecnico'        => $technician !== null ? trim("{$technician->first_name} {$technician->last_name}") : null,
            'fecha'          => ($sheet->fecha_reclamo ?? $sheet->created_at)->toIso8601String(),
            'estado'         => $sheet->estado,
        ];
    }
}
