<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Epic 11 — Seguridad Agéntica. security_blocked_at es un gate independiente de is_active
 * (ver ADR-004): un usuario activo pero bloqueado por seguridad no debe poder loguearse.
 */
class SecurityBlockedLoginTest extends FeatureTestCase
{
    /** Setea security_blocked_at directo en BD — requiere tenant activo, como el resto del modelo. */
    private function blockUser(string $email): void
    {
        $tenant = Tenant::where('slug', 'illuminations')->firstOrFail();
        $tenant->makeCurrent();

        try {
            $user = User::where('email', $email)->firstOrFail();
            $user->security_blocked_at = now();
            $user->save();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    public function test_usuario_bloqueado_por_seguridad_no_puede_hacer_login(): void
    {
        $this->blockUser('designer@illuminations.test');

        $this->postJson('/api/auth/login', [
            'email'    => 'designer@illuminations.test',
            'password' => 'Password123!',
        ])->assertStatus(401);
    }

    public function test_usuario_activo_y_no_bloqueado_puede_hacer_login(): void
    {
        $this->postJson('/api/auth/login', [
            'email'    => 'designer@illuminations.test',
            'password' => 'Password123!',
        ])->assertStatus(200);
    }

    public function test_refresh_token_falla_si_usuario_fue_bloqueado_despues_de_loguearse(): void
    {
        $login = $this->postJson('/api/auth/login', [
            'email'    => 'designer@illuminations.test',
            'password' => 'Password123!',
        ]);
        $refreshToken = (string) $login->json('refresh_token');

        $this->blockUser('designer@illuminations.test');

        $this->postJson('/api/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertStatus(401);
    }
}
