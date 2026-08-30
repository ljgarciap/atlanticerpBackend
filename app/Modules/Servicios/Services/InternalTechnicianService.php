<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Models\Tool;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

/**
 * Fase 4 — Servicios, Batch 6 (REQ-255→260). Ver docs/architecture/servicios-fase4-diseno.md y
 * el docblock de la migración `..._create_servicios_internal_technicians_table.php`.
 */
final class InternalTechnicianService
{
    // REQ-259 RN3 — paleta fija; ámbar reservado en la práctica para Miguel Castillo (seed real),
    // pero no hay una regla que lo prohíba explícitamente para otro — solo unicidad real.
    private const COLOR_PALETTE = [
        '#F59E0B', '#3B82F6', '#8B5CF6', '#EC4899', '#10B981', '#F97316', '#6366F1', '#14B8A6',
    ];

    private const BUSINESS_TIMEZONE = 'America/Panama';

    public function __construct(private readonly TechnicianStatusService $status) {}

    /**
     * REQ-255 — vista Equipo. `pct_resuelto_primera_visita`/`tiempo_promedio` siguen siendo
     * placeholders documentados: la fórmula canónica de "resuelto en 1ra visita" vive en REQ-211
     * (Batch 15, Panel Indicadores del mes — tampoco existe). No se inventa un cálculo propio acá
     * para no divergir del REQ-211 real el día que se construya.
     *
     * `herramientas_asignadas` (SCRUM-777, rebote de Daniela 2026-08-19) — este docblock decía
     * "placeholder, Herramientas es Batch 13, no existe tools todavía"; quedó desactualizado
     * desde que Batch 13 (REQ-268→272) creó `tools.assigned_to_technician_id` sin que nadie
     * volviera acá a reemplazar el `0` fijo por el conteo real.
     *
     * @return array<int, array<string, mixed>>
     */
    public function list(): array
    {
        $technicians = InternalTechnician::query()->orderBy('nombre')->get();
        $todayByUser = $this->todaysTicketsByUser($technicians);
        $toolCounts  = Tool::query()
            ->whereNotNull('assigned_to_technician_id')
            ->selectRaw('assigned_to_technician_id, count(*) as total')
            ->groupBy('assigned_to_technician_id')
            ->pluck('total', 'assigned_to_technician_id');

        return $technicians->map(function (InternalTechnician $t) use ($todayByUser, $toolCounts): array {
            $today = $todayByUser->get($t->user_id, collect());

            return [
                'id'                          => $t->id,
                // Batch 10 (REQ-258 RN8) — el frontend necesita saber "es este técnico el usuario
                // en sesión" para mostrar el mini-indicador de comisión solo a Carlos mismo o
                // Gerencia (el backend igual re-valida esto mismo en GET .../commission, esto solo
                // decide si mostrar el botón).
                'user_id'                     => $t->user_id,
                'nombre'                      => $t->nombre,
                'telefono'                    => $t->telefono,
                'email'                       => $t->email,
                'especialidad'                => $t->especialidad,
                'color'                       => $t->color,
                'has_bonus_plan'              => $t->has_bonus_plan,
                'estado'                      => $this->status->statusFor($t, $today),
                'visitas_hoy'                 => $today->count(),
                'herramientas_asignadas'      => (int) ($toolCounts[$t->id] ?? 0),
                'pct_resuelto_primera_visita' => null,
                'tiempo_promedio_minutos'     => null,
            ];
        })->values()->all();
    }

    /**
     * REQ-261 — 3 tarjetas resumen. RN1: activos hoy = estado distinto de "off", sobre el total.
     * RN2: suma de visitas de hoy de todo el equipo. RN3: promedio de "1ra visita" solo sobre
     * técnicos con valor registrado — hoy siempre vacío (null en todas las filas) porque REQ-211
     * (Batch 15) todavía no existe; ver docblock de list().
     *
     * @return array{activos_hoy: int, total_tecnicos: int, visitas_hoy_total: int, promedio_primera_visita: float|null}
     */
    public function teamStats(): array
    {
        $technicians = $this->list();

        $activos = 0;
        $visitasTotal = 0;
        $primeraVisitaValues = [];

        foreach ($technicians as $t) {
            if ($t['estado'] !== TechnicianStatusService::OFF) {
                $activos++;
            }
            $visitasTotal += $t['visitas_hoy'];
            if ($t['pct_resuelto_primera_visita'] !== null) {
                $primeraVisitaValues[] = $t['pct_resuelto_primera_visita'];
            }
        }

        return [
            'activos_hoy'             => $activos,
            'total_tecnicos'          => count($technicians),
            'visitas_hoy_total'       => $visitasTotal,
            'promedio_primera_visita' => $primeraVisitaValues === [] ? null : array_sum($primeraVisitaValues) / count($primeraVisitaValues),
        ];
    }

    /**
     * REQ-259 — alta. RN2: iniciales derivadas del nombre (frontend). RN3: color único, solo se
     * repite si la paleta ya está agotada. RN4: Disponible/0 herramientas/sin visitas es el
     * estado natural de un registro nuevo sin tickets — no hace falta setear nada acá.
     *
     * @param array{nombre: string, telefono?: ?string, email?: ?string, especialidad: string} $data
     */
    public function create(array $data): InternalTechnician
    {
        return InternalTechnician::create([
            'nombre'        => $data['nombre'],
            'telefono'      => $data['telefono'] ?? null,
            'email'         => $data['email'] ?? null,
            'especialidad'  => $data['especialidad'],
            'color'         => $this->nextColor(),
        ]);
    }

    /** REQ-259 RN3 — primer color de la paleta sin usar; si todos están tomados, el menos repetido. */
    private function nextColor(): string
    {
        $used = InternalTechnician::query()->pluck('color');

        foreach (self::COLOR_PALETTE as $color) {
            if (! $used->contains($color)) {
                return $color;
            }
        }

        $counts = $used->countBy();

        return collect(self::COLOR_PALETTE)
            ->sortBy(fn (string $color): int => $counts->get($color, 0))
            ->first() ?? self::COLOR_PALETTE[0];
    }

    /**
     * REQ-257 — visitas de hoy de un técnico, orden cronológico (RN2). RN1: array vacío si no
     * tiene ninguna — el mensaje "Sin visitas hoy" lo resuelve el frontend.
     *
     * @return array<int, array<string, mixed>>
     */
    public function visitsToday(InternalTechnician $technician): array
    {
        if ($technician->user_id === null) {
            return [];
        }

        return $this->ticketsToday($technician->user_id)
            ->map(fn (Ticket $t): array => $this->visitSummary($t))
            ->values()
            ->all();
    }

    /**
     * REQ-260 — Agenda equipo: visitas agrupadas por técnico. RN2: filtro opcional a un único
     * técnico. RN4: misma fuente de datos que "Rutas del día" (Home, Batch 15) — `todaysRoutes()`
     * abajo la reusa, no la duplica.
     *
     * SCRUM-803 (2026-08-28) — agrega vistas Día/Semana/Mes: `$view` ('day'|'week'|'month',
     * default 'day') + `$referenceDate` (`Y-m-d`, default hoy en America/Panama) definen el rango
     * de `scheduled_at` a mostrar. Semana arranca Domingo — mismo criterio que `startOfWeek()` de
     * `dateGrid.ts` en el frontend (`Date.getDay()`, 0 = domingo), para que "semana" signifique el
     * mismo rango de días en ambos lados.
     *
     * @return array<int, array<string, mixed>>
     */
    public function agenda(?int $technicianId = null, string $view = 'day', ?string $referenceDate = null): array
    {
        $technicians = InternalTechnician::query()
            ->when($technicianId !== null, fn ($q) => $q->where('id', $technicianId))
            ->orderBy('nombre')
            ->get();

        [$start, $end] = $this->rangeForView($view, $referenceDate);
        $ticketsByUser = $this->ticketsByUserInRange($technicians, $start, $end);

        return $technicians->map(function (InternalTechnician $t) use ($ticketsByUser): array {
            $visits = $ticketsByUser->get($t->user_id, collect());

            return [
                'id'      => $t->id,
                'nombre'  => $t->nombre,
                'color'   => $t->color,
                'visitas' => $visits->map(fn (Ticket $ticket): array => $this->visitSummary($ticket))->values()->all(),
            ];
        })->values()->all();
    }

    /**
     * SCRUM-803 — límites (UTC) del rango a consultar según la vista. `$referenceDate` es el
     * día/semana/mes que el usuario está navegando en el frontend — NULL usa hoy.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    private function rangeForView(string $view, ?string $referenceDate): array
    {
        $reference = $referenceDate !== null
            ? Carbon::parse($referenceDate, self::BUSINESS_TIMEZONE)
            : Carbon::now(self::BUSINESS_TIMEZONE);

        $start = match ($view) {
            'week'  => $reference->copy()->startOfWeek(Carbon::SUNDAY)->startOfDay(),
            'month' => $reference->copy()->startOfMonth(),
            default => $reference->copy()->startOfDay(),
        };
        $end = match ($view) {
            'week'  => $reference->copy()->startOfWeek(Carbon::SUNDAY)->addDays(6)->endOfDay(),
            'month' => $reference->copy()->endOfMonth(),
            default => $reference->copy()->endOfDay(),
        };

        return [$start->utc(), $end->utc()];
    }

    /**
     * REQ-208 (Batch 15) — panel "Rutas del día" de Inicio. RN4: MISMA fuente de datos que Agenda
     * equipo (`agenda()` arriba, REQ-260) — reusa `todaysTicketsByUser()`, nunca una lista
     * mantenida por separado; si un ticket se reagenda/cancela en Tickets, el próximo fetch de
     * este método ya lo refleja, sin caché intermedio. RN3: el color de cada técnico es
     * `internal_technicians.color`, el MISMO campo que usa Técnicos Internos — no un esquema
     * derivado aparte (a diferencia de `TechnicianBadge.tsx` en el frontend, que sí deriva su
     * propio color por hash del id de usuario en la tabla/tablero de Tickets — divergencia
     * preexistente de Batch 1, fuera de alcance de este batch, ver reporte).
     *
     * A diferencia de `agenda()` (agrupado por técnico, orden local por técnico), acá el orden es
     * GLOBAL por hora — "las 5 visitas más próximas de TODO el equipo", no 5 por técnico.
     *
     * @return array<int, array<string, mixed>>
     */
    public function todaysRoutes(): array
    {
        $technicians = InternalTechnician::query()->orderBy('nombre')->get();
        $todayByUser = $this->todaysTicketsByUser($technicians);

        /** @var Collection<int, array<string, mixed>> $rows */
        $rows = collect();
        foreach ($technicians as $technician) {
            $today = $todayByUser->get($technician->user_id, collect());
            foreach ($today as $ticket) {
                $rows->push($this->routeSummary($ticket, $technician));
            }
        }

        return $rows
            ->sortBy(fn (array $row): string => (string) $row['_sort'])
            ->values()
            ->map(function (array $row): array {
                unset($row['_sort']);

                return $row;
            })
            ->all();
    }

    /** @return array<string, mixed> */
    private function routeSummary(Ticket $ticket, InternalTechnician $technician): array
    {
        return [
            '_sort'      => $ticket->scheduled_at?->toIso8601String() ?? '',
            'ticket_id'  => $ticket->id,
            'numero'     => $ticket->numero,
            'hora'       => $ticket->scheduled_at?->copy()->timezone(self::BUSINESS_TIMEZONE)->format('H:i'),
            'tipo'       => $ticket->tipo,
            'cliente'    => $ticket->cliente,
            'descripcion' => $ticket->descripcion,
            'direccion'  => $ticket->direccion,
            'contacto'   => $ticket->contacto,
            'telefono'   => $ticket->telefono,
            'tecnico'    => [
                'id'     => $technician->id,
                'nombre' => $technician->nombre,
                'color'  => $technician->color,
            ],
        ];
    }

    /**
     * @param EloquentCollection<int, InternalTechnician> $technicians
     * @return Collection<int|string, EloquentCollection<int, Ticket>> técnicos con
     *         user_id => sus tickets de hoy, un solo query.
     */
    private function todaysTicketsByUser(EloquentCollection $technicians): Collection
    {
        $todayStart = Carbon::now(self::BUSINESS_TIMEZONE)->startOfDay()->utc();
        $todayEnd   = Carbon::now(self::BUSINESS_TIMEZONE)->endOfDay()->utc();

        return $this->ticketsByUserInRange($technicians, $todayStart, $todayEnd);
    }

    /**
     * SCRUM-803 — generalización de `todaysTicketsByUser()` a un rango arbitrario (UTC).
     *
     * @param  EloquentCollection<int, InternalTechnician> $technicians
     * @return Collection<int|string, EloquentCollection<int, Ticket>> técnicos con
     *         user_id => sus tickets del rango, un solo query.
     */
    private function ticketsByUserInRange(EloquentCollection $technicians, Carbon $start, Carbon $end): Collection
    {
        $userIds = $technicians->pluck('user_id')->filter()->values();
        if ($userIds->isEmpty()) {
            return collect();
        }

        return Ticket::query()
            ->whereIn('internal_technician_id', $userIds)
            ->whereBetween('scheduled_at', [$start, $end])
            ->where('estado', '!=', Ticket::ESTADO_CANCELLED)
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy('internal_technician_id')
            ->collect();
    }

    /** @return Collection<int, Ticket> */
    private function ticketsToday(int $userId): Collection
    {
        $todayStart = Carbon::now(self::BUSINESS_TIMEZONE)->startOfDay()->utc();
        $todayEnd   = Carbon::now(self::BUSINESS_TIMEZONE)->endOfDay()->utc();

        return Ticket::query()
            ->where('internal_technician_id', $userId)
            ->whereBetween('scheduled_at', [$todayStart, $todayEnd])
            ->where('estado', '!=', Ticket::ESTADO_CANCELLED)
            ->orderBy('scheduled_at')
            ->get();
    }

    /** @return array<string, mixed> */
    private function visitSummary(Ticket $ticket): array
    {
        return [
            'ticket_id'   => $ticket->id,
            'numero'      => $ticket->numero,
            // SCRUM-803 — 'fecha' nuevo (antes solo 'hora', suficiente cuando la agenda era
            // siempre "hoy"); en vistas Semana/Mes las visitas caen en días distintos.
            'fecha'       => $ticket->scheduled_at?->copy()->timezone(self::BUSINESS_TIMEZONE)->format('Y-m-d'),
            'hora'        => $ticket->scheduled_at?->copy()->timezone(self::BUSINESS_TIMEZONE)->format('H:i'),
            'cliente'     => $ticket->cliente,
            'descripcion' => $ticket->descripcion,
        ];
    }
}
