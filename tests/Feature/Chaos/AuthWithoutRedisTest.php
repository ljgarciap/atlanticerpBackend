<?php

declare(strict_types=1);

namespace Tests\Feature\Chaos;

use App\Models\User;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Support\AuthThrottle;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Concerns\InjectsFaults;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-965 / SCRUM-1031 — qué le pasa a la AUTENTICACIÓN cuando Redis no responde (A-01 del threat model de Auth y R-05 de
 * docs/security/RESILIENCE-FINDINGS.md). Decisión del Arquitecto aprobada por Luis:
 *  - login, refresh, registro y "olvidé mi contraseña": FAIL-OPEN (nunca 500; el flujo sigue sin límite y se avisa);
 *  - verificación y reenvío de MFA y re-autenticación del operador: FAIL-CLOSED (503 `service_unavailable`);
 *  - `/api/health/deep` refleja la caída (503) mientras `/up` y `/api/health` siguen en 200.
 *
 * En test el cache es `array`, así que aquí se fuerza el store `redis` (`useRedisCache()`) para reproducir producción, donde
 * `AuthThrottle`/`RateLimiter` viven en Redis.
 */
final class AuthWithoutRedisTest extends FeatureTestCase
{
    use InjectsFaults;

    private const PASSWORD = 'ClaveOriginal99!';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Mail::fake();
        $this->user = User::create([
            'first_name' => 'Persona', 'last_name' => 'Caos', 'email' => uniqid('chaos-') . '@atlantic.test',
            'password_hash' => self::PASSWORD, 'security_level_id' => SecurityLevel::where('level', 3)->firstOrFail()->id,
            'is_active' => true,
        ]);
        $this->useRedisCache();
    }

    protected function tearDown(): void
    {
        $this->redisUp();
        // el cache redis de este stack local es compartido: se borran solo las claves de ESTE test, nunca un flush
        RateLimiter::clear('login:ip:127.0.0.1');
        RateLimiter::clear('login:email:' . AuthThrottle::emailKey($this->user->email));
        RateLimiter::clear('refresh:ip:127.0.0.1');
        parent::tearDown();
    }

    /** @return \Illuminate\Testing\TestResponse<\Illuminate\Http\JsonResponse> */
    private function login()
    {
        return $this->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => self::PASSWORD]);
    }

    public function test_con_redis_sano_el_login_funciona(): void
    {
        $this->login()->assertOk()->assertJsonStructure(['token', 'refresh_token']);
    }

    /** A-01 · SCRUM-1031 — fail-open: el login funciona (con credenciales correctas) y nunca da 500. */
    public function test_login_con_redis_caido_es_fail_open_y_no_da_500(): void
    {
        $this->redisDown();

        $this->login()->assertOk()->assertJsonStructure(['token', 'refresh_token']);
        $this->postJson('/api/auth/login', ['email' => $this->user->email, 'password' => 'incorrecta'])->assertStatus(401);
    }

    /** A-01 · SCRUM-1031 — fail-open: una caída de Redis ya no echa a todos de la sesión al renovar el token. */
    public function test_refresh_con_redis_caido_es_fail_open_y_no_da_500(): void
    {
        $refresh = (string) $this->login()->assertOk()->json('refresh_token');

        $this->redisDown();

        $this->assertNotSame(500, $this->postJson('/api/auth/refresh', ['refresh_token' => $refresh])->getStatusCode());
        $this->assertSame(401, $this->postJson('/api/auth/refresh', ['refresh_token' => 'invalido'])->getStatusCode());
    }

    /** A-01 · SCRUM-1031 — fail-closed: sin contador no se permite probar códigos MFA. */
    public function test_mfa_con_redis_caido_es_fail_closed_con_503(): void
    {
        $this->redisDown();

        $challenge = (string) \Illuminate\Support\Str::uuid();
        foreach (['/api/auth/mfa/verify' => ['mfa_challenge_id' => $challenge, 'code' => '123456'], '/api/auth/mfa/resend' => ['mfa_challenge_id' => $challenge]] as $url => $body) {
            $this->postJson($url, $body)
                ->assertStatus(503)
                ->assertJsonPath('code', 'service_unavailable')
                ->assertHeader('Retry-After', '30');
        }
    }

    public function test_reservar_aplica_la_politica_pedida_cuando_el_almacen_no_responde(): void
    {
        $this->redisDown();

        $this->assertNull(AuthThrottle::reservar(['chaos:k' => [1, 60]], AuthThrottle::ABRIR), 'fail-open: sin límite, sin excepción');
        $this->assertSame(0, AuthThrottle::contar('chaos:c', 60, AuthThrottle::ABRIR));

        $this->expectException(\App\Modules\Auth\Support\AuthThrottleUnavailableException::class);
        AuthThrottle::reservar(['chaos:k' => [1, 60]], AuthThrottle::CERRAR);
    }

    public function test_limpiar_y_devolver_no_lanzan_con_redis_caido(): void
    {
        $this->redisDown();

        AuthThrottle::limpiar('chaos:k');
        AuthThrottle::devolver('chaos:k', 60);
        AuthThrottle::golpear('chaos:k', 60);

        $this->addToAssertionCount(1);   // llegar aquí sin excepción es la aserción: un éxito nunca se convierte en 500
    }

    public function test_una_sesion_ya_abierta_sigue_funcionando_con_redis_caido(): void
    {
        $token = (string) $this->login()->assertOk()->json('token');

        $this->redisDown();

        $this->withHeaders(['Authorization' => "Bearer {$token}"])->getJson('/api/auth/me')->assertOk();
    }

    public function test_el_login_se_recupera_cuando_redis_vuelve(): void
    {
        $this->redisDown();
        $this->login()->assertOk();

        $this->redisUp();

        $this->login()->assertOk()->assertJsonStructure(['token']);
    }

    /** R-05 · SCRUM-1031 — /up y /api/health siguen en 200 (los usa Docker/nginx/deploy); el chequeo profundo sí ve la caída. */
    public function test_health_deep_da_503_con_redis_caido_y_los_health_simples_siguen_en_200(): void
    {
        $this->redisDown();

        $this->get('/up')->assertOk();
        $this->getJson('/api/health')->assertOk()->assertExactJson(['status' => 'ok']);
        $this->getJson('/api/health/deep')
            ->assertStatus(503)
            ->assertExactJson(['status' => 'degraded', 'checks' => ['db' => 'ok', 'redis' => 'fail']]);
    }

    public function test_health_deep_da_200_con_todo_sano_y_sin_detalles_internos(): void
    {
        $this->getJson('/api/health/deep')->assertOk()->assertExactJson(['status' => 'ok', 'checks' => ['db' => 'ok', 'redis' => 'ok']]);
    }
}
