<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\SecurityAnomaly;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Epic 11 — Seguridad Agéntica. El job nunca bloquea por sí mismo (ver ADR-004) — estos
 * endpoints son la única vía real de bloqueo/reactivación, y siempre requieren un superadmin.
 *
 * Nota: cada operación directa sobre modelos del schema de tenant (User, SecurityAnomaly)
 * necesita el tenant activo — a diferencia de las llamadas HTTP, que lo resuelven y lo
 * liberan solas por request (ver AuthController::login, que hace Tenant::forgetCurrent()
 * en su finally). withTenant() centraliza ese activar/liberar para los fixtures del test.
 */
class SecurityAnomalyControllerTest extends FeatureTestCase
{
    private function withTenant(\Closure $fn): mixed
    {
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            return $fn();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function findUser(string $email): User
    {
        return $this->withTenant(fn () => User::where('email', $email)->firstOrFail());
    }

    private function refreshUser(User $user): void
    {
        $this->withTenant(fn () => $user->refresh());
    }

    private function blockUser(User $user): void
    {
        $this->withTenant(function () use ($user) {
            $user->security_blocked_at = now()->subHour();
            $user->save();
        });
    }

    private function makeAnomaly(?int $affectedUserId, string $status = SecurityAnomaly::STATUS_PENDING): SecurityAnomaly
    {
        return $this->withTenant(fn () => SecurityAnomaly::create([
            'severity'         => SecurityAnomaly::SEVERITY_MEDIUM,
            'reasoning'        => 'Actividad de prueba fuera de patrón esperado.',
            'raw_response'     => ['source' => 'claude'],
            'affected_user_id' => $affectedUserId,
            'range_start'      => now()->subDay(),
            'range_end'        => now(),
            'status'           => $status,
        ]));
    }

    public function test_usuario_sin_permiso_no_puede_listar_anomalias(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/security/anomalies')
            ->assertStatus(403);
    }

    public function test_superadmin_puede_listar_anomalias(): void
    {
        $affected = $this->findUser('designer@atlantic.test');
        $this->makeAnomaly($affected->id);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/security/anomalies?status=pending');

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.affected_user.id', $affected->id);
    }

    public function test_confirm_bloquea_al_usuario_afectado_y_marca_la_anomalia_como_confirmada(): void
    {
        $affected = $this->findUser('designer@atlantic.test');
        $this->assertNull($affected->security_blocked_at);

        $anomaly = $this->makeAnomaly($affected->id);
        $token   = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin/security/anomalies/{$anomaly->id}/confirm")
            ->assertStatus(200)
            ->assertJsonPath('data.status', SecurityAnomaly::STATUS_CONFIRMED);

        $this->refreshUser($affected);
        $this->assertNotNull($affected->security_blocked_at);
        $this->assertTrue($affected->isSecurityBlocked());

        // El usuario bloqueado no puede loguearse
        $this->postJson('/api/auth/login', [
            'email'    => 'designer@atlantic.test',
            'password' => 'Password123!',
        ])->assertStatus(401);
    }

    public function test_confirm_es_idempotente_si_el_usuario_ya_estaba_bloqueado(): void
    {
        $affected = $this->findUser('designer@atlantic.test');
        $this->blockUser($affected);
        $this->refreshUser($affected);
        $originalBlockedAt = $affected->security_blocked_at;

        $anomaly = $this->makeAnomaly($affected->id);
        $token   = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin/security/anomalies/{$anomaly->id}/confirm")
            ->assertStatus(200);

        $this->refreshUser($affected);
        // No se pisa el timestamp de un bloqueo ya existente.
        $this->assertTrue($affected->security_blocked_at->equalTo($originalBlockedAt));
    }

    public function test_dismiss_no_bloquea_al_usuario_afectado(): void
    {
        $affected = $this->findUser('designer@atlantic.test');
        $anomaly  = $this->makeAnomaly($affected->id);
        $token    = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin/security/anomalies/{$anomaly->id}/dismiss")
            ->assertStatus(200)
            ->assertJsonPath('data.status', SecurityAnomaly::STATUS_DISMISSED);

        $this->refreshUser($affected);
        $this->assertNull($affected->security_blocked_at);
    }

    public function test_unblock_reactiva_a_un_usuario_bloqueado(): void
    {
        $affected = $this->findUser('designer@atlantic.test');
        $this->blockUser($affected);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin/security/users/{$affected->id}/unblock")
            ->assertStatus(200);

        $this->refreshUser($affected);
        $this->assertNull($affected->security_blocked_at);

        $this->postJson('/api/auth/login', [
            'email'    => 'designer@atlantic.test',
            'password' => 'Password123!',
        ])->assertStatus(200);
    }

    public function test_unblock_retorna_422_si_el_usuario_no_esta_bloqueado(): void
    {
        $affected = $this->findUser('designer@atlantic.test');
        $this->assertNull($affected->security_blocked_at);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin/security/users/{$affected->id}/unblock")
            ->assertStatus(422);
    }

    /**
     * SCRUM-2 — QA 2026-07-05: no existía forma de rescatar a un usuario con MFA activo que
     * perdió acceso a su correo/dispositivo sin apagar la política de MFA de toda la
     * organización. Este endpoint es la única vía de soporte soportada.
     */
    private function enableMfa(User $user): void
    {
        $this->withTenant(function () use ($user) {
            $user->mfa_enabled = true;
            $user->save();
        });
    }

    public function test_reset_mfa_desactiva_el_mfa_de_un_usuario_bloqueado(): void
    {
        $affected = $this->findUser('designer@atlantic.test');
        $this->enableMfa($affected);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin/security/users/{$affected->id}/mfa/reset")
            ->assertStatus(200);

        $this->refreshUser($affected);
        $this->assertFalse($affected->mfa_enabled);
    }

    public function test_reset_mfa_retorna_422_si_el_usuario_no_tiene_mfa_activo(): void
    {
        $affected = $this->findUser('designer@atlantic.test');
        $this->assertFalse($affected->mfa_enabled);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin/security/users/{$affected->id}/mfa/reset")
            ->assertStatus(422);
    }

    public function test_reset_mfa_retorna_404_si_el_usuario_no_existe(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/security/users/999999/mfa/reset')
            ->assertStatus(404);
    }

    public function test_usuario_sin_permiso_no_puede_resetear_mfa_de_otro(): void
    {
        $affected = $this->findUser('electrician@atlantic.test');
        $this->enableMfa($affected);

        // designer no tiene superadmin.all (a diferencia de management@atlantic.test,
        // que en el seed sí lo lleva junto con el rol management — ver AuthUserSeeder).
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/admin/security/users/{$affected->id}/mfa/reset")
            ->assertStatus(403);
    }
}
