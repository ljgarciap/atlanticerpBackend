<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Modules\AdminCont\Models\AdminContFiscalSettings;
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
        // SCRUM-943 (2026-09-25) — 'digifact' nunca está wireado, así que nunca hay credenciales
        // reales que chequear: 'desconectado' es el default honesto, ya no un 'conectado' fijo.
        $response->assertJsonPath('pac_connection_status', 'desconectado');
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

    // Pre-QA SCRUM-857/858 (2026-09-09) — la pantalla no permitía VACIAR un campo de texto ya
    // cargado: ConvertEmptyStringsToNull convertía el "" a null y `string` lo rechazaba.
    public function test_update_permite_vaciar_un_campo_de_texto_ya_cargado(): void
    {
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['nombre_comercial' => 'Algo Cargado'])
            ->assertStatus(200)
            ->assertJsonPath('nombre_comercial', 'Algo Cargado');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['nombre_comercial' => ''])
            ->assertStatus(200)
            ->assertJsonPath('nombre_comercial', '');
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

    /**
     * SCRUM-943 (2026-09-25) — `pac_connection_status` ya no es un valor de BD que el cliente
     * pueda intentar mandar: siempre se recalcula real (¿el proveedor/ambiente activo tiene API
     * key configurada?), sin importar lo que venga en el body.
     */
    public function test_update_ignora_pac_connection_status_del_body_y_calcula_el_real(): void
    {
        config(['services.msl_fe.sandbox.api_key' => 'una-key-real']);
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl'])->save();
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', [
                'pac_connection_status' => 'desconectado', // se ignora — el real es 'conectado'
                'pac_ambiente' => 'testing',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('pac_connection_status', 'conectado');
        $response->assertJsonPath('pac_ambiente', 'testing');
    }

    public function test_pac_connection_status_desconectado_sin_api_key_configurada(): void
    {
        config(['services.msl_fe.sandbox.api_key' => null]);
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'testing'])->save();
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/fiscal-settings')
            ->assertStatus(200)
            ->assertJsonPath('pac_connection_status', 'desconectado');
    }

    public function test_pac_connection_status_desconectado_en_apagado_aunque_haya_api_key(): void
    {
        config(['services.msl_fe.sandbox.api_key' => 'una-key-real']);
        AdminContFiscalSettings::current()->forceFill(['pac_provider' => 'msl', 'pac_ambiente' => 'apagado'])->save();
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/fiscal-settings')
            ->assertStatus(200)
            ->assertJsonPath('pac_connection_status', 'desconectado');
    }

    /** SCRUM-943 — interruptor global apagado/sandbox/producción, Senior Review 2026-09-24. */
    public function test_update_acepta_apagado_como_valor_de_pac_ambiente(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['pac_ambiente' => 'apagado'])
            ->assertStatus(200);

        $response->assertJsonPath('pac_ambiente', 'apagado');
    }

    public function test_update_rechaza_un_valor_invalido_de_pac_ambiente(): void
    {
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['pac_ambiente' => 'no-existe'])
            ->assertStatus(422);
    }

    // SCRUM-550 (rebote de Daniela 2026-09-10) — contacto de la empresa (teléfono/correo), nuevo
    // en Configuración Fiscal para que el recibo de cobro pueda mostrarlo (RN1).
    public function test_update_carga_y_vacia_el_contacto_de_la_empresa(): void
    {
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', [
                'telefono_contacto' => '+507 6000-9999',
                'correo_contacto' => 'facturacion@atlantic.com.pa',
            ])
            ->assertStatus(200)
            ->assertJsonPath('telefono_contacto', '+507 6000-9999')
            ->assertJsonPath('correo_contacto', 'facturacion@atlantic.com.pa');

        // Mismo criterio que el resto de campos de texto (test_update_permite_vaciar_...): ""
        // debe poder vaciar un contacto ya cargado, no rechazarse.
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['telefono_contacto' => '', 'correo_contacto' => ''])
            ->assertStatus(200)
            ->assertJsonPath('telefono_contacto', '')
            ->assertJsonPath('correo_contacto', '');
    }

    public function test_update_rechaza_correo_contacto_con_formato_invalido(): void
    {
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/fiscal-settings', ['correo_contacto' => 'no-es-un-correo'])
            ->assertStatus(422);
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
