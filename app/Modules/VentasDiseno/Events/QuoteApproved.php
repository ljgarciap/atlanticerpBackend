<?php

declare(strict_types=1);

namespace App\Modules\VentasDiseno\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-308) — disparado por `PipelineService::changeStage()`
 * únicamente cuando una tarjeta de Pipeline entra a la etapa "approved" (Cotización aprobada),
 * nunca en otras transiciones. Consumido hoy por
 * `App\Modules\Bodega\Listeners\CreateOrderFromApprovedQuote` para crear automáticamente la
 * tarjeta de Pedido en Bodega — ver `docs/adr` o el reporte de Batch A1 para el porqué de este
 * punto de disparo (y no `CRM\Project.etapa -> 'cerrado'`, que era el supuesto original
 * incorrecto).
 */
class QuoteApproved implements DomainEvent
{
    public function __construct(
        public readonly int $pipelineCardId,
        public readonly int $salesProjectId,
        public readonly int $ownerId,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'ventasdiseno.quote.approved';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'pipeline_card_id' => $this->pipelineCardId,
            'sales_project_id' => $this->salesProjectId,
            'owner_id'         => $this->ownerId,
            'tenant'           => $this->tenant,
        ];
    }
}
