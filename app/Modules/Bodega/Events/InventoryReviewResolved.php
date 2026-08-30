<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-329 Oleada A — declarado en Batch A1 (fundación), sin disparador todavía. REQ-317/318
 * (revisión de inventario cuando falta stock durante picking) lo dispara en Batch A2 cuando se
 * resuelve una tarjeta de tipo `Order::ORDER_TYPE_REVISION_INVENTARIO`. Shape provisional.
 */
class InventoryReviewResolved implements DomainEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly int $resolvedBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'bodega.inventory-review.resolved';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'order_id'     => $this->orderId,
            'order_number' => $this->orderNumber,
            'resolved_by'  => $this->resolvedBy,
            'tenant'       => $this->tenant,
        ];
    }
}
