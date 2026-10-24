<?php

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\PasswordResetToken;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

class ForgotPasswordTest extends FeatureTestCase
{
    // ── SCRUM-1178 ────────────────────────────────────────────────────────────

    public function test_el_enlace_lleva_el_token_en_el_fragmento_y_el_correo_va_a_la_cola(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'designer@atlantic.test'])->assertStatus(200);

        Mail::assertQueued(\App\Modules\Auth\Mail\PasswordResetMail::class, function (\App\Modules\Auth\Mail\PasswordResetMail $m): bool {
            $this->assertNull(parse_url($m->resetUrl, PHP_URL_QUERY), 'el token no puede viajar en la query: queda en el log de nginx');
            $this->assertStringStartsWith('token=', (string) parse_url($m->resetUrl, PHP_URL_FRAGMENT));

            return true;
        });
    }

    public function test_el_payload_de_la_cola_del_correo_de_restablecer_va_cifrado_y_no_deja_el_token_legible(): void
    {
        $mail = new \App\Modules\Auth\Mail\PasswordResetMail('Ana', 'https://x.test/reset-password#token=SECRETO123', 'es');
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeEncrypted::class, $mail);

        $job = new \Illuminate\Mail\SendQueuedMailable($mail);
        $this->assertTrue($job->shouldBeEncrypted, 'el job que viaja a Redis debe cifrarse');

        $json = (fn () => $this->createPayload($job, 'default'))->call(app('queue')->connection('redis'));
        $this->assertStringNotContainsString('SECRETO123', $json);
    }

    public function test_el_correo_del_enlace_sale_en_el_idioma_del_perfil(): void
    {
        $tenant = \App\Shared\Multitenancy\Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();
        $user = \App\Models\User::query()->where('email', 'designer@atlantic.test')->firstOrFail();
        $render = function (?string $lang) use ($user, $tenant): array {
            $tenant->makeCurrent();
            Mail::fake();
            $user->forceFill(['preferences' => $lang === null ? [] : ['language' => $lang]])->save();
            $this->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertStatus(200);
            $sent = Mail::queued(\App\Modules\Auth\Mail\PasswordResetMail::class)->first();
            $this->assertNotNull($sent);

            return [$sent->envelope()->subject, $sent->render()];
        };

        [$subjectEn, $htmlEn] = $render('en');
        $this->assertSame('Reset your password — AtlanticERP CRM', $subjectEn);
        $this->assertStringContainsString('Reset password', $htmlEn);
        $this->assertStringNotContainsString('Restablecer', $htmlEn);

        [$subjectEs, $htmlEs] = $render(null);
        $this->assertSame('Restablece tu contraseña — AtlanticERP CRM', $subjectEs);
        $this->assertStringContainsString('Restablecer contraseña', $htmlEs);
    }

    public function test_si_no_se_puede_encolar_el_correo_la_respuesta_sigue_siendo_200_y_no_filtra_nada(): void
    {
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('redis caído'));

        $r = $this->postJson('/api/auth/forgot-password', ['email' => 'designer@atlantic.test'])->assertStatus(200);

        $this->assertSame('Si el correo está registrado, recibirás un enlace en los próximos minutos.', $r->json('message'));
    }

    public function test_el_correo_por_ses_usa_el_rol_de_la_instancia_y_no_claves_del_entorno(): void
    {
        $this->assertSame('ses-role', config('mail.mailers.ses.transport'));
        $this->assertInstanceOf(\Illuminate\Mail\Transport\SesV2Transport::class, \App\Shared\Mail\SesRoleTransport::make());
        $this->assertSame(['ses', 'smtp'], config('mail.mailers.ses_respaldo.mailers'));
        // Sin SES_ACCESS_KEY_ID/SES_SECRET_ACCESS_KEY el SDK usa la cadena por defecto (rol de la instancia); nunca las claves del bucket.
        $this->assertNull(config('services.ses.key'));
        $this->assertNull(config('services.ses.secret'));
    }

    // ── forgot-password ───────────────────────────────────────────────────────

    public function test_forgot_password_con_email_valido_retorna_200(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', [
            'email' => 'designer@atlantic.test',
        ])
        ->assertStatus(200)
        ->assertJsonStructure(['message']);
    }

    public function test_forgot_password_no_filtra_email_inexistente(): void
    {
        // Anti-enumeration: same response regardless of whether email exists
        Mail::fake();

        $r1 = $this->postJson('/api/auth/forgot-password', ['email' => 'noexiste@atlantic.test']);
        $r2 = $this->postJson('/api/auth/forgot-password', ['email' => 'designer@atlantic.test']);

        $r1->assertStatus(200);
        $r2->assertStatus(200);
        $this->assertEquals($r1->json('message'), $r2->json('message'));
    }

    // SCRUM-1142 (Pre-QA) — «olvidé mi contraseña» no puede dar acceso a las cuentas sin acceso de Bodega.
    public function test_forgot_password_no_envia_enlace_a_las_cuentas_sin_acceso(): void
    {
        Mail::fake();
        $normal = $this->postJson('/api/auth/forgot-password', ['email' => 'noexiste@atlantic.test']);

        foreach (\App\Modules\Auth\Support\NoLoginAccounts::EMAILS as $email) {
            $r = $this->postJson('/api/auth/forgot-password', ['email' => $email]);

            $r->assertStatus(200);
            $this->assertSame($normal->json('message'), $r->json('message'), 'Misma respuesta que un correo inexistente: no se revela nada.');
        }

        Mail::assertNothingSent();
        Mail::assertNothingQueued();
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

        $this->postJson('/api/auth/forgot-password', ['email' => 'designer@atlantic.test'])
            ->assertStatus(200);

        $token = $this->stealResetToken('designer@atlantic.test');

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'NuevoPass99!',
            'password_confirmation' => 'NuevoPass99!',
        ])->assertStatus(200);
    }

    // SCRUM-1142 (Pre-QA) — un token emitido antes del fix y aún vigente no puede dar acceso a una cuenta sin acceso.
    public function test_reset_password_con_un_token_vigente_de_una_cuenta_sin_acceso_se_rechaza(): void
    {
        $email = 'pickerbodega@test.com';

        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();
        PasswordResetToken::create([
            'email' => $email, 'token_hash' => hash('sha256', 'token-anterior-al-fix'), 'expires_at' => now()->addHour(),
        ]);
        Tenant::forgetCurrent();

        $this->postJson('/api/auth/reset-password', [
            'token'                 => 'token-anterior-al-fix',
            'password'              => 'NuevoPass99!',
            'password_confirmation' => 'NuevoPass99!',
        ])->assertStatus(422)->assertJsonValidationErrors(['token']);

        $this->postJson('/api/auth/login', ['email' => $email, 'password' => 'NuevoPass99!'])->assertStatus(401);
    }

    public function test_reset_password_actualiza_credenciales(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'designer@atlantic.test'])
            ->assertStatus(200);

        $token = $this->stealResetToken('designer@atlantic.test');

        $this->postJson('/api/auth/reset-password', [
            'token'                 => $token,
            'password'              => 'NuevoPass99!',
            'password_confirmation' => 'NuevoPass99!',
        ])->assertStatus(200);

        $this->postJson('/api/auth/login', [
            'email'    => 'designer@atlantic.test',
            'password' => 'NuevoPass99!',
        ])->assertStatus(200);

        $this->postJson('/api/auth/login', [
            'email'    => 'designer@atlantic.test',
            'password' => 'Password123!',
        ])->assertStatus(401);
    }

    public function test_reset_password_limpia_el_indicador_de_cambio_obligatorio(): void
    {
        Mail::fake();
        $this->marcarCambioObligatorio('designer@atlantic.test', true);

        $this->postJson('/api/auth/forgot-password', ['email' => 'designer@atlantic.test'])->assertStatus(200);
        $token = $this->stealResetToken('designer@atlantic.test');

        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'password' => 'NuevoPass99!', 'password_confirmation' => 'NuevoPass99!',
        ])->assertStatus(200);

        $this->assertFalse($this->indicador('designer@atlantic.test'), 'elegir contraseña por el enlace cuenta como el cambio obligatorio');
    }

    public function test_reset_password_rechaza_que_la_nueva_contrasena_sea_el_correo(): void
    {
        Mail::fake();
        // El correo cumple la política de complejidad (mayúscula, minúscula y número) para aislar la regla del correo.
        $this->cambiarCorreo('designer@atlantic.test', 'Designer7@atlantic.test');
        $this->marcarCambioObligatorio('Designer7@atlantic.test', true);

        $this->postJson('/api/auth/forgot-password', ['email' => 'Designer7@atlantic.test'])->assertStatus(200);
        $token = $this->stealResetToken('Designer7@atlantic.test');

        $this->postJson('/api/auth/reset-password', [
            'token' => $token, 'password' => 'DESIGNER7@atlantic.test', 'password_confirmation' => 'DESIGNER7@atlantic.test',
        ])->assertStatus(422)->assertJsonValidationErrors(['password']);

        $this->assertTrue($this->indicador('Designer7@atlantic.test'), 'sigue obligado a cambiarla');
    }

    public function test_reset_password_token_usado_retorna_422(): void
    {
        Mail::fake();

        $this->postJson('/api/auth/forgot-password', ['email' => 'designer@atlantic.test'])
            ->assertStatus(200);

        $token = $this->stealResetToken('designer@atlantic.test');

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

        $this->postJson('/api/auth/forgot-password', ['email' => 'designer@atlantic.test'])
            ->assertStatus(200);

        $token = $this->stealResetToken('designer@atlantic.test');

        $this->expireResetTokens('designer@atlantic.test');

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

        $this->postJson('/api/auth/forgot-password', ['email' => 'designer@atlantic.test'])
            ->assertStatus(200);

        $token = $this->stealResetToken('designer@atlantic.test');

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
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
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
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            PasswordResetToken::where('email', $email)
                ->whereNull('used_at')
                ->update(['expires_at' => now()->subHour()]);
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function marcarCambioObligatorio(string $email, bool $valor): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
        \App\Models\User::where('email', $email)->update(['must_change_password' => $valor]);
    }

    private function indicador(string $email): bool
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();

        return (bool) \App\Models\User::where('email', $email)->value('must_change_password');
    }

    private function cambiarCorreo(string $de, string $a): void
    {
        Tenant::where('slug', 'atlantic')->firstOrFail()->makeCurrent();
        \App\Models\User::where('email', $de)->update(['email' => $a]);
    }
}
