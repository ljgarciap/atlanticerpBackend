<?php

declare(strict_types=1);

namespace App\Modules\CRM\Services;

use App\Modules\CRM\Events\DocumentDeleted;
use App\Modules\CRM\Events\DocumentUploaded;
use App\Modules\CRM\Models\ProjectDocument;
use App\Shared\Services\AuditService;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\CircuitBreaker;
use App\Shared\Support\PublicFileUrl;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

final class DocumentService
{
    /** Presigned URL lifetime for private documents (minutes) */
    private const PRESIGNED_URL_TTL = 15;

    public function __construct(private readonly AuditService $audit) {}

    /**
     * Uploads a file to S3, handles versioning, fires domain events and records audit entry.
     *
     * Storage key format: `{projectId}/{categoria}/{uuid}.{ext}`
     * The key is stored relative to the disk prefix (s3-private → 'private/', s3-public → 'public/').
     *
     * Versioning: if an is_current document with the same categoria already exists for the
     * project, it is marked is_current=false and the new document records it as its parent.
     *
     * @param 'private'|'public' $zone
     */
    public function upload(
        UploadedFile $file,
        int $projectId,
        string $categoria,
        string $zone,
        int $uploadedBy,
    ): ProjectDocument {
        $disk = $zone === ProjectDocument::ZONE_PUBLIC ? 's3-public' : 's3-private';
        $ext  = strtolower($file->getClientOriginalExtension());
        $key  = "{$projectId}/{$categoria}/" . Str::uuid()->toString() . ($ext !== '' ? ".{$ext}" : '');

        // Build public_url for public zone — CDN_URL (s3-public.cdn_url) or AWS_URL + '/public/' + key
        $publicUrl = null;
        if ($zone === ProjectDocument::ZONE_PUBLIC) {
            $publicUrl = PublicFileUrl::for($key);
        }

        $document = DB::transaction(function () use (
            $file, $projectId, $categoria, $zone, $uploadedBy, $disk, $key, $publicUrl
        ): ProjectDocument {
            // Upload via circuit breaker — fast-fails with CircuitOpenException when S3 is
            // degraded. putFileAs() returns false on silent failure (throw=false disk config),
            // which we convert to RuntimeException so the CB records it as a failure.
            (new CircuitBreaker($disk))->call(function () use ($disk, $projectId, $categoria, $file, $key): void {
                $uploaded = Storage::disk($disk)->putFileAs(
                    "{$projectId}/{$categoria}",
                    $file,
                    basename($key),
                );

                if ($uploaded === false) {
                    throw new \RuntimeException("S3 upload failed for disk [{$disk}] key [{$key}]");
                }
            });

            try {
                // Find existing current document for this project + categoria (versioning)
                $previous = ProjectDocument::where('project_id', $projectId)
                    ->where('categoria', $categoria)
                    ->where('is_current', true)
                    ->first();

                $version          = $previous !== null ? $previous->version + 1 : 1;
                $parentDocumentId = $previous?->id;

                if ($previous !== null) {
                    $previous->is_current = false;
                    $previous->save();
                }

                /** @var ProjectDocument $doc */
                $doc = ProjectDocument::create([
                    'project_id'         => $projectId,
                    'categoria'          => $categoria,
                    'nombre_archivo'     => $file->getClientOriginalName(),
                    'storage_key'        => $key,
                    'size_bytes'         => $file->getSize(),
                    'mime_type'          => $file->getMimeType() ?? 'application/octet-stream',
                    'storage_zone'       => $zone,
                    'public_url'         => $publicUrl,
                    'version'            => $version,
                    'parent_document_id' => $parentDocumentId,
                    'is_current'         => true,
                    'uploaded_by'        => $uploadedBy,
                ]);
            } catch (\Throwable $e) {
                // Roll back the storage upload to avoid orphaned files
                try {
                    Storage::disk($disk)->delete($key);
                } catch (\Throwable $se) {
                    Log::error('document_service.storage_cleanup_failed', [
                        'disk'  => $disk,
                        'key'   => $key,
                        'error' => $se->getMessage(),
                    ]);
                }

                throw $e;
            }

            return $doc;
        });

        $document->load('uploader');

        $tenantSlug = $this->requiredTenantSlug();

        // Determine if this is a new version or a first upload
        $isVersioned = $document->parent_document_id !== null;

        event(new DocumentUploaded(
            documentId:    $document->id,
            projectId:     $projectId,
            categoria:     $document->categoria,
            nombreArchivo: $document->nombre_archivo,
            sizeBytes:     $document->size_bytes,
            uploadedBy:    $uploadedBy,
            tenant:        $tenantSlug,
        ));

        $auditAction = $isVersioned
            ? AuditService::CRM_DOCUMENT_VERSION_CREATED
            : AuditService::CRM_DOCUMENT_UPLOADED;

        $this->audit->log(
            action:     $auditAction,
            entityType: 'project_document',
            entityId:   $document->id,
            newValues:  $document->toArray(),
        );

        return $document;
    }

    /**
     * Generates a 15-minute presigned URL for a private document.
     * Records a download audit entry — only for authenticated download endpoints
     * (DocumentController). Anonymous/public access paths must use
     * resolveShareableUrl() instead, which does not attribute the access to a
     * "download" in the audit trail.
     */
    public function getPresignedUrl(ProjectDocument $doc): string
    {
        $url = $this->presignUrl($doc);

        $this->audit->log(
            action:     AuditService::CRM_DOCUMENT_DOWNLOADED,
            entityType: 'project_document',
            entityId:   $doc->id,
        );

        return $url;
    }

    /**
     * Resolves the URL a share link should redirect to, honoring storage_zone:
     * public documents already have a permanent public_url (no presigning needed);
     * private documents get a 15-minute presigned URL. No audit side effect —
     * anonymous share-link access is tracked on the ShareLink row itself
     * (access_count/last_accessed_at), not as a CRM_DOCUMENT_DOWNLOADED entry.
     */
    public function resolveShareableUrl(ProjectDocument $doc): string
    {
        return $doc->storage_zone === ProjectDocument::ZONE_PUBLIC
            ? (string) $doc->public_url
            : $this->presignUrl($doc);
    }

    private function presignUrl(ProjectDocument $doc): string
    {
        return Storage::disk('s3-private')->temporaryUrl(
            $doc->storage_key,
            now()->addMinutes(self::PRESIGNED_URL_TTL),
        );
    }

    /**
     * Deletes a document from storage and the database.
     *
     * If the deleted document was the current version (is_current=true) and it has a
     * parent, the parent is promoted back to is_current=true.
     *
     * Records a delete audit entry.
     */
    public function delete(ProjectDocument $doc): void
    {
        $disk       = $doc->diskName();
        $storageKey = $doc->storage_key;
        $documentId = $doc->id;
        $projectId  = $doc->project_id;

        DB::transaction(function () use ($doc): void {
            $wasCurrent    = $doc->is_current;
            $parentId      = $doc->parent_document_id;

            $doc->delete();

            // Promote the previous version back to current when the current is deleted
            if ($wasCurrent && $parentId !== null) {
                ProjectDocument::where('id', $parentId)->update(['is_current' => true]);
            }
        });

        try {
            (new CircuitBreaker($disk))->call(fn () => Storage::disk($disk)->delete($storageKey));
        } catch (\Throwable $e) {
            Log::error('document_service.storage_delete_failed', [
                'disk'        => $disk,
                'storage_key' => $storageKey,
                'document_id' => $documentId,
                'error'       => $e->getMessage(),
            ]);
        }

        event(new DocumentDeleted(
            documentId: $documentId,
            projectId:  $projectId,
            storageKey: $storageKey,
            tenant:     $this->requiredTenantSlug(),
        ));

        $this->audit->log(
            action:     AuditService::CRM_DOCUMENT_DELETED,
            entityType: 'project_document',
            entityId:   $documentId,
        );
    }

    /**
     * Returns the documents for a project, optionally filtered to current versions only.
     *
     * @return Collection<int, ProjectDocument>
     */
    public function listForProject(int $projectId, bool $currentOnly = true): Collection
    {
        $query = ProjectDocument::with('uploader')
            ->where('project_id', $projectId);

        if ($currentOnly) {
            $query->where('is_current', true);
        }

        return $query->orderByDesc('created_at')->orderByDesc('id')->get();
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function requiredTenantSlug(): string
    {
        $tenant = Tenant::current();

        if ($tenant === null) {
            throw new \RuntimeException('Cannot dispatch domain event without an active tenant.');
        }

        return $tenant->slug;
    }
}
