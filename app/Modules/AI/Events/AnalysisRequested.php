<?php

declare(strict_types=1);

namespace App\Modules\AI\Events;

use App\Shared\Contracts\DomainEvent;

final class AnalysisRequested implements DomainEvent
{
    public function __construct(
        public readonly string $analysisId,
        public readonly string $entityType,
        public readonly string $analysisType,
    ) {}

    public function eventName(): string { return 'ai.analysis.requested'; }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'analysis_id'   => $this->analysisId,
            'entity_type'   => $this->entityType,
            'analysis_type' => $this->analysisType,
        ];
    }
}
