<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\SecurityLevel;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1158 (Senior Review R1) — el caso típico de soporte es «no puedo entrar»: la persona observada tiene un cambio de contraseña pendiente o
 * una temporal caducada. Con el claim `act` esas dos comprobaciones de `AuthenticateJwt` sobre el OBJETIVO se omiten (la sesión es de solo
 * lectura y el cambio de contraseña ya está bloqueado); SIN `act` el comportamiento es idéntico al de siempre.
 */
final class ImpersonationPendingPasswordTest extends FeatureTestCase
{
    private const PASSWORD = 'Password123!';
    private const REASON   = 'SCRUM-1158 no puede entrar';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reMakeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function makeTarget(): User
    {
        $this->reMakeCurrent();

        return User::create([
            'first_name' => 'Persona', 'last_name' => 'Pendiente', 'email' => uniqid('pend-') . '@atlantic.test',
            'password_hash' => self::PASSWORD, 'security_level_id' => SecurityLevel::where('level', 3)->firstOrFail()->id, 'is_active' => true,
        ]);
    }

    /** Estado que deja un reset administrativo: cambio obligatorio y la fecha del reset (`$hoursAgo` horas atrás). */
    private function conCambioPendiente(User $user, int $hoursAgo): void
    {
        $this->reMakeCurrent();
        $user->forceFill(['must_change_password' => true, 'password_reset_at' => now()->subHours($hoursAgo)])->save();
    }

    /** @return array<string, string> */
    private function impersonar(User $target): array
    {
        $admin = $this->authHeaders($this->loginAs('superadmin@atlantic.test', self::PASSWORD, 'superadmin'));
        $token = $this->postJson("/api/users/{$target->id}/impersonate", ['reason' => self::REASON, 'operator_password' => self::PASSWORD], $admin)
            ->assertStatus(201)->json('token');

        return $this->authHeaders((string) $token);
    }

    /** Token propio del objetivo, emitido ANTES de dejarle el cambio pendiente (con él pendiente ya no puede ni entrar). */
    private function tokenPropio(User $target): array
    {
        return $this->authHeaders($this->loginAs($target->email, self::PASSWORD, 'designer'));
    }

    // ── R1: con `act` se omiten ───────────────────────────────────────────────────────────────────────────

    public function test_con_cambio_de_contrasena_pendiente_la_sesion_lee_un_get_normal(): void
    {
        $target = $this->makeTarget();
        $this->conCambioPendiente($target, hoursAgo: 1);
        $headers = $this->impersonar($target);

        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(200);
        $this->getJson('/api/auth/me', $headers)->assertStatus(200)->assertJsonPath('must_change_password', true);
    }

    public function test_con_la_temporal_caducada_la_sesion_lee_un_get_normal(): void
    {
        $target = $this->makeTarget();
        $this->conCambioPendiente($target, hoursAgo: 100); // el plazo por defecto es de 48 h
        $headers = $this->impersonar($target);

        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(200);
        $this->getJson('/api/auth/me', $headers)->assertStatus(200);
    }

    public function test_la_sesion_sigue_siendo_de_solo_lectura_aunque_se_omitan_esas_comprobaciones(): void
    {
        $target = $this->makeTarget();
        $this->conCambioPendiente($target, hoursAgo: 1);
        $headers = $this->impersonar($target);

        // El cambio de contraseña, la única escritura que ese estado permite a la persona, está bloqueado por el modo solo lectura.
        $this->putJson('/api/auth/password', [
            'current_password' => self::PASSWORD, 'new_password' => 'NuevaClave99!', 'new_password_confirmation' => 'NuevaClave99!',
        ], $headers)->assertStatus(403)->assertJsonPath('code', 'impersonation_read_only');
        $this->patchJson('/api/auth/profile', ['first_name' => 'Otro'], $headers)->assertStatus(403)->assertJsonPath('code', 'impersonation_read_only');

        $this->reMakeCurrent();
        $this->assertTrue((bool) User::findOrFail($target->id)->must_change_password, 'nada cambió');
    }

    // ── Sin `act` queda idéntico a hoy ────────────────────────────────────────────────────────────────────

    public function test_sin_act_el_cambio_pendiente_sigue_dando_403(): void
    {
        $target  = $this->makeTarget();
        $headers = $this->tokenPropio($target);
        $this->conCambioPendiente($target, hoursAgo: 1);

        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(403)->assertJsonPath('code', 'password_change_required');
        $this->getJson('/api/auth/me', $headers)->assertStatus(200); // las dos rutas permitidas siguen pasando
    }

    public function test_sin_act_la_temporal_caducada_sigue_dando_401(): void
    {
        $target  = $this->makeTarget();
        $headers = $this->tokenPropio($target);
        $this->conCambioPendiente($target, hoursAgo: 200);

        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(401)->assertJsonPath('code', 'password_reset_expired');
        $this->getJson('/api/auth/me', $headers)->assertStatus(401)->assertJsonPath('code', 'password_reset_expired');
    }
}
