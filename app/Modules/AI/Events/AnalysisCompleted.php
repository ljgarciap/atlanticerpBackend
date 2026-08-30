<?php

declare(strict_types=1);

namespace App\Modules\AI\Events;

use App\Shared\Contracts\DomainEvent;

final class AnalysisCompleted implements DomainEvent
{
    public function __construct(
        public readonly string $analysisId,
        public readonly bool   $success,
    ) {}

    public function eventName(): string { return 'ai.analysis.completed'; }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'analysis_id' => $this->analysisId,
            'success'     => $this->success,
        ];
    }
}
