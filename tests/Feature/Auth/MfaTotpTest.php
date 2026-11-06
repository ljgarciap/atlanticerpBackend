<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\MfaBackupCode;
use App\Modules\Auth\Models\MfaCode;
use App\Modules\Auth\Models\SystemSetting;
use App\Shared\Auth\Totp;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

/** Fase 27c — aplicación autenticadora (TOTP) y códigos de respaldo. */
class MfaTotpTest extends FeatureTestCase
{
    private const EMAIL = 'vendedor@atlantic.test';
    private const PASS  = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();
        // Los topes de errores viven en la caché: cada test parte de cero (varios queman a propósito el tope por IP o por usuario).
        Cache::flush();
    }

    // ── Alta ──────────────────────────────────────────────────────────────────

    public function test_setup_devuelve_el_secreto_y_el_qr_pero_no_activa_nada(): void
    {
        $token = $this->loginAs(self::EMAIL);

        $r = $this->postAuth($token, '/api/auth/mfa/totp/setup', ['password' => self::PASS])->assertStatus(200);
        $this->assertSame(32, strlen((string) $r->json('secret')));
        $this->assertStringStartsWith('otpauth://totp/', (string) $r->json('otpauth_uri'));
        $this->assertStringContainsString('secret=' . $r->json('secret'), (string) $r->json('otpauth_uri'));

        $this->getAuth($token, '/api/auth/mfa/status')->assertJsonPath('mfa_enabled', false)->assertJsonPath('mfa_method', null);
    }

    public function test_setup_con_clave_incorrecta_da_422(): void
    {
        $token = $this->loginAs(self::EMAIL);
        $this->postAuth($token, '/api/auth/mfa/totp/setup', ['password' => 'Incorrecta1!'])->assertStatus(422);
        $this->postAuth($token, '/api/auth/mfa/totp/setup')->assertStatus(422);
    }

    public function test_confirmar_con_codigo_malo_no_activa_y_con_el_bueno_activa_y_da_10_respaldos(): void
    {
        $token  = $this->loginAs(self::EMAIL);
        $secret = (string) $this->postAuth($token, '/api/auth/mfa/totp/setup', ['password' => self::PASS])->json('secret');

        $this->postAuth($token, '/api/auth/mfa/totp/confirm', ['code' => '000000'])->assertStatus(422);
        $this->getAuth($token, '/api/auth/mfa/status')->assertJsonPath('mfa_enabled', false);

        $r = $this->postAuth($token, '/api/auth/mfa/totp/confirm', ['code' => Totp::codeAt($secret, Totp::currentStep())])->assertStatus(200);
        $r->assertJsonPath('mfa_enabled', true)->assertJsonPath('mfa_method', 'totp');
        $codes = $r->json('backup_codes');
        $this->assertCount(10, $codes);
        $this->assertCount(10, array_unique($codes));
        $this->assertMatchesRegularExpression('/^[A-Z0-9]{5}-[A-Z0-9]{5}$/', $codes[0]);

        $this->getAuth($token, '/api/auth/mfa/status')
            ->assertJsonPath('mfa_method', 'totp')->assertJsonPath('backup_codes_remaining', 10);
    }

    public function test_confirmar_sin_haber_iniciado_el_alta_da_422(): void
    {
        $token = $this->loginAs(self::EMAIL);
        $this->postAuth($token, '/api/auth/mfa/totp/confirm', ['code' => '123456'])->assertStatus(422);
    }

    public function test_el_secreto_y_los_respaldos_no_aparecen_en_respuestas_posteriores_ni_en_la_auditoria(): void
    {
        [$token, $secret, $codes] = $this->activarTotp(self::EMAIL);

        $cuerpos = [
            $this->getAuth($token, '/api/auth/mfa/status')->getContent(),
            $this->getAuth($token, '/api/auth/me')->getContent(),
            $this->postAuth($token, '/api/auth/mfa/proof', ['password' => self::PASS])->getContent(),
        ];
        foreach ($cuerpos as $cuerpo) {
            $this->assertStringNotContainsString($secret, $cuerpo);
            foreach ($codes as $code) {
                $this->assertStringNotContainsString($code, $cuerpo);
            }
        }

        $registro = $this->conTenant(fn () => AuditLog::query()->get()->toJson());
        $this->assertStringNotContainsString($secret, $registro);
        foreach ($codes as $code) {
            $this->assertStringNotContainsString($code, $registro);
            $this->assertStringNotContainsString(str_replace('-', '', $code), $registro);
        }
        $this->assertStringContainsString('security.mfa.totp_enabled', $registro);
    }

    public function test_el_secreto_se_guarda_cifrado_en_la_base(): void
    {
        [, $secret] = $this->activarTotp(self::EMAIL);

        $crudo = $this->conTenant(fn () => (string) User::query()->where('email', self::EMAIL)->toBase()->value('mfa_totp_secret'));
        $this->assertNotSame('', $crudo);
        $this->assertStringNotContainsString($secret, $crudo);
    }

    public function test_con_correo_activo_cambiar_a_aplicacion_exige_probar_el_metodo_vigente(): void
    {
        $token = $this->loginAs(self::EMAIL);
        $this->conTenant(fn () => User::query()->where('email', self::EMAIL)->update(['mfa_enabled' => true]));

        $this->postAuth($token, '/api/auth/mfa/totp/setup', ['password' => self::PASS])->assertStatus(422);

        $proof = $this->postAuth($token, '/api/auth/mfa/proof', ['password' => self::PASS])->assertStatus(200)->assertJsonPath('method', 'email');
        $challengeId = (string) $proof->json('mfa_challenge_id');
        $this->conTenant(fn () => MfaCode::where('id', $challengeId)->update(['code_hash' => hash('sha256', '111222')]));

        $this->postAuth($token, '/api/auth/mfa/totp/setup', ['password' => self::PASS, 'mfa_challenge_id' => $challengeId, 'code' => '999999'])->assertStatus(422);
        $this->postAuth($token, '/api/auth/mfa/totp/setup', ['password' => self::PASS, 'mfa_challenge_id' => $challengeId, 'code' => '111222'])
            ->assertStatus(200)->assertJsonStructure(['secret', 'otpauth_uri']);
    }

    public function test_activar_por_correo_con_aplicacion_activa_da_422(): void
    {
        [$token] = $this->activarTotp(self::EMAIL);
        $this->postAuth($token, '/api/auth/mfa/enable', ['password' => self::PASS])->assertStatus(422);
        $this->postAuth($token, '/api/auth/mfa/totp/setup', ['password' => self::PASS])->assertStatus(422);
    }

    // ── Ingreso ───────────────────────────────────────────────────────────────

    public function test_login_con_aplicacion_no_envia_correo_e_indica_el_metodo(): void
    {
        $this->activarTotp(self::EMAIL);
        Mail::fake();

        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASS])
            ->assertStatus(200)
            ->assertJsonPath('requires_mfa', true)
            ->assertJsonPath('mfa_method', 'totp')
            ->assertJsonStructure(['mfa_challenge_id']);
        Mail::assertNothingSent();
    }

    public function test_login_con_correo_indica_method_email(): void
    {
        Mail::fake();
        $this->setMfaMode('required');

        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASS])
            ->assertJsonPath('requires_mfa', true)->assertJsonPath('mfa_method', 'email');
    }

    public function test_ingresar_con_el_codigo_de_la_aplicacion_y_rechazar_su_repeticion(): void
    {
        [, $secret] = $this->activarTotp(self::EMAIL);

        // El alta ya gastó el paso actual: el siguiente (válido por la tolerancia) es el que entra.
        $code = Totp::codeAt($secret, Totp::currentStep() + 1);

        $this->verificar($this->retoTotp(), $code)->assertStatus(200)->assertJsonStructure(['token', 'refresh_token']);
        $this->verificar($this->retoTotp(), $code)->assertStatus(422);
    }

    public function test_un_codigo_de_un_paso_anterior_al_ultimo_aceptado_no_sirve(): void
    {
        [, $secret] = $this->activarTotp(self::EMAIL);

        $this->verificar($this->retoTotp(), Totp::codeAt($secret, Totp::currentStep() + 1))->assertStatus(200);
        $this->verificar($this->retoTotp(), Totp::codeAt($secret, Totp::currentStep()))->assertStatus(422);
    }

    public function test_un_codigo_de_la_aplicacion_incorrecto_da_422(): void
    {
        $this->activarTotp(self::EMAIL);
        $this->verificar($this->retoTotp(), '000000')->assertStatus(422);
    }

    public function test_un_respaldo_sirve_una_sola_vez(): void
    {
        [, , $codes] = $this->activarTotp(self::EMAIL);

        $this->verificar($this->retoTotp(), $codes[0])->assertStatus(200)->assertJsonStructure(['token']);
        $this->verificar($this->retoTotp(), $codes[0])->assertStatus(422);
        // Otro respaldo sigue sirviendo, y se acepta sin guion y en minúsculas.
        $this->verificar($this->retoTotp(), strtolower(str_replace('-', '', $codes[1])))->assertStatus(200);

        $this->conTenant(function () {
            $this->assertSame(8, MfaBackupCode::query()->whereNull('used_at')->count());
            $this->assertTrue(AuditLog::query()->where('action', 'security.mfa.backup_used')->exists());
        });
    }

    public function test_con_aplicacion_activa_el_reto_no_se_reenvia(): void
    {
        $this->activarTotp(self::EMAIL);
        $this->postJson('/api/auth/mfa/resend', ['mfa_challenge_id' => $this->retoTotp()])->assertStatus(422);
    }

    public function test_el_reto_de_correo_sigue_pidiendo_seis_digitos(): void
    {
        Mail::fake();
        $this->setMfaMode('required');
        $id = (string) $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASS])->json('mfa_challenge_id');

        $this->verificar($id, 'ABCDE-FGHIJ')->assertStatus(422)->assertJsonValidationErrors('code');
    }

    public function test_el_tope_de_errores_por_usuario_tambien_rige_con_la_aplicacion(): void
    {
        $this->activarTotp(self::EMAIL);

        for ($i = 0; $i < 5; $i++) {
            $this->verificar($this->retoTotp(), '000000')->assertStatus(422);
        }
        $this->verificar($this->retoTotp(), '000000')->assertStatus(429);
    }

    // ── Acciones sensibles ────────────────────────────────────────────────────

    public function test_desactivar_con_aplicacion_pide_clave_y_codigo_y_borra_secreto_y_respaldos(): void
    {
        [$token, $secret] = $this->activarTotp(self::EMAIL);

        $this->postAuth($token, '/api/auth/mfa/disable', ['password' => 'Incorrecta1!'])->assertStatus(422);
        $this->postAuth($token, '/api/auth/mfa/disable', ['password' => self::PASS])->assertStatus(200)->assertJsonPath('method', 'totp');

        $this->postAuth($token, '/api/auth/mfa/disable/confirm', ['code' => '000000'])->assertStatus(422);
        $this->getAuth($token, '/api/auth/mfa/status')->assertJsonPath('mfa_enabled', true);

        $this->postAuth($token, '/api/auth/mfa/disable/confirm', ['code' => Totp::codeAt($secret, Totp::currentStep() + 1)])
            ->assertStatus(200)->assertJsonPath('mfa_enabled', false);

        $this->conTenant(function () {
            $u = User::query()->where('email', self::EMAIL)->firstOrFail();
            $this->assertSame('email', $u->mfa_method);
            $this->assertNull($u->mfa_totp_secret);
            $this->assertSame(0, MfaBackupCode::query()->where('user_id', $u->id)->count());
            $this->assertTrue(AuditLog::query()->where('action', 'security.mfa.totp_disabled')->exists());
        });
        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASS])->assertJsonMissingPath('requires_mfa')->assertJsonStructure(['token']);
    }

    public function test_desactivar_con_un_respaldo_funciona(): void
    {
        [$token, , $codes] = $this->activarTotp(self::EMAIL);
        $this->postAuth($token, '/api/auth/mfa/disable/confirm', ['code' => $codes[3]])->assertStatus(200)->assertJsonPath('mfa_enabled', false);
    }

    public function test_regenerar_respaldos_exige_clave_y_codigo_e_invalida_los_anteriores(): void
    {
        [$token, $secret, $viejos] = $this->activarTotp(self::EMAIL);
        $code = Totp::codeAt($secret, Totp::currentStep() + 1);

        $this->postAuth($token, '/api/auth/mfa/backup-codes/regenerate', ['password' => 'Incorrecta1!', 'code' => $code])->assertStatus(422);
        $this->postAuth($token, '/api/auth/mfa/backup-codes/regenerate', ['password' => self::PASS, 'code' => '000000'])->assertStatus(422);
        $this->postAuth($token, '/api/auth/mfa/backup-codes/regenerate', ['password' => self::PASS])->assertStatus(422);

        $nuevos = $this->postAuth($token, '/api/auth/mfa/backup-codes/regenerate', ['password' => self::PASS, 'code' => $code])
            ->assertStatus(200)->json('backup_codes');
        $this->assertCount(10, $nuevos);
        $this->assertEmpty(array_intersect($viejos, $nuevos));

        $this->verificar($this->retoTotp(), $viejos[0])->assertStatus(422);
        $this->verificar($this->retoTotp(), $nuevos[0])->assertStatus(200);
    }

    public function test_regenerar_sin_aplicacion_activa_da_422(): void
    {
        $token = $this->loginAs(self::EMAIL);
        $this->postAuth($token, '/api/auth/mfa/backup-codes/regenerate', ['password' => self::PASS, 'code' => '123456'])->assertStatus(422);
    }

    public function test_el_reinicio_de_un_administrador_borra_secreto_y_respaldos_y_la_persona_entra_sin_segundo_factor(): void
    {
        $this->activarTotp(self::EMAIL);
        $afectado = $this->conTenant(fn () => User::query()->where('email', self::EMAIL)->firstOrFail());

        $admin = $this->loginAs('superadmin@atlantic.test', self::PASS, 'superadmin');
        $this->postAuth($admin, "/api/admin/security/users/{$afectado->id}/mfa/reset")->assertStatus(200);

        $this->conTenant(function () use ($afectado) {
            $u = User::query()->findOrFail($afectado->id);
            $this->assertFalse($u->mfa_enabled);
            $this->assertSame('email', $u->mfa_method);
            $this->assertNull($u->mfa_totp_secret);
            $this->assertSame(0, MfaBackupCode::query()->where('user_id', $afectado->id)->count());
        });
        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASS])->assertJsonMissingPath('requires_mfa')->assertJsonStructure(['token']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array{0: string, 1: string, 2: list<string>} token de sesión, secreto y respaldos */
    private function activarTotp(string $email): array
    {
        $token  = $this->loginAs($email);
        $secret = (string) $this->postAuth($token, '/api/auth/mfa/totp/setup', ['password' => self::PASS])->assertStatus(200)->json('secret');
        $codes  = $this->postAuth($token, '/api/auth/mfa/totp/confirm', ['code' => Totp::codeAt($secret, Totp::currentStep())])
            ->assertStatus(200)->json('backup_codes');

        return [$token, $secret, $codes];
    }

    private function retoTotp(): string
    {
        $r = $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::PASS])->assertStatus(200)->assertJsonPath('mfa_method', 'totp');

        return (string) $r->json('mfa_challenge_id');
    }

    private function verificar(string $challengeId, string $code): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $challengeId, 'code' => $code]);
    }

    /** @param array<string, mixed> $body */
    private function postAuth(string $token, string $url, array $body = []): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeaders($token))->postJson($url, $body);
    }

    private function getAuth(string $token, string $url): \Illuminate\Testing\TestResponse
    {
        return $this->withHeaders($this->authHeaders($token))->getJson($url);
    }

    private function conTenant(\Closure $fn): mixed
    {
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            return $fn();
        } finally {
            Tenant::forgetCurrent();
        }
    }

    private function setMfaMode(string $mode): void
    {
        $this->conTenant(fn () => SystemSetting::updateOrCreate(['key' => 'mfa_mode'], ['value' => $mode, 'updated_by' => null, 'updated_at' => now()]));
    }
}
