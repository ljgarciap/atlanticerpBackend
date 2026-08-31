<?php

declare(strict_types=1);

namespace Tests\Feature\AdminCont;

use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/** SCRUM-635/636 (REQ-558/559) — catálogo de tasas de ITBMS. */
class AdminContItbmsRateControllerTest extends FeatureTestCase
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

    public function test_index_devuelve_las_3_tasas_base_activas(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/itbms-rates')
            ->assertStatus(200);

        $response->assertJsonCount(3);
        $response->assertJsonFragment(['nombre' => '7% — General', 'es_base' => true, 'activa' => true]);
        $response->assertJsonFragment(['nombre' => '3.5% — Retención de impuesto', 'es_base' => true, 'activa' => true]);
        $response->assertJsonFragment(['nombre' => '0% — Exento', 'es_base' => true, 'activa' => true]);
    }

    public function test_index_rechaza_a_un_usuario_que_no_es_mark(): void
    {
        $token = $this->loginAs('multi@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin-contab/itbms-rates')
            ->assertStatus(403);
    }

    public function test_store_crea_una_tasa_personalizada_desactivada_por_defecto(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/itbms-rates', [
                'nombre' => '10% — Servicios de hospedaje',
                'descripcion' => 'Tasa especial para servicios de hospedaje.',
                'porcentaje' => 10,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('es_base', false);
        $response->assertJsonPath('activa', false);
    }

    public function test_store_ignora_activa_del_body(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/itbms-rates', [
                'nombre' => 'Tasa X',
                'descripcion' => 'Descripción X',
                'porcentaje' => 5,
                'activa' => true,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('activa', false);
    }

    public function test_store_no_exige_nombre(): void
    {
        $token = $this->loginAsMark();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/itbms-rates', [
                'descripcion' => 'Servicios de hospedaje',
                'porcentaje' => 10,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('nombre', null);
        $response->assertJsonPath('descripcion', 'Servicios de hospedaje');
        $response->assertJsonPath('activa', false);
    }

    public function test_store_exige_porcentaje_y_descripcion(): void
    {
        $token = $this->loginAsMark();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin-contab/itbms-rates', ['nombre' => 'Tasa incompleta'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['descripcion', 'porcentaje']);
    }

    public function test_update_activa_una_tasa_personalizada(): void
    {
        $token = $this->loginAsMark();
        $headers = $this->authHeaders($token);

        $created = $this->withHeaders($headers)->postJson('/api/admin-contab/itbms-rates', [
            'nombre' => 'Tasa Y', 'descripcion' => 'Descripción Y', 'porcentaje' => 12,
        ])->assertStatus(201);

        $id = $created->json('id');

        $this->withHeaders($headers)
            ->putJson("/api/admin-contab/itbms-rates/{$id}", ['activa' => true])
            ->assertStatus(200)
            ->assertJsonPath('activa', true);
    }

    public function test_destroy_elimina_una_tasa_personalizada(): void
    {
        $token = $this->loginAsMark();
        $headers = $this->authHeaders($token);

        $created = $this->withHeaders($headers)->postJson('/api/admin-contab/itbms-rates', [
            'nombre' => 'Tasa Z', 'descripcion' => 'Descripción Z', 'porcentaje' => 8,
        ])->assertStatus(201);

        $id = $created->json('id');

        $this->withHeaders($headers)
            ->deleteJson("/api/admin-contab/itbms-rates/{$id}")
            ->assertStatus(204);
    }

    public function test_destroy_rechaza_eliminar_una_tasa_base(): void
    {
        $token = $this->loginAsMark();
        $headers = $this->authHeaders($token);

        $baseId = $this->withHeaders($headers)
            ->getJson('/api/admin-contab/itbms-rates')
            ->json('0.id');

        $this->withHeaders($headers)
            ->deleteJson("/api/admin-contab/itbms-rates/{$baseId}")
            ->assertStatus(403);
    }
}
