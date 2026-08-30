<?php

declare(strict_types=1);

namespace App\Modules\Compras\Events;

use App\Shared\Contracts\DomainEvent;

/** SCRUM-260 (REQ-197) */
final class ClaimCreated implements DomainEvent
{
    public function __construct(
        public readonly int    $claimId,
        public readonly int    $orderId,
        public readonly int    $createdBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'compras.claim.created';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'claim_id'   => $this->claimId,
            'order_id'   => $this->orderId,
            'created_by' => $this->createdBy,
            'tenant'     => $this->tenant,
        ];
    }
}
