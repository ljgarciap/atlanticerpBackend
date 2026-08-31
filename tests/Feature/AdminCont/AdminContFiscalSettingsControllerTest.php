<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-632/633/634/637 (REQ-555/556/557/560) — Configuración Fiscal, exclusiva de Mark
 * (`gerencia3@test.com`, sembrado por CoreUserSeeder, password default = email).
 */
class AdminContFiscalSettingsControllerTest extends FeatureTestCase
{
    private const APPROVER_EMAIL = 'gerencia3@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function loginAsMark(): string
    {
        return $this->loginAs(self::APPROVER_EMAIL, self::APPROVER_EMAIL, 'management');
    }

    public function test_show_devuelve_los_defaults_para_mark(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/fiscal-settings')
            ->assertStatus(200);

        $response->assertJsonPath('regimen_tributario', 'general');
        $response->assertJsonPath('pac_provider', 'digifact');
        $response->assertJsonPath('pac_connection_status', 'conectado');
        $response->assertJsonPath('pac_ambiente', 'testing');
        $response->assertJsonPath('pac_doc_factura_habilitado', true);
        $response->assertJsonPath('retencion_proveedores_activa', false);
    }

    public function test_show_rechaza_a_un_usuario_que_no_es_mark(): void
    {
        $token = $this->loginAs('multi@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/fiscal-settings')
            ->assertStatus(403);
    }

    /** superadmin.all bypassa el gate exclusivo de Mark (decisión de Luis, 2026-08-22) — a
     *  diferencia de Gerencia genérica, que sigue bloqueada (test de arriba). */
    public function test_show_permite_a_superadmin_aunque_no_sea_mark(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/fiscal-settings')
            ->assertStatus(200);
    }

    public function test_update_cambia_los_datos_fiscales(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', [
                'razon_social' => 'Atlantic Panama S.A.',
                'ruc' => '155646789-2-2019',
                'regimen_tributario' => 'pequeno_contribuyente',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('razon_social', 'Atlantic Panama S.A.');
        $response->assertJsonPath('regimen_tributario', 'pequeno_contribuyente');
    }

    public function test_update_ignora_pac_last_sync_at_y_primary_approver_user_id_del_body(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', [
                'pac_last_sync_at' => '2020-01-01 00:00:00',
                'primary_approver_user_id' => 999999,
                'pac_ambiente' => 'production',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('pac_last_sync_at', null);
        $response->assertJsonPath('pac_ambiente', 'production');
        $response->assertJsonPath('primary_approver_user_id', fn ($id) => $id !== 999999);
    }

    public function test_update_ignora_pac_connection_status_del_body(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', [
                'pac_connection_status' => 'desconectado',
                'pac_ambiente' => 'production',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('pac_connection_status', 'conectado');
        $response->assertJsonPath('pac_ambiente', 'production');
    }

    public function test_update_rechaza_regimen_tributario_invalido(): void
    {
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['regimen_tributario' => 'otro'])
            ->assertStatus(422);
    }

    public function test_update_rechaza_a_un_usuario_que_no_es_mark(): void
    {
        $token = $this->loginAs('multi@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['razon_social' => 'Otra cosa'])
            ->assertStatus(403);
    }
}
