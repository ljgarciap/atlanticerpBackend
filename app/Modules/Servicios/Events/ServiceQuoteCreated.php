<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Events;

use App\Shared\Contracts\DomainEvent;

/** Fase 4 — Servicios, Batch 11 (REQ-229). Disparado al asignar folio (creación de la fila). */
class ServiceQuoteCreated implements DomainEvent
{
    public function __construct(
        public readonly int    $serviceQuoteId,
        public readonly string $numero,
        public readonly int    $ticketId,
        public readonly int    $createdBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'servicios.quote.created';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'service_quote_id' => $this->serviceQuoteId,
            'numero'            => $this->numero,
            'ticket_id'         => $this->ticketId,
            'created_by'        => $this->createdBy,
            'tenant'            => $this->tenant,
        ];
    }
}
