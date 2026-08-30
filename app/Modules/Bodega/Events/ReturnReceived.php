<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-479/480 (REQ-409/410, Bloque B6 "Devoluciones") — disparado al confirmar la recepción
 * física de una devolución (`CustomerReturnService::confirmReception()`). Deja la puerta abierta
 * a un listener real de Administración & Contabilidad el día que ese módulo exista — hoy no hay
 * ningún listener, mismo criterio que el resto del proyecto (interfaz lista, notificación real
 * diferida, ver ADR-SCRUM473-489-devoluciones.md).
 */
class ReturnReceived implements DomainEvent
{
    public function __construct(
        public readonly int $returnId,
        public readonly string $returnNumber,
        public readonly int $orderId,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'bodega.return.received';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'return_id'     => $this->returnId,
            'return_number' => $this->returnNumber,
            'order_id'      => $this->orderId,
            'tenant'        => $this->tenant,
        ];
    }
}
