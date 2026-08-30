<?php

declare(strict_types=1);

namespace App\Modules\Compras\Events;

use App\Shared\Contracts\DomainEvent;

/** SCRUM-252 (REQ-189) — un pago real se registró con comprobante. */
final class PaymentRegistered implements DomainEvent
{
    public function __construct(
        public readonly int    $orderId,
        public readonly int    $paymentId,
        public readonly float  $amount,
        public readonly string $paymentStatus,
        public readonly int    $registeredBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'compras.payment.registered';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'order_id'       => $this->orderId,
            'payment_id'     => $this->paymentId,
            'amount'         => $this->amount,
            'payment_status' => $this->paymentStatus,
            'registered_by'  => $this->registeredBy,
            'tenant'         => $this->tenant,
        ];
    }
}
