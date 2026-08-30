<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * REQ-218/REQ-221 RN3 — incluye el resultado del gate de cierre (cotización/informe), no solo el
 * cambio de estado en sí, para que un listener futuro (ej. notificaciones) no tenga que volver a
 * consultar el ticket para saber si el cierre pasó la validación.
 */
class TicketStageChanged implements DomainEvent
{
    public function __construct(
        public readonly int    $ticketId,
        public readonly string $numero,
        public readonly string $oldEstado,
        public readonly string $newEstado,
        public readonly int    $changedBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'servicios.ticket.stage-changed';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'ticket_id'  => $this->ticketId,
            'numero'     => $this->numero,
            'old_estado' => $this->oldEstado,
            'new_estado' => $this->newEstado,
            'changed_by' => $this->changedBy,
            'tenant'     => $this->tenant,
        ];
    }
}
