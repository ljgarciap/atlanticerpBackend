<?php

declare(strict_types=1);

namespace App\Modules\Compras\Events;

use App\Shared\Contracts\DomainEvent;

/** SCRUM-258 (REQ-195) */
final class ClaimStatusChanged implements DomainEvent
{
    public function __construct(
        public readonly int    $claimId,
        public readonly string $previousStatus,
        public readonly string $newStatus,
        public readonly int    $changedBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'compras.claim.status-changed';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'claim_id'         => $this->claimId,
            'previous_status'  => $this->previousStatus,
            'new_status'       => $this->newStatus,
            'changed_by'       => $this->changedBy,
            'tenant'           => $this->tenant,
        ];
    }
}
