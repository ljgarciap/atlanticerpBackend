<?php

namespace App\Modules\CRM\Events;

use App\Shared\Contracts\DomainEvent;

class ActivityAdded implements DomainEvent
{
    public function __construct(
        public readonly int    $activityId,
        public readonly int    $projectId,
        public readonly string $tipo,
        public readonly string $fecha,
        public readonly int    $userId,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'crm.project.activity-added';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'activity_id' => $this->activityId,
            'project_id'  => $this->projectId,
            'tipo'        => $this->tipo,
            'fecha'       => $this->fecha,
            'user_id'     => $this->userId,
            'tenant'      => $this->tenant,
        ];
    }
}
