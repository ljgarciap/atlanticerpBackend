<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Events;

use App\Shared\Contracts\DomainEvent;

/** Fase 4 — Servicios, Batch 11 (REQ-234). Borrador -> Enviada, vía el botón "Enviar al cliente". */
class ServiceQuoteSent implements DomainEvent
{
    public function __construct(
        public readonly int    $serviceQuoteId,
        public readonly string $numero,
        public readonly int    $ticketId,
        public readonly float  $total,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'servicios.quote.sent';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'service_quote_id' => $this->serviceQuoteId,
            'numero'            => $this->numero,
            'ticket_id'         => $this->ticketId,
            'total'             => $this->total,
            'tenant'            => $this->tenant,
        ];
    }
}
