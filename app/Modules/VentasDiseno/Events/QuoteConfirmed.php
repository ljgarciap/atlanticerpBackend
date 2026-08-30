<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-734 (Batch F, sección 11-12) — disparado por QuoteService::confirm()
 * cuando una cotización queda registrada oficialmente (confirmed_at), justo
 * antes/junto con mover la tarjeta de Pipeline vinculada a etapa Cotización.
 * Higiene arquitectónica (consistencia con el resto del sistema de eventos,
 * ej. QuoteApproved) — ningún AC del ticket exige un consumidor real hoy; no
 * hay listener todavía. `isNewVersion` distingue la primera confirmación de un
 * proyecto de una "nueva versión" (RN2.3) sobre una tarjeta que ya tenía una
 * cotización confirmada antes — útil para un futuro consumidor que solo le
 * importen versiones nuevas (ej. notificar al cliente).
 */
class QuoteConfirmed implements DomainEvent
{
    public function __construct(
        public readonly int $quoteId,
        public readonly int $salesProjectId,
        public readonly int $pipelineCardId,
        public readonly int $ownerId,
        public readonly string $folio,
        public readonly float $grandTotal,
        public readonly bool $isNewVersion,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'ventasdiseno.quote.confirmed';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'quote_id'         => $this->quoteId,
            'sales_project_id' => $this->salesProjectId,
            'pipeline_card_id' => $this->pipelineCardId,
            'owner_id'         => $this->ownerId,
            'folio'            => $this->folio,
            'grand_total'      => $this->grandTotal,
            'is_new_version'   => $this->isNewVersion,
            'tenant'           => $this->tenant,
        ];
    }
}
