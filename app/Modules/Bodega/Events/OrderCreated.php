<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-329 Oleada A / Batch A1 (REQ-308) — disparado por `OrderService::createFromApprovedQuote()`
 * justo después de crear el `Order` + sus `OrderItem` + la primera fila de `OrderStageHistory`.
 * Único evento de Bodega que se dispara de verdad en este batch — el resto (Batch A2 en
 * adelante) queda declarado para que el listener/observer que corresponda lo dispare cuando
 * exista esa lógica.
 *
 * `assistantId` es nullable (ajuste 2026-07-22): un pedido puede nacer sin asistente asignado si
 * falta el `WarehouseAssistantMapping` del vendedor — ver
 * `OrderService::createFromApprovedQuote()`.
 */
class OrderCreated implements DomainEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly ?int $assistantId,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'bodega.order.created';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'order_id'     => $this->orderId,
            'order_number' => $this->orderNumber,
            'assistant_id' => $this->assistantId,
            'tenant'       => $this->tenant,
        ];
    }
}
