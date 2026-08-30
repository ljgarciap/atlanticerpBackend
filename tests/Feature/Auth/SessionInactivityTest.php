<?php

namespace Tests\Feature\Auth;

use Illuminate\Support\Carbon;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-746 — Sesión expira por inactividad consecutiva (no absoluta desde el login).
 *
 * Ver docs/specs/scrum742-743-746-747-748-analista-spec.md (criterios de aceptación),
 * docs/architecture/scrum742-743-746-747-748-diseno.md (diseño técnico) y
 * docs/security/THREAT-MODEL-scrum746-session-inactivity.md (threat model — Hallazgos 1/2/3/5
 * cubiertos abajo).
 *
 * El "touch" de actividad vive en AuthController::refresh() (no en AuthenticateJwt) — cada test
 * simula actividad llamando POST /api/auth/refresh, nunca requests genéricos a /api/*.
 */
class SessionInactivityTest extends FeatureTestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array{token: string, refresh_token: string} */
    private function login(string $email = 'designer@atlantic.test'): array
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => $email,
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200);

        /** @var array{token: string, refresh_token: string} $data */
        $data = $response->json();

        return $data;
    }

    private function setSessionInactivityHours(int $hours): void
    {
        $token = $this->loginAs('gerencia@atlantic.test', 'Password123!', 'management');

        $this->patchJson('/api/settings/system', ['session_inactivity_hours' => $hours], $this->authHeaders($token))
            ->assertStatus(200)
            ->assertJsonPath('session_inactivity_hours', $hours);
    }

    // ── Escenario 1 — inactividad total expira a las 10h (default) ─────────────

    public function test_sesion_sin_ninguna_actividad_expira_a_las_10h_default(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'UTC'));
        $login = $this->login();

        // A las 9h59 sin ninguna otra actividad, todavía dentro del umbral default.
        Carbon::setTestNow(Carbon::parse('2026-08-13 17:59:00', 'UTC'));
        $this->postJson('/api/auth/refresh', ['refresh_token' => $login['refresh_token']])
            ->assertStatus(200);
    }

    public function test_sesion_sin_ninguna_actividad_rechazada_pasadas_10h(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'UTC'));
        $login = $this->login();

        // A las 10h01 sin ninguna otra actividad, ya expiró por inactividad.
        Carbon::setTestNow(Carbon::parse('2026-08-13 18:01:00', 'UTC'));
        $this->postJson('/api/auth/refresh', ['refresh_token' => $login['refresh_token']])
            ->assertStatus(401);
    }

    // ── Escenario 2 — actividad real reinicia el contador a 10h completas ──────

    public function test_actividad_real_reinicia_el_contador_a_10h_completas(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'UTC'));
        $login = $this->login();

        // Actividad a las 9h — dentro del umbral, refresca y reinicia el contador.
        Carbon::setTestNow(Carbon::parse('2026-08-13 17:00:00', 'UTC'));
        $refresh1 = $this->postJson('/api/auth/refresh', ['refresh_token' => $login['refresh_token']]);
        $refresh1->assertStatus(200);
        $secondRefreshToken = (string) $refresh1->json('refresh_token');

        // 9h50 después de ESE refresh (18h50 desde el login original) — si el contador no se
        // hubiera reiniciado (comparando contra el login original en vez de la última
        // actividad), esto ya habría expirado hace rato. Debe seguir vigente.
        Carbon::setTestNow(Carbon::parse('2026-08-14 02:50:00', 'UTC'));
        $this->postJson('/api/auth/refresh', ['refresh_token' => $secondRefreshToken])
            ->assertStatus(200);
    }

    public function test_tener_la_app_abierta_sin_interaccion_no_reinicia_el_contador(): void
    {
        // Criterio de aceptación 5 — sin ningún refresh de por medio (= sin actividad real
        // reportada), pasadas las 10h la sesión expira igual, sin importar que "la pestaña
        // siguiera abierta".
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'UTC'));
        $login = $this->login();

        Carbon::setTestNow(Carbon::parse('2026-08-13 19:00:00', 'UTC')); // +11h, sin actividad
        $this->postJson('/api/auth/refresh', ['refresh_token' => $login['refresh_token']])
            ->assertStatus(401);
    }

    // ── Escenario 3 — cambiar la config aplica de inmediato, sin depender del login original ──

    public function test_cambio_de_config_10_a_5h_aplica_sin_depender_del_login_original(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'UTC'));
        $login = $this->login();

        // Bajo el default (10h) esto seguiría vigente a las 6h — lo confirmamos antes de bajar
        // el umbral, para probar que el cambio de abajo es lo que realmente causa el rechazo.
        Carbon::setTestNow(Carbon::parse('2026-08-13 14:00:00', 'UTC')); // +6h
        $this->setSessionInactivityHours(5);

        // Mismo refresh_token del login original — nunca hubo actividad después del login, así
        // que last_activity_at sigue siendo la del login (8h00). A las +6h, bajo el NUEVO umbral
        // de 5h, la sesión ya debería estar expirada, aunque el login ocurrió bajo la config
        // vieja de 10h.
        $this->postJson('/api/auth/refresh', ['refresh_token' => $login['refresh_token']])
            ->assertStatus(401);
    }

    // ── TOCTOU — 2 requests concurrentes con el mismo refresh_token, solo una debe ganar ───────

    public function test_toctou_dos_requests_concurrentes_en_el_borde_del_umbral_solo_una_gana(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'UTC'));
        $login = $this->login();

        // Justo en el borde del umbral default (10h) — todavía vigente por 1 minuto.
        Carbon::setTestNow(Carbon::parse('2026-08-13 17:59:00', 'UTC'));

        $first  = $this->postJson('/api/auth/refresh', ['refresh_token' => $login['refresh_token']]);
        $second = $this->postJson('/api/auth/refresh', ['refresh_token' => $login['refresh_token']]);

        // El gate atómico (UPDATE...RETURNING) + la revocación atómica (WHERE revoked = false)
        // garantizan que, aunque ambos requests lleguen con el mismo token todavía vigente, solo
        // uno puede rotar con éxito — el segundo encuentra el token ya revocado por el primero.
        $statuses = [$first->getStatusCode(), $second->getStatusCode()];
        sort($statuses);
        $this->assertSame([200, 401], $statuses);
    }

    // ── Rango inválido de config — Threat Model Hallazgo 5 (DoS por 0/negativo) ────────────────

    public function test_session_inactivity_hours_0_es_rechazado(): void
    {
        $token = $this->loginAs('gerencia@atlantic.test', 'Password123!', 'management');

        $this->patchJson('/api/settings/system', ['session_inactivity_hours' => 0], $this->authHeaders($token))
            ->assertStatus(422);
    }

    public function test_session_inactivity_hours_negativo_es_rechazado(): void
    {
        $token = $this->loginAs('gerencia@atlantic.test', 'Password123!', 'management');

        $this->patchJson('/api/settings/system', ['session_inactivity_hours' => -5], $this->authHeaders($token))
            ->assertStatus(422);
    }

    public function test_session_inactivity_hours_por_encima_del_maximo_es_rechazado(): void
    {
        $token = $this->loginAs('gerencia@atlantic.test', 'Password123!', 'management');

        $this->patchJson('/api/settings/system', ['session_inactivity_hours' => 721], $this->authHeaders($token))
            ->assertStatus(422);
    }

    public function test_valor_invalido_no_altera_el_default_persistido(): void
    {
        $token = $this->loginAs('gerencia@atlantic.test', 'Password123!', 'management');

        $this->patchJson('/api/settings/system', ['session_inactivity_hours' => 0], $this->authHeaders($token))
            ->assertStatus(422);

        $this->getJson('/api/settings/system', $this->authHeaders($token))
            ->assertStatus(200)
            ->assertJsonPath('session_inactivity_hours', 10);
    }

    // ── Techo absoluto de 30 días — sobrevive múltiples rotaciones ──────────────────────────────

    public function test_techo_absoluto_30_dias_sobrevive_multiples_rotaciones_sin_resetearse(): void
    {
        // Umbral de inactividad alto a propósito (720h = 30 días, el máximo permitido) para que
        // NINGÚN gap entre rotaciones dispare el gate de inactividad — así lo único que puede
        // rechazar el refresh final es el techo absoluto de 30 días desde el LOGIN ORIGINAL.
        $this->setSessionInactivityHours(720);

        Carbon::setTestNow(Carbon::parse('2026-08-13 08:00:00', 'UTC'));
        $login = $this->login();
        $refreshToken = $login['refresh_token'];

        // 3 rotaciones espaciadas 8 días entre sí (24 días acumulados) — cada gap individual
        // (8 días = 192h) está muy por debajo del umbral de inactividad de 720h, así que si el
        // techo de 30 días se calculara mal (reseteado en cada rotación en vez de propagado
        // desde el login original), estas 3 rotaciones pasarían igual sin distinguir el bug.
        foreach ([8, 8, 8] as $daysForward) {
            Carbon::setTestNow(Carbon::now()->addDays($daysForward));
            $response = $this->postJson('/api/auth/refresh', ['refresh_token' => $refreshToken]);
            $response->assertStatus(200);
            $refreshToken = (string) $response->json('refresh_token');
        }

        // Las 3 rotaciones (24 días acumulados) ya pasaron arriba (`assertStatus(200)` en el
        // loop) — control positivo de que la sesión sigue viva bien dentro del techo de 30 días.

        // Un cuarto salto de 7 días más (31 días acumulados desde el LOGIN ORIGINAL) — cada gap
        // individual sigue siendo trivial para el umbral de inactividad (720h), pero el techo
        // absoluto de 30 días desde el login original ya se cumplió. Si login_at se hubiera
        // reseteado en alguna rotación anterior (bug), este refresh pasaría incorrectamente.
        Carbon::setTestNow(Carbon::now()->addDays(7));
        $this->postJson('/api/auth/refresh', ['refresh_token' => $refreshToken])
            ->assertStatus(401);
    }

}
