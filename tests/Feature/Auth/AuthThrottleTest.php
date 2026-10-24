<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Mail\MfaCodeMail;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Models\SystemSetting;
use App\Modules\Auth\Support\AuthThrottle;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\FeatureTestCase;

/**
 * H4 del cierre de la revisión de seguridad: límites de intentos en login, refresh, MFA (verificación y reenvío) y olvidé mi contraseña.
 * Valores en config/throttle.php; aquí se bajan por test para no necesitar decenas de peticiones.
 */
class AuthThrottleTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Mail::fake();
        // Por defecto los demás topes quedan muy altos: cada test baja SOLO el que prueba.
        config([
            'throttle.login.ip_max' => 1000, 'throttle.login.email_max' => 1000,
            'throttle.refresh.ip_max' => 1000,
            'throttle.mfa_verify.ip_max' => 1000, 'throttle.mfa_verify.user_max' => 1000,
            'throttle.mfa_resend.max' => 1000, 'throttle.mfa_send.max' => 1000,
            'throttle.forgot_password.ip_max' => 1000, 'throttle.forgot_password.email_max' => 1000,
        ]);
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
        $this->reMakeCurrent();

        return User::create([
            'first_name' => 'Persona', 'last_name' => 'Prueba', 'email' => uniqid('thr-') . '@atlantic.test',
            'password_hash' => 'ClaveOriginal99!', 'security_level_id' => SecurityLevel::where('level', 3)->firstOrFail()->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /** @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse> */
    private function login(string $email, string $password)
    {
        return $this->postJson('/api/auth/login', ['email' => $email, 'password' => $password]);
    }

    // ── Login ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_el_login_se_limita_por_correo_tras_los_fallos_y_responde_429_con_retry_after(): void
    {
        config(['throttle.login.email_max' => 3]);
        $user = $this->makeUser();

        for ($i = 0; $i < 3; $i++) {
            $this->login($user->email, 'incorrecta')->assertStatus(401);
        }

        $bloqueado = $this->login($user->email, 'ClaveOriginal99!')
            ->assertStatus(429)
            ->assertJsonPath('code', 'too_many_attempts');
        $this->assertGreaterThan(0, $bloqueado->json('retry_after'));
        $this->assertNotNull($bloqueado->headers->get('Retry-After'));
        $this->assertArrayNotHasKey('token', $bloqueado->json());
    }

    public function test_el_limite_por_correo_no_revela_si_el_correo_existe(): void
    {
        config(['throttle.login.email_max' => 3]);
        $existe = $this->makeUser();
        $noExiste = 'nadie-' . uniqid() . '@atlantic.test';

        $resp = ['existe' => [], 'noExiste' => []];
        foreach (['existe' => $existe->email, 'noExiste' => $noExiste] as $clave => $email) {
            for ($i = 0; $i < 4; $i++) {
                $r = $this->login($email, 'incorrecta');
                $resp[$clave][] = [$r->getStatusCode(), $r->json('code'), array_keys($r->json())];
            }
        }

        $this->assertSame($resp['existe'], $resp['noExiste'], 'misma secuencia de estados, códigos y forma de respuesta');
        $this->assertSame([401, 401, 401, 429], array_column($resp['existe'], 0));
    }

    public function test_un_login_correcto_de_otro_correo_no_se_bloquea_por_los_fallos_ajenos(): void
    {
        config(['throttle.login.email_max' => 3, 'throttle.login.ip_max' => 100]);
        $victima = $this->makeUser();
        $otro = $this->makeUser();

        for ($i = 0; $i < 3; $i++) {
            $this->login($victima->email, 'incorrecta')->assertStatus(401);
        }
        $this->login($victima->email, 'ClaveOriginal99!')->assertStatus(429);

        $this->login($otro->email, 'ClaveOriginal99!')->assertStatus(200);
    }

    public function test_el_tope_por_ip_bloquea_incluso_un_login_correcto_de_otro_correo(): void
    {
        config(['throttle.login.ip_max' => 3, 'throttle.login.email_max' => 100]);
        $bueno = $this->makeUser();

        for ($i = 0; $i < 3; $i++) {
            $this->login('x' . $i . '-' . uniqid() . '@atlantic.test', 'incorrecta')->assertStatus(401);
        }

        $this->login($bueno->email, 'ClaveOriginal99!')->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_los_logins_correctos_no_consumen_cupo(): void
    {
        config(['throttle.login.ip_max' => 2, 'throttle.login.email_max' => 2]);
        $user = $this->makeUser();

        for ($i = 0; $i < 8; $i++) {
            $this->login($user->email, 'ClaveOriginal99!')->assertStatus(200);
        }
    }

    public function test_un_login_correcto_reinicia_el_contador_de_fallos_de_ese_correo(): void
    {
        config(['throttle.login.email_max' => 3]);
        $user = $this->makeUser();

        for ($ronda = 0; $ronda < 3; $ronda++) {
            $this->login($user->email, 'incorrecta')->assertStatus(401);
            $this->login($user->email, 'incorrecta')->assertStatus(401);
            $this->login($user->email, 'ClaveOriginal99!')->assertStatus(200);
        }
    }

    public function test_el_correo_se_normaliza_para_el_limite(): void
    {
        config(['throttle.login.email_max' => 2]);
        $user = $this->makeUser();

        $this->login($user->email, 'incorrecta')->assertStatus(401);
        $this->login('  ' . strtoupper($user->email) . ' ', 'incorrecta')->assertStatus(401);
        $this->login($user->email, 'ClaveOriginal99!')->assertStatus(429);
    }

    public function test_no_hay_bloqueo_permanente_el_cupo_vuelve_al_vencer_la_ventana(): void
    {
        config(['throttle.login.email_max' => 2, 'throttle.login.email_decay' => 60]);
        $user = $this->makeUser();

        $this->login($user->email, 'incorrecta')->assertStatus(401);
        $this->login($user->email, 'incorrecta')->assertStatus(401);
        $this->login($user->email, 'ClaveOriginal99!')->assertStatus(429);

        Carbon::setTestNow(now()->addSeconds(61));
        $this->login($user->email, 'ClaveOriginal99!')->assertStatus(200);
    }

    // ── Refresh ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_el_refresh_se_limita_por_ip_solo_con_tokens_invalidos(): void
    {
        config(['throttle.refresh.ip_max' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/refresh', ['refresh_token' => 'invalido-' . $i])->assertStatus(401);
        }

        $this->postJson('/api/auth/refresh', ['refresh_token' => 'otro'])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_los_refresh_validos_no_consumen_cupo(): void
    {
        config(['throttle.refresh.ip_max' => 2]);
        $user = $this->makeUser();
        $refresh = (string) $this->login($user->email, 'ClaveOriginal99!')->assertStatus(200)->json('refresh_token');

        for ($i = 0; $i < 5; $i++) {
            $resp = $this->postJson('/api/auth/refresh', ['refresh_token' => $refresh])->assertStatus(200);
            $refresh = (string) $resp->json('refresh_token');
        }
    }

    // ── MFA ───────────────────────────────────────────────────────────────────────────────────────────────

    private function usuarioConMfa(): User
    {
        $user = $this->makeUser(['mfa_enabled' => true]);
        $this->reMakeCurrent();
        SystemSetting::updateOrCreate(['key' => 'mfa_mode'], ['value' => 'optional', 'updated_by' => null, 'updated_at' => now()]);

        return $user;
    }

    private function ultimoCodigoMfa(): string
    {
        return (string) Mail::sent(MfaCodeMail::class)->last()?->code;
    }

    public function test_la_verificacion_mfa_se_limita_por_ip_con_codigos_erroneos(): void
    {
        config(['throttle.mfa_verify.ip_max' => 3]);
        $uuid = '11111111-1111-4111-8111-111111111111';

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $uuid, 'code' => '000000'])->assertStatus(422);
        }

        $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $uuid, 'code' => '000000'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_reenviar_el_codigo_no_reinicia_el_contador_de_fallos_del_usuario(): void
    {
        config(['throttle.mfa_verify.user_max' => 3]);
        $user = $this->usuarioConMfa();

        $reto1 = (string) $this->login($user->email, 'ClaveOriginal99!')->assertStatus(200)->json('mfa_challenge_id');
        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $reto1, 'code' => '000000'])->assertStatus(422);
        }

        // Reenviar da un reto NUEVO (con 0 intentos propios) pero el tope del usuario sigue agotado: ni el código correcto pasa.
        $reto2 = (string) $this->postJson('/api/auth/mfa/resend', ['mfa_challenge_id' => $reto1])->assertStatus(200)->json('mfa_challenge_id');
        $this->assertNotSame($reto1, $reto2);
        $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $reto2, 'code' => $this->ultimoCodigoMfa()])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts')->assertJsonStructure(['retry_after']);

        // Al vencer la ventana (y con un reto nuevo vigente) el usuario vuelve a poder entrar: no hay bloqueo permanente.
        Carbon::setTestNow(now()->addMinutes(11));
        $this->reMakeCurrent();
        $reto3 = (string) $this->login($user->email, 'ClaveOriginal99!')->assertStatus(200)->json('mfa_challenge_id');
        $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $reto3, 'code' => $this->ultimoCodigoMfa()])
            ->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_un_codigo_correcto_reinicia_el_contador_de_fallos_del_usuario(): void
    {
        config(['throttle.mfa_verify.user_max' => 3]);
        $user = $this->usuarioConMfa();

        $reto = (string) $this->login($user->email, 'ClaveOriginal99!')->json('mfa_challenge_id');
        $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $reto, 'code' => '000000'])->assertStatus(422);
        $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $reto, 'code' => $this->ultimoCodigoMfa()])->assertStatus(200);

        $reto2 = (string) $this->login($user->email, 'ClaveOriginal99!')->json('mfa_challenge_id');
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $reto2, 'code' => '000000'])->assertStatus(422);
        }
        $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $reto2, 'code' => $this->ultimoCodigoMfa()])->assertStatus(200);
    }

    public function test_el_reenvio_de_mfa_se_limita_por_usuario(): void
    {
        config(['throttle.mfa_resend.max' => 3]);
        $user = $this->usuarioConMfa();
        $reto = (string) $this->login($user->email, 'ClaveOriginal99!')->json('mfa_challenge_id');

        for ($i = 0; $i < 3; $i++) {
            $reto = (string) $this->postJson('/api/auth/mfa/resend', ['mfa_challenge_id' => $reto])->assertStatus(200)->json('mfa_challenge_id');
        }

        $this->postJson('/api/auth/mfa/resend', ['mfa_challenge_id' => $reto])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_el_tope_de_codigos_erroneos_por_usuario_responde_429_con_retry_after_y_cabecera(): void
    {
        config(['throttle.mfa_verify.user_max' => 2]);
        $user = $this->usuarioConMfa();
        $reto = (string) $this->login($user->email, 'ClaveOriginal99!')->json('mfa_challenge_id');

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $reto, 'code' => '000000'])->assertStatus(422);
        }

        $resp = $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $reto, 'code' => '000000'])->assertStatus(429);
        $this->assertGreaterThan(0, (int) $resp->json('retry_after'));
        $this->assertNotEmpty($resp->headers->get('Retry-After'));
    }

    public function test_confirmar_la_activacion_de_mfa_tambien_responde_429_al_agotar_el_tope_por_usuario(): void
    {
        config(['throttle.mfa_verify.user_max' => 2]);
        $user  = $this->makeUser();
        $token = (string) $this->login($user->email, 'ClaveOriginal99!')->assertStatus(200)->json('token');
        $reto  = (string) $this->postJson('/api/auth/mfa/enable', ['password' => 'ClaveOriginal99!'], ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(200)->json('mfa_challenge_id');

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/auth/mfa/enable/confirm', ['mfa_challenge_id' => $reto, 'code' => '000000'], ['Authorization' => 'Bearer ' . $token])->assertStatus(422);
        }

        $this->postJson('/api/auth/mfa/enable/confirm', ['mfa_challenge_id' => $reto, 'code' => '000000'], ['Authorization' => 'Bearer ' . $token])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_los_logins_correctos_con_mfa_cuentan_los_envios_de_codigo_y_se_limitan_por_usuario(): void
    {
        config(['throttle.mfa_send.max' => 3]);
        $user = $this->usuarioConMfa();

        for ($i = 0; $i < 3; $i++) {
            $this->login($user->email, 'ClaveOriginal99!')->assertStatus(200)->assertJsonPath('requires_mfa', true);
        }
        Mail::assertSent(MfaCodeMail::class, 3);

        $resp = $this->login($user->email, 'ClaveOriginal99!')->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        $this->assertGreaterThan(0, (int) $resp->json('retry_after'));
        // El cuarto no envió ningún correo más.
        Mail::assertSent(MfaCodeMail::class, 3);

        // El tope es por usuario: otro usuario con MFA sigue recibiendo su código.
        $otro = $this->makeUser(['mfa_enabled' => true]);
        $this->login($otro->email, 'ClaveOriginal99!')->assertStatus(200)->assertJsonPath('requires_mfa', true);
    }

    // ── Atomicidad (el cupo se reserva ANTES de evaluar) ──────────────────────────────────────────────────

    public function test_reservar_incrementa_antes_de_evaluar_y_bloquea_al_pasar_el_maximo(): void
    {
        $clave = 'test:reservar:' . uniqid();

        $this->assertNull(AuthThrottle::reservar([$clave => [2, 60]], AuthThrottle::ABRIR));
        $this->assertNull(AuthThrottle::reservar([$clave => [2, 60]], AuthThrottle::ABRIR));
        $this->assertSame(2, RateLimiter::attempts($clave));
        $this->assertNotNull(AuthThrottle::reservar([$clave => [2, 60]], AuthThrottle::ABRIR));
        // Quien ya pasó el máximo también cuenta: una ráfaga no "recupera" intentos por llegar en paralelo.
        $this->assertSame(3, RateLimiter::attempts($clave));

        AuthThrottle::devolver($clave, 60);
        $this->assertSame(2, RateLimiter::attempts($clave));
    }

    public function test_un_login_fallido_deja_el_cupo_consumido_y_uno_correcto_lo_devuelve(): void
    {
        $user   = $this->makeUser();
        $ipKey  = 'login:ip:127.0.0.1';
        $mailKey = 'login:email:' . AuthThrottle::emailKey($user->email);

        $this->login($user->email, 'mala')->assertStatus(401);
        $this->assertSame(1, RateLimiter::attempts($ipKey));
        $this->assertSame(1, RateLimiter::attempts($mailKey));

        // El correcto devuelve SU cupo reservado (la IP conserva el fallo previo) y limpia el contador del correo.
        $this->login($user->email, 'ClaveOriginal99!')->assertStatus(200);
        $this->assertSame(1, RateLimiter::attempts($ipKey));
        $this->assertSame(0, RateLimiter::attempts($mailKey));
    }

    public function test_una_verificacion_mfa_correcta_devuelve_el_cupo_de_la_ip(): void
    {
        $user = $this->usuarioConMfa();
        $reto = (string) $this->login($user->email, 'ClaveOriginal99!')->json('mfa_challenge_id');

        $this->postJson('/api/auth/mfa/verify', ['mfa_challenge_id' => $reto, 'code' => $this->ultimoCodigoMfa()])->assertStatus(200);

        $this->assertSame(0, RateLimiter::attempts('mfa:verify:ip:127.0.0.1'));
    }

    // ── Olvidé mi contraseña ──────────────────────────────────────────────────────────────────────────────

    public function test_olvide_mi_contrasena_se_limita_por_ip_y_cuenta_todas_las_solicitudes(): void
    {
        config(['throttle.forgot_password.ip_max' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => "n{$i}-" . uniqid() . '@atlantic.test'])->assertStatus(200);
        }

        $this->postJson('/api/auth/forgot-password', ['email' => 'otro-' . uniqid() . '@atlantic.test'])
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_olvide_mi_contrasena_responde_igual_para_correos_existentes_e_inexistentes(): void
    {
        config(['throttle.forgot_password.email_max' => 2]);
        $existe = $this->makeUser();
        $noExiste = 'nadie-' . uniqid() . '@atlantic.test';

        $resp = ['existe' => [], 'noExiste' => []];
        foreach (['existe' => $existe->email, 'noExiste' => $noExiste] as $clave => $email) {
            for ($i = 0; $i < 3; $i++) {
                $r = $this->postJson('/api/auth/forgot-password', ['email' => $email]);
                $resp[$clave][] = [$r->getStatusCode(), $r->json('code'), array_keys($r->json())];
            }
        }

        $this->assertSame($resp['existe'], $resp['noExiste']);
        $this->assertSame([200, 200, 429], array_column($resp['existe'], 0));
    }

    // ── Configuración ─────────────────────────────────────────────────────────────────────────────────────

    public function test_los_valores_por_defecto_de_config_throttle_son_los_acordados(): void
    {
        // El setUp los sube para los tests; aquí se lee el archivo de configuración tal cual, sin overrides de entorno.
        $cfg = require base_path('config/throttle.php');

        $this->assertSame(10, $cfg['login']['ip_max']);
        $this->assertSame(5, $cfg['login']['email_max']);
        $this->assertSame(30, $cfg['refresh']['ip_max']);
        $this->assertSame(10, $cfg['mfa_verify']['ip_max']);
        $this->assertSame(3, $cfg['mfa_resend']['max']);
        $this->assertSame(600, $cfg['mfa_resend']['decay']);
        $this->assertSame(5, $cfg['forgot_password']['ip_max']);
        $this->assertSame(900, $cfg['forgot_password']['ip_decay']);
        $this->assertSame(3, $cfg['forgot_password']['email_max']);
        $this->assertSame(3600, $cfg['forgot_password']['email_decay']);
        $this->assertSame(5, $cfg['mfa_send']['max']);
        $this->assertSame(600, $cfg['mfa_send']['decay']);
    }
}
