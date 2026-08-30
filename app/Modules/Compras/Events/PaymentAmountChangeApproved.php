<?php

declare(strict_types=1);

namespace App\Modules\Compras\Events;

use App\Shared\Contracts\DomainEvent;

/** SCRUM-252 (REQ-189) RN7 — Mark aprobó un cambio de monto a pagar antes del primer pago. */
final class PaymentAmountChangeApproved implements DomainEvent
{
    public function __construct(
        public readonly int    $orderId,
        public readonly float  $previousAmount,
        public readonly float  $newAmount,
        public readonly int    $approvedBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'compras.payment.amount-change-approved';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'order_id'        => $this->orderId,
            'previous_amount' => $this->previousAmount,
            'new_amount'      => $this->newAmount,
            'approved_by'     => $this->approvedBy,
            'tenant'          => $this->tenant,
        ];
    }
}
