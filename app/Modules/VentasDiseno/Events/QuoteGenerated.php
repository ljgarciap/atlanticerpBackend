<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-734 (Batch F, sección 11-12) — disparado por QuoteService::generate()
 * cuando se le asigna folio a una cotización por primera vez. Higiene
 * arquitectónica (consistencia con el resto del sistema de eventos, ej.
 * QuoteApproved) — ningún AC del ticket exige un consumidor real hoy; no hay
 * listener todavía.
 */
class QuoteGenerated implements DomainEvent
{
    public function __construct(
        public readonly int $quoteId,
        public readonly ?int $salesProjectId,
        public readonly int $ownerId,
        public readonly string $folio,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'ventasdiseno.quote.generated';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'quote_id'         => $this->quoteId,
            'sales_project_id' => $this->salesProjectId,
            'owner_id'         => $this->ownerId,
            'folio'            => $this->folio,
            'tenant'           => $this->tenant,
        ];
    }
}
