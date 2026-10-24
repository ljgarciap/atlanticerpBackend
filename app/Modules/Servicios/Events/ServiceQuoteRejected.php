<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * Fase 4 — Servicios, Batch 11 (REQ-234). Enviada -> Rechazada, exclusivo de Lider Servicios/lider_servicios.
 * Habilita "Generar nueva cotización" sobre este mismo ticket (RN: solo si esta es la más reciente).
 */
class ServiceQuoteRejected implements DomainEvent
{
    public function __construct(
        public readonly int    $serviceQuoteId,
        public readonly string $numero,
        public readonly int    $ticketId,
        public readonly int    $decidedBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'servicios.quote.rejected';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'service_quote_id' => $this->serviceQuoteId,
            'numero'            => $this->numero,
            'ticket_id'         => $this->ticketId,
            'decided_by'        => $this->decidedBy,
            'tenant'            => $this->tenant,
        ];
    }
}
