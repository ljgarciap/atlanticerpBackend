<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\PipelineCard;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

class PipelineFileUploadTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function grantVentasDisenoEdit(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.write', 'ventas_diseno.edit'])]);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        return (int) DB::table("{$slug}_auth.users")->where('email', $email)->value('id');
    }

    private function makeCard(int $ownerId, string $stage = 'design'): PipelineCard
    {
        $master = MasterClient::create(['name' => 'Cliente Test']);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Subcliente Test', 'tax_id' => '155-0000-1-2026']);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto Test']);

        return PipelineCard::create([
            'sales_project_id' => $project->id, 'stage' => $stage, 'master_client_id' => $master->id,
            'sub_client_id' => $sub->id, 'owner_id' => $ownerId, 'stage_changed_at' => Carbon::now(),
        ]);
    }

    public function test_subir_archivo_de_diseno(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'design');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $file  = UploadedFile::fake()->create('plano.pdf', 200, 'application/pdf');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/pipeline/{$card->id}/files", ['type' => 'design', 'file' => $file])
            ->assertStatus(201);

        $response->assertJsonPath('type', 'design');
        $response->assertJsonPath('file_name', 'plano.pdf');

        // La request HTTP anterior ya olvidó el tenant activo al terminar (AuthenticateJwt
        // lo hace en su `finally`) — se reactiva para esta consulta directa por Eloquent.
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $storageKey = $card->files()->first()?->storage_key;
        $this->assertNotNull($storageKey);
        Storage::disk('s3-private')->assertExists($storageKey);
    }

    public function test_subir_archivo_con_extension_no_permitida_retorna_422(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'design');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $file  = UploadedFile::fake()->create('modelo.dwg', 100);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/pipeline/{$card->id}/files", ['type' => 'design', 'file' => $file])
            ->assertStatus(422);
    }

    public function test_subir_archivo_a_tarjeta_perdida_retorna_403(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'lost');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $file  = UploadedFile::fake()->create('plano.pdf', 100, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/pipeline/{$card->id}/files", ['type' => 'design', 'file' => $file])
            ->assertStatus(403);
    }

    public function test_archivo_subido_aparece_en_el_detalle_de_la_tarjeta(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'design');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $file  = UploadedFile::fake()->create('plano.pdf', 100, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/pipeline/{$card->id}/files", ['type' => 'design', 'file' => $file])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}")
            ->assertStatus(200);

        $detail->assertJsonCount(1, 'files');
        $detail->assertJsonPath('files.0.file_name', 'plano.pdf');
        // SCRUM-767 — el frontend necesita mime_type para decidir preview inline vs descarga.
        $detail->assertJsonPath('files.0.mime_type', 'application/pdf');
    }

    /** SCRUM-767 — GET .../files/{fileId}/url devuelve una URL presignada real, no expone el storage_key crudo. */
    public function test_obtener_url_de_archivo_ya_cargado(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'design');

        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $file  = UploadedFile::fake()->create('plano.pdf', 100, 'application/pdf');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/pipeline/{$card->id}/files", ['type' => 'design', 'file' => $file])
            ->assertStatus(201);

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $fileId = $card->files()->first()?->id;
        $this->assertNotNull($fileId);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}/files/{$fileId}/url")
            ->assertStatus(200);

        $response->assertJsonPath('filename', 'plano.pdf');
        $response->assertJsonPath('mime_type', 'application/pdf');
        $this->assertNotEmpty($response->json('url'));
        // La respuesta nunca expone el storage_key crudo (S3 key interno).
        $this->assertArrayNotHasKey('storage_key', $response->json());
    }

    /**
     * Fix de Pre-QA 2026-08-15 (verificado en vivo contra S3 real con Playwright, ver
     * e2e/preqa-scrum767-*): sin `ResponseContentDisposition` explícito, el navegador ignora
     * `a.download` en una URL cross-origin (el propio bucket S3) y guarda el archivo con la KEY
     * UUID interna en vez de `file_name`. Acá solo se verifica que el endpoint acepta ambos
     * valores de `disposition` y cae a 'inline' ante cualquier otro — el disco fake de test no
     * refleja `ResponseContentDisposition` en la URL devuelta (no es una firma S3 real), así que
     * la diferencia real de contenido queda verificada contra S3 real en el spec de Playwright.
     */
    public function test_url_acepta_disposition_inline_y_attachment_cae_a_inline_por_defecto(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'design');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $file  = UploadedFile::fake()->create('plano.pdf', 100, 'application/pdf');
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/pipeline/{$card->id}/files", ['type' => 'design', 'file' => $file])
            ->assertStatus(201);

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $fileId = $card->files()->first()?->id;

        foreach (['inline', 'attachment', 'algo-invalido', null] as $disposition) {
            $query = $disposition !== null ? "?disposition={$disposition}" : '';
            $this->withHeaders($this->authHeaders($token))
                ->getJson("/api/ventas-diseno/pipeline/{$card->id}/files/{$fileId}/url{$query}")
                ->assertStatus(200)
                ->assertJsonPath('filename', 'plano.pdf');
        }
    }

    /**
     * SCRUM-767 — el gate de acceso a la URL es el mismo que el resto de la tarjeta (dueño o
     * equipo). Mismo patrón que `PipelineControllerTest::test_ver_detalle_de_tarjeta_ajena_sin_alcance_equipo_retorna_403()`:
     * la tarjeta es de `management` (dueño), `designer` no tiene alcance de equipo ni es el dueño.
     */
    public function test_obtener_url_de_archivo_de_tarjeta_ajena_retorna_403(): void
    {
        $this->grantVentasDisenoEdit('management@illuminations.test');
        $ownerId = $this->userIdByEmail('management@illuminations.test');
        $card = $this->makeCard($ownerId, 'design');
        $ownerToken = $this->loginAs('management@illuminations.test', 'Password123!', 'management');
        $file = UploadedFile::fake()->create('plano.pdf', 100, 'application/pdf');
        $this->withHeaders($this->authHeaders($ownerToken))
            ->postJson("/api/ventas-diseno/pipeline/{$card->id}/files", ['type' => 'design', 'file' => $file])
            ->assertStatus(201);

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $fileId = $card->files()->first()?->id;

        $this->grantVentasDisenoEdit('designer@illuminations.test'); // designer: sin alcance de equipo, no dueño
        $designerToken = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($designerToken))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}/files/{$fileId}/url")
            ->assertStatus(403);
    }

    /**
     * Rebote de Daniela Amaya (2026-08-16, SCRUM-767): S3 rechazaba `ResponseContentDisposition`
     * con "Header value cannot be represented using ISO-8859-1" para un nombre de archivo real de
     * captura de pantalla de macOS — el nombre autogenerado trae un espacio angosto Unicode
     * (U+202F, "\xE2\x80\xAF" en UTF-8) entre la hora y "p.m.", invisible pero fuera de Latin-1.
     * El disco fake no valida el charset del header (eso solo lo hace S3 real, ver docblock de
     * `PipelineFileService::getPresignedUrl()`), pero si la construcción del header rompiera con
     * `iconv`/`rawurlencode`, este test sí lo agarraría — nunca debe tirar excepción ni 500 con un
     * nombre así.
     */
    public function test_obtener_url_de_archivo_con_nombre_conflictivo_para_iso_8859_1(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'design');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $problematicName = "Captura de pantalla 2026-08-11 a la(s) 2.35.19\u{202F}p.m..png";
        $file = UploadedFile::fake()->create($problematicName, 100, 'image/png');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/ventas-diseno/pipeline/{$card->id}/files", ['type' => 'design', 'file' => $file])
            ->assertStatus(201);

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $fileId = $card->files()->first()?->id;
        $this->assertNotNull($fileId);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}/files/{$fileId}/url?disposition=attachment")
            ->assertStatus(200);

        $response->assertJsonPath('filename', $problematicName);
        $this->assertNotEmpty($response->json('url'));
    }

    public function test_obtener_url_de_archivo_inexistente_retorna_404(): void
    {
        $this->grantVentasDisenoEdit('designer@illuminations.test');
        $designerId = $this->userIdByEmail('designer@illuminations.test');
        $card = $this->makeCard($designerId, 'design');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/ventas-diseno/pipeline/{$card->id}/files/999999/url")
            ->assertStatus(404);
    }
}
