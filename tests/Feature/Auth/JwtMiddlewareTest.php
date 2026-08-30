<?php

namespace Tests\Feature\Auth;

use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Tests del middleware AuthenticateJwt aplicado globalmente a /api/*.
 * Usa GET /api/auth/me como endpoint protegido real (no requiere un endpoint temporal).
 */
class JwtMiddlewareTest extends FeatureTestCase
{
    public function test_ruta_protegida_sin_jwt_retorna_401(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_ruta_protegida_con_token_invalido_retorna_401(): void
    {
        $this->withHeaders(['Authorization' => 'Bearer token.invalido.aqui'])
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }

    public function test_ruta_protegida_con_jwt_valido_retorna_200(): void
    {
        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonStructure(['id', 'email', 'role', 'tenant'])
            ->assertJsonPath('email', 'management@illuminations.test')
            ->assertJsonPath('role', 'management')
            ->assertJsonPath('tenant', 'illuminations');
    }

    public function test_middleware_inicializa_tenant_en_ruta_protegida(): void
    {
        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me')
            ->assertStatus(200);

        // Tras el request el tenant debe haberse reseteado (forgetCurrent en finally)
        $this->assertNull(Tenant::current());
    }
}
