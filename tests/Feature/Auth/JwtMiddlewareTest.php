<?php

namespace Tests\Feature\Auth;

use App\Models\User;
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
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonStructure(['id', 'email', 'role', 'tenant'])
            ->assertJsonPath('email', 'management@atlantic.test')
            ->assertJsonPath('role', 'management')
            ->assertJsonPath('tenant', 'atlantic');
    }

    public function test_middleware_inicializa_tenant_en_ruta_protegida(): void
    {
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me')
            ->assertStatus(200);

        // Tras el request el tenant debe haberse reseteado (forgetCurrent en finally)
        $this->assertNull(Tenant::current());
    }

    /**
     * SCRUM-823 — revocación inmediata: un token emitido ANTES de desactivar al usuario deja de
     * funcionar en la siguiente request, sin esperar a que expire ni a un intento de refresh.
     * Antes de este fix, AuthenticateJwt solo validaba la firma del JWT — nunca re-consultaba
     * is_active contra la BD, así que el mismo token seguía pasando.
     */
    public function test_token_deja_de_funcionar_apenas_se_desactiva_al_usuario(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $user = User::where('email', 'management@atlantic.test')->firstOrFail();
        $token = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        // El token todavía es válido acá — confirma que no es un problema de token vencido.
        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me')
            ->assertStatus(200);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $user->update(['is_active' => false]);

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me')
            ->assertStatus(401);

        // Limpieza — este usuario es un fixture compartido por muchos otros tests.
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $user->update(['is_active' => true]);
    }

    public function test_token_de_usuario_eliminado_retorna_401(): void
    {
        $token = $this->loginAs('nivel2@atlantic.test', 'Password123!', 'electrician');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        User::where('email', 'nivel2@atlantic.test')->delete();

        $this->withHeaders(['Authorization' => 'Bearer '.$token])
            ->getJson('/api/auth/me')
            ->assertStatus(401);
    }
}
