<?php

declare(strict_types=1);

namespace App\Modules\Compras\Events;

use App\Shared\Contracts\DomainEvent;

/** SCRUM-252 (REQ-189) — Compras notifica a Mark que hay que pagar. No bloquea nada por sí solo. */
final class PaymentRequested implements DomainEvent
{
    public function __construct(
        public readonly int    $orderId,
        public readonly int    $requestedBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'compras.payment.requested';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'order_id'     => $this->orderId,
            'requested_by' => $this->requestedBy,
            'tenant'       => $this->tenant,
        ];
    }
}
