<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Mail\PasswordResetMail;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\GlobalSetting;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Services\AdminPasswordResetService;
use App\Modules\Auth\Services\PasswordResetPolicy;
use App\Modules\Auth\Services\PasswordResetService;
use App\Modules\Auth\Support\GlobalSettingsRegistry;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

/**
 * H2 (caducidad de la contraseña temporal) y S2 (interruptor `password_reset_enabled`) del cierre de la revisión de seguridad del reset
 * administrativo de contraseñas. docs/security/reset-password-revision-seguridad.md.
 */
class PasswordResetExpirationTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Mail::fake();
        foreach ([GlobalSettingsRegistry::PASSWORD_RESET_ENABLED, GlobalSettingsRegistry::PASSWORD_RESET_EXPIRATION_HOURS] as $key) {
            Cache::forget(GlobalSettingsRegistry::cacheKey($key));
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $overrides */
    private function makeUser(array $overrides = []): User
    {
        return User::create([
            'first_name' => 'Persona', 'last_name' => 'Prueba', 'email' => uniqid('exp-') . '@atlantic.test',
            'password_hash' => 'ClaveOriginal99!', 'security_level_id' => SecurityLevel::where('level', 3)->firstOrFail()->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function setSetting(string $key, int $value): void
    {
        GlobalSetting::updateOrCreate(['key' => $key], ['value' => (string) $value, 'updated_at' => now()]);
        Cache::forget(GlobalSettingsRegistry::cacheKey($key));
    }

    /**
     * Reinicia y deja la cuenta como la dejaba un reset ANTERIOR a la Fase 12c (clave = correo): hoy el reset ya no deja ninguna clave utilizable,
     * pero las cuentas reiniciadas antes siguen existiendo y la caducidad (H2) tiene que seguir protegiéndolas.
     */
    private function resetear(User $user): Carbon
    {
        app(AdminPasswordResetService::class)->resetUser($user, 'prueba', 'comando');
        $this->reMakeCurrent();
        $fresh = $user->fresh();
        $fresh->password_hash = $fresh->email;
        $fresh->save();

        return Carbon::parse($user->fresh()?->password_reset_at);
    }

    /** @return array<string, string> */
    private function superadminHeaders(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'));
    }

    // ── Política: valores por defecto ─────────────────────────────────────────────────────────────────────

    public function test_la_vigencia_por_defecto_es_7_dias_y_el_interruptor_esta_encendido_fuera_de_produccion(): void
    {
        $policy = app(PasswordResetPolicy::class);

        $this->assertSame(168, $policy->expirationHours());
        $this->assertTrue($policy->enabled());
    }

    public function test_en_produccion_sin_valor_guardado_el_interruptor_arranca_apagado_y_un_valor_guardado_lo_reemplaza(): void
    {
        $this->app->detectEnvironment(static fn (): string => 'production');
        $policy = app(PasswordResetPolicy::class);

        try {
            $this->assertFalse($policy->enabled(), 'en producción arranca apagado');
            $this->assertSame(0, GlobalSettingsRegistry::defaultFor(GlobalSettingsRegistry::PASSWORD_RESET_ENABLED));

            $this->setSetting(GlobalSettingsRegistry::PASSWORD_RESET_ENABLED, 1);
            $this->assertTrue($policy->enabled(), 'un superadmin lo enciende durante la ventana acordada');

            $this->setSetting(GlobalSettingsRegistry::PASSWORD_RESET_ENABLED, 0);
            $this->assertFalse($policy->enabled());
        } finally {
            $this->app->detectEnvironment(static fn (): string => 'testing');
        }
    }

    // ── Caducidad en el login ─────────────────────────────────────────────────────────────────────────────

    public function test_la_temporal_vigente_entra_y_justo_despues_del_plazo_da_401_sin_token(): void
    {
        $user    = $this->makeUser();
        $resetAt = $this->resetear($user);

        Carbon::setTestNow($resetAt->copy()->addHours(167)->addMinutes(59));
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $user->email])
            ->assertStatus(200)
            ->assertJsonPath('user.must_change_password', true);

        Carbon::setTestNow($resetAt->copy()->addHours(168)->addMinute());
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $user->email])
            ->assertStatus(401)
            ->assertJsonPath('code', 'password_reset_expired');
        $this->assertArrayNotHasKey('token', $response->json());
        $this->assertArrayNotHasKey('refresh_token', $response->json());
    }

    public function test_la_caducidad_respeta_el_ajuste_global(): void
    {
        $this->setSetting(GlobalSettingsRegistry::PASSWORD_RESET_EXPIRATION_HOURS, 2);
        $user    = $this->makeUser();
        $resetAt = $this->resetear($user);

        Carbon::setTestNow($resetAt->copy()->addHour()->addMinutes(59));
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $user->email])->assertStatus(200);

        Carbon::setTestNow($resetAt->copy()->addHours(2)->addMinute());
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $user->email])
            ->assertStatus(401)->assertJsonPath('code', 'password_reset_expired');
    }

    public function test_una_cuenta_sin_reset_pendiente_nunca_caduca(): void
    {
        $normal = $this->makeUser();
        Carbon::setTestNow(now()->addDays(60));

        $this->postJson('/api/auth/login', ['email' => $normal->email, 'password' => 'ClaveOriginal99!'])->assertStatus(200);
    }

    public function test_una_contrasena_incorrecta_no_revela_que_la_cuenta_esta_caducada(): void
    {
        $user    = $this->makeUser();
        $resetAt = $this->resetear($user);
        Carbon::setTestNow($resetAt->copy()->addHours(169));

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'otra-clave-cualquiera'])
            ->assertStatus(401)
            ->assertJsonMissingPath('code');
    }

    // ── Caducidad en un token ya emitido ──────────────────────────────────────────────────────────────────

    public function test_un_token_vivo_de_una_cuenta_caducada_recibe_401_en_cualquier_ruta(): void
    {
        $this->setSetting(GlobalSettingsRegistry::PASSWORD_RESET_EXPIRATION_HOURS, 1);
        $user    = $this->makeUser();
        $this->resetear($user);
        $this->reMakeCurrent();
        sleep(1); // el login debe quedar en un segundo posterior al reset (H1 compara iat con password_reset_at)

        $token = (string) $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $user->email])->assertStatus(200)->json('token');
        $headers = $this->authHeaders($token);

        Carbon::setTestNow(now()->addMinutes(30));
        $this->getJson('/api/auth/me', $headers)->assertStatus(200)->assertJsonPath('must_change_password', true);

        Carbon::setTestNow(now()->addMinutes(31));
        $this->getJson('/api/auth/me', $headers)->assertStatus(401)->assertJsonPath('code', 'password_reset_expired');
        $this->putJson('/api/auth/password', [
            'current_password' => $user->email, 'new_password' => 'NuevaClave99!x', 'new_password_confirmation' => 'NuevaClave99!x',
        ], $headers)->assertStatus(401)->assertJsonPath('code', 'password_reset_expired');
    }

    public function test_un_token_de_una_cuenta_sin_reset_no_se_ve_afectado_por_el_paso_del_tiempo(): void
    {
        $this->setSetting(GlobalSettingsRegistry::PASSWORD_RESET_EXPIRATION_HOURS, 1);
        $normal  = $this->makeUser();
        $headers = $this->authHeaders($this->loginAs($normal->email, 'ClaveOriginal99!'));

        Carbon::setTestNow(now()->addHours(3));
        $this->getJson('/api/auth/me', $headers)->assertStatus(200);
    }

    // ── Recuperación ──────────────────────────────────────────────────────────────────────────────────────

    public function test_otro_reset_administrativo_reabre_una_cuenta_caducada(): void
    {
        $user    = $this->makeUser();
        $resetAt = $this->resetear($user);

        Carbon::setTestNow($resetAt->copy()->addHours(169));
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $user->email])->assertStatus(401);

        $this->reMakeCurrent();
        $this->resetear($user);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => $user->email])->assertStatus(200);
    }

    public function test_olvide_mi_contrasena_recupera_una_cuenta_caducada_y_limpia_el_indicador(): void
    {
        $user    = $this->makeUser();
        $resetAt = $this->resetear($user);
        Carbon::setTestNow($resetAt->copy()->addHours(169));

        app(PasswordResetService::class)->sendResetLink($user->email);
        $url = null;
        Mail::assertQueued(PasswordResetMail::class, static function (PasswordResetMail $m) use (&$url): bool {
            $url = $m->resetUrl;

            return true;
        });
        parse_str((string) parse_url((string) $url, PHP_URL_FRAGMENT), $query);

        $this->postJson('/api/auth/reset-password', [
            'token' => $query['token'], 'password' => 'NuevaClave99!x', 'password_confirmation' => 'NuevaClave99!x',
        ])->assertStatus(200);

        $this->reMakeCurrent();
        $this->assertFalse($user->fresh()->must_change_password);
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'NuevaClave99!x'])->assertStatus(200);
    }

    // ── Ajustes globales (API) ────────────────────────────────────────────────────────────────────────────

    public function test_los_ajustes_se_listan_con_su_valor_efectivo_y_tipo(): void
    {
        $rows = collect($this->getJson('/api/admin/global-settings', $this->superadminHeaders())->assertStatus(200)->json('data'))->keyBy('key');

        $this->assertSame(168, $rows['password_reset_expiration_hours']['value']);
        $this->assertSame(1, $rows['password_reset_expiration_hours']['min']);
        $this->assertSame(720, $rows['password_reset_expiration_hours']['max']);
        $this->assertSame('boolean', $rows['password_reset_enabled']['type']);
        $this->assertSame(1, $rows['password_reset_enabled']['value'], 'fuera de producción el default efectivo es encendido');
    }

    public function test_la_caducidad_valida_el_rango_y_audita_el_cambio(): void
    {
        $headers = $this->superadminHeaders();

        foreach ([0, -5, 721, 'abc'] as $malo) {
            $this->putJson('/api/admin/global-settings/password_reset_expiration_hours', ['value' => $malo, 'operator_password' => 'Password123!'], $headers)->assertStatus(422);
        }

        $this->putJson('/api/admin/global-settings/password_reset_expiration_hours', ['value' => 72, 'operator_password' => 'Password123!'], $headers)
            ->assertStatus(200)->assertJsonPath('value', 72);
        $this->reMakeCurrent();
        $this->assertSame(72, app(PasswordResetPolicy::class)->expirationHours());

        $this->reMakeCurrent();
        $log = AuditLog::query()->where('action', 'settings.updated')->where('entity_id', 'password_reset_expiration_hours')->latest('id')->first();
        $this->assertNotNull($log, 'el cambio de ajuste queda auditado');
    }

    public function test_el_interruptor_acepta_true_false_o_0_1_y_se_refleja_en_la_politica(): void
    {
        $headers = $this->superadminHeaders();

        $this->putJson('/api/admin/global-settings/password_reset_enabled', ['value' => false, 'operator_password' => 'Password123!'], $headers)->assertStatus(200)->assertJsonPath('value', 0);
        $this->reMakeCurrent();
        $this->assertFalse(app(PasswordResetPolicy::class)->enabled());

        $this->putJson('/api/admin/global-settings/password_reset_enabled', ['value' => 1, 'operator_password' => 'Password123!'], $headers)->assertStatus(200)->assertJsonPath('value', 1);
        $this->reMakeCurrent();
        $this->assertTrue(app(PasswordResetPolicy::class)->enabled());

        $this->putJson('/api/admin/global-settings/password_reset_enabled', ['value' => 2, 'operator_password' => 'Password123!'], $headers)->assertStatus(422);
        $this->reMakeCurrent();
        $this->assertNotNull(AuditLog::query()->where('action', 'settings.updated')->where('entity_id', 'password_reset_enabled')->first());
    }

    public function test_solo_superadmin_edita_los_ajustes(): void
    {
        $headers = $this->authHeaders($this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer'));

        $this->putJson('/api/admin/global-settings/password_reset_enabled', ['value' => 0], $headers)->assertStatus(403);
    }

    // ── Interruptor apagado: API y comando ────────────────────────────────────────────────────────────────

    public function test_con_el_interruptor_apagado_la_api_bloquea_aplicar_pero_permite_simular(): void
    {
        $this->setSetting(GlobalSettingsRegistry::PASSWORD_RESET_ENABLED, 0);
        $objetivo = $this->makeUser();
        $headers  = $this->superadminHeaders();

        $this->postJson("/api/users/{$objetivo->id}/reset-password", [], $headers)
            ->assertStatus(403)->assertJsonPath('code', 'password_reset_disabled');

        $dry = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->assertStatus(200);
        $this->postJson('/api/users/reset-passwords', [
            'operator_password' => 'Password123!', 'confirm' => 'RESETEAR ' . $dry->json('data.count'),
        ], $headers)->assertStatus(403)->assertJsonPath('code', 'password_reset_disabled');

        $this->reMakeCurrent();
        $this->assertFalse((bool) $objetivo->fresh()?->must_change_password, 'no se cambió nada');
    }

    public function test_con_el_interruptor_apagado_el_comando_falla_al_aplicar_y_la_simulacion_sigue_funcionando(): void
    {
        $this->setSetting(GlobalSettingsRegistry::PASSWORD_RESET_ENABLED, 0);
        $objetivo = $this->makeUser();

        $this->artisan('users:reset-password', ['--email' => $objetivo->email, '--apply' => true, '--yes' => true, '--operador' => 'Prueba'])
            ->expectsOutputToContain('DESHABILITADO')
            ->assertExitCode(1);
        $this->reMakeCurrent();
        $this->assertFalse((bool) $objetivo->fresh()?->must_change_password);

        $this->artisan('users:reset-password', ['--email' => $objetivo->email])
            ->expectsOutputToContain('SIMULACIÓN')
            ->assertExitCode(0);
    }

    public function test_con_el_interruptor_encendido_el_reset_funciona_como_siempre(): void
    {
        $this->setSetting(GlobalSettingsRegistry::PASSWORD_RESET_ENABLED, 1);
        $objetivo = $this->makeUser();

        $this->postJson("/api/users/{$objetivo->id}/reset-password", [], $this->superadminHeaders())->assertStatus(200);
        $this->reMakeCurrent();
        $this->assertTrue($objetivo->fresh()->must_change_password);
    }
}
