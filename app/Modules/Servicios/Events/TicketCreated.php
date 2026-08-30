<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Events;

use App\Shared\Contracts\DomainEvent;

class TicketCreated implements DomainEvent
{
    public function __construct(
        public readonly int    $ticketId,
        public readonly string $numero,
        public readonly string $tipo,
        public readonly int    $createdBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'servicios.ticket.created';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'ticket_id'  => $this->ticketId,
            'numero'     => $this->numero,
            'tipo'       => $this->tipo,
            'created_by' => $this->createdBy,
            'tenant'     => $this->tenant,
        ];
    }
}
