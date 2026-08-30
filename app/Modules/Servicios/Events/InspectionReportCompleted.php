<?php

declare(strict_types=1);

namespace App\Modules\Servicios\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * Fase 4 — Servicios, Batch 8 (REQ-238→243), ver docs/architecture/servicios-fase4-diseno.md
 * sección 5. Disparado al completar (o re-completar) un Informe de Inspección — mismo criterio
 * que `TicketStageChanged`: se dispara aunque hoy no tenga listener real (el consumidor
 * documentado, `KardexService::registrar()` por cada material, es REQ-240 RN3 — DIFERIDO a Batch
 * 14, ver docblock de `InspectionReportService`). El payload incluye `materiales` para que ese
 * listener futuro no tenga que volver a consultar el informe.
 */
class InspectionReportCompleted implements DomainEvent
{
    /** @param list<array{nombre_material: string, cantidad: string}> $materiales */
    public function __construct(
        public readonly int    $inspectionReportId,
        public readonly string $numero,
        public readonly int    $ticketId,
        public readonly array  $materiales,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'servicios.inspection-report.completed';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'inspection_report_id' => $this->inspectionReportId,
            'numero'                => $this->numero,
            'ticket_id'             => $this->ticketId,
            'materiales'            => $this->materiales,
            'tenant'                => $this->tenant,
        ];
    }
}
