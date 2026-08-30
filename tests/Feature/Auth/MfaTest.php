<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\MfaCode;
use App\Modules\Auth\Models\SystemSetting;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

class MfaTest extends FeatureTestCase
{
    // ── Login → MFA trigger ───────────────────────────────────────────────────

    public function test_login_con_mfa_required_retorna_requires_mfa(): void
    {
        Mail::fake();
        $this->setMfaMode('required');

        $this->postJson('/api/auth/login', [
            'email'    => 'designer@atlantic.test',
            'password' => 'Password123!',
        ])
        ->assertStatus(200)
        ->assertJsonPath('requires_mfa', true)
        ->assertJsonStructure(['mfa_challenge_id']);
    }

    public function test_login_con_mfa_required_y_envio_de_correo_fallido_retorna_503_sin_filtrar_trace(): void
    {
        $this->setMfaMode('required');

        Mail::shouldReceive('to')->andReturnSelf();
        Mail::shouldReceive('send')->andThrow(new \RuntimeException('SMTP rejected: RFC 2606 reserved domain'));

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'designer@atlantic.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(503)
            ->assertJsonStructure(['message'])
            ->assertJsonMissingPath('exception')
            ->assertJsonMissingPath('trace')
            ->assertJsonMissingPath('file');
    }

    public function test_login_con_mfa_disabled_no_genera_challenge(): void
    {
        $this->setMfaMode('disabled');

        $this->postJson('/api/auth/login', [
            'email'    => 'designer@atlantic.test',
            'password' => 'Password123!',
        ])
        ->assertStatus(200)
        ->assertJsonMissing(['requires_mfa'])
        ->assertJsonStructure(['token']);
    }

    /**
     * SCRUM-2 — QA 2026-07-05: 'disabled' apagaba el MFA de TODOS los usuarios sin excepción,
     * incluso a quien ya lo había activado personalmente (downgrade de seguridad silencioso).
     * 'disabled' solo debe impedir que la organización lo fuerce y ocultar el auto-servicio de
     * activación — el MFA personal ya activado debe seguir pidiéndose.
     */
    public function test_login_con_mfa_disabled_pero_usuario_con_mfa_personal_sigue_pidiendo_challenge(): void
    {
        Mail::fake();
        $this->setMfaMode('disabled');
        $this->enableMfaForUser('designer@atlantic.test');

        $this->postJson('/api/auth/login', [
            'email'    => 'designer@atlantic.test',
            'password' => 'Password123!',
        ])
        ->assertStatus(200)
        ->assertJsonPath('requires_mfa', true)
        ->assertJsonStructure(['mfa_challenge_id']);
    }

    // ── mfa/verify ────────────────────────────────────────────────────────────

    public function test_mfa_verify_con_codigo_valido_retorna_jwt(): void
    {
        Mail::fake();
        $this->setMfaMode('required');

        [$challengeId, $code] = $this->createChallenge('designer@atlantic.test');

        $this->postJson('/api/auth/mfa/verify', [
            'mfa_challenge_id' => $challengeId,
            'code'             => $code,
        ])
        ->assertStatus(200)
        ->assertJsonStructure(['token', 'token_type', 'expires_in_ms', 'user']);
    }

    public function test_mfa_verify_codigo_incorrecto_retorna_422(): void
    {
        Mail::fake();
        $this->setMfaMode('required');

        [$challengeId] = $this->createChallenge('designer@atlantic.test');

        $this->postJson('/api/auth/mfa/verify', [
            'mfa_challenge_id' => $challengeId,
            'code'             => '000000',
        ])->assertStatus(422);
    }

    public function test_mfa_verify_challenge_expirado_retorna_422(): void
    {
        Mail::fake();
        $this->setMfaMode('required');

        [$challengeId, $code] = $this->createChallenge('designer@atlantic.test');

        $this->expireChallenge($challengeId);

        $this->postJson('/api/auth/mfa/verify', [
            'mfa_challenge_id' => $challengeId,
            'code'             => $code,
        ])->assertStatus(422);
    }

    public function test_mfa_verify_challenge_usado_retorna_422(): void
    {
        Mail::fake();
        $this->setMfaMode('required');

        [$challengeId, $code] = $this->createChallenge('designer@atlantic.test');

        // Use it once
        $this->postJson('/api/auth/mfa/verify', [
            'mfa_challenge_id' => $challengeId,
            'code'             => $code,
        ])->assertStatus(200);

        // Second use must fail
        $this->postJson('/api/auth/mfa/verify', [
            'mfa_challenge_id' => $challengeId,
            'code'             => $code,
        ])->assertStatus(422);
    }

    public function test_mfa_verify_challenge_inexistente_retorna_422(): void
    {
        $this->postJson('/api/auth/mfa/verify', [
            'mfa_challenge_id' => '00000000-0000-0000-0000-000000000000',
            'code'             => '123456',
        ])->assertStatus(422);
    }

    public function test_mfa_verify_sin_campos_retorna_422(): void
    {
        $this->postJson('/api/auth/mfa/verify', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    // ── mfa/resend ────────────────────────────────────────────────────────────

    public function test_mfa_resend_genera_nuevo_challenge(): void
    {
        Mail::fake();
        $this->setMfaMode('required');

        [$originalId] = $this->createChallenge('designer@atlantic.test');

        $response = $this->postJson('/api/auth/mfa/resend', [
            'mfa_challenge_id' => $originalId,
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['mfa_challenge_id']);

        $this->assertNotEquals($originalId, $response->json('mfa_challenge_id'));
    }

    public function test_mfa_resend_challenge_invalido_retorna_422(): void
    {
        $this->postJson('/api/auth/mfa/resend', [
            'mfa_challenge_id' => '00000000-0000-0000-0000-000000000000',
        ])->assertStatus(422);
    }

    // ── mfa/status ────────────────────────────────────────────────────────────

    public function test_mfa_status_retorna_estado_del_usuario(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/auth/mfa/status')
            ->assertStatus(200)
            ->assertJsonStructure(['mfa_enabled', 'mfa_mode'])
            ->assertJsonPath('mfa_enabled', false);
    }

    public function test_mfa_status_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/auth/mfa/status')->assertStatus(401);
    }

    // ── mfa/enable + mfa/enable/confirm + mfa/disable ─────────────────────────
    //
    // SCRUM-2 — QA 2026-07-05: activar MFA no pedía ningún paso de verificación — el usuario
    // quedaba con mfa_enabled=true sin haber demostrado que puede recibir códigos por correo.
    // Ahora /mfa/enable solo genera un challenge (igual que el login) y /mfa/enable/confirm
    // recién activa tras validar el código.

    public function test_mfa_enable_con_password_correcto_genera_challenge_sin_activar(): void
    {
        Mail::fake();
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/auth/mfa/enable', ['password' => 'Password123!'])
            ->assertStatus(200)
            ->assertJsonStructure(['mfa_challenge_id']);

        // No se activa hasta confirmar el código
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/auth/mfa/status')
            ->assertJsonPath('mfa_enabled', false);
    }

    public function test_mfa_enable_con_password_incorrecto_retorna_422(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/auth/mfa/enable', ['password' => 'PasswordEquivocado99!'])
            ->assertStatus(422);
    }

    public function test_mfa_enable_confirm_con_codigo_valido_activa_mfa(): void
    {
        $token = $this->loginAs();
        [$challengeId, $code] = $this->createEnableChallenge($token);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/auth/mfa/enable/confirm', ['mfa_challenge_id' => $challengeId, 'code' => $code])
            ->assertStatus(200)
            ->assertJsonPath('mfa_enabled', true);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/auth/mfa/status')
            ->assertJsonPath('mfa_enabled', true);
    }

    public function test_mfa_enable_confirm_con_codigo_incorrecto_retorna_422_y_no_activa(): void
    {
        $token = $this->loginAs();
        [$challengeId] = $this->createEnableChallenge($token);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/auth/mfa/enable/confirm', ['mfa_challenge_id' => $challengeId, 'code' => '000000'])
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/auth/mfa/status')
            ->assertJsonPath('mfa_enabled', false);
    }

    public function test_mfa_enable_confirm_con_challenge_de_otro_usuario_retorna_403(): void
    {
        // designer genera su propio challenge de enrolamiento...
        $designerToken = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        [$challengeId, $code] = $this->createEnableChallenge($designerToken);

        // ...pero otro usuario autenticado intenta confirmarlo con el código correcto
        $otherToken = $this->loginAs('management@atlantic.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($otherToken))
            ->postJson('/api/auth/mfa/enable/confirm', ['mfa_challenge_id' => $challengeId, 'code' => $code])
            ->assertStatus(403);
    }

    public function test_mfa_disable_desactiva_mfa(): void
    {
        $token = $this->loginAs();
        [$challengeId, $code] = $this->createEnableChallenge($token);
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/auth/mfa/enable/confirm', ['mfa_challenge_id' => $challengeId, 'code' => $code])
            ->assertStatus(200);

        // Then disable
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/auth/mfa/disable')
            ->assertStatus(200)
            ->assertJsonPath('mfa_enabled', false);
    }

    public function test_mfa_enable_sin_autenticar_retorna_401(): void
    {
        $this->postJson('/api/auth/mfa/enable', ['password' => 'Password123!'])
            ->assertStatus(401);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Triggers login (which creates an MFA challenge), replaces the code hash
     * with a known plaintext, and returns [challengeId, code].
     *
     * @return array{0: string, 1: string}
     */
    private function createChallenge(string $email): array
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => $email,
            'password' => 'Password123!',
        ]);

        $response->assertJsonPath('requires_mfa', true);
        $challengeId = (string) $response->json('mfa_challenge_id');

        $code = '654321';

        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            MfaCode::where('id', $challengeId)
                ->update(['code_hash' => hash('sha256', $code)]);
        } finally {
            Tenant::forgetCurrent();
        }

        return [$challengeId, $code];
    }

    /**
     * Calls POST /mfa/enable (already-authenticated flow) and rewrites the resulting
     * challenge's code hash to a known plaintext, mirroring createChallenge() above but for
     * the enrollment endpoint instead of login.
     *
     * @return array{0: string, 1: string}
     */
    private function createEnableChallenge(string $token): array
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/auth/mfa/enable', ['password' => 'Password123!']);

        $response->assertStatus(200);
        $challengeId = (string) $response->json('mfa_challenge_id');

        $code = '654321';

        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            MfaCode::where('id', $challengeId)
                ->update(['code_hash' => hash('sha256', $code)]);
        } finally {
            Tenant::forgetCurrent();
        }

        return [$challengeId, $code];
    }

    private function enableMfaForUser(string $email): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            User::where('email', $email)->update(['mfa_enabled' => true]);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function expireChallenge(string $challengeId): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            MfaCode::where('id', $challengeId)->update(['expires_at' => now()->subMinutes(15)]);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function setMfaMode(string $mode): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            SystemSetting::updateOrCreate(
                ['key' => 'mfa_mode'],
                ['value' => $mode, 'updated_by' => null, 'updated_at' => now()],
            );
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
