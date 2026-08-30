<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-329 Oleada A — declarado en Batch A1 (fundación), sin disparador todavía. Batch A2 lo
 * dispara al asignar `Order.courier_id` (etapa por_despachar). Shape provisional.
 */
class CourierAssigned implements DomainEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly int $courierId,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'bodega.courier.assigned';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'order_id'     => $this->orderId,
            'order_number' => $this->orderNumber,
            'courier_id'   => $this->courierId,
            'tenant'       => $this->tenant,
        ];
    }
}
