<?php

namespace Tests\Feature\Auth;

use Tests\Feature\FeatureTestCase;

/**
 * Tests de refresh token y logout.
 */
class RefreshTokenTest extends FeatureTestCase
{
    private function login(): array
    {
        // designer has a single role → login returns token + refresh_token directly
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'designer@illuminations.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200);

        return $response->json();
    }

    public function test_login_devuelve_refresh_token(): void
    {
        $data = $this->login();

        $this->assertArrayHasKey('refresh_token', $data);
        $this->assertNotEmpty($data['refresh_token']);
    }

    public function test_refresh_con_token_valido_retorna_nuevo_jwt_y_nuevo_refresh_token(): void
    {
        $loginData = $this->login();

        $response = $this->postJson('/api/auth/refresh', [
            'refresh_token' => $loginData['refresh_token'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'token_type', 'expires_in_ms', 'refresh_token']);
        $this->assertNotEmpty($response->json('token'));
        // El refresh token rota: el nuevo debe ser distinto al original
        $this->assertNotEquals($loginData['refresh_token'], $response->json('refresh_token'));
    }

    public function test_refresh_token_usado_queda_revocado(): void
    {
        $loginData = $this->login();
        $originalRefreshToken = $loginData['refresh_token'];

        // Primer refresh — exitoso
        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $originalRefreshToken,
        ])->assertStatus(200);

        // Segundo intento con el mismo token — debe fallar porque fue revocado al rotar
        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $originalRefreshToken,
        ])->assertStatus(401);
    }

    public function test_refresh_con_token_invalido_retorna_401(): void
    {
        $this->postJson('/api/auth/refresh', [
            'refresh_token' => 'token_que_no_existe_en_la_bd',
        ])->assertStatus(401);
    }

    public function test_logout_revoca_el_refresh_token(): void
    {
        $loginData = $this->login();
        $refreshToken = $loginData['refresh_token'];

        $this->postJson('/api/auth/logout', [
            'refresh_token' => $refreshToken,
        ])->assertStatus(204);

        // Después del logout el refresh token no debe funcionar
        $this->postJson('/api/auth/refresh', [
            'refresh_token' => $refreshToken,
        ])->assertStatus(401);
    }

    public function test_logout_sin_refresh_token_retorna_422(): void
    {
        $this->postJson('/api/auth/logout', [])->assertStatus(422);
    }

    public function test_refresh_sin_token_retorna_422(): void
    {
        $this->postJson('/api/auth/refresh', [])->assertStatus(422);
    }
}
