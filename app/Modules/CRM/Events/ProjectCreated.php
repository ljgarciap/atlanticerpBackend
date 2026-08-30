<?php

namespace App\Modules\CRM\Events;

use App\Shared\Contracts\DomainEvent;

class ProjectCreated implements DomainEvent
{
    public function __construct(
        public readonly int    $projectId,
        public readonly string $nombre,
        public readonly string $etapa,
        public readonly int    $createdBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'crm.project.created';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'project_id' => $this->projectId,
            'nombre'     => $this->nombre,
            'etapa'      => $this->etapa,
            'created_by' => $this->createdBy,
            'tenant'     => $this->tenant,
        ];
    }
}
