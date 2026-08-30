<?php

declare(strict_types=1);

namespace Tests\Feature\VentasDiseno;

use App\Modules\VentasDiseno\Models\PricingSettings;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * REQ-033 (2026-07-12): porcentajes/divisores de las fórmulas de Tipo de
 * Precio, sacados de constantes PHP a una tabla configurable. Mismo patrón
 * que ReportsConfigControllerTest — ver memory/project_backlog_ventas_diseno_req.md.
 */
class PricingSettingsControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function grantPricingConfigure(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read', 'ventas_diseno.pricing.configure'])]);
    }

    private function grantVentasDisenoRead(string $email): void
    {
        $slug = (string) Tenant::where('slug', 'illuminations')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode(['ventas_diseno.read'])]);
    }

    public function test_get_settings_sin_permiso_pricing_configure_retorna_403(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pricing-settings')
            ->assertStatus(403);
    }

    public function test_get_settings_retorna_los_valores_default(): void
    {
        $this->grantPricingConfigure('management@illuminations.test');
        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/ventas-diseno/pricing-settings')
            ->assertStatus(200);

        $response->assertJsonPath('project_discount_percent', 15);
        $response->assertJsonPath('special_additional_discount_percent', 5);
        $response->assertJsonPath('partner_divisor', 0.85);
        $response->assertJsonPath('premium_divisor', 0.65);
    }

    public function test_actualizar_settings_sin_permiso_retorna_403(): void
    {
        $this->grantVentasDisenoRead('designer@illuminations.test');
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/ventas-diseno/pricing-settings', ['project_discount_percent' => 20])
            ->assertStatus(403);
    }

    public function test_actualizar_settings_con_permiso(): void
    {
        $this->grantPricingConfigure('management@illuminations.test');
        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/ventas-diseno/pricing-settings', ['project_discount_percent' => 20])
            ->assertStatus(200)
            ->assertJsonPath('project_discount_percent', 20)
            ->assertJsonPath('special_additional_discount_percent', 5);

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertSame(20.0, PricingSettings::current()->project_discount_percent);
    }

    public function test_actualizar_settings_rechaza_divisor_cero(): void
    {
        $this->grantPricingConfigure('management@illuminations.test');
        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/ventas-diseno/pricing-settings', ['premium_divisor' => 0])
            ->assertStatus(422);
    }
}
