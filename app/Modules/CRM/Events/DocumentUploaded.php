<?php

declare(strict_types=1);

namespace App\Modules\CRM\Events;

use App\Shared\Contracts\DomainEvent;

class DocumentUploaded implements DomainEvent
{
    public function __construct(
        public readonly int    $documentId,
        public readonly int    $projectId,
        public readonly string $categoria,
        public readonly string $nombreArchivo,
        public readonly int    $sizeBytes,
        public readonly int    $uploadedBy,
        public readonly string $tenant,
    ) {}

    public function eventName(): string
    {
        return 'crm.document.uploaded';
    }

    /** @return array<string, mixed> */
    public function payload(): array
    {
        return [
            'document_id'    => $this->documentId,
            'project_id'     => $this->projectId,
            'categoria'      => $this->categoria,
            'nombre_archivo' => $this->nombreArchivo,
            'size_bytes'     => $this->sizeBytes,
            'uploaded_by'    => $this->uploadedBy,
            'tenant'         => $this->tenant,
        ];
    }
}
