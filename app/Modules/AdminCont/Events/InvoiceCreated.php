<?php

declare(strict_types=1);

namespace App\Modules\AdminCont\Events;

use App\Shared\Contracts\DomainEvent;

/**
 * REQ-441 RN4 — se dispara al confirmar una factura (`AdminContInvoiceController::store()`),
 * inmediatamente después de que `InvoiceService::confirm()` complete sin excepción. Único
 * consumidor hoy: `App\Modules\Bodega\Listeners\MarkOrderInvoiceReady`, que reemplaza el stub
 * `Order::invoice_ready` por el estado real — mismo criterio de acoplamiento mínimo que
 * `Compras\Events\PurchaseOrderReceived` (el listener nunca vuelve a consultar `admin_contab`
 * directo, todo lo que necesita ya viaja en el payload).
 */
final class InvoiceCreated implements DomainEvent
{
    public function __construct(
        public readonly int $invoiceId,
        public readonly int $orderId,
        public readonly string $numero,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'admin_contab.invoice.created';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'invoice_id' => $this->invoiceId,
            'order_id'   => $this->orderId,
            'numero'     => $this->numero,
            'tenant'     => $this->tenant,
        ];
    }
}
