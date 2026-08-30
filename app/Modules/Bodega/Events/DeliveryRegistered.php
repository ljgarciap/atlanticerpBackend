<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-329 Oleada A — declarado en Batch A1 (fundación), sin disparador todavía. Batch A2 lo
 * dispara cuando se registra la entrega (total o parcial) de un `Order` en etapa despachado ->
 * entregado. Shape provisional.
 */
class DeliveryRegistered implements DomainEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly int $registeredBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'bodega.delivery.registered';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'order_id'      => $this->orderId,
            'order_number'  => $this->orderNumber,
            'registered_by' => $this->registeredBy,
            'tenant'        => $this->tenant,
        ];
    }
}
