<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\Quote;
use App\Modules\VentasDiseno\Models\QuoteConditionsSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-138 — texto global de Condiciones (REQ-046), reemplaza la edición
 * por-cotización. Mismo patrón que PricingSettingsControllerTest.
 */
class QuoteConditionsSettingsControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantEditConditions(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.edit.conditions'])]);
    }

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    public function test_get_settings_sin_permiso_retorna_403(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quote-conditions-settings')
            ->assertStatus(403);
    }

    public function test_get_settings_retorna_el_texto_fijo_por_defecto(): void
    {
        $this->grantEditConditions('management@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/quote-conditions-settings')
            ->assertStatus(200)
            ->assertJsonPath('text', Quote::DEFAULT_CONDITIONS_TEXT);
    }

    public function test_actualizar_settings_sin_permiso_retorna_403(): void
    {
        $this->grantVentasDisenoRead('designer@atlantic.test');
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/ventas-diseno/quote-conditions-settings', ['text' => 'Nuevo texto'])
            ->assertStatus(403);
    }

    public function test_actualizar_settings_con_permiso(): void
    {
        $this->grantEditConditions('management@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/ventas-diseno/quote-conditions-settings', ['text' => 'Condiciones globales nuevas'])
            ->assertStatus(200)
            ->assertJsonPath('text', 'Condiciones globales nuevas');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame('Condiciones globales nuevas', QuoteConditionsSettings::current()->text);
    }

    public function test_actualizar_settings_rechaza_texto_vacio(): void
    {
        $this->grantEditConditions('management@atlantic.test');
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/ventas-diseno/quote-conditions-settings', ['text' => ''])
            ->assertStatus(422);
    }
}
