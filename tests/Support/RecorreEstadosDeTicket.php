<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Models\User;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\TicketService;

/**
 * Fase 12a — los estados de un ticket solo avanzan por `Ticket::TRANSICIONES`. Las pruebas de métricas que antes cerraban un ticket de un salto
 * lo llevan ahora por el camino válido más corto (cada paso pasa por `TicketService::changeStage`, con sus compuertas reales).
 */
trait RecorreEstadosDeTicket
{
    protected function cerrarTicket(TicketService $service, Ticket $ticket, User $user): Ticket
    {
        return $this->llevarTicketA($service, $ticket, $user, Ticket::ESTADO_CLOSED);
    }

    protected function llevarTicketA(TicketService $service, Ticket $ticket, User $user, string $destino): Ticket
    {
        foreach ($this->caminoDeEstados($ticket->estado, $destino) as $paso) {
            // «scheduled» y «on_site» exigen técnico y agenda: si la prueba no los puso, se completan solo para poder avanzar.
            if (in_array($paso, [Ticket::ESTADO_SCHEDULED, Ticket::ESTADO_ON_SITE], true)
                && ($ticket->internal_technician_id === null || $ticket->scheduled_at === null)) {
                $ticket->forceFill([
                    'internal_technician_id' => $ticket->internal_technician_id ?? $user->id,
                    'scheduled_at' => $ticket->scheduled_at ?? now(),
                ])->save();
            }
            $ticket = $service->changeStage($ticket, $paso, $user);
        }

        return $ticket;
    }

    /** @return list<string> pasos (sin incluir el estado de partida) por el camino más corto de la matriz */
    private function caminoDeEstados(string $desde, string $hasta): array
    {
        if ($desde === $hasta) {
            return [];
        }

        $cola = [[$desde]];
        $visto = [$desde => true];
        while ($cola !== []) {
            $camino = array_shift($cola);
            $ultimo = end($camino);
            foreach (Ticket::TRANSICIONES[$ultimo] ?? [] as $siguiente) {
                if (isset($visto[$siguiente])) {
                    continue;
                }
                $nuevo = [...$camino, $siguiente];
                if ($siguiente === $hasta) {
                    return array_slice($nuevo, 1);
                }
                $visto[$siguiente] = true;
                $cola[] = $nuevo;
            }
        }

        throw new \LogicException("No hay camino de «{$desde}» a «{$hasta}» en la matriz de transiciones.");
    }
}
