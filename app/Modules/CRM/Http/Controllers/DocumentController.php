<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Models\User;
use App\Modules\Auth\Models\GlobalSetting;
use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Models\ProjectDocument;
use App\Modules\CRM\Services\DocumentService;
use App\Shared\Support\CircuitOpenException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Cache;

final class DocumentController extends Controller
{
    private const DEFAULT_MAX_SIZE_MB = 500;

    private const CACHE_KEY = 'global:documents.max_size_mb';

    private const CACHE_TTL_SECONDS = 300;

    private const ALLOWED_MIMES = [
        'pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'ppt', 'pptx',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp',
        'zip', 'rar', '7z',
        'dwg', 'dxf', 'rvt',
        'obj', 'fbx', 'glb', 'gltf', 'blend', 'skp', 'ma', 'mb',
    ];

    public function __construct(private readonly DocumentService $documents) {}

    // ── GET /api/crm/projects/{projectId}/documents ───────────────────────────

    public function index(Request $request, int $projectId): JsonResponse
    {
        [$user, $project, $error] = $this->resolveProjectAccess($request, $projectId);

        if ($error !== null) {
            return $error;
        }

        /** @var User $user */
        /** @var Project $project */
        $documents = $this->documents->listForProject($project->id, currentOnly: true);

        return response()->json(
            $documents->map(fn (ProjectDocument $d) => $this->format($d))->values()
        );
    }

    // ── GET /api/crm/projects/{projectId}/documents/history ───────────────────

    public function history(Request $request, int $projectId): JsonResponse
    {
        [$user, $project, $error] = $this->resolveProjectAccess($request, $projectId);

        if ($error !== null) {
            return $error;
        }

        /** @var Project $project */
        $documents = $this->documents->listForProject($project->id, currentOnly: false);

        return response()->json(
            $documents->map(fn (ProjectDocument $d) => $this->formatWithVersion($d))->values()
        );
    }

    // ── POST /api/crm/projects/{projectId}/documents ──────────────────────────

    public function store(Request $request, int $projectId): JsonResponse
    {
        [$user, $project, $error] = $this->resolveProjectAccess($request, $projectId);

        if ($error !== null) {
            return $error;
        }

        /** @var User $user */
        /** @var Project $project */
        $maxKb = $this->resolveMaxSizeKb();
        $allowedExt = implode(',', self::ALLOWED_MIMES);

        $validated = $request->validate([
            // extensions (client filename), not mimes (content-sniffed): mimes: fails
            // silently for extensions Symfony's MIME registry doesn't recognize (rvt,
            // and fbx before this fix) even when explicitly whitelisted, since it
            // validates via a MIME-type round trip rather than the extension string.
            'file' => "required|file|max:{$maxKb}|extensions:{$allowedExt}",
            'categoria' => 'required|in:cotizacion,diseno,presentacion,plano,render,ficha,modelo_3d,otro',
            'zone' => 'sometimes|in:private,public',
        ]);

        /** @var UploadedFile $file */
        $file = $request->file('file');

        /** @var string $categoria */
        $categoria = $validated['categoria'];

        /** @var 'private'|'public' $zone */
        $zone = isset($validated['zone']) ? (string) $validated['zone'] : ProjectDocument::ZONE_PRIVATE;

        try {
            $document = $this->documents->upload($file, $project->id, $categoria, $zone, $user->id);
        } catch (CircuitOpenException) {
            return response()->json([
                'message' => 'Storage service temporarily unavailable. Please try again in a few minutes.',
            ], 503);
        }

        return response()->json($this->format($document), 201);
    }

    // ── GET /api/crm/documents/{document}/url ─────────────────────────────────

    public function url(Request $request, int $documentId): JsonResponse
    {
        [$user, $doc, $error] = $this->resolveDocumentAccess($request, $documentId);

        if ($error !== null) {
            return $error;
        }

        /** @var ProjectDocument $doc */
        if ($doc->storage_zone === ProjectDocument::ZONE_PUBLIC) {
            return response()->json([
                'url' => $doc->public_url,
                'filename' => $doc->nombre_archivo,
                'zone' => 'public',
            ]);
        }

        // Private — generate 15-minute presigned URL
        $presignedUrl = $this->documents->getPresignedUrl($doc);

        return response()->json([
            'url' => $presignedUrl,
            'filename' => $doc->nombre_archivo,
            'zone' => 'private',
        ]);
    }

    // ── GET /api/crm/projects/{projectId}/documents/{documentId}/download ───────
    // Kept for backward compatibility with existing FE clients.

    public function download(Request $request, int $projectId, int $documentId): JsonResponse
    {
        [$user, $project, $error] = $this->resolveProjectAccess($request, $projectId);

        if ($error !== null) {
            return $error;
        }

        /** @var Project $project */
        $document = $project->documents()->find($documentId);

        if ($document === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        // Delegate to the url() logic reusing the document resolution
        return $this->buildUrlResponse($document);
    }

    // ── DELETE /api/crm/documents/{documentId}
    // ── DELETE /api/crm/projects/{projectId}/documents/{documentId} ──────────
    // Shared handler for both routes. Read {documentId} by name to avoid
    // positional injection issues when {projectId} precedes {documentId}.

    public function destroy(Request $request): JsonResponse
    {
        $raw = $request->route('documentId');

        if (! is_string($raw) && ! is_int($raw)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $documentId = (int) $raw;

        [$user, $doc, $error] = $this->resolveDocumentAccess($request, $documentId);

        if ($error !== null) {
            return $error;
        }

        /** @var ProjectDocument $doc */
        $this->documents->delete($doc);

        return response()->json(null, 204);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolves user + project + access check for project-scoped routes.
     *
     * @return array{User|null, Project|null, JsonResponse|null}
     */
    private function resolveProjectAccess(Request $request, int $projectId): array
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            return [null, null, response()->json(['message' => trans('auth.unauthenticated')], 401)];
        }

        $project = Project::with('assignees')->find($projectId);

        if ($project === null) {
            return [null, null, response()->json(['message' => 'Not found'], 404)];
        }

        if (! $this->canAccess($project, $user)) {
            return [null, null, response()->json(['message' => trans('auth.unauthorized')], 403)];
        }

        return [$user, $project, null];
    }

    /**
     * Resolves user + document + access check for document-scoped routes.
     *
     * @return array{User|null, ProjectDocument|null, JsonResponse|null}
     */
    private function resolveDocumentAccess(Request $request, int $documentId): array
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            return [null, null, response()->json(['message' => trans('auth.unauthenticated')], 401)];
        }

        $doc = ProjectDocument::find($documentId);

        if ($doc === null) {
            return [null, null, response()->json(['message' => 'Not found'], 404)];
        }

        $project = Project::with('assignees')->find($doc->project_id);

        if ($project === null) {
            return [null, null, response()->json(['message' => 'Not found'], 404)];
        }

        if (! $this->canAccess($project, $user)) {
            return [null, null, response()->json(['message' => trans('auth.unauthorized')], 403)];
        }

        return [$user, $doc, null];
    }

    private function buildUrlResponse(ProjectDocument $document): JsonResponse
    {
        if ($document->storage_zone === ProjectDocument::ZONE_PUBLIC) {
            return response()->json([
                'url' => $document->public_url,
                'filename' => $document->nombre_archivo,
                'zone' => 'public',
            ]);
        }

        // Private — generate presigned URL
        $presignedUrl = $this->documents->getPresignedUrl($document);

        return response()->json([
            'url' => $presignedUrl,
            'filename' => $document->nombre_archivo,
            'zone' => 'private',
        ]);
    }

    private function resolveUser(Request $request): ?User
    {
        /** @var array{sub?: int}|null $payload */
        $payload = $request->attributes->get('jwt');

        if ($payload === null || ! isset($payload['sub'])) {
            return null;
        }

        return User::find((int) $payload['sub']);
    }

    private function canAccess(Project $project, User $user): bool
    {
        if ($user->canViewTeamV2('ventas_diseno')) {
            return true;
        }

        return $project->assignees->contains('id', $user->id);
    }

    private function resolveMaxSizeKb(): int
    {
        /** @var int $mb */
        $mb = Cache::remember(self::CACHE_KEY, self::CACHE_TTL_SECONDS, function (): int {
            try {
                $setting = GlobalSetting::find('documents.max_size_mb');
                if ($setting !== null && $setting->value !== '') {
                    return (int) $setting->value;
                }
            } catch (\Throwable) {
                // Table may not exist yet during migrations — fall through to default
            }

            return self::DEFAULT_MAX_SIZE_MB;
        });

        return $mb * 1024;
    }

    /** @return array<string, mixed> */
    private function format(ProjectDocument $document): array
    {
        $uploader = $document->uploader;

        return [
            'id' => $document->id,
            'project_id' => $document->project_id,
            'categoria' => $document->categoria,
            'nombre_archivo' => $document->nombre_archivo,
            'size_bytes' => $document->size_bytes,
            'mime_type' => $document->mime_type,
            'storage_zone' => $document->storage_zone,
            'is_current' => $document->is_current,
            'version' => $document->version,
            'uploader' => [
                'id' => $uploader->id,
                'first_name' => $uploader->first_name,
                'last_name' => $uploader->last_name,
            ],
            'created_at' => $document->created_at->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function formatWithVersion(ProjectDocument $document): array
    {
        return array_merge($this->format($document), [
            'parent_document_id' => $document->parent_document_id,
        ]);
    }
}
