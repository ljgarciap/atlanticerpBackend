<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** SCRUM-638/639/642 (REQ-561/562/565) — Datos de la Empresa, exclusiva de Mark. */
class AdminContCompanyProfileControllerTest extends FeatureTestCase
{
    private const MARK_EMAIL = 'mbekhar@atlantic.com.pa';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-public');
        config(['filesystems.disks.s3-public.cdn_url' => 'https://cdn.atlantic.test']);
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::MARK_EMAIL, self::MARK_EMAIL, 'management');
    }

    public function test_show_devuelve_los_defaults_y_espeja_razon_social_de_fiscal_settings(): void
    {
        AdminContFiscalSettings::current()->update([
            'razon_social'     => 'Atlantic Panama S.A.',
            'nombre_comercial' => 'Atlantic',
        ]);

        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/company-profile')
            ->assertStatus(200);

        $response->assertJsonPath('razon_social', 'Atlantic Panama S.A.');
        $response->assertJsonPath('nombre_comercial', 'Atlantic');
        $response->assertJsonPath('moneda', 'USD');
        $response->assertJsonPath('anio_fiscal', 'Enero-Diciembre');
        $response->assertJsonPath('zona_horaria', 'America/Panama');
        $response->assertJsonPath('logo_url', null);
    }

    public function test_show_rechaza_a_un_usuario_que_no_es_mark(): void
    {
        // `management@atlantic.test` carga Nivel 10 (superadmin.all) — desde que superadmin
        // bypassa el gate de Mark (2026-08-22), usar `multi@atlantic.test` (Nivel 8) para
        // simular un management genuino, no-Mark y no-superadmin.
        $token = $this->loginAs('multi@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/company-profile')
            ->assertStatus(403);
    }

    public function test_update_cambia_los_campos_editables(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/company-profile', [
                'descripcion_corta' => 'Diseño y suministro de iluminación en Panamá.',
                'sitio_web'         => 'https://atlantic.com.pa',
                'redes_sociales'    => [['plataforma' => 'Instagram', 'url' => 'https://instagram.com/atlantic']],
            ])
            ->assertStatus(200);

        $response->assertJsonPath('descripcion_corta', 'Diseño y suministro de iluminación en Panamá.');
        $response->assertJsonPath('sitio_web', 'https://atlantic.com.pa');
        $response->assertJsonPath('redes_sociales.0.plataforma', 'Instagram');
    }

    public function test_update_ignora_razon_social_nombre_comercial_moneda_anio_fiscal_del_body(): void
    {
        AdminContFiscalSettings::current()->update(['razon_social' => 'Original S.A.', 'nombre_comercial' => 'Original']);

        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/company-profile', [
                'razon_social'     => 'Otra Cosa S.A.',
                'nombre_comercial' => 'Otra Cosa',
                'moneda'           => 'PAB',
                'anio_fiscal'      => 'Abril-Marzo',
                'descripcion_corta' => 'Cambio real',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('razon_social', 'Original S.A.');
        $response->assertJsonPath('nombre_comercial', 'Original');
        $response->assertJsonPath('moneda', 'USD');
        $response->assertJsonPath('anio_fiscal', 'Enero-Diciembre');
        $response->assertJsonPath('descripcion_corta', 'Cambio real');
    }

    public function test_update_rechaza_zona_horaria_distinta_a_america_panama(): void
    {
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/company-profile', ['zona_horaria' => 'America/New_York'])
            ->assertStatus(422);
    }

    public function test_upload_logo_acepta_png_y_devuelve_url_publica(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token) + ['Accept' => 'application/json'])
            ->post('/api/admin-contab/company-profile/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(200);

        $response->assertJsonStructure(['logo_url']);
        $this->assertStringStartsWith('https://cdn.atlantic.test/public/admin-contab/company/', (string) $response->json('logo_url'));

        $show = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/company-profile')
            ->assertStatus(200);
        $this->assertNotNull($show->json('logo_url'));
    }

    public function test_upload_logo_rechaza_formato_no_permitido(): void
    {
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token) + ['Accept' => 'application/json'])
            ->post('/api/admin-contab/company-profile/logo', [
                'file' => UploadedFile::fake()->create('logo.pdf', 100, 'application/pdf'),
            ])
            ->assertStatus(422);
    }

    public function test_upload_logo_rechaza_a_un_usuario_que_no_es_mark(): void
    {
        // `management@atlantic.test` carga Nivel 10 (superadmin.all) — desde que superadmin
        // bypassa el gate de Mark (2026-08-22), usar `multi@atlantic.test` (Nivel 8) para
        // simular un management genuino, no-Mark y no-superadmin.
        $token = $this->loginAs('multi@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token) + ['Accept' => 'application/json'])
            ->post('/api/admin-contab/company-profile/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(403);
    }
}
