<?php

declare(strict_types=1);

namespace Tests\Feature\Biblioteca;

use App\Modules\Biblioteca\Contracts\LibraryStore;
use App\Modules\Biblioteca\Exceptions\LibraryObjectChangedException;
use App\Modules\Biblioteca\Exceptions\LibraryStorageUnavailableException;
use App\Modules\Biblioteca\Services\LibraryService;
use App\Modules\Biblioteca\Services\PdfActiveContentScanner;
use App\Modules\Biblioteca\Services\S3LibraryStore;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-984 — biblioteca de Manuales de Usuario (PDF + video) sobre el bucket compartido.
 * S3 siempre simulado (FakeLibraryStore): ninguna prueba toca AWS.
 */
class BibliotecaControllerTest extends FeatureTestCase
{
    private FakeLibraryStore $store;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Cache::flush();

        $this->store = new FakeLibraryStore();
        $this->app->instance(LibraryStore::class, $this->store);
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function normalHeaders(): array
    {
        return $this->authHeaders($this->loginAs('designer@atlantic.test', 'Password123!', 'designer'));
    }

    /** designer + permiso angosto manuales.manage (el grant de Daniela, sin ser superadmin). */
    private function managerHeaders(): array
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', 'designer@atlantic.test')
            ->update(['extra_permissions' => json_encode(['manuales.manage'])]);

        return $this->normalHeaders();
    }

    private function managerUserId(): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) DB::table("{$slug}_auth.users")->where('email', 'designer@atlantic.test')->value('id');
    }

    private function superadminHeaders(): array
    {
        return $this->authHeaders($this->loginAs('management@atlantic.test'));
    }

    /** Sube un archivo completo por el flujo real: init -> PUT simulado -> finalize. */
    private function upload(array $headers, string $module, string $name, string $filename, string $mime, string $content): string
    {
        $init = $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => $module, 'name' => $name, 'filename' => $filename,
            'content_type' => $mime, 'size_bytes' => strlen($content),
        ])->assertStatus(201);

        $this->store->simulateBrowserPut(
            '_pending/' . $init->json('upload_id') . '.' . pathinfo($filename, PATHINFO_EXTENSION),
            $content,
            $mime,
        );

        $this->withHeaders($headers)
            ->postJson('/api/biblioteca/uploads/' . $init->json('upload_id') . '/finalize')
            ->assertStatus(201);

        return (string) $init->json('upload_id');
    }

    private function pdf(): string
    {
        return "%PDF-1.7\n" . str_repeat('x', 200);
    }

    // ── acceso ───────────────────────────────────────────────────────────────

    public function test_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/biblioteca')->assertStatus(401);
        $this->postJson('/api/biblioteca/uploads')->assertStatus(401);
    }

    public function test_sin_bucket_configurado_responde_503(): void
    {
        $this->store->configured = false;

        $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca')->assertStatus(503);
        $this->withHeaders($this->superadminHeaders())->postJson('/api/biblioteca/uploads', [])->assertStatus(503);
    }

    public function test_usuario_normal_lista_y_recibe_lista_vacia_sin_manuales(): void
    {
        $this->withHeaders($this->normalHeaders())
            ->getJson('/api/biblioteca')
            ->assertStatus(200)
            ->assertExactJson([]);
    }

    public function test_usuario_normal_no_puede_gestionar(): void
    {
        $headers = $this->normalHeaders();
        $id      = (string) Str::uuid();

        $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [])->assertStatus(403);
        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")->assertStatus(403);
        $this->withHeaders($headers)->deleteJson("/api/biblioteca/{$id}")->assertStatus(403);
        $this->withHeaders($headers)->getJson('/api/biblioteca/config')->assertStatus(403);
        $this->withHeaders($headers)->putJson('/api/biblioteca/config', [])->assertStatus(403);
    }

    public function test_filtros_invalidos_retornan_422(): void
    {
        $headers = $this->normalHeaders();

        $this->withHeaders($headers)->getJson('/api/biblioteca?modulo=inexistente')->assertStatus(422);
        $this->withHeaders($headers)->getJson('/api/biblioteca?tipo=audio')->assertStatus(422);
    }

    // ── subida ───────────────────────────────────────────────────────────────

    public function test_init_upload_emite_put_a_pending_con_clave_generada_por_el_servidor(): void
    {
        $response = $this->withHeaders($this->managerHeaders())->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'Manual CRM', 'filename' => '../../etc/passwd.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => 1000,
        ])->assertStatus(201);

        $put = $this->store->presignedPuts[0];
        $this->assertSame('_pending/' . $response->json('upload_id') . '.pdf', $put['key']);
        $this->assertSame('application/pdf', $put['content_type']);
        $this->assertSame(1000, $put['length']);
        $this->assertSame(1800, $put['ttl']); // library_url_ttl_upload_seconds, desde system_settings
        $this->assertStringNotContainsString('passwd', $put['key']);
    }

    #[DataProvider('invalidUploads')]
    public function test_init_upload_valida_extension_mime_tamano_y_modulo(array $override, string $field): void
    {
        $payload = array_merge([
            'module' => 'crm', 'name' => 'X', 'filename' => 'x.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => 1000,
        ], $override);

        $this->withHeaders($this->managerHeaders())
            ->postJson('/api/biblioteca/uploads', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors($field);

        $this->assertSame([], $this->store->presignedPuts);
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function invalidUploads(): array
    {
        return [
            'extension fuera de lista blanca' => [['filename' => 'virus.exe', 'content_type' => 'application/x-msdownload'], 'file'],
            'svg/html rechazados'             => [['filename' => 'x.html', 'content_type' => 'text/html'], 'file'],
            'mime no coincide con extension'  => [['content_type' => 'video/mp4'], 'content_type'],
            'pdf sobre el tope de 50 MB'      => [['size_bytes' => 51 * 1024 * 1024], 'size_bytes'],
            'video sobre el tope de 500 MB'   => [['filename' => 'v.mp4', 'content_type' => 'video/mp4', 'size_bytes' => 501 * 1024 * 1024], 'size_bytes'],
            'modulo invalido'                 => [['module' => 'finanzas'], 'module'],
        ];
    }

    public function test_video_hasta_500mb_es_aceptado(): void
    {
        $this->withHeaders($this->managerHeaders())->postJson('/api/biblioteca/uploads', [
            'module' => 'bodega', 'name' => 'Capacitación', 'filename' => 'cap.mp4',
            'content_type' => 'video/mp4', 'size_bytes' => 500 * 1024 * 1024,
        ])->assertStatus(201);
    }

    public function test_cuota_de_subidas_pendientes_por_usuario(): void
    {
        $headers = $this->managerHeaders();
        $payload = ['module' => 'crm', 'name' => 'X', 'filename' => 'x.pdf', 'content_type' => 'application/pdf', 'size_bytes' => 100];

        for ($i = 0; $i < 5; $i++) {
            $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', $payload)->assertStatus(201);
        }
        $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', $payload)->assertStatus(422);
    }

    public function test_finalize_publica_el_archivo_y_aparece_en_el_listado(): void
    {
        $headers = $this->managerHeaders();
        $id      = $this->upload($headers, 'compras', 'Manual de Compras', 'compras.pdf', 'application/pdf', $this->pdf());

        // pending limpio, objeto final + sidecar en el catálogo
        $this->assertArrayNotHasKey("_pending/{$id}.pdf", $this->store->objects);
        $this->assertArrayHasKey("compras/pdf/{$id}.pdf", $this->store->objects);
        $this->assertArrayHasKey("compras/pdf/{$id}.json", $this->store->objects);

        $list = $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca')->assertStatus(200);
        $this->assertSame(
            ['id' => $id, 'name' => 'Manual de Compras', 'module' => 'compras', 'type' => 'pdf', 'size_bytes' => strlen($this->pdf())],
            collect($list->json(0))->except('created_at')->all(),
        );
        $this->assertNotEmpty($list->json('0.created_at'));
    }

    public function test_finalize_registra_auditoria_de_la_subida(): void
    {
        $id = $this->upload($this->managerHeaders(), 'crm', 'Manual CRM', 'crm.pdf', 'application/pdf', $this->pdf());

        $this->assertDatabaseHas('atlantic_auth.audit_logs', [
            'action' => 'biblioteca.file.uploaded', 'entity_type' => 'library_file', 'entity_id' => $id,
        ]);
    }

    public function test_finalize_rechaza_y_borra_si_los_magic_bytes_no_corresponden(): void
    {
        $headers = $this->managerHeaders();
        $content = 'MZ-este-no-es-un-pdf';
        $init    = $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'Falso', 'filename' => 'falso.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => strlen($content),
        ])->assertStatus(201);
        $key = '_pending/' . $init->json('upload_id') . '.pdf';
        $this->store->simulateBrowserPut($key, $content, 'application/pdf');

        $this->withHeaders($headers)
            ->postJson('/api/biblioteca/uploads/' . $init->json('upload_id') . '/finalize')
            ->assertStatus(422);

        $this->assertArrayNotHasKey($key, $this->store->objects);
        $this->assertSame([], $this->withHeaders($headers)->getJson('/api/biblioteca')->json());
    }

    public function test_finalize_rechaza_si_el_tamano_real_no_coincide_con_el_declarado(): void
    {
        $headers = $this->managerHeaders();
        $init    = $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'X', 'filename' => 'x.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => 10,
        ])->assertStatus(201);
        $key = '_pending/' . $init->json('upload_id') . '.pdf';
        $this->store->simulateBrowserPut($key, $this->pdf(), 'application/pdf'); // mucho mayor a 10 bytes

        $this->withHeaders($headers)
            ->postJson('/api/biblioteca/uploads/' . $init->json('upload_id') . '/finalize')
            ->assertStatus(422);
        $this->assertArrayNotHasKey($key, $this->store->objects);
    }

    public function test_finalize_sin_archivo_en_pending_retorna_422(): void
    {
        $headers = $this->managerHeaders();
        $init    = $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'X', 'filename' => 'x.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => 10,
        ])->assertStatus(201);

        $this->withHeaders($headers)
            ->postJson('/api/biblioteca/uploads/' . $init->json('upload_id') . '/finalize')
            ->assertStatus(422);
    }

    public function test_finalize_de_una_subida_ajena_o_inexistente_retorna_404(): void
    {
        $init = $this->withHeaders($this->managerHeaders())->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'X', 'filename' => 'x.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => 10,
        ])->assertStatus(201);

        // otro usuario con permiso (superadmin) no puede publicar el upload del primero
        $this->withHeaders($this->superadminHeaders())
            ->postJson('/api/biblioteca/uploads/' . $init->json('upload_id') . '/finalize')
            ->assertStatus(404);
        $this->withHeaders($this->superadminHeaders())
            ->postJson('/api/biblioteca/uploads/' . Str::uuid() . '/finalize')
            ->assertStatus(404);
    }

    // ── listado / filtros ────────────────────────────────────────────────────

    public function test_listado_filtra_por_modulo_y_tipo(): void
    {
        $sa = $this->superadminHeaders();
        $this->upload($sa, 'crm', 'Manual CRM', 'crm.pdf', 'application/pdf', $this->pdf());
        $this->upload($sa, 'bodega', 'Manual Bodega', 'bodega.pdf', 'application/pdf', $this->pdf());
        $this->upload($sa, 'bodega', 'Video Bodega', 'bodega.mp4', 'video/mp4', "\x00\x00\x00\x18ftypmp42" . str_repeat('v', 50));

        $normal = $this->normalHeaders();

        $this->assertCount(3, $this->withHeaders($normal)->getJson('/api/biblioteca')->json());
        $this->assertCount(2, $this->withHeaders($normal)->getJson('/api/biblioteca?modulo=bodega')->json());
        $this->assertCount(1, $this->withHeaders($normal)->getJson('/api/biblioteca?modulo=bodega&tipo=video')->json());
        $this->assertCount(2, $this->withHeaders($normal)->getJson('/api/biblioteca?tipo=pdf')->json());
        $this->assertSame([], $this->withHeaders($normal)->getJson('/api/biblioteca?modulo=gerencia')->json());
    }

    public function test_objeto_sin_sidecar_no_aparece_en_el_catalogo(): void
    {
        $this->store->objects['crm/pdf/' . Str::uuid() . '.pdf'] = $this->pdf();
        $this->store->objects['_pending/' . Str::uuid() . '.json'] = '{"id":"x"}';

        $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca')->assertExactJson([]);
    }

    // ── URLs prefirmadas ─────────────────────────────────────────────────────

    public function test_url_ver_pdf_usa_ttl_de_15_min_y_content_type_forzado(): void
    {
        $id = $this->upload($this->superadminHeaders(), 'crm', 'Manual CRM', 'crm.pdf', 'application/pdf', $this->pdf());

        $this->withHeaders($this->normalHeaders())
            ->getJson("/api/biblioteca/{$id}/url?accion=ver")
            ->assertStatus(200)
            ->assertJsonStructure(['url', 'expires_at']);

        $get = $this->store->presignedGets[0];
        $this->assertSame("crm/pdf/{$id}.pdf", $get['key']);
        $this->assertSame(900, $get['ttl']);
        $this->assertSame('application/pdf', $get['content_type']);
        $this->assertSame('inline', $get['disposition']);
    }

    public function test_url_ver_video_usa_ttl_de_60_min(): void
    {
        $id = $this->upload($this->superadminHeaders(), 'bodega', 'Video', 'v.webm', 'video/webm', "\x1A\x45\xDF\xA3" . str_repeat('w', 40));

        $this->withHeaders($this->normalHeaders())->getJson("/api/biblioteca/{$id}/url?accion=ver")->assertStatus(200);

        $this->assertSame(3600, $this->store->presignedGets[0]['ttl']);
        $this->assertSame('video/webm', $this->store->presignedGets[0]['content_type']);
    }

    public function test_url_descargar_fuerza_attachment_con_nombre_saneado_y_audita(): void
    {
        $id = $this->upload($this->superadminHeaders(), 'crm', 'Manual CRM', 'crm.pdf', 'application/pdf', $this->pdf());

        $this->withHeaders($this->normalHeaders())->getJson("/api/biblioteca/{$id}/url?accion=descargar")->assertStatus(200);

        $disposition = $this->store->presignedGets[0]['disposition'];
        $this->assertStringStartsWith('attachment; filename="Manual CRM.pdf"', $disposition);
        $this->assertStringContainsString("filename*=UTF-8''Manual%20CRM.pdf", $disposition);
        $this->assertDatabaseHas('atlantic_auth.audit_logs', [
            'action' => 'biblioteca.file.downloaded', 'entity_id' => $id,
        ]);
    }

    public function test_url_de_archivo_inexistente_o_id_invalido_retorna_404(): void
    {
        $headers = $this->normalHeaders();

        $this->withHeaders($headers)->getJson('/api/biblioteca/' . Str::uuid() . '/url?accion=ver')->assertStatus(404);
        $this->withHeaders($headers)->getJson('/api/biblioteca/no-es-uuid/url?accion=ver')->assertStatus(404);
        $this->withHeaders($headers)->getJson('/api/biblioteca/' . Str::uuid() . '/url?accion=borrar')->assertStatus(422);
    }

    // ── borrado ──────────────────────────────────────────────────────────────

    public function test_delete_quita_objeto_y_sidecar_y_audita(): void
    {
        $headers = $this->managerHeaders();
        $id      = $this->upload($headers, 'crm', 'Manual CRM', 'crm.pdf', 'application/pdf', $this->pdf());

        $this->withHeaders($headers)->deleteJson("/api/biblioteca/{$id}")->assertStatus(204);

        $this->assertArrayNotHasKey("crm/pdf/{$id}.pdf", $this->store->objects);
        $this->assertArrayNotHasKey("crm/pdf/{$id}.json", $this->store->objects);
        $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca')->assertExactJson([]);
        $this->assertDatabaseHas('atlantic_auth.audit_logs', ['action' => 'biblioteca.file.deleted', 'entity_id' => $id]);
    }

    public function test_delete_de_inexistente_retorna_404(): void
    {
        $this->withHeaders($this->managerHeaders())->deleteJson('/api/biblioteca/' . Str::uuid())->assertStatus(404);
    }

    // ── configuración (topes/formatos/TTL en system_settings) ────────────────

    public function test_config_la_ve_quien_gestiona_con_los_topes_sembrados(): void
    {
        $this->withHeaders($this->managerHeaders())
            ->getJson('/api/biblioteca/config')
            ->assertStatus(200)
            ->assertJson([
                'max_pdf_bytes' => 50 * 1024 * 1024,
                'max_video_bytes' => 500 * 1024 * 1024,
                'allowed_extensions' => ['pdf', 'mp4', 'webm'],
            ])
            ->assertJsonCount(7, 'modules');
    }

    public function test_solo_superadmin_modifica_la_config_y_el_cambio_aplica_sin_deploy(): void
    {
        // manuales.manage solo NO alcanza para editar los parámetros
        $this->withHeaders($this->managerHeaders())
            ->putJson('/api/biblioteca/config', ['max_pdf_mb' => 10])
            ->assertStatus(403);

        $this->withHeaders($this->superadminHeaders())
            ->putJson('/api/biblioteca/config', ['max_pdf_mb' => 10, 'allowed_extensions' => ['pdf']])
            ->assertStatus(200)
            ->assertJsonPath('max_pdf_mb', 10)
            ->assertJsonPath('max_pdf_bytes', 10 * 1024 * 1024);

        $headers = $this->managerHeaders();
        $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'X', 'filename' => 'x.pdf', 'content_type' => 'application/pdf',
            'size_bytes' => 11 * 1024 * 1024,
        ])->assertStatus(422)->assertJsonValidationErrors('size_bytes');
        $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'V', 'filename' => 'v.mp4', 'content_type' => 'video/mp4', 'size_bytes' => 100,
        ])->assertStatus(422)->assertJsonValidationErrors('file'); // mp4 ya no está permitido
    }

    public function test_config_rechaza_valores_fuera_de_rango(): void
    {
        $this->withHeaders($this->superadminHeaders())
            ->putJson('/api/biblioteca/config', ['max_pdf_mb' => 0, 'allowed_extensions' => ['exe']])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['max_pdf_mb', 'allowed_extensions.0']);
    }

    // ── permiso de Daniela ───────────────────────────────────────────────────

    public function test_seeder_concede_manuales_manage_a_daniela_y_es_idempotente(): void
    {
        $this->seed(\Database\Seeders\SpecialPermissionSeeder::class);

        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        $perms = json_decode((string) DB::table("{$slug}_auth.users")->where('email', 'gerencia@test.com')->value('extra_permissions'), true);

        $this->assertContains('manuales.manage', $perms);
        $this->assertSame(1, count(array_keys($perms, 'manuales.manage', true)));
        $this->assertTrue(DB::table("{$slug}_auth.permission_catalog")->where('key', 'manuales.manage')->exists());
    }

    // ── saneo del nombre visible ─────────────────────────────────────────────

    public function test_sanitize_name_elimina_control_bidi_separadores_y_limita_largo(): void
    {
        $service = $this->app->make(LibraryService::class);

        $this->assertSame('a b c', $service->sanitizeName("a\x00\x1F/b\\c"));
        $this->assertSame('x y', $service->sanitizeName("x\u{202E}..y"));
        $this->assertSame(120, mb_strlen($service->sanitizeName(str_repeat('ñ', 300))));

        $this->expectException(ValidationException::class);
        $service->sanitizeName("\u{202E}\x00 / ");
    }

    // ── endurecimiento (Senior Review + Cybersecurity, 2026-09-30) ───────────

    public function test_config_get_y_put_comparten_las_mismas_claves(): void
    {
        $get = $this->withHeaders($this->managerHeaders())->getJson('/api/biblioteca/config')->assertStatus(200)->json();
        $put = $this->withHeaders($this->superadminHeaders())
            ->putJson('/api/biblioteca/config', ['max_video_mb' => 300])->assertStatus(200)->json();

        $this->assertSame(array_keys($get), array_keys($put));
        foreach (['max_pdf_bytes', 'max_video_bytes', 'allowed_extensions', 'modules', 'max_pdf_mb', 'max_video_mb',
            'url_ttl_view_seconds', 'url_ttl_video_seconds', 'url_ttl_upload_seconds', 'max_pending_uploads'] as $key) {
            $this->assertArrayHasKey($key, $get);
        }
        $this->assertSame(300 * 1024 * 1024, $put['max_video_bytes']);
    }

    public function test_put_config_se_audita_con_valores_viejos_y_nuevos(): void
    {
        $this->withHeaders($this->superadminHeaders())
            ->putJson('/api/biblioteca/config', ['max_pdf_mb' => 20, 'url_ttl_view_seconds' => 600])->assertStatus(200);

        $row = DB::table('atlantic_auth.audit_logs')->where('action', 'biblioteca.config.updated')->first();
        $this->assertNotNull($row);
        $this->assertSame(50, json_decode((string) $row->old_values, true)['max_pdf_mb']);
        $this->assertSame(20, json_decode((string) $row->new_values, true)['max_pdf_mb']);
        $this->assertSame(600, json_decode((string) $row->new_values, true)['url_ttl_view_seconds']);
    }

    public function test_reput_entre_validar_y_copiar_no_publica_el_contenido_no_validado(): void
    {
        $headers = $this->managerHeaders();
        $good    = $this->pdf();
        $init    = $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'Carrera', 'filename' => 'c.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => strlen($good),
        ])->assertStatus(201);
        $id  = $init->json('upload_id');
        $key = "_pending/{$id}.pdf";
        $this->store->simulateBrowserPut($key, $good, 'application/pdf');

        // El cliente re-sube contenido distinto (y enorme) justo antes de la copia.
        $this->store->beforeCopy = fn (FakeLibraryStore $s) => $s->simulateBrowserPut($key, '%PDF-1.7 /JavaScript ' . str_repeat('Z', 5000), 'application/pdf');

        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")->assertStatus(201);

        $this->assertSame($good, $this->store->objects["crm/pdf/{$id}.pdf"], 'la copia debe ser la versión validada, no la re-subida');
        $this->assertNotNull($this->store->copies[0]['version_id']);
    }

    public function test_la_copia_fija_el_content_type_canonico_no_el_del_cliente(): void
    {
        $headers = $this->managerHeaders();
        $id      = $this->upload($headers, 'crm', 'Manual', 'm.pdf', 'application/pdf', $this->pdf());

        $this->assertSame('application/pdf', $this->store->copies[0]['content_type']);
        $this->assertSame('application/pdf', $this->store->contentTypes["crm/pdf/{$id}.pdf"]);
    }

    /** @return array<string, array{string}> */
    public static function pdfConContenidoActivo(): array
    {
        return [
            'javascript'  => ["%PDF-1.7\n1 0 obj << /S /JavaScript /JS (app.alert(1)) >> endobj"],
            'launch'      => ["%PDF-1.7\n1 0 obj << /S /Launch /F (cmd.exe) >> endobj"],
            'embedded'    => ["%PDF-1.7\n1 0 obj << /Type /EmbeddedFile >> endobj"],
            'escape-hex'  => ["%PDF-1.7\n1 0 obj << /S /J#61vaScr#69pt >> endobj"],
        ];
    }

    #[DataProvider('pdfConContenidoActivo')]
    public function test_finalize_rechaza_pdf_con_contenido_activo_y_libera_el_cupo(string $content): void
    {
        $headers = $this->managerHeaders();
        $init    = $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'Malo', 'filename' => 'malo.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => strlen($content),
        ])->assertStatus(201);
        $key = '_pending/' . $init->json('upload_id') . '.pdf';
        $this->store->simulateBrowserPut($key, $content, 'application/pdf');

        $this->withHeaders($headers)
            ->postJson('/api/biblioteca/uploads/' . $init->json('upload_id') . '/finalize')
            ->assertStatus(422)
            ->assertJsonPath('message', trans('biblioteca.rejected_active_content'));

        $this->assertArrayNotHasKey($key, $this->store->objects);
        $this->assertSame([], $this->withHeaders($headers)->getJson('/api/biblioteca')->json());
        $this->assertSame(0, (int) Cache::get('biblioteca:pending:' . $this->managerUserId()));
    }

    public function test_pdf_legitimo_con_openaction_goto_y_nombres_parecidos_pasa(): void
    {
        $content = "%PDF-1.7\n1 0 obj << /OpenAction [3 0 R /Fit] /Metadata /JSONLike /Type /Catalog >> endobj";
        $this->upload($this->managerHeaders(), 'crm', 'Legitimo', 'ok.pdf', 'application/pdf', $content);

        $this->assertCount(1, $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca')->json());
    }

    public function test_el_escaner_detecta_un_token_partido_entre_dos_bloques(): void
    {
        config(['biblioteca.pdf_scan_chunk_bytes' => 4096]);
        $data   = str_repeat('a', 4090) . '/JavaScript ' . str_repeat('b', 500);
        $reader = static fn (int $offset, int $length): string => substr($data, $offset, $length);

        $this->assertSame('/JavaScript', (new PdfActiveContentScanner())->scan($reader, strlen($data)));
        $this->assertNull((new PdfActiveContentScanner())->scan(
            static fn (int $o, int $l): string => substr(str_repeat('a', 9000), $o, $l),
            9000,
        ));
    }

    public function test_finalize_repetido_no_publica_dos_veces_ni_deja_el_cupo_en_negativo(): void
    {
        $headers = $this->managerHeaders();
        $id      = $this->upload($headers, 'crm', 'Manual', 'm.pdf', 'application/pdf', $this->pdf());

        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")->assertStatus(404);
        $this->assertGreaterThanOrEqual(0, (int) Cache::get('biblioteca:pending:' . $this->managerUserId(), 0));
    }

    public function test_finalize_concurrente_es_rechazado_mientras_otro_finaliza(): void
    {
        $headers = $this->managerHeaders();
        $init    = $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'X', 'filename' => 'x.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => strlen($this->pdf()),
        ])->assertStatus(201);
        $id = $init->json('upload_id');
        $this->store->simulateBrowserPut("_pending/{$id}.pdf", $this->pdf(), 'application/pdf');

        $lock = Cache::lock('biblioteca:finalize:' . $id, 60);
        $this->assertTrue($lock->get());
        // 409 con `code` (no un 422 de rechazo de contenido): el frontend lo reconoce por status o por code y ofrece reintentar.
        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")
            ->assertStatus(409)
            ->assertExactJson(['message' => trans('biblioteca.finalize_in_progress'), 'code' => 'finalize_in_progress']);
        $lock->release();

        // No fue un rechazo: el pending y el cupo siguen intactos y el reintento publica.
        $this->assertArrayHasKey("_pending/{$id}.pdf", $this->store->objects);
        $this->assertSame(1, (int) Cache::get('biblioteca:pending:' . $this->managerUserId(), 0));
        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")->assertStatus(201);
    }

    public function test_el_listado_lee_los_sidecars_en_un_solo_pase_sin_n_mas_uno(): void
    {
        $headers = $this->managerHeaders();
        foreach (['crm', 'compras', 'bodega'] as $module) {
            $this->upload($headers, $module, "Manual {$module}", "{$module}.pdf", 'application/pdf', $this->pdf());
        }
        Cache::flush();
        $this->store->getJsonCalls = $this->store->getJsonManyCalls = 0;

        $this->assertCount(3, $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca')->json());
        $this->assertSame(0, $this->store->getJsonCalls);
        $this->assertSame(1, $this->store->getJsonManyCalls);
    }

    public function test_sidecar_de_otro_entorno_se_resanea_y_se_descarta_si_tipo_y_extension_no_coinciden(): void
    {
        $base = ['module' => 'crm', 'size_bytes' => 10, 'created_at' => now()->toIso8601String()];
        $ok   = (string) Str::uuid();
        $bad  = (string) Str::uuid();
        $this->store->putJson("crm/pdf/{$ok}.json", $base + ['id' => $ok, 'name' => "Manual\u{200B}\u{2028}Limpio", 'type' => 'pdf', 'ext' => 'pdf']);
        $this->store->putJson("crm/video/{$bad}.json", $base + ['id' => $bad, 'name' => 'Raro', 'type' => 'video', 'ext' => 'pdf']);

        $list = $this->withHeaders($this->normalHeaders())->getJson('/api/biblioteca')->json();

        $this->assertCount(1, $list);
        $this->assertSame('ManualLimpio', $list[0]['name']); // U+200B y U+2028 se eliminan, no se reemplazan por espacio
    }

    public function test_rate_limit_de_biblioteca_es_por_usuario_y_no_comparte_cupo_entre_usuarios(): void
    {
        $limiter = RateLimiter::limiter('biblioteca-lectura');
        $this->assertNotNull($limiter);

        $reqFor = function (array $headers): Request {
            $request = Request::create('/api/biblioteca', 'GET', [], [], [], ['REMOTE_ADDR' => '10.0.0.9']);
            $request->headers->set('Authorization', $headers['Authorization']);

            return $request;
        };

        $a = $limiter($reqFor($this->normalHeaders()))->key;
        $b = $limiter($reqFor($this->superadminHeaders()))->key;

        $this->assertNotSame($a, $b, 'misma IP de oficina, usuarios distintos: cupos distintos');
        $this->assertStringStartsWith('biblioteca-lectura:u', $a);
        $this->assertSame(20, RateLimiter::limiter('biblioteca-escritura')($reqFor($this->normalHeaders()))->maxAttempts);
    }

    public function test_sin_keys_propias_de_la_biblioteca_el_store_real_no_se_considera_configurado(): void
    {
        $store = new S3LibraryStore();

        config(['filesystems.disks.s3-library.bucket' => 'atlanticerp-library', 'filesystems.disks.s3-library.key' => '', 'filesystems.disks.s3-library.secret' => '']);
        $this->assertFalse($store->isConfigured(), 'sin keys el SDK caería a las credenciales por defecto');

        config(['filesystems.disks.s3-library.key' => 'AKIATEST', 'filesystems.disks.s3-library.secret' => 'secret']);
        $this->assertTrue($store->isConfigured());

        config(['filesystems.disks.s3-library.bucket' => '']);
        $this->assertFalse($store->isConfigured());
    }

    public function test_cuota_de_pendientes_se_libera_si_falla_la_firma_de_la_url(): void
    {
        $this->store->presignPutFails = new \RuntimeException('s3 caído');

        $headers = $this->managerHeaders();
        $payload = ['module' => 'crm', 'name' => 'X', 'filename' => 'x.pdf', 'content_type' => 'application/pdf', 'size_bytes' => 100];
        for ($i = 0; $i < 7; $i++) {
            $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', $payload)->assertStatus(500);
        }

        $this->assertSame(0, (int) Cache::get('biblioteca:pending:' . $this->managerUserId(), 0));
    }

    // ── Ronda 3: escáner (N-2/N-3) y errores de S3 en finalize ───────────────

    /** @return array<string, array{string}> */
    public static function nombresConDelimitadoresRaros(): array
    {
        return [
            'nul'                 => ["1 0 obj << /S /JavaScript\0 /JS (x) >>"],
            'llave-abre'          => ["<< /S /Launch{ /F (x) >>"],
            'llave-cierra'        => ["<< /S /Launch} /F (x) >>"],
            'js-nul-parentesis'   => ["<< /S /JS\0(app.alert(1)) >>"],
            'fin-de-datos'        => ["<< /S /JavaScript"],
            'porcentaje'          => ["<< /S /EmbeddedFile%comentario\n >>"],
            'escape-hex-con-nul'  => ["<< /S /JavaScript#00 >>"],
            'escape-hex-en-token' => ["<< /S /J#61vaScr#69pt\0 >>"],
        ];
    }

    #[DataProvider('nombresConDelimitadoresRaros')]
    public function test_el_escaner_detecta_el_token_aunque_lo_siga_un_delimitador_poco_comun(string $data): void
    {
        $found = (new PdfActiveContentScanner())->scan(
            static fn (int $o, int $l): string => substr($data, $o, $l),
            strlen($data),
        );

        $this->assertNotNull($found, 'el token seguido de NUL/llaves/% no debe pasar como limpio');
    }

    public function test_el_escaner_no_marca_nombres_que_solo_empiezan_igual(): void
    {
        foreach (['<< /JSON 1 >>', '<< /JSX /Launcher /EmbeddedFiles >>', '<< /JavaScriptish >>'] as $data) {
            $this->assertNull(
                (new PdfActiveContentScanner())->scan(static fn (int $o, int $l): string => substr($data, $o, $l), strlen($data)),
                $data,
            );
        }
    }

    public function test_el_escaner_falla_cerrado_si_pcre_da_error(): void
    {
        // preg_match() devuelve false ante un error de PCRE (backtracking, JIT, etc.): debe contar como sospechoso.
        $scanner = new class () extends PdfActiveContentScanner {
            protected function search(string $pattern, string $subject): int|false
            {
                return false;
            }
        };

        $data = "%PDF-1.7\n1 0 obj << /Type /Catalog >> endobj";

        $this->assertSame(
            PdfActiveContentScanner::SCAN_ERROR,
            $scanner->scan(static fn (int $o, int $l): string => substr($data, $o, $l), strlen($data)),
            'un error de PCRE nunca debe contar como PDF limpio',
        );
    }

    public function test_el_escaner_rechaza_un_archivo_mas_grande_que_el_tope_de_escaneo_en_vez_de_escanear_un_prefijo(): void
    {
        config(['biblioteca.pdf_scan_max_bytes' => 1000]);
        // El contenido activo está DESPUÉS del tope: antes de este arreglo se escaneaban solo los primeros bytes.
        $data = str_repeat('a', 1500) . ' /JavaScript ';

        $this->assertSame(
            PdfActiveContentScanner::TOO_LARGE,
            (new PdfActiveContentScanner())->scan(static fn (int $o, int $l): string => substr($data, $o, $l), strlen($data)),
        );
    }

    public function test_limite_conocido_un_token_dentro_de_una_cadena_uri_se_rechaza_a_proposito(): void
    {
        // Documenta la decisión: ignorar el contenido entre paréntesis abriría un bypass (`%(` en un comentario
        // esconde un /JavaScript real del escáner, no del lector PDF). Se prefiere el falso positivo.
        $data = '<< /S /URI /URI (https://x/JS/a) >>';

        $this->assertSame(
            '/JS',
            (new PdfActiveContentScanner())->scan(static fn (int $o, int $l): string => substr($data, $o, $l), strlen($data)),
        );
    }

    public function test_si_el_tope_de_escaneo_baja_despues_del_init_el_finalize_rechaza_el_pdf_y_libera_el_cupo(): void
    {
        $headers = $this->managerHeaders();
        $content = $this->pdf();
        $init    = $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'Grande', 'filename' => 'g.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => strlen($content),
        ])->assertStatus(201);
        $key = '_pending/' . $init->json('upload_id') . '.pdf';
        $this->store->simulateBrowserPut($key, $content, 'application/pdf');
        // El tope del escáner baja DESPUÉS del init (cambio de config entre la emisión de la URL y el finalize).
        // El tope efectivo de PDF nunca supera al del escáner, así que el finalize rechaza por tamaño antes de
        // escanear; la rama TOO_LARGE del escáner queda como defensa en profundidad (test unitario aparte).
        config(['biblioteca.pdf_scan_max_bytes' => 100]);

        $this->withHeaders($headers)
            ->postJson('/api/biblioteca/uploads/' . $init->json('upload_id') . '/finalize')
            ->assertStatus(422)
            ->assertJsonPath('message', trans('biblioteca.rejected_size'));

        $this->assertArrayNotHasKey($key, $this->store->objects);
        $this->assertSame(0, (int) Cache::get('biblioteca:pending:' . $this->managerUserId(), 0));
    }

    /** @return array{0: array<string, string>, 1: string, 2: string} headers, upload_id, clave del pending */
    private function pendingUpload(): array
    {
        $headers = $this->managerHeaders();
        $content = $this->pdf();
        $init    = $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'Manual', 'filename' => 'm.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => strlen($content),
        ])->assertStatus(201);
        $id  = (string) $init->json('upload_id');
        $key = "_pending/{$id}.pdf";
        $this->store->simulateBrowserPut($key, $content, 'application/pdf');

        return [$headers, $id, $key];
    }

    public function test_finalize_con_version_inexistente_rechaza_limpia_el_pending_libera_el_cupo_y_audita(): void
    {
        [$headers, $id, $key] = $this->pendingUpload();
        $this->store->failures['copy'] = new LibraryObjectChangedException('NoSuchVersion');

        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")
            ->assertStatus(422)
            ->assertJsonPath('message', trans('biblioteca.rejected_changed'));

        $this->assertArrayNotHasKey($key, $this->store->objects, 'el pending no queda retenido');
        $this->assertSame(0, (int) Cache::get('biblioteca:pending:' . $this->managerUserId(), 0), 'el cupo se libera');
        $this->assertSame([], $this->withHeaders($headers)->getJson('/api/biblioteca')->json());
        $row = DB::table('atlantic_auth.audit_logs')->where('action', 'biblioteca.file.rejected')->where('entity_id', $id)->first();
        $this->assertNotNull($row, 'el rechazo queda auditado');
        $this->assertStringContainsString('object_changed', (string) $row->new_values);
    }

    public function test_finalize_con_la_version_borrada_durante_la_lectura_de_magic_bytes_tambien_rechaza(): void
    {
        [$headers, $id, $key] = $this->pendingUpload();
        $this->store->failures['readRange'] = new LibraryObjectChangedException('NoSuchVersion');

        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")->assertStatus(422);

        $this->assertArrayNotHasKey($key, $this->store->objects);
        $this->assertSame(0, (int) Cache::get('biblioteca:pending:' . $this->managerUserId(), 0));
    }

    public function test_finalize_con_s3_no_disponible_responde_503_y_es_reintentable(): void
    {
        [$headers, $id, $key] = $this->pendingUpload();
        $this->store->failures['copy'] = new LibraryStorageUnavailableException('AccessDenied');

        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")
            ->assertStatus(503)
            ->assertJsonPath('message', trans('biblioteca.storage_unavailable'));

        // Nada se descartó: el pending y el cupo siguen, y el upload sigue registrado.
        $this->assertArrayHasKey($key, $this->store->objects);
        $this->assertSame(1, (int) Cache::get('biblioteca:pending:' . $this->managerUserId(), 0));
        $this->assertNotNull(DB::table('atlantic_auth.audit_logs')->where('action', 'biblioteca.file.rejected')->where('entity_id', $id)->first());

        // El almacenamiento vuelve: el mismo finalize ahora publica.
        unset($this->store->failures['copy']);
        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")->assertStatus(201);
        $this->assertCount(1, $this->withHeaders($headers)->getJson('/api/biblioteca')->json());
        $this->assertSame(0, (int) Cache::get('biblioteca:pending:' . $this->managerUserId(), 0));
    }

    public function test_finalize_con_head_no_disponible_responde_503_sin_descartar(): void
    {
        [$headers, $id, $key] = $this->pendingUpload();
        $this->store->failures['head'] = new LibraryStorageUnavailableException('InternalError');

        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")->assertStatus(503);

        $this->assertArrayHasKey($key, $this->store->objects);
        $this->assertSame(1, (int) Cache::get('biblioteca:pending:' . $this->managerUserId(), 0));
    }

    public function test_si_no_se_puede_borrar_el_pending_tras_publicar_la_subida_igual_se_completa(): void
    {
        [$headers, $id] = $this->pendingUpload();
        $this->store->failures['delete'] = new LibraryStorageUnavailableException('AccessDenied');

        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")->assertStatus(201);

        $this->assertCount(1, $this->withHeaders($headers)->getJson('/api/biblioteca')->json());
        $this->assertSame(0, (int) Cache::get('biblioteca:pending:' . $this->managerUserId(), 0));
    }

    public function test_los_rechazos_de_contenido_tambien_quedan_auditados(): void
    {
        $headers = $this->managerHeaders();
        $content = "%PDF-1.7\n<< /S /Launch\0 >>";
        $init    = $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'Malo', 'filename' => 'malo.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => strlen($content),
        ])->assertStatus(201);
        $id = (string) $init->json('upload_id');
        $this->store->simulateBrowserPut("_pending/{$id}.pdf", $content, 'application/pdf');

        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")->assertStatus(422);

        $row = DB::table('atlantic_auth.audit_logs')->where('action', 'biblioteca.file.rejected')->where('entity_id', $id)->first();
        $this->assertNotNull($row);
        $this->assertStringContainsString('pdf_scan:/Launch', (string) $row->new_values);
    }

    // ── Ronda 4: DELETE con S3 caído, tope de max_pdf_mb y logging ────────────

    public function test_delete_con_s3_caido_responde_503_deja_el_catalogo_intacto_y_no_audita(): void
    {
        $headers = $this->managerHeaders();
        $id      = $this->upload($headers, 'crm', 'Manual', 'm.pdf', 'application/pdf', $this->pdf());
        $this->store->failures['delete'] = new LibraryStorageUnavailableException('AccessDenied');
        Log::spy();

        $this->withHeaders($headers)->deleteJson("/api/biblioteca/{$id}")
            ->assertStatus(503)
            ->assertJsonPath('message', trans('biblioteca.storage_unavailable'));

        // Falló el primer borrado (el sidecar): nada cambió y el ítem sigue entero y visible.
        $this->assertArrayHasKey("crm/pdf/{$id}.pdf", $this->store->objects);
        $this->assertArrayHasKey("crm/pdf/{$id}.json", $this->store->objects);
        $this->assertCount(1, $this->withHeaders($headers)->getJson('/api/biblioteca')->json());
        $this->assertNull(DB::table('atlantic_auth.audit_logs')->where('action', 'biblioteca.file.deleted')->where('entity_id', $id)->first(), 'no se audita un borrado que no ocurrió');
        Log::shouldHaveReceived('error')->withArgs(
            static fn (string $message, array $context): bool => $message === 'biblioteca.storage_unavailable'
                && $context['operation'] === 'delete' && $context['aws_code'] === 'AccessDenied' && $context['step'] === 'sidecar',
        )->once();

        // Con S3 de vuelta, el mismo borrado funciona.
        unset($this->store->failures['delete']);
        $this->withHeaders($headers)->deleteJson("/api/biblioteca/{$id}")->assertStatus(204);
        $this->assertSame([], $this->withHeaders($headers)->getJson('/api/biblioteca')->json());
    }

    public function test_delete_borra_primero_el_sidecar_y_luego_el_objeto(): void
    {
        $headers = $this->managerHeaders();
        $id      = $this->upload($headers, 'crm', 'Manual', 'm.pdf', 'application/pdf', $this->pdf());
        $this->store->deleteLog = []; // ignora el borrado del pending que hizo el finalize

        $this->withHeaders($headers)->deleteJson("/api/biblioteca/{$id}")->assertStatus(204);

        $this->assertSame(["crm/pdf/{$id}.json", "crm/pdf/{$id}.pdf"], $this->store->deleteLog, 'primero el sidecar, después el objeto');
    }

    public function test_delete_a_medias_sidecar_borrado_y_objeto_fallido_invalida_el_catalogo_audita_y_responde_503(): void
    {
        $headers = $this->managerHeaders();
        $id      = $this->upload($headers, 'crm', 'Manual', 'm.pdf', 'application/pdf', $this->pdf());
        // Calienta la caché del catálogo: debe invalidarse aunque el objeto no se pueda borrar.
        $this->assertCount(1, $this->withHeaders($headers)->getJson('/api/biblioteca')->json());
        $this->store->deleteFailures["crm/pdf/{$id}.pdf"] = new LibraryStorageUnavailableException('SlowDown');
        Log::spy();

        $this->withHeaders($headers)->deleteJson("/api/biblioteca/{$id}")->assertStatus(503);

        $this->assertSame([], $this->withHeaders($headers)->getJson('/api/biblioteca')->json(), 'el ítem ya no se ve: no queda un ítem roto visible');
        $this->assertArrayHasKey("crm/pdf/{$id}.pdf", $this->store->objects, 'el objeto huérfano queda, invisible');
        $row = DB::table('atlantic_auth.audit_logs')->where('action', 'biblioteca.file.deleted')->where('entity_id', $id)->first();
        $this->assertNotNull($row, 'el ítem sí salió del catálogo: se audita, marcando el objeto pendiente');
        $this->assertStringContainsString('object_delete_failed', (string) $row->new_values);
        Log::shouldHaveReceived('error')->withArgs(
            static fn (string $message, array $context): bool => $message === 'biblioteca.orphan_object' && $context['aws_code'] === 'SlowDown',
        )->once();
    }

    public function test_delete_con_objeto_cambiado_tambien_responde_503_y_no_500(): void
    {
        $headers = $this->managerHeaders();
        $id      = $this->upload($headers, 'crm', 'Manual', 'm.pdf', 'application/pdf', $this->pdf());
        $this->store->failures['delete'] = new LibraryObjectChangedException('NoSuchKey');

        $this->withHeaders($headers)->deleteJson("/api/biblioteca/{$id}")->assertStatus(503);
    }

    public function test_max_pdf_mb_no_puede_superar_el_tope_del_escaner(): void
    {
        $super = $this->superadminHeaders();

        $this->withHeaders($super)->putJson('/api/biblioteca/config', ['max_pdf_mb' => 101])
            ->assertStatus(422)->assertJsonValidationErrors(['max_pdf_mb']);
        $this->withHeaders($super)->putJson('/api/biblioteca/config', ['max_pdf_mb' => 100])
            ->assertOk()->assertJsonPath('max_pdf_mb', 100)->assertJsonPath('max_pdf_bytes', 100 * 1024 * 1024);

        // El tope sigue a pdf_scan_max_bytes: si se baja el escáner, baja lo configurable.
        config(['biblioteca.pdf_scan_max_bytes' => 60 * 1024 * 1024]);
        $this->withHeaders($super)->putJson('/api/biblioteca/config', ['max_pdf_mb' => 61])->assertStatus(422);
        $this->withHeaders($super)->putJson('/api/biblioteca/config', ['max_pdf_mb' => 60])->assertOk();
    }

    public function test_un_valor_ya_guardado_por_encima_del_tope_del_escaner_no_deja_subir_pdfs_que_el_finalize_rechazaria(): void
    {
        // Dato previo al tope (antes se admitía hasta 1024): el tope efectivo es el del escáner, no el guardado.
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        \App\Modules\Auth\Models\SystemSetting::updateOrCreate(['key' => 'library_max_pdf_mb'], ['value' => 200, 'updated_at' => now()]);

        $headers = $this->managerHeaders();
        $this->assertSame(100 * 1024 * 1024, $this->withHeaders($headers)->getJson('/api/biblioteca/config')->json('max_pdf_bytes'));
        $this->withHeaders($headers)->postJson('/api/biblioteca/uploads', [
            'module' => 'crm', 'name' => 'Enorme', 'filename' => 'e.pdf',
            'content_type' => 'application/pdf', 'size_bytes' => 150 * 1024 * 1024,
        ])->assertStatus(422)->assertJsonValidationErrors(['size_bytes']);
        $this->assertNotSame('', $slug);
    }

    public function test_finalize_con_s3_no_disponible_deja_log_de_error_con_contexto_y_sin_secretos(): void
    {
        [$headers, $id] = $this->pendingUpload();
        $this->store->failures['copy'] = new LibraryStorageUnavailableException('AccessDenied');
        Log::spy();

        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")->assertStatus(503);

        Log::shouldHaveReceived('error')->withArgs(
            static fn (string $message, array $context): bool => $message === 'biblioteca.storage_unavailable'
                && $context['upload_id'] === $id
                && $context['aws_code'] === 'AccessDenied'
                && $context['operation'] === 'finalize'
                && ! str_contains((string) json_encode($context), 'secret'),
        )->once();
    }

    public function test_si_falla_la_limpieza_del_pending_se_deja_un_warning_para_el_operador(): void
    {
        [$headers, $id, $key] = $this->pendingUpload();
        $this->store->failures['delete'] = new LibraryStorageUnavailableException('AccessDenied');
        Log::spy();

        $this->withHeaders($headers)->postJson("/api/biblioteca/uploads/{$id}/finalize")->assertStatus(201);

        Log::shouldHaveReceived('warning')->withArgs(
            static fn (string $message, array $context): bool => $message === 'biblioteca.pending_cleanup_failed'
                && $context['key'] === $key && $context['aws_code'] === 'AccessDenied',
        )->once();
    }
}
