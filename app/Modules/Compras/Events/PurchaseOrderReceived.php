<?php

declare(strict_types=1);

namespace App\Modules\Compras\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * SCRUM-406 RN2 (ADR `docs/adr/ADR-SCRUM406-compras-bodega-stock-bridge.md`) — se dispara en
 * `PurchaseOrderController::advance()`, inmediatamente después de que
 * `GoodsReceiptConfirmationService::confirmPendingForOrder()` complete sin excepción (la orden ya
 * pasó a `STATUS_RECIBIDO`). Único consumidor hoy: `App\Modules\Bodega\Listeners\
 * ReleaseOrdersAwaitingStock`, que libera las tarjetas "Sin stock" (`Order.order_type =
 * faltante`) cuyo stock real ya alcanza. `product_ids` va en el payload (no se re-consulta desde
 * el listener) para que Bodega no necesite conocer el shape interno de `PurchaseOrderLine` — mismo
 * criterio de acoplamiento mínimo que ya sigue el resto de `DomainEvent`.
 */
final class PurchaseOrderReceived implements DomainEvent
{
    /** @param list<int> $productIds catalog_product_id de las líneas de esta orden de compra */
    public function __construct(
        public readonly int $purchaseOrderId,
        public readonly array $productIds,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'compras.purchase-order.received';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'purchase_order_id' => $this->purchaseOrderId,
            'product_ids'       => $this->productIds,
            'tenant'            => $this->tenant,
        ];
    }
}
