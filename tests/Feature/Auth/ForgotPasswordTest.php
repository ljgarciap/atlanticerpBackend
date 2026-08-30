<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\PasswordResetToken;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

class ForgotPasswordTest extends FeatureTestCase
{
    // ── forgot-password ───────────────────────────────────────────────────────

    public function test_forgot_password_con_email_valido_retorna_200(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'designer@illuminations.test',
        ])
        ->assertStatus(200)
        ->assertJsonStructure(['message']);
    }

    public function test_forgot_password_no_filtra_email_inexistente(): void
    {
        // Anti-enumeration: same response regardless of whether email exists
        Mail::fake();

        $r1 = $this->postJson('/api/auth/forgot-password', ['email' => 'noexiste@illuminations.test']);
        $r2 = $this->postJson('/api/auth/forgot-password', ['email' => 'designer@illuminations.test']);

        $r1->assertStatus(200);
        $r2->assertStatus(200);
        $this->assertEquals($r1->json('message'), $r2->json('message'));
    }

    public function test_forgot_password_sin_email_retorna_422(): void
    {
        $this->postJson('/api/auth/forgot-password', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    public function test_forgot_password_email_invalido_retorna_422(): void
    {
        $this->postJson('/api/auth/forgot-password', ['email' => 'no-es-un-email'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['email']]);
    }

    // ── reset-password ────────────────────────────────────────────────────────

    public function test_reset_password_con_token_valido_retorna_200(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'designer@illuminations.test'])
            ->assertStatus(200);

        $token = $this->stealResetToken('designer@illuminations.test');

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'NuevoPass99!',
            'password_confirmation' => 'NuevoPass99!',
        ])->assertStatus(200);
    }

    public function test_reset_password_actualiza_credenciales(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'designer@illuminations.test'])
            ->assertStatus(200);

        $token = $this->stealResetToken('designer@illuminations.test');

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'NuevoPass99!',
            'password_confirmation' => 'NuevoPass99!',
        ])->assertStatus(200);

        $this->postJson('/api/auth/login', [
            'email'    => 'designer@illuminations.test',
            'password' => 'NuevoPass99!',
        ])->assertStatus(200);

        $this->postJson('/api/auth/login', [
            'email'    => 'designer@illuminations.test',
            'password' => 'Password123!',
        ])->assertStatus(401);
    }

    public function test_reset_password_token_usado_retorna_422(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'designer@illuminations.test'])
            ->assertStatus(200);

        $token = $this->stealResetToken('designer@illuminations.test');

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'NuevoPass99!',
            'password_confirmation' => 'NuevoPass99!',
        ])->assertStatus(200);

        // Second use must fail
        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'OtroPass88!',
            'password_confirmation' => 'OtroPass88!',
        ])->assertStatus(422);
    }

    public function test_reset_password_token_invalido_retorna_422(): void
    {
        $this->postJson('/api/auth/reset-password', [
            'token'                 => 'token-que-no-existe-en-ninguna-parte',
            'password'              => 'NuevoPass99!',
            'password_confirmation' => 'NuevoPass99!',
        ])->assertStatus(422);
    }

    public function test_reset_password_token_expirado_retorna_422(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'designer@illuminations.test'])
            ->assertStatus(200);

        $token = $this->stealResetToken('designer@illuminations.test');

        $this->expireResetTokens('designer@illuminations.test');

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'NuevoPass99!',
            'password_confirmation' => 'NuevoPass99!',
        ])->assertStatus(422);
    }

    public function test_reset_password_sin_campos_retorna_422(): void
    {
        $this->postJson('/api/auth/reset-password', [])
            ->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_reset_password_confirmacion_no_coincide_retorna_422(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'designer@illuminations.test'])
            ->assertStatus(200);

        $token = $this->stealResetToken('designer@illuminations.test');

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'NuevoPass99!',
            'password_confirmation' => 'OtroPass88!',
        ])->assertStatus(422);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Replaces the token hash in the latest reset token row with a known
     * plaintext, then returns that plaintext so the test can use it.
     * Necessary because we cannot reverse the SHA-256 hash stored by the service.
     */
    private function stealResetToken(string $email): string
    {
        $plain = 'test-reset-token-known';

        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'illuminations')->firstOrFail();
        $tenant->makeCurrent();

        try {
            PasswordResetToken::where('email', $email)
                ->whereNull('used_at')
                ->update(['token_hash' => hash('sha256', $plain)]);
        } finally {
            Tenant::forgetCurrent();
        }

        return $plain;
    }

    private function expireResetTokens(string $email): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'illuminations')->firstOrFail();
        $tenant->makeCurrent();

        try {
            PasswordResetToken::where('email', $email)
                ->whereNull('used_at')
                ->update(['expires_at' => now()->subHour()]);
        } finally {
            Tenant::forgetCurrent();
        }
    }
}
