<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Services;

use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\Ticket;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Fase 4 — Servicios, Batch 6 (REQ-256). Estado de un técnico interno (Disponible/En ruta/
 * Ocupado/Fuera) — 100% calculado en cada request desde su agenda del día, NUNCA persistido ni
 * editable (RN5). Se re-evalúa en cada llamada; el "refresco corto" de RN6 es responsabilidad del
 * frontend (polling/refetch), no de este servicio.
 *
 * Mismo patrón de zona horaria que `SecurityAnalysisJob::BUSINESS_TIMEZONE` — la app corre en UTC
 * (`config('app.timezone')`) pero "hoy"/"la próxima hora"/`working_hours` son conceptos de negocio
 * en hora de Panamá.
 */
final class TechnicianStatusService
{
    public const AVAILABLE = 'available';

    public const EN_ROUTE = 'en_route';

    public const ON_SITE = 'on_site';

    public const OFF = 'off';

    /** Zona horaria del negocio: la del tenant activo (Fase 6a, `TenantRegion`). */
    private static function businessTimezone(): string
    {
        return \App\Shared\Multitenancy\TenantRegion::timezone();
    }

    // RN2 — "empieza dentro de la próxima hora".
    private const EN_ROUTE_WINDOW_MINUTES = 60;

    // Si scheduled_ends_at es null (visita sin fin estimado todavía), se asume esta duración solo
    // para el cálculo de estado — nunca se persiste ni se muestra como dato real de la visita.
    private const DEFAULT_VISIT_DURATION_MINUTES = 60;

    /**
     * REQ-256 — estado de un técnico dado su set de visitas de hoy ya cargado (evita N+1, ver
     * caller). `$todaysTickets` siempre trae `scheduled_at` no nulo — el caller ya filtra por
     * `whereBetween('scheduled_at', ...)`.
     *
     * @param Collection<int, Ticket> $todaysTickets
     */
    public function statusFor(InternalTechnician $technician, Collection $todaysTickets): string
    {
        // SCRUM-322 fix (2026-08-12): antes había un early-return acá que forzaba "Fuera" a
        // cualquier técnico sin `user_id` vinculado, razonando que "sin cuenta real, nunca puede
        // tener una visita agendada, no hay ambigüedad posible". Eso rompía RN4/Escenario 1 de
        // REQ-259 ("un técnico nuevo siempre inicia Disponible") de forma PERMANENTE — un técnico
        // creado por el formulario de alta (REQ-259) nunca setea `user_id` (ver
        // InternalTechnicianService::create()), así que quedaba en "Fuera" para siempre, no solo
        // al momento de crearlo. El caller ya garantiza `$todaysTickets` vacío para estos técnicos
        // (`InternalTechnicianService::todaysTicketsByUser()` los filtra por no tener `user_id`),
        // así que dejarlos caer al cálculo normal de abajo los trata igual que cualquier técnico
        // con 0 visitas hoy: Disponible dentro de horario laboral, Fuera fuera de horario — mismo
        // comportamiento que un técnico real sin agenda, consistente con RN4 y sin necesitar un
        // caso especial. La limitación real (no es seleccionable para agendar sin `user_id`, ver
        // docblock de la migración) sigue intacta — es un límite de agendamiento, no de estado.
        $now = Carbon::now(self::businessTimezone());

        foreach ($todaysTickets as $ticket) {
            [$start, $end] = $this->visitWindow($ticket);
            if ($now->betweenIncluded($start, $end)) {
                return self::ON_SITE;
            }
        }

        foreach ($todaysTickets as $ticket) {
            $start = $this->scheduledAt($ticket)->timezone(self::businessTimezone());
            if ($now->lessThan($start) && $now->diffInMinutes($start, false) <= self::EN_ROUTE_WINDOW_MINUTES) {
                return self::EN_ROUTE;
            }
        }

        if (! $this->withinWorkingHours($technician, $now)) {
            return self::OFF;
        }

        $hasMoreToday = $todaysTickets->contains(
            fn (Ticket $t): bool => $this->scheduledAt($t)->timezone(self::businessTimezone())->greaterThan($now),
        );

        // RN4 — sin más visitas hoy también es "Fuera", no "Disponible" (Disponible implica que
        // todavía puede recibir carga hoy).
        if ($todaysTickets->isNotEmpty() && ! $hasMoreToday) {
            return self::OFF;
        }

        return self::AVAILABLE;
    }

    /** @return array{0: Carbon, 1: Carbon} */
    private function visitWindow(Ticket $ticket): array
    {
        $start = $this->scheduledAt($ticket)->timezone(self::businessTimezone());
        $end   = $ticket->scheduled_ends_at !== null
            ? $ticket->scheduled_ends_at->copy()->timezone(self::businessTimezone())
            : $start->copy()->addMinutes(self::DEFAULT_VISIT_DURATION_MINUTES);

        return [$start, $end];
    }

    /** `$ticket->scheduled_at` es nullable en el modelo, pero nunca acá — ver docblock de statusFor(). */
    private function scheduledAt(Ticket $ticket): Carbon
    {
        \assert($ticket->scheduled_at !== null);

        return $ticket->scheduled_at->copy();
    }

    private function withinWorkingHours(InternalTechnician $technician, Carbon $now): bool
    {
        $hours = $technician->working_hours;
        $start = $hours['start'] ?? '08:00';
        $end   = $hours['end'] ?? '18:00';

        $todayStart = $now->copy()->setTimeFromTimeString($start);
        $todayEnd   = $now->copy()->setTimeFromTimeString($end);

        return $now->betweenIncluded($todayStart, $todayEnd);
    }
}
