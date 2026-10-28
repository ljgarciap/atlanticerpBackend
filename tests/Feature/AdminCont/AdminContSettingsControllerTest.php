<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * REQ-635 (SCRUM-811) — umbrales configurables de alertas de fraude en Admin&Cont (ver
 * `AdminContSettingsService`). Mismo contrato/estilo que `BodegaSettingsControllerTest`.
 */
class AdminContSettingsControllerTest extends FeatureTestCase
{
    private const CONTABILIDAD_EMAIL = 'contabilidad@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantConfigurePermission(): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")->where('email', self::CONTABILIDAD_EMAIL)
            ->update(['extra_permissions' => json_encode(['admin_contab.settings.configure'])]);
    }

    private function loginAsContabilidad(): string
    {
        return $this->loginAs(self::CONTABILIDAD_EMAIL, self::CONTABILIDAD_EMAIL, 'lider_admin_contab');
    }

    public function test_show_devuelve_los_valores_por_defecto(): void
    {
        $token = $this->loginAsContabilidad();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/settings')
            ->assertStatus(200);

        $response->assertJsonPath('fraude_monto_desviaciones', 3);
        $response->assertJsonPath('fraude_monto_ventana_dias', 90);
        $response->assertJsonPath('fraude_aprobaciones_repetidas_n', 5);
        $response->assertJsonPath('fraude_aprobaciones_repetidas_minutos', 10);
    }

    public function test_update_cambia_los_umbrales_y_persiste(): void
    {
        $this->grantConfigurePermission();
        $token = $this->loginAsContabilidad();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/settings', [
                'fraude_monto_desviaciones'            => 2,
                'fraude_monto_ventana_dias'             => 60,
                'fraude_aprobaciones_repetidas_n'       => 8,
                'fraude_aprobaciones_repetidas_minutos' => 15,
            ])
            ->assertStatus(200)
            ->assertJsonPath('fraude_monto_desviaciones', 2)
            ->assertJsonPath('fraude_monto_ventana_dias', 60)
            ->assertJsonPath('fraude_aprobaciones_repetidas_n', 8)
            ->assertJsonPath('fraude_aprobaciones_repetidas_minutos', 15);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/settings')
            ->assertStatus(200);
        $response->assertJsonPath('fraude_monto_desviaciones', 2);
    }

    public function test_update_sin_permiso_configure_retorna_403(): void
    {
        $token = $this->loginAsContabilidad();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/settings', ['fraude_monto_desviaciones' => 2])
            ->assertStatus(403);
    }

    public function test_update_con_valor_cero_retorna_422(): void
    {
        $this->grantConfigurePermission();
        $token = $this->loginAsContabilidad();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/settings', ['fraude_monto_desviaciones' => 0])
            ->assertStatus(422);
    }

    public function test_update_por_encima_del_maximo_retorna_422(): void
    {
        $this->grantConfigurePermission();
        $token = $this->loginAsContabilidad();

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/admin-contab/settings', ['fraude_monto_desviaciones' => 11])
            ->assertStatus(422);
    }

    public function test_show_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/admin-contab/settings')->assertStatus(401);
    }
}
