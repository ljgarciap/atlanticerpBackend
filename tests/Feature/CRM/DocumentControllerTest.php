<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use App\Modules\CRM\Events\DocumentDeleted;
use App\Modules\CRM\Events\DocumentUploaded;
use App\Modules\CRM\Models\ProjectDocument;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

class DocumentControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Fake both named S3 disks used by DocumentService
        $private = Storage::fake('s3-private');
        $private->buildTemporaryUrlsUsing(
            fn (string $path): string => "https://s3-private.test/presigned/{$path}"
        );
        Storage::fake('s3-public');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createProject(string $token): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', [
                'nombre'       => 'Proyecto Documentos',
                'tipo'         => 'ambos',
                'etapa'        => 'lead',
                'requires_technical_study'       => false,
                'assignee_ids' => [],
            ]);
        $response->assertStatus(201);
        return (int) $response->json('id');
    }

    /**
     * @param 'private'|'public' $zone
     */
    private function uploadDocument(string $token, int $projectId, string $categoria = 'plano', string $zone = 'private'): int
    {
        $file = UploadedFile::fake()->create('documento.pdf', 100, 'application/pdf');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => $categoria,
                'zone'      => $zone,
            ]);
        $response->assertStatus(201);
        return (int) $response->json('id');
    }

    // ── index — current only ──────────────────────────────────────────────────

    public function test_listar_documentos_proyecto_sin_documentos_retorna_lista_vacia(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents");

        $response->assertStatus(200);
        $response->assertExactJson([]);
    }

    public function test_listar_documentos_retorna_200_con_estructura(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $this->uploadDocument($token, $projectId, 'render');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents");

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
        $response->assertJsonStructure([[
            'id', 'project_id', 'categoria', 'nombre_archivo',
            'size_bytes', 'mime_type', 'storage_zone', 'is_current', 'version',
            'uploader', 'created_at',
        ]]);
        $response->assertJsonPath('0.categoria',    'render');
        $response->assertJsonPath('0.is_current',   true);
        $response->assertJsonPath('0.version',      1);
        $response->assertJsonStructure(['0' => ['uploader' => ['id', 'first_name', 'last_name']]]);
    }

    public function test_listar_documentos_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/crm/projects/1/documents')
            ->assertStatus(401);
    }

    public function test_listar_documentos_proyecto_inexistente_retorna_404(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/projects/99999/documents')
            ->assertStatus(404);
    }

    // ── index — only current version appears in list ──────────────────────────

    public function test_lista_solo_retorna_documentos_con_is_current_true(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        // v1
        $this->uploadDocument($token, $projectId, 'plano');

        // v2 — replaces v1, only v2 should appear in list
        $this->uploadDocument($token, $projectId, 'plano');

        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents")
            ->assertStatus(200)
            ->json();

        $this->assertCount(1, $list, 'Solo debe aparecer la versión actual');
        $this->assertSame(2, $list[0]['version']);
        $this->assertTrue($list[0]['is_current']);
    }

    // ── history — all versions ────────────────────────────────────────────────

    public function test_history_retorna_todas_las_versiones(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->uploadDocument($token, $projectId, 'plano'); // v1
        $this->uploadDocument($token, $projectId, 'plano'); // v2
        $this->uploadDocument($token, $projectId, 'plano'); // v3

        $history = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents/history")
            ->assertStatus(200)
            ->json();

        $this->assertCount(3, $history, 'El historial debe contener las 3 versiones');
        $versions = array_column($history, 'version');
        sort($versions);
        $this->assertSame([1, 2, 3], $versions);
    }

    public function test_history_retorna_parent_document_id(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $v1Id = $this->uploadDocument($token, $projectId, 'plano'); // v1
        $this->uploadDocument($token, $projectId, 'plano');           // v2

        $history = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents/history")
            ->assertStatus(200)
            ->assertJsonStructure([['parent_document_id']])
            ->json();

        // v2 should reference v1 as parent
        $v2 = collect($history)->firstWhere('version', 2);
        $this->assertNotNull($v2);
        $this->assertSame($v1Id, $v2['parent_document_id']);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_subir_documento_privado_retorna_201_con_estructura(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $file      = UploadedFile::fake()->create('plano.pdf', 512, 'application/pdf');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'plano',
                'zone'      => 'private',
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'id', 'project_id', 'categoria', 'nombre_archivo',
            'size_bytes', 'mime_type', 'storage_zone', 'is_current', 'version',
            'uploader', 'created_at',
        ]);
        $response->assertJsonPath('categoria',      'plano');
        $response->assertJsonPath('nombre_archivo', 'plano.pdf');
        $response->assertJsonPath('project_id',     $projectId);
        $response->assertJsonPath('storage_zone',   'private');
        $response->assertJsonPath('version',        1);
        $response->assertJsonPath('is_current',     true);
        $response->assertJsonStructure(['uploader' => ['id', 'first_name', 'last_name']]);

        // File must exist on the s3-private disk
        $this->assertNotEmpty(Storage::disk('s3-private')->allFiles("{$projectId}"));
    }

    public function test_subir_documento_publico_retorna_201_con_zona_publica(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $file      = UploadedFile::fake()->create('catalogo.pdf', 100, 'application/pdf');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'otro',
                'zone'      => 'public',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('storage_zone', 'public');

        // File must exist on the s3-public disk
        $this->assertNotEmpty(Storage::disk('s3-public')->allFiles("{$projectId}"));
    }

    public function test_subir_documento_sin_zona_usa_private_por_defecto(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $file      = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'plano',
            ]);

        $response->assertStatus(201);
        $response->assertJsonPath('storage_zone', 'private');
    }

    public function test_subir_documento_categoria_invalida_retorna_422(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $file      = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'categoria_inventada',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['categoria']]);
    }

    public function test_subir_documento_sin_archivo_retorna_422(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'categoria' => 'plano',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['file']]);
    }

    public function test_subir_documento_sin_categoria_retorna_422(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $file      = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file' => $file,
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['categoria']]);
    }

    public function test_subir_documento_sin_autenticar_retorna_401(): void
    {
        $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $this->postJson('/api/crm/projects/1/documents', [
            'file'      => $file,
            'categoria' => 'plano',
        ])->assertStatus(401);
    }

    public function test_subir_documento_proyecto_inexistente_retorna_404(): void
    {
        $token = $this->loginAs();
        $file  = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects/99999/documents', [
                'file'      => $file,
                'categoria' => 'plano',
            ])
            ->assertStatus(404);
    }

    public function test_subir_archivo_con_mime_prohibido_retorna_422(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $file      = UploadedFile::fake()->create('malware.php', 10, 'application/x-php');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'otro',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['file']]);

        $this->assertEmpty(Storage::disk('s3-private')->allFiles("{$projectId}"));
    }

    public function test_subir_archivo_rvt_es_aceptado(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $file      = UploadedFile::fake()->create('modelo.rvt', 500, 'application/octet-stream');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'plano',
            ])
            ->assertStatus(201);
    }

    public function test_subir_archivo_que_excede_limite_retorna_422(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        \App\Modules\Auth\Models\GlobalSetting::updateOrCreate(
            ['key' => 'documents.max_size_mb'],
            ['value' => '1', 'updated_by' => null, 'updated_at' => now()]
        );
        \Illuminate\Support\Facades\Cache::forget('global:documents.max_size_mb');

        // 2 MB — exceeds 1 MB limit
        $file = UploadedFile::fake()->create('grande.pdf', 2048, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'plano',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['file']]);

        $this->assertEmpty(Storage::disk('s3-private')->allFiles("{$projectId}"));
    }

    public function test_todas_las_categorias_son_validas(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $categorias = ['cotizacion', 'diseno', 'presentacion', 'plano', 'render', 'ficha', 'modelo_3d', 'otro'];

        foreach ($categorias as $categoria) {
            $file = UploadedFile::fake()->create("{$categoria}.pdf", 10, 'application/pdf');

            $this->withHeaders($this->authHeaders($token))
                ->postJson("/api/crm/projects/{$projectId}/documents", [
                    'file'      => $file,
                    'categoria' => $categoria,
                ])
                ->assertStatus(201);
        }

        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents")
            ->json();

        // All are different categories → all are is_current=true → all appear in list
        $this->assertCount(count($categorias), $list);
    }

    // ── Versioning ────────────────────────────────────────────────────────────

    public function test_segunda_subida_misma_categoria_crea_v2_y_marca_v1_no_current(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $v1Id = $this->uploadDocument($token, $projectId, 'plano');
        $v2Id = $this->uploadDocument($token, $projectId, 'plano');

        // Query via schema-qualified table — ProjectDocument::find() needs active tenant context
        $v1 = DB::table('atlantic_crm.project_documents')->where('id', $v1Id)->first();
        $v2 = DB::table('atlantic_crm.project_documents')->where('id', $v2Id)->first();

        $this->assertNotNull($v1);
        $this->assertNotNull($v2);
        $this->assertFalse((bool) $v1->is_current);
        $this->assertTrue((bool) $v2->is_current);
        $this->assertSame(1, (int) $v1->version);
        $this->assertSame(2, (int) $v2->version);
        $this->assertSame($v1Id, (int) $v2->parent_document_id);
    }

    public function test_tercera_subida_misma_categoria_crea_v3(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->uploadDocument($token, $projectId, 'plano'); // v1
        $this->uploadDocument($token, $projectId, 'plano'); // v2
        $v3Id = $this->uploadDocument($token, $projectId, 'plano'); // v3

        $v3 = DB::table('atlantic_crm.project_documents')->where('id', $v3Id)->first();

        $this->assertNotNull($v3);
        $this->assertSame(3, (int) $v3->version);
        $this->assertTrue((bool) $v3->is_current);

        // Previous two must be non-current
        $nonCurrent = DB::table('atlantic_crm.project_documents')
            ->where('project_id', $projectId)
            ->where('is_current', false)
            ->count();
        $this->assertSame(2, $nonCurrent);
    }

    public function test_categorias_distintas_no_interfieren_en_versioning(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->uploadDocument($token, $projectId, 'plano');  // plano v1
        $this->uploadDocument($token, $projectId, 'render'); // render v1 — separate versioning chain

        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents")
            ->assertStatus(200)
            ->json();

        // Both should appear as current (different categories)
        $this->assertCount(2, $list);
        $this->assertTrue($list[0]['is_current']);
        $this->assertTrue($list[1]['is_current']);
    }

    // ── url endpoint ─────────────────────────────────────────────────────────

    public function test_url_documento_privado_retorna_presigned_url(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $documentId = $this->uploadDocument($token, $projectId, 'plano', 'private');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/documents/{$documentId}/url");

        $response->assertStatus(200)
                 ->assertJsonStructure(['url', 'filename', 'zone'])
                 ->assertJsonPath('zone', 'private')
                 ->assertJsonPath('filename', 'documento.pdf');

        $this->assertStringContainsString('s3-private.test', (string) $response->json('url'));
    }

    public function test_url_documento_publico_retorna_public_url(): void
    {
        // Configure CDN_URL so DocumentService can build the public URL
        config(['app.cdn_url' => 'https://cdn.example.com']);

        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $documentId = $this->uploadDocument($token, $projectId, 'otro', 'public');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/documents/{$documentId}/url");

        $response->assertStatus(200)
                 ->assertJsonPath('zone', 'public');
    }

    public function test_url_documento_inexistente_retorna_404(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/documents/99999/url')
            ->assertStatus(404);
    }

    public function test_url_documento_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/crm/documents/1/url')
            ->assertStatus(401);
    }

    // ── download (backward-compat route) ─────────────────────────────────────

    public function test_descargar_documento_retorna_200_con_url(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $documentId = $this->uploadDocument($token, $projectId);

        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/crm/projects/{$projectId}/documents/{$documentId}/download");

        $response->assertStatus(200)
                 ->assertJsonStructure(['url', 'filename', 'zone']);
    }

    public function test_descargar_documento_inexistente_retorna_404(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->get("/api/crm/projects/{$projectId}/documents/99999/download")
            ->assertStatus(404);
    }

    public function test_descargar_documento_sin_autenticar_retorna_401(): void
    {
        $this->get('/api/crm/projects/1/documents/1/download')
            ->assertStatus(401);
    }

    // ── destroy — project-scoped route ───────────────────────────────────────

    public function test_eliminar_documento_retorna_204_y_borra_storage(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $documentId = $this->uploadDocument($token, $projectId);

        $this->assertNotEmpty(Storage::disk('s3-private')->allFiles("{$projectId}"));

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/projects/{$projectId}/documents/{$documentId}")
            ->assertStatus(204);

        // Must no longer appear in list
        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents")
            ->json();

        $this->assertEmpty($list);

        // File must be gone from storage
        $this->assertEmpty(Storage::disk('s3-private')->allFiles("{$projectId}"));
    }

    public function test_eliminar_documento_inexistente_retorna_404(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/projects/{$projectId}/documents/99999")
            ->assertStatus(404);
    }

    public function test_eliminar_documento_sin_autenticar_retorna_401(): void
    {
        $this->deleteJson('/api/crm/projects/1/documents/1')
            ->assertStatus(401);
    }

    // ── destroy — document-level route ───────────────────────────────────────

    public function test_eliminar_documento_via_ruta_directa_retorna_204(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $documentId = $this->uploadDocument($token, $projectId);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/documents/{$documentId}")
            ->assertStatus(204);
    }

    // ── destroy — versioning promotion ───────────────────────────────────────

    public function test_eliminar_version_actual_promueve_version_anterior(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $v1Id = $this->uploadDocument($token, $projectId, 'plano'); // v1
        $v2Id = $this->uploadDocument($token, $projectId, 'plano'); // v2 (current)

        // Delete v2 (current)
        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/documents/{$v2Id}")
            ->assertStatus(204);

        // v1 should be promoted back to current
        $v1 = DB::table('atlantic_crm.project_documents')->where('id', $v1Id)->first();
        $this->assertNotNull($v1);
        $this->assertTrue((bool) $v1->is_current);

        // List should now show v1 only
        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents")
            ->json();

        $this->assertCount(1, $list);
        $this->assertSame($v1Id, $list[0]['id']);
    }

    // ── autorización ─────────────────────────────────────────────────────────

    public function test_diseñador_no_asignado_no_puede_listar_documentos(): void
    {
        $mgmt      = $this->loginAs();
        $designer  = $this->loginAs('vendedor@atlantic.test', 'Password123!');
        $projectId = $this->createProject($mgmt);

        $this->withHeaders($this->authHeaders($designer))
            ->getJson("/api/crm/projects/{$projectId}/documents")
            ->assertStatus(403);
    }

    public function test_diseñador_no_asignado_no_puede_subir_documentos(): void
    {
        $mgmt      = $this->loginAs();
        $designer  = $this->loginAs('vendedor@atlantic.test', 'Password123!');
        $projectId = $this->createProject($mgmt);
        $file      = UploadedFile::fake()->create('plano.pdf', 10, 'application/pdf');

        $this->withHeaders($this->authHeaders($designer))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'plano',
            ])
            ->assertStatus(403);
    }

    public function test_diseñador_no_asignado_no_puede_descargar_documentos(): void
    {
        $mgmt       = $this->loginAs();
        $designer   = $this->loginAs('vendedor@atlantic.test', 'Password123!');
        $projectId  = $this->createProject($mgmt);
        $documentId = $this->uploadDocument($mgmt, $projectId);

        $this->withHeaders($this->authHeaders($designer))
            ->get("/api/crm/projects/{$projectId}/documents/{$documentId}/download")
            ->assertStatus(403);
    }

    public function test_diseñador_no_asignado_no_puede_eliminar_documentos(): void
    {
        $mgmt       = $this->loginAs();
        $designer   = $this->loginAs('vendedor@atlantic.test', 'Password123!');
        $projectId  = $this->createProject($mgmt);
        $documentId = $this->uploadDocument($mgmt, $projectId);

        $this->withHeaders($this->authHeaders($designer))
            ->deleteJson("/api/crm/projects/{$projectId}/documents/{$documentId}")
            ->assertStatus(403);
    }

    public function test_diseñador_asignado_puede_listar_documentos(): void
    {
        $mgmt      = $this->loginAs();
        $projectId = $this->createProject($mgmt);

        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!');
        $designerId    = (int) $this->withHeaders($this->authHeaders($designerToken))
            ->getJson('/api/auth/me')
            ->json('id');

        $this->withHeaders($this->authHeaders($mgmt))
            ->putJson("/api/crm/projects/{$projectId}", [
                'nombre'       => 'Proyecto Documentos',
                'tipo'         => 'ambos',
                'etapa'        => 'lead',
                'requires_technical_study'       => false,
                'assignee_ids' => [$designerId],
            ])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($designerToken))
            ->getJson("/api/crm/projects/{$projectId}/documents")
            ->assertStatus(200);
    }

    // ── documents_count ───────────────────────────────────────────────────────

    public function test_documents_count_refleja_documentos_subidos(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/projects')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $projectId, 'documents_count' => 0]);

        $this->uploadDocument($token, $projectId, 'render');
        $this->uploadDocument($token, $projectId, 'plano');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/projects')
            ->assertStatus(200)
            ->assertJsonFragment(['id' => $projectId, 'documents_count' => 2]);
    }

    // ── authorization — canAccess() positive ─────────────────────────────────

    public function test_diseñador_asignado_puede_subir_documento(): void
    {
        $mgmt      = $this->loginAs();
        $projectId = $this->createProject($mgmt);

        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!');
        $designerId    = (int) $this->withHeaders($this->authHeaders($designerToken))
            ->getJson('/api/auth/me')
            ->json('id');

        $this->assignToProject($mgmt, $projectId, $designerId);

        $file = UploadedFile::fake()->create('plano.pdf', 100, 'application/pdf');

        $this->withHeaders($this->authHeaders($designerToken))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'plano',
            ])
            ->assertStatus(201)
            ->assertJsonPath('categoria', 'plano');
    }

    public function test_diseñador_asignado_puede_descargar_documento(): void
    {
        $mgmt      = $this->loginAs();
        $projectId = $this->createProject($mgmt);

        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!');
        $designerId    = (int) $this->withHeaders($this->authHeaders($designerToken))
            ->getJson('/api/auth/me')
            ->json('id');

        $this->assignToProject($mgmt, $projectId, $designerId);
        $documentId = $this->uploadDocument($mgmt, $projectId);

        $this->withHeaders($this->authHeaders($designerToken))
            ->get("/api/crm/projects/{$projectId}/documents/{$documentId}/download")
            ->assertStatus(200);
    }

    // ── middleware — permission checks ────────────────────────────────────────

    public function test_usuario_sin_crm_write_no_puede_subir_documento(): void
    {
        $mgmt      = $this->loginAs();
        $projectId = $this->createProject($mgmt);

        $readonlyToken = $this->loginAs('nivel1@atlantic.test', 'Password123!');
        $readonlyId    = (int) $this->withHeaders($this->authHeaders($readonlyToken))
            ->getJson('/api/auth/me')
            ->json('id');

        $this->assignToProject($mgmt, $projectId, $readonlyId);

        $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $this->withHeaders($this->authHeaders($readonlyToken))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'plano',
            ])
            ->assertStatus(403);
    }

    public function test_asignado_sin_crm_delete_no_puede_eliminar_documento(): void
    {
        // nivel2@ (nivel 2, Basic User: crm.read+crm.write, sin crm.delete) en vez de
        // vendedor@ -- desde SCRUM-83, vendedor@ es un vendedor real en nivel 4
        // ("Power User"), que sí incluye crm.delete en el escape hatch legado, así que ya no
        // sirve como fixture "asignado pero sin crm.delete".
        $mgmt      = $this->loginAs();
        $projectId = $this->createProject($mgmt);

        $assigneeToken = $this->loginAs('nivel2@atlantic.test', 'Password123!');
        $assigneeId    = (int) $this->withHeaders($this->authHeaders($assigneeToken))
            ->getJson('/api/auth/me')
            ->json('id');

        $this->assignToProject($mgmt, $projectId, $assigneeId);
        $documentId = $this->uploadDocument($mgmt, $projectId);

        $this->withHeaders($this->authHeaders($assigneeToken))
            ->deleteJson("/api/crm/projects/{$projectId}/documents/{$documentId}")
            ->assertStatus(403);
    }

    // ── domain events ─────────────────────────────────────────────────────────

    public function test_subir_documento_dispara_evento_document_uploaded(): void
    {
        Event::fake([DocumentUploaded::class]);

        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $documentId = $this->uploadDocument($token, $projectId);

        Event::assertDispatched(DocumentUploaded::class, function (DocumentUploaded $event) use ($documentId, $projectId): bool {
            return $event->payload()['document_id'] === $documentId
                && $event->payload()['project_id']  === $projectId;
        });
    }

    public function test_eliminar_documento_dispara_evento_document_deleted(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $documentId = $this->uploadDocument($token, $projectId);

        Event::fake([DocumentDeleted::class]);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/projects/{$projectId}/documents/{$documentId}")
            ->assertStatus(204);

        Event::assertDispatched(DocumentDeleted::class, function (DocumentDeleted $event) use ($documentId): bool {
            return $event->payload()['document_id'] === $documentId;
        });
    }

    // ── audit log ─────────────────────────────────────────────────────────────

    public function test_subir_documento_registra_entrada_en_audit_log(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $documentId = $this->uploadDocument($token, $projectId, 'plano');

        // Query directly via schema-qualified table — AuditLog::getTable() needs active tenant
        // but in test assertions the tenant context has already been cleared by JWT middleware's
        // forgetCurrent() call when the HTTP response was returned.
        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', AuditService::CRM_DOCUMENT_UPLOADED)
            ->where('entity_id', (string) $documentId)
            ->first();

        $this->assertNotNull($log, 'Debe existir una entrada de audit con action=crm.document.uploaded');
        $this->assertSame('project_document', $log->entity_type);
    }

    public function test_segunda_subida_registra_version_en_audit_log(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $this->uploadDocument($token, $projectId, 'plano'); // v1
        $v2Id = $this->uploadDocument($token, $projectId, 'plano'); // v2

        $versionLog = DB::table('atlantic_auth.audit_logs')
            ->where('action', AuditService::CRM_DOCUMENT_VERSION_CREATED)
            ->where('entity_id', (string) $v2Id)
            ->first();

        $this->assertNotNull($versionLog, 'El v2 debe registrar action=crm.document.version_created');
    }

    public function test_eliminar_documento_registra_entrada_en_audit_log(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $documentId = $this->uploadDocument($token, $projectId);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/projects/{$projectId}/documents/{$documentId}")
            ->assertStatus(204);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', AuditService::CRM_DOCUMENT_DELETED)
            ->where('entity_id', (string) $documentId)
            ->first();

        $this->assertNotNull($log, 'Debe existir una entrada de audit con action=crm.document.deleted');
    }

    // ── circuit breaker ───────────────────────────────────────────────────────

    public function test_circuit_abierto_retorna_503(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        // Force circuit open by setting the Redis key used by CircuitBreaker('s3-private')
        Redis::set('cb:s3-private:opened_at', (string) time());

        try {
            $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

            $this->withHeaders($this->authHeaders($token))
                ->postJson("/api/crm/projects/{$projectId}/documents", [
                    'file'      => $file,
                    'categoria' => 'plano',
                ])
                ->assertStatus(503)
                ->assertJsonFragment(['message' => 'Storage service temporarily unavailable. Please try again in a few minutes.']);

            $this->assertDatabaseMissing('atlantic_crm.project_documents', [
                'project_id' => $projectId,
            ]);
        } finally {
            Redis::del('cb:s3-private:opened_at');
        }
    }

    // ── request_id correlation ────────────────────────────────────────────────

    public function test_audit_entries_comparten_request_id_dentro_de_la_misma_request(): void
    {
        // Simulate what AssignRequestId middleware does for a single HTTP request.
        // AuditLog lives in the tenant schema, so the tenant must be active for direct inserts.
        $knownId = '11111111-1111-1111-1111-111111111111';
        Context::add('request_id', $knownId);

        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            $svc = app(AuditService::class);
            $svc->log(AuditService::CRM_DOCUMENT_UPLOADED, 'project_document', 99999);
            $svc->log(AuditService::CRM_DOCUMENT_VERSION_CREATED, 'project_document', 99999);

            $entries = DB::table('atlantic_auth.audit_logs')
                ->where('entity_id', '99999')
                ->whereIn('action', [
                    AuditService::CRM_DOCUMENT_UPLOADED,
                    AuditService::CRM_DOCUMENT_VERSION_CREATED,
                ])
                ->orderBy('id')
                ->get();

            $this->assertCount(2, $entries, 'Ambas acciones deben generar una entrada en audit_logs');
            $this->assertSame($knownId, $entries[0]->request_id, 'Primera entrada debe tener el request_id del contexto');
            $this->assertSame($knownId, $entries[1]->request_id, 'Ambas entradas de la misma request deben compartir request_id');
        } finally {
            Context::flush();
            Tenant::forgetCurrent();
        }
    }

    // ── rollback — DB failure cleans up storage ───────────────────────────────

    public function test_fallo_en_bd_durante_subida_limpia_archivo_de_storage(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $triggered = false;
        Event::listen('eloquent.creating: ' . ProjectDocument::class, function () use (&$triggered): void {
            if (! $triggered) {
                $triggered = true;
                throw new \RuntimeException('Simulated BD failure for rollback test');
            }
        });

        $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'plano',
            ])
            ->assertStatus(500);

        $this->assertEmpty(
            Storage::disk('s3-private')->allFiles("{$projectId}"),
            'El archivo debe eliminarse de storage si el create() en BD falla'
        );
    }

    public function test_fallo_silencioso_s3_no_crea_registro_en_bd(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        // Replace the fake disk with a stub that returns false on putFileAs,
        // simulating a silent S3 failure (throw: false in filesystems.php).
        $failingDisk = $this->createMock(\Illuminate\Contracts\Filesystem\Filesystem::class);
        $failingDisk->method('putFileAs')->willReturn(false);
        app('filesystem')->set('s3-private', $failingDisk);

        $file = UploadedFile::fake()->create('doc.pdf', 10, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/documents", [
                'file'      => $file,
                'categoria' => 'plano',
            ])
            ->assertStatus(500);

        $this->assertDatabaseMissing('atlantic_crm.project_documents', [
            'project_id' => $projectId,
        ]);
    }

    // ── ordering ─────────────────────────────────────────────────────────────

    public function test_documentos_se_listan_en_orden_descendente_por_fecha(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $docId1 = $this->uploadDocument($token, $projectId, 'plano');
        $docId2 = $this->uploadDocument($token, $projectId, 'render');
        $docId3 = $this->uploadDocument($token, $projectId, 'diseno');

        DB::statement(
            'UPDATE atlantic_crm.project_documents SET created_at = $1 WHERE id = $2',
            [now()->subSeconds(20)->format('Y-m-d H:i:s.u'), $docId1]
        );
        DB::statement(
            'UPDATE atlantic_crm.project_documents SET created_at = $1 WHERE id = $2',
            [now()->subSeconds(10)->format('Y-m-d H:i:s.u'), $docId2]
        );

        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/documents")
            ->assertStatus(200)
            ->json();

        $this->assertCount(3, $list);
        $this->assertSame(
            [$docId3, $docId2, $docId1],
            array_map(fn (array $d): int => (int) $d['id'], $list),
            'Los documentos deben ordenarse de más reciente a más antiguo'
        );
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function assignToProject(string $mgmtToken, int $projectId, int $userId): void
    {
        $this->withHeaders($this->authHeaders($mgmtToken))
            ->putJson("/api/crm/projects/{$projectId}", [
                'nombre'       => 'Proyecto Documentos',
                'tipo'         => 'ambos',
                'etapa'        => 'lead',
                'requires_technical_study'       => false,
                'assignee_ids' => [$userId],
            ])
            ->assertStatus(200);
    }
}
