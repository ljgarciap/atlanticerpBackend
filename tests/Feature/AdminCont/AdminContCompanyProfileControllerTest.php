<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/** SCRUM-638/639/642 (REQ-561/562/565) — Datos de la Empresa, exclusiva de Gerencia. */
class AdminContCompanyProfileControllerTest extends FeatureTestCase
{
    private const APPROVER_EMAIL = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-public');
        config(['filesystems.disks.s3-public.cdn_url' => 'https://cdn.atlantic.test']);
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    public function test_show_devuelve_los_defaults_y_espeja_razon_social_de_fiscal_settings(): void
    {
        AdminContFiscalSettings::current()->update([
            'razon_social' => 'Atlantic Panama S.A.',
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
        // bypassa el gate de Gerencia (2026-08-22), usar `multi@atlantic.test` (Nivel 8) para
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
                'sitio_web' => 'https://atlantic.com.pa',
                'redes_sociales' => [['plataforma' => 'Instagram', 'url' => 'https://instagram.com/atlantic']],
            ])
            ->assertStatus(200);

        $response->assertJsonPath('descripcion_corta', 'Diseño y suministro de iluminación en Panamá.');
        $response->assertJsonPath('sitio_web', 'https://atlantic.com.pa');
        $response->assertJsonPath('redes_sociales.0.plataforma', 'Instagram');
    }

    public function test_update_ignora_razon_social_nombre_comercial_y_anio_fiscal_del_body(): void
    {
        AdminContFiscalSettings::current()->update(['razon_social' => 'Original S.A.', 'nombre_comercial' => 'Original']);

        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/company-profile', [
                'razon_social' => 'Otra Cosa S.A.',
                'nombre_comercial' => 'Otra Cosa',
                'anio_fiscal' => 'Abril-Marzo',
                'descripcion_corta' => 'Cambio real',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('razon_social', 'Original S.A.');
        $response->assertJsonPath('nombre_comercial', 'Original');
        $response->assertJsonPath('moneda', 'USD');
        $response->assertJsonPath('anio_fiscal', 'Enero-Diciembre');
        $response->assertJsonPath('descripcion_corta', 'Cambio real');
    }

    public function test_show_expone_el_catalogo_de_paises_y_el_contexto_regional(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/admin-contab/company-profile')->assertOk();

        $response->assertJsonPath('pais', 'PA')->assertJsonPath('moneda', 'USD')->assertJsonPath('zona_horaria', 'America/Panama')->assertJsonPath('locale', 'es-PA');
        $this->assertSame(['PA', 'CO', 'US'], collect($response->json('paises'))->pluck('code')->all());
        $this->assertSame(
            ['code' => 'CO', 'name' => 'Colombia', 'currency' => 'COP', 'timezone' => 'America/Bogota', 'locale' => 'es-CO'],
            collect($response->json('paises'))->firstWhere('code', 'CO'),
        );
    }

    public function test_update_rechaza_una_zona_horaria_que_no_es_iana(): void
    {
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/company-profile', ['zona_horaria' => 'Marte/Olimpo'])
            ->assertStatus(422);
    }

    /** Fase 6a — país, moneda, zona y locale viven en el tenant; el perfil los lee y los escribe ahí. */
    public function test_update_cambia_el_contexto_regional_del_tenant(): void
    {
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/company-profile', ['pais' => 'CO', 'moneda' => 'COP', 'zona_horaria' => 'America/Bogota', 'locale' => 'es-CO'])
            ->assertOk()
            ->assertJsonPath('pais', 'CO')
            ->assertJsonPath('moneda', 'COP')
            ->assertJsonPath('zona_horaria', 'America/Bogota')
            ->assertJsonPath('locale', 'es-CO');

        $this->assertSame(
            ['country' => 'CO', 'currency' => 'COP', 'timezone' => 'America/Bogota', 'locale' => 'es-CO'],
            \App\Shared\Multitenancy\Tenant::where('slug', 'atlantic')->firstOrFail()->region(),
        );
    }

    public function test_update_rechaza_un_pais_fuera_del_catalogo_y_una_moneda_invalida(): void
    {
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))->putJson('/api/admin-contab/company-profile', ['pais' => 'ZZ'])->assertStatus(422);
        $this->withHeaders($this->authHeaders($token))->putJson('/api/admin-contab/company-profile', ['moneda' => 'dolar'])->assertStatus(422);
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
        // bypassa el gate de Gerencia (2026-08-22), usar `multi@atlantic.test` (Nivel 8) para
        // simular un management genuino, no-Mark y no-superadmin.
        $token = $this->loginAs('multi@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token) + ['Accept' => 'application/json'])
            ->post('/api/admin-contab/company-profile/logo', [
                'file' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertStatus(403);
    }
}
