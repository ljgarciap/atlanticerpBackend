<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Shared\Contracts\DomainEvent;

class DocumentDeleted implements DomainEvent
{
    public function __construct(
        public readonly int    $documentId,
        public readonly int    $projectId,
        public readonly string $storageKey,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'crm.document.deleted';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'document_id' => $this->documentId,
            'project_id'  => $this->projectId,
            'storage_key' => $this->storageKey,
            'tenant'      => $this->tenant,
        ];
    }
}
