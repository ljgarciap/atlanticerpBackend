<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Modules\CRM\Models\ProjectDocument;
use App\Modules\CRM\Models\ShareLink;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

class ShareLinkControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // El driver de cache en testing es 'array' (persiste en memoria para todo el proceso
        // de PHPUnit) — sin flush, el contador de throttle:30,1 de /public/share/{token} se
        // arrastra entre tests y contamina test_public_access_throttle_bloquea_despues_de_30_por_minuto.
        Cache::flush();

        $private = Storage::fake('s3-private');
        $private->buildTemporaryUrlsUsing(
            fn (string $path): string => "https://s3-private.test/presigned/{$path}"
        );
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @param list<int> $assigneeIds */
    private function createProject(string $token, array $assigneeIds = []): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', [
                'nombre'       => 'Proyecto Share Links',
                'tipo'         => 'ambos',
                'etapa'        => 'lead',
                'requires_technical_study'       => false,
                'assignee_ids' => $assigneeIds,
            ]);
        $response->assertStatus(201);
        return (int) $response->json('id');
    }

    private function levelId(int $level): int
    {
        return (int) DB::table('atlantic_auth.security_levels')->where('level', $level)->value('id');
    }

    private function userId(string $email): int
    {
        return (int) DB::table('atlantic_auth.users')->where('email', $email)->value('id');
    }

    /** Documento en zona pública, insertado directo (sin pasar por el endpoint de upload
     * real, que depende de AWS_URL/CDN_URL configurados) — solo para probar que el link
     * público respeta storage_zone en vez de forzar siempre el disco s3-private. */
    private function createPublicDocument(int $projectId, string $publicUrl): int
    {
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            $userId = DB::table('atlantic_auth.users')->value('id');

            $doc = ProjectDocument::create([
                'project_id'     => $projectId,
                'categoria'      => 'otro',
                'nombre_archivo' => 'publico.pdf',
                'storage_key'    => 'crm/projects/' . $projectId . '/publico.pdf',
                'size_bytes'     => 1024,
                'mime_type'      => 'application/pdf',
                'storage_zone'   => ProjectDocument::ZONE_PUBLIC,
                'public_url'     => $publicUrl,
                'version'        => 1,
                'is_current'     => true,
                'uploaded_by'    => $userId,
            ]);

            return $doc->id;
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function uploadDocument(string $token, int $projectId): int
    {
        $file = UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'otro',
                'zone'      => 'private',
            ]);
        $response->assertStatus(201);
        return (int) $response->json('id');
    }

    // ── POST — generar link ─────────────────────────────────────────────────────

    public function test_generar_share_link_retorna_201_con_token_y_url(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");

        $response->assertStatus(201)
            ->assertJsonStructure(['id', 'url', 'expires_at', 'created_by' => ['id', 'first_name', 'last_name']]);

        $this->assertStringContainsString('/public/share/', (string) $response->json('url'));
    }

    public function test_generar_share_link_expira_en_30_dias_por_defecto(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");

        $expiresAt = \Carbon\Carbon::parse((string) $response->json('expires_at'));
        $this->assertEqualsWithDelta(30, now()->diffInDays($expiresAt), 1);
    }

    public function test_generar_share_link_sin_permiso_crm_write_retorna_403(): void
    {
        $adminToken = $this->loginAs();
        $projectId  = $this->createProject($adminToken);
        $docId      = $this->uploadDocument($adminToken, $projectId);

        $readonlyToken = $this->loginAs('nivel1@atlantic.test', 'Password123!', 'electrician');

        $this->withHeaders($this->authHeaders($readonlyToken))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links")
            ->assertStatus(403);
    }

    public function test_generar_share_link_documento_inexistente_retorna_404(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/999999/share-links")
            ->assertStatus(404);
    }

    // ── GET — listar links ──────────────────────────────────────────────────────

    public function test_listar_share_links_incluye_los_generados(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links")
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_listar_share_links_sin_generar_retorna_lista_vacia(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");

        $response->assertStatus(200);
        $this->assertCount(0, $response->json());
    }

    // ── DELETE — revocar ─────────────────────────────────────────────────────────

    public function test_revocar_share_link_retorna_204_y_queda_revocado(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $linkId = (int) $create->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/share-links/{$linkId}")
            ->assertStatus(204);

        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $this->assertNotNull($list->json('0.revoked_at'));
    }

    public function test_revocar_share_link_ya_revocado_es_idempotente(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $linkId = (int) $create->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/share-links/{$linkId}")
            ->assertStatus(204);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/share-links/{$linkId}")
            ->assertStatus(204);
    }

    public function test_revocar_share_link_inexistente_retorna_404(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson('/api/crm/share-links/999999')
            ->assertStatus(404);
    }

    public function test_revocar_share_link_sin_autenticar_retorna_401(): void
    {
        $this->deleteJson('/api/crm/share-links/999999')
            ->assertStatus(401);
    }

    // Revoke es destructivo/irreversible — gateado por crm.delete, no crm.write (a
    // diferencia de crear/listar). vendedor@atlantic.test (nivel 3, "Standard User")
    // tiene crm.write+crm.edit pero NO crm.delete.
    public function test_revocar_share_link_requiere_crm_delete_no_solo_crm_write(): void
    {
        // nivel2@ (nivel 2: crm.read+crm.write, sin crm.delete) en vez de vendedor@ -- ver
        // SCRUM-83, vendedor@ ahora es un vendedor real en nivel 4 con crm.delete.
        $mgmtToken     = $this->loginAs();
        $assigneeId    = $this->userId('nivel2@atlantic.test');
        $projectId     = $this->createProject($mgmtToken, [$assigneeId]);
        $docId         = $this->uploadDocument($mgmtToken, $projectId);

        $create = $this->withHeaders($this->authHeaders($mgmtToken))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $linkId = (int) $create->json('id');

        $assigneeToken = $this->loginAs('nivel2@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($assigneeToken))
            ->deleteJson("/api/crm/share-links/{$linkId}")
            ->assertStatus(403);
    }

    // Aísla el chequeo de asignación al proyecto: un usuario con crm.delete (via
    // extra_permissions) pero no asignado al proyecto dueño del link igual debe recibir 403.
    public function test_revocar_share_link_de_proyecto_ajeno_retorna_403(): void
    {
        $mgmtToken = $this->loginAs();
        $projectId = $this->createProject($mgmtToken); // sin asignar a nadie más
        $docId     = $this->uploadDocument($mgmtToken, $projectId);

        $create = $this->withHeaders($this->authHeaders($mgmtToken))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $linkId = (int) $create->json('id');

        $outsiderEmail = 'share-link-outsider@atlantic.test';
        $this->withHeaders($this->authHeaders($mgmtToken))
            ->postJson('/api/users', [
                'first_name'        => 'Outsider',
                'last_name'         => 'Test',
                'email'             => $outsiderEmail,
                'role_id'           => (int) \Illuminate\Support\Facades\DB::table('atlantic_auth.roles')->where('key', 'vendedor')->value('id'),
                'security_level_id' => $this->levelId(3),
                'extra_permissions' => ['crm.delete'],
            ])->assertStatus(201);

        $outsiderToken = $this->loginAs($outsiderEmail, $outsiderEmail, 'designer');

        $this->withHeaders($this->authHeaders($outsiderToken))
            ->deleteJson("/api/crm/share-links/{$linkId}")
            ->assertStatus(403);
    }

    // ── GET /public/share/{token} — sin autenticación ───────────────────────────

    private function withTenant(callable $callback): mixed
    {
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            return $callback();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    /**
     * El token real solo viaja en la respuesta de creación (url) — nunca se persiste.
     *
     * @param \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse> $createResponse
     */
    private function rawTokenFrom(\Illuminate\Testing\TestResponse $createResponse): string
    {
        return basename((string) $createResponse->json('url'));
    }

    public function test_public_access_token_valido_redirige_a_url_firmada(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $rawToken = $this->rawTokenFrom($create);

        $response = $this->get("/public/share/{$rawToken}");

        $response->assertStatus(302);
        $this->assertStringContainsString('s3-private.test/presigned', (string) $response->headers->get('Location'));
    }

    public function test_public_access_incrementa_access_count_y_last_accessed_at(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $rawToken = $this->rawTokenFrom($create);

        $this->get("/public/share/{$rawToken}")->assertStatus(302);
        $this->get("/public/share/{$rawToken}")->assertStatus(302);

        $this->withTenant(function () use ($rawToken): void {
            $link = ShareLink::findByRawToken($rawToken);
            $this->assertNotNull($link);
            $this->assertSame(2, $link->access_count);
            $this->assertNotNull($link->last_accessed_at);
        });
    }

    public function test_public_access_token_revocado_retorna_404(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $rawToken = $this->rawTokenFrom($create);
        $linkId   = (int) $create->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/share-links/{$linkId}")
            ->assertStatus(204);

        $this->get("/public/share/{$rawToken}")->assertStatus(404);
    }

    public function test_public_access_token_expirado_retorna_404(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $rawToken = $this->rawTokenFrom($create);

        $this->withTenant(function () use ($rawToken): void {
            ShareLink::findByRawToken($rawToken)?->update(['expires_at' => now()->subDay()]);
        });

        $this->get("/public/share/{$rawToken}")->assertStatus(404);
    }

    public function test_public_access_token_inexistente_retorna_404(): void
    {
        $this->get('/public/share/' . str_repeat('0', 64))->assertStatus(404);
    }

    public function test_public_access_throttle_bloquea_despues_de_30_por_minuto(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $rawToken = $this->rawTokenFrom($create);

        for ($i = 0; $i < 30; $i++) {
            $this->get("/public/share/{$rawToken}")->assertStatus(302);
        }

        $this->get("/public/share/{$rawToken}")->assertStatus(429);
    }

    // ── Regresión: zona pública no debe forzar el disco s3-private ─────────────

    public function test_public_access_documento_zona_publica_redirige_a_public_url_sin_presignar(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $publicUrl  = 'https://cdn.example.test/public/crm/projects/publico.pdf';
        $docId      = $this->createPublicDocument($projectId, $publicUrl);

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $rawToken = $this->rawTokenFrom($create);

        $response = $this->get("/public/share/{$rawToken}");

        $response->assertStatus(302);
        $this->assertSame($publicUrl, $response->headers->get('Location'));
    }

    // ── Audit log — creación y revocación ───────────────────────────────────────

    public function test_generar_share_link_registra_audit_log(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links")
            ->assertStatus(201);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'crm.share_link.created')
            ->where('entity_id', (string) $docId)
            ->first();

        $this->assertNotNull($log);
    }

    public function test_revocar_share_link_registra_audit_log(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $linkId = (int) $create->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/share-links/{$linkId}")
            ->assertStatus(204);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'crm.share_link.revoked')
            ->where('entity_id', (string) $docId)
            ->first();

        $this->assertNotNull($log);
    }

    public function test_publicAccess_no_registra_document_downloaded(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $docId     = $this->uploadDocument($token, $projectId);

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents/{$docId}/share-links");
        $rawToken = $this->rawTokenFrom($create);

        $this->get("/public/share/{$rawToken}")->assertStatus(302);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'crm.document.downloaded')
            ->where('entity_id', (string) $docId)
            ->first();

        $this->assertNull($log);
    }
}
