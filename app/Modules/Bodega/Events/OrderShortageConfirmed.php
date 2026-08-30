<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-329 Oleada A — declarado en Batch A1 (fundación), sin disparador todavía. REQ-317
 * (faltante confirmado tras revisión de inventario) lo dispara en Batch A2, marcando
 * `OrderItem.shortage_reason`. Shape provisional.
 */
class OrderShortageConfirmed implements DomainEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly int $orderItemId,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'bodega.order.shortage-confirmed';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'order_id'      => $this->orderId,
            'order_number'  => $this->orderNumber,
            'order_item_id' => $this->orderItemId,
            'tenant'        => $this->tenant,
        ];
    }
}
