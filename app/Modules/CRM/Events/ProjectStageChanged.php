<?php

namespace App\Modules\CRM\Events;

use App\Shared\Contracts\DomainEvent;

class ProjectStageChanged implements DomainEvent
{
    public function __construct(
        public readonly int    $projectId,
        public readonly string $nombre,
        public readonly string $oldEtapa,
        public readonly string $newEtapa,
        public readonly int    $changedBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'crm.project.stage-changed';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'project_id' => $this->projectId,
            'nombre'     => $this->nombre,
            'old_etapa'  => $this->oldEtapa,
            'new_etapa'  => $this->newEtapa,
            'changed_by' => $this->changedBy,
            'tenant'     => $this->tenant,
        ];
    }
}
