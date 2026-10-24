<?php

declare(strict_types=1);

namespace Tests\Feature\Compras;

use App\Modules\Compras\Models\ComprasSettings;
use App\Modules\Compras\Models\Provider;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * Umbral de "calificación baja" (REQ-121/122) — parámetro configurable, pedido explícito de
 * Luis de no hardcodear valores de negocio (daily sprint2).
 */
class ComprasSettingsControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function grantCompras(string $email, array $perms): void
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');
        DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->update(['extra_permissions' => json_encode($perms)]);
    }

    public function test_show_devuelve_el_valor_por_defecto(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/settings')
            ->assertStatus(200);

        // Luis pidió explícitamente que el default quede por debajo del punto medio de la
        // escala 0-100 (corregido 2026-07-16 desde el 60 original, ver migración).
        $response->assertJsonPath('low_rating_threshold', 40);
        // SCRUM-257 (REQ-194) — hallazgo de QA: existía en la tabla paramétrica pero no se
        // exponía por API, así que no había forma de verificar ni ajustar el umbral desde Ajustes.
        $response->assertJsonPath('claim_attention_days', 7);
        // SCRUM-247 (REQ-184) — literal de la Regla de Negocio: "menor a 15%".
        $response->assertJsonPath('replacement_margin_threshold', 15);
        // Gap real encontrado por Luis (superadmin, 2026-08-22): estos 5 campos (Lote 4,
        // SCRUM-246) ya se persistían bien en update() pero nunca se devolvían acá — ninguna
        // pantalla podía leer ni editar la fórmula de similitud desde entonces.
        $response->assertJsonPath('similarity_weight_text', 50);
        $response->assertJsonPath('similarity_weight_price', 30);
        $response->assertJsonPath('similarity_weight_margin', 20);
        $response->assertJsonPath('similarity_other_category_penalty', 30);
        $response->assertJsonPath('similarity_min_threshold', 25);
    }

    public function test_update_cambia_los_pesos_de_la_formula_de_similitud(): void
    {
        $this->grantCompras('designer@atlantic.test', [
            'compras.read', 'compras.settings.configure',
        ]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/compras/settings', [
                'similarity_weight_text' => 60, 'similarity_weight_price' => 25,
                'similarity_weight_margin' => 15, 'similarity_min_threshold' => 30,
            ])
            ->assertStatus(200)
            ->assertJsonPath('similarity_weight_text', 60)
            ->assertJsonPath('similarity_min_threshold', 30);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/settings')
            ->assertStatus(200);
        $response->assertJsonPath('similarity_weight_text', 60);
        $response->assertJsonPath('similarity_min_threshold', 30);
    }

    public function test_update_cambia_los_dias_de_atencion_de_reclamos(): void
    {
        $this->grantCompras('designer@atlantic.test', [
            'compras.read', 'compras.settings.configure',
        ]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/compras/settings', ['claim_attention_days' => 14])
            ->assertStatus(200)
            ->assertJsonPath('claim_attention_days', 14);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/settings')
            ->assertStatus(200);
        $response->assertJsonPath('claim_attention_days', 14);
    }

    public function test_update_cambia_el_umbral_de_margen_de_sustitutos(): void
    {
        $this->grantCompras('designer@atlantic.test', [
            'compras.read', 'compras.settings.configure',
        ]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/compras/settings', ['replacement_margin_threshold' => 20])
            ->assertStatus(200)
            ->assertJsonPath('replacement_margin_threshold', 20);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/settings')
            ->assertStatus(200);
        $response->assertJsonPath('replacement_margin_threshold', 20);
    }

    public function test_update_sin_permiso_configure_retorna_403(): void
    {
        $this->grantCompras('designer@atlantic.test', ['compras.read']);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/compras/settings', ['low_rating_threshold' => 70])
            ->assertStatus(403);
    }

    public function test_zona_libre_provider_apunta_a_bk_interprises(): void
    {
        $settings = ComprasSettings::current();
        $this->assertNotNull($settings->zona_libre_provider_id);

        $provider = Provider::find($settings->zona_libre_provider_id);
        $this->assertNotNull($provider);
        $this->assertSame('BK INTERPRISES S.A.', $provider->name);
    }

    public function test_update_cambia_el_umbral_usado_por_el_filtro_de_proveedores(): void
    {
        $this->grantCompras('designer@atlantic.test', [
            'compras.read', 'compras.settings.configure',
        ]);
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/compras/settings', ['low_rating_threshold' => 85])
            ->assertStatus(200)
            ->assertJsonPath('low_rating_threshold', 85);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/compras/settings')
            ->assertStatus(200);
        $response->assertJsonPath('low_rating_threshold', 85);
    }
}
