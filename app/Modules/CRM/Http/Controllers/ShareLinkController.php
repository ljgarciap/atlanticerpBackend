<?php

declare(strict_types=1);

namespace App\Modules\CRM\Http\Controllers;

use App\Models\User;
use App\Modules\CRM\Models\Project;
use App\Modules\CRM\Models\ProjectDocument;
use App\Modules\CRM\Models\ShareLink;
use App\Modules\CRM\Services\DocumentService;
use App\Shared\Auth\TenantResolver;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class ShareLinkController extends Controller
{
    public function __construct(
        private readonly DocumentService $documents,
        private readonly TenantResolver $tenantResolver,
        private readonly AuditService $audit,
    ) {}

    // ── GET /public/share/{token} — sin autenticación ─────────────────────────

    public function publicAccess(Request $request, string $token): JsonResponse|RedirectResponse
    {
        $tenant = $this->tenantResolver->resolve($request);

        if ($tenant === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $tenant->makeCurrent();

        try {
            $link = ShareLink::findByRawToken($token);

            // 404 genérico en los 3 casos (no existe / expirado / revocado) —
            // no distinguir el motivo para no filtrar información al público.
            if ($link === null || ! $link->isActive()) {
                return response()->json(['message' => 'Not found'], 404);
            }

            try {
                $url = $this->documents->resolveShareableUrl($link->document);
            } catch (\Throwable $e) {
                return response()->json(['message' => 'El documento no está disponible en este momento.'], 503);
            }

            // Solo se cuenta un acceso una vez que sabemos que la URL resolvió bien.
            $link->increment('access_count', 1, ['last_accessed_at' => now()]);

            return redirect($url);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    // ── POST /api/projects/{projectId}/documents/{documentId}/share-links ────

    public function store(Request $request, int $projectId, int $documentId): JsonResponse
    {
        [$user, $document, $error] = $this->resolveDocumentAccess($request, $projectId, $documentId);

        if ($error !== null) {
            return $error;
        }

        /** @var User $user */
        /** @var ProjectDocument $document */
        $link = ShareLink::createFor($document, $user);

        $this->audit->log(
            action: AuditService::CRM_SHARE_LINK_CREATED,
            entityType: 'project_document',
            entityId: $document->id,
        );

        return response()->json($this->formatJustCreated($link), 201);
    }

    // ── GET /api/projects/{projectId}/documents/{documentId}/share-links ─────

    public function index(Request $request, int $projectId, int $documentId): JsonResponse
    {
        [$user, $document, $error] = $this->resolveDocumentAccess($request, $projectId, $documentId);

        if ($error !== null) {
            return $error;
        }

        /** @var ProjectDocument $document */
        $links = ShareLink::where('document_id', $document->id)
            ->with('creator')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($links->map(fn (ShareLink $l) => $this->format($l))->values());
    }

    // ── DELETE /api/share-links/{id} ──────────────────────────────────────────

    public function revoke(Request $request, int $id): JsonResponse
    {
        $user = $this->resolveUser($request);

        if ($user === null) {
            return response()->json(['message' => trans('auth.unauthenticated')], 401);
        }

        /** @var ShareLink|null $link */
        $link = ShareLink::find($id);

        if ($link === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        $project = Project::with('assignees')->find($link->document->project_id);

        if ($project === null) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (! $this->canAccess($project, $user)) {
            return response()->json(['message' => trans('auth.unauthorized')], 403);
        }

        if ($link->revoked_at === null) {
            $link->update(['revoked_at' => now()]);

            $this->audit->log(
                action: AuditService::CRM_SHARE_LINK_REVOKED,
                entityType: 'project_document',
                entityId: $link->document_id,
            );
        }

        return response()->json(null, 204);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Resolves user + document access for routes nested under a project.
     *
     * @return array{User|null, ProjectDocument|null, JsonResponse|null}
     */
    private function resolveDocumentAccess(Request $request, int $projectId, int $documentId): array
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

        $document = ProjectDocument::where('project_id', $projectId)->find($documentId);

        if ($document === null) {
            return [null, null, response()->json(['message' => 'Not found'], 404)];
        }

        return [$user, $document, null];
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

    /**
     * Response shape for a link right after creation — the only moment the raw
     * (unhashed) token exists, so this is the only response that can include a
     * usable share URL. Once persisted, only token_hash remains; the URL cannot
     * be recovered again (see ShareLink::createFor()).
     *
     * @return array<string, mixed>
     */
    private function formatJustCreated(ShareLink $link): array
    {
        return array_merge($this->format($link), [
            'url' => url("/public/share/{$link->rawToken}"),
        ]);
    }

    /** @return array<string, mixed> */
    private function format(ShareLink $link): array
    {
        $creator = $link->creator;

        return [
            'id' => $link->id,
            'expires_at' => $link->expires_at->toIso8601String(),
            'revoked_at' => $link->revoked_at?->toIso8601String(),
            'access_count' => $link->access_count,
            'last_accessed_at' => $link->last_accessed_at?->toIso8601String(),
            'created_by' => [
                'id' => $creator->id,
                'first_name' => $creator->first_name,
                'last_name' => $creator->last_name,
            ],
            'created_at' => $link->created_at->toIso8601String(),
        ];
    }
}
