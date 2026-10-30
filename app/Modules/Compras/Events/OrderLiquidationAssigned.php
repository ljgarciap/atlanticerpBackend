<?php

declare(strict_types=1);

namespace App\Modules\Compras\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-210 (REQ-147, alcance reducido) — se dispara al asignar/cambiar la agencia de
 * liquidación de una orden de importación. Hoy no tiene ningún listener (el consumidor real, Bodega,
 * todavía no existe — ver CLAUDE.md, sprint de Bodega pendiente); el evento ya queda disponible
 * para que ese módulo lo escuche sin tener que tocar Compras de nuevo cuando se construya.
 * RN6 del ticket ("reflejarse en la tarjeta 'Nuevos pedidos' del Inicio de Bodega... asignado al
 * responsable según el vendedor/diseñador del proyecto") queda fuera de este evento — ese mapeo
 * es responsabilidad del listener que Bodega agregue, no de quien lo dispara.
 */
final class OrderLiquidationAssigned implements DomainEvent
{
    public function __construct(
        public readonly int    $orderId,
        public readonly int    $agencyId,
        public readonly string $agencyName,
        public readonly int    $assignedBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'compras.order.liquidation-assigned';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'order_id'    => $this->orderId,
            'agency_id'   => $this->agencyId,
            'agency_name' => $this->agencyName,
            'assigned_by' => $this->assignedBy,
            'tenant'      => $this->tenant,
        ];
    }
}
