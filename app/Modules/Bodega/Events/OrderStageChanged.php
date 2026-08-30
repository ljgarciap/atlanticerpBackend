<?php

declare(strict_types=1);

namespace App\Modules\Bodega\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-329 Oleada A — declarado en Batch A1 (fundación), sin disparador todavía. Batch A2
 * (Pedidos backend — transiciones del tablero Kanban) dispara este evento cada vez que
 * `OrderService`/el service que reemplace la lógica de picking mueva un `Order` de etapa, mismo
 * patrón que `CRM\Events\ProjectStageChanged`. Shape provisional — ajustar en A2 si hace falta
 * más contexto (ej. motivo del salto de etapa).
 */
class OrderStageChanged implements DomainEvent
{
    public function __construct(
        public readonly int $orderId,
        public readonly string $orderNumber,
        public readonly ?string $fromStage,
        public readonly string $toStage,
        public readonly ?int $changedBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'bodega.order.stage-changed';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'order_id'     => $this->orderId,
            'order_number' => $this->orderNumber,
            'from_stage'   => $this->fromStage,
            'to_stage'     => $this->toStage,
            'changed_by'   => $this->changedBy,
            'tenant'       => $this->tenant,
        ];
    }
}
