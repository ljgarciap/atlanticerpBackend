<?php

declare(strict_types=1);

namespace Tests\Feature\Gerencia;

use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-163 (REQ-101) — infraestructura configurable de REGLA_APROBACION. La matriz real (qué
 * tipo aprueba cada persona) sigue pendiente de definir con el cliente — estos tests cubren solo
 * el mecanismo CRUD + el gate de "exclusivo de superadmin".
 */
class ReglaAprobacionControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_superadmin_crea_lista_edita_y_elimina_una_regla(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $aprobador = $this->userIdByEmail('management@atlantic.test');

        $create = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/gerencia/reglas-aprobacion', [
                'tipo'          => 'Orden de compra fuera de rango',
                'activo'        => true,
                'observaciones' => 'Prueba',
                'aprobador_ids' => [$aprobador],
            ])
            ->assertStatus(201)
            ->assertJsonPath('tipo', 'Orden de compra fuera de rango')
            ->assertJsonPath('activo', true)
            ->assertJsonCount(1, 'aprobadores');

        $id = $create->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/gerencia/reglas-aprobacion')
            ->assertStatus(200)
            ->assertJsonFragment(['tipo' => 'Orden de compra fuera de rango']);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/gerencia/reglas-aprobacion/{$id}", [
                'tipo'          => 'Orden de compra fuera de rango (editada)',
                'activo'        => false,
                'aprobador_ids' => [$aprobador],
            ])
            ->assertStatus(200)
            ->assertJsonPath('tipo', 'Orden de compra fuera de rango (editada)')
            ->assertJsonPath('activo', false);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/gerencia/reglas-aprobacion/{$id}")
            ->assertStatus(204);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/gerencia/reglas-aprobacion')
            ->assertStatus(200)
            ->assertJsonMissing(['tipo' => 'Orden de compra fuera de rango (editada)']);
    }

    public function test_store_sin_aprobadores_falla_validacion(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/gerencia/reglas-aprobacion', [
                'tipo'          => 'Descuento especial',
                'activo'        => true,
                'aprobador_ids' => [],
            ])
            ->assertStatus(422);
    }

    public function test_actor_no_superadmin_no_puede_ver_ni_configurar_reglas(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/gerencia/reglas-aprobacion')
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/gerencia/reglas-aprobacion', [
                'tipo' => 'X', 'activo' => true, 'aprobador_ids' => [1],
            ])
            ->assertStatus(403);
    }

    private function userIdByEmail(string $email): int
    {
        $slug = (string) Tenant::where('slug', 'atlantic')->value('slug');

        return (int) \Illuminate\Support\Facades\DB::table("{$slug}_auth.users")
            ->where('email', $email)
            ->value('id');
    }
}
