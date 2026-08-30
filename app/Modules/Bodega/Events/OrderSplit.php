<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-329 Oleada A — declarado en Batch A1 (fundación), sin disparador todavía. REQ-319
 * (entregas parciales / familias) lo dispara en Batch A2 cuando un `Order` se divide en varias
 * tarjetas hijas (`Order.family_id`). Shape provisional.
 */
class OrderSplit implements DomainEvent
{
    /** @param list<int> $childOrderIds */
    public function __construct(
        public readonly int $parentOrderId,
        public readonly string $orderNumber,
        public readonly array $childOrderIds,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'bodega.order.split';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'parent_order_id' => $this->parentOrderId,
            'order_number'    => $this->orderNumber,
            'child_order_ids' => $this->childOrderIds,
            'tenant'          => $this->tenant,
        ];
    }
}
