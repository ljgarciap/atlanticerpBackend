<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-329 Oleada A — declarado en Batch A1 (fundación), sin disparador todavía. Batch A2 lo
 * dispara cuando el picker marca completo el picking de un `Order` (etapa en_picking ->
 * packing). Shape provisional.
 */
class PickingCompleted implements DomainEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly int $pickerId,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'bodega.picking.completed';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'order_id'     => $this->orderId,
            'order_number' => $this->orderNumber,
            'picker_id'    => $this->pickerId,
            'tenant'       => $this->tenant,
        ];
    }
}
