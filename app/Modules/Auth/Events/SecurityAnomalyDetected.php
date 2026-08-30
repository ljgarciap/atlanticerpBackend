<?php

declare(strict_types=1);

namespace App\Modules\Auth\Events;

use App\Modules\Auth\Models\SecurityAnomaly;
use App\Shared\Contracts\DomainEvent;

final class SecurityAnomalyDetected implements DomainEvent
{
    public function __construct(
        public readonly SecurityAnomaly $anomaly,
    ) {}

    public function eventName(): string { return 'auth.security.anomaly-detected'; }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'anomaly_id'        => $this->anomaly->id,
            'severity'          => $this->anomaly->severity,
            'affected_user_id'  => $this->anomaly->affected_user_id,
            'reasoning'         => $this->anomaly->reasoning,
        ];
    }
}
