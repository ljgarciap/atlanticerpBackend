<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-329 Oleada A — declarado en Batch A1 (fundación), sin disparador todavía. Batch A2 lo
 * dispara cuando un `Order` pasa a la etapa entregado (cierre del flujo). Shape provisional.
 */
class OrderDelivered implements DomainEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'bodega.order.delivered';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'order_id'     => $this->orderId,
            'order_number' => $this->orderNumber,
            'tenant'       => $this->tenant,
        ];
    }
}
