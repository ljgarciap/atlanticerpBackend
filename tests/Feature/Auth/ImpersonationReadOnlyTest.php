<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\ImpersonationSession;
use App\Modules\Auth\Models\Role;
use App\Shared\Auth\JwtClaimsBuilder;
use App\Shared\Auth\JwtService;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1158 (T3) — el token de «Ver como usuario» es de SOLO LECTURA y se revalida en cada request. Cubre CA2, CA9–CA13, CA15, CA16 de la spec y
 * las condiciones C5 y C8 del threat model.
 */
final class ImpersonationReadOnlyTest extends FeatureTestCase
{
    private const PASSWORD = 'Password123!';
    private const REASON   = 'SCRUM-1158 reproducir pantalla vacia';

    protected function setUp(): void
    {
        parent::setUp();
        $this->reMakeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function userId(string $email): int
    {
        $this->reMakeCurrent();

        return (int) User::where('email', $email)->value('id');
    }

    /** @return array{0: array<string, string>, 1: string} headers del token de impersonación y el id de la sesión */
    private function beginImpersonation(string $targetEmail = 'designer@atlantic.test'): array
    {
        $admin = $this->authHeaders($this->loginAs('superadmin@atlantic.test', self::PASSWORD, 'superadmin'));
        $response = $this->postJson('/api/users/' . $this->userId($targetEmail) . '/impersonate', [
            'reason' => self::REASON, 'operator_password' => self::PASSWORD,
        ], $admin)->assertStatus(201);

        return [$this->authHeaders((string) $response->json('token')), (string) $response->json('user.impersonation.id')];
    }

    private function sessionRow(string $id): ImpersonationSession
    {
        $this->reMakeCurrent();

        return ImpersonationSession::findOrFail($id);
    }

    // ── Lectura permitida con la identidad del objetivo (CA2) ─────────────────────────────────────────────

    public function test_las_lecturas_funcionan_con_la_identidad_del_objetivo(): void
    {
        [$headers] = $this->beginImpersonation();

        $this->getJson('/api/auth/me', $headers)->assertOk()
            ->assertJsonPath('email', 'designer@atlantic.test')
            ->assertJsonPath('role', 'vendedor')
            ->assertJsonPath('impersonation.actor_name', 'Super Admin')
            ->assertJsonPath('impersonation.target_name', 'Designer Demo');
    }

    public function test_el_token_normal_no_trae_bloque_de_impersonacion_en_me(): void
    {
        $headers = $this->authHeaders($this->loginAs('designer@atlantic.test', self::PASSWORD, 'designer'));

        $this->getJson('/api/auth/me', $headers)->assertOk()->assertJsonPath('impersonation', null);
    }

    public function test_el_objetivo_no_alcanza_lo_exclusivo_de_superadmin_aunque_el_actor_si_pueda(): void
    {
        [$headers] = $this->beginImpersonation();

        $this->getJson('/api/admin/security/impersonations', $headers)->assertStatus(403);
        $this->getJson('/api/admin/global-settings', $headers)->assertStatus(403);
        $this->getJson('/api/admin/audit/logs', $headers)->assertStatus(403);
    }

    // ── CA9 / CA10: escrituras bloqueadas ─────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: string, 1: string, 2: array<string, mixed>}> */
    public static function escrituras(): array
    {
        return [
            'POST de CRM'                 => ['POST', '/api/crm/contacts', ['nombre' => 'Intento', 'tipo' => 'contacto']],
            'PUT de CRM'                  => ['PUT', '/api/crm/contacts/1', ['nombre' => 'Intento']],
            'PATCH de perfil'             => ['PATCH', '/api/auth/profile', ['phone' => '+507 6000-0000']],
            'DELETE de CRM'               => ['DELETE', '/api/crm/contacts/1', []],
            'cambio de contraseña'        => ['PUT', '/api/auth/password', ['current_password' => 'Password123!', 'password' => 'Nueva-Clave-9!x', 'password_confirmation' => 'Nueva-Clave-9!x']],
            'activar MFA'                 => ['POST', '/api/auth/mfa/enable', []],
            'desactivar MFA'              => ['POST', '/api/auth/mfa/disable', ['password' => 'Password123!']],
            'preferencias'                => ['PATCH', '/api/settings/preferences', ['theme' => 'dark']],
            'reset de contraseña ajeno'   => ['POST', '/api/users/1/reset-password', ['operator_password' => 'Password123!']],
            'reset masivo'                => ['POST', '/api/users/reset-passwords', ['dry_run' => true]],
            'iniciar otra impersonación'  => ['POST', '/api/users/1/impersonate', ['reason' => self::REASON, 'operator_password' => self::PASSWORD]],
            'refresh con cuerpo vacío'    => ['POST', '/api/auth/refresh', []],
        ];
    }

    /** @param array<string, mixed> $body */
    #[DataProvider('escrituras')]
    public function test_toda_escritura_responde_403_impersonation_read_only(string $method, string $url, array $body): void
    {
        [$headers] = $this->beginImpersonation();

        $response = $this->json($method, $url, $body, $headers);

        // `refresh` es una ruta pública sin el middleware JWT: no se bloquea por impersonación, pero tampoco puede renovar nada (no hay refresh token).
        if ($url === '/api/auth/refresh') {
            $this->assertContains($response->getStatusCode(), [401, 422], 'sin refresh token no hay nada que renovar');

            return;
        }

        $response->assertStatus(403)->assertJsonPath('code', 'impersonation_read_only');
    }

    public function test_una_escritura_bloqueada_no_cambia_nada_en_la_base(): void
    {
        [$headers] = $this->beginImpersonation();
        $designer = $this->userId('designer@atlantic.test');
        $before = DB::table('atlantic_auth.users')->where('id', $designer)->first();

        $this->patchJson('/api/auth/profile', ['phone' => '+507 6111-1111', 'first_name' => 'Hackeado'], $headers)->assertStatus(403);
        $this->putJson('/api/auth/password', ['current_password' => self::PASSWORD, 'password' => 'Nueva-Clave-9!x', 'password_confirmation' => 'Nueva-Clave-9!x'], $headers)->assertStatus(403);

        $after = DB::table('atlantic_auth.users')->where('id', $designer)->first();
        $this->assertEquals($before, $after);
        // La contraseña original del objetivo sigue funcionando.
        $this->loginAs('designer@atlantic.test', self::PASSWORD, 'designer');
    }

    public function test_un_post_con_method_override_a_get_tambien_se_bloquea(): void
    {
        [$headers] = $this->beginImpersonation();

        $this->post('/api/auth/me', ['_method' => 'GET'], $headers + ['Accept' => 'application/json'])
            ->assertStatus(403)->assertJsonPath('code', 'impersonation_read_only');
    }

    public function test_cada_intento_bloqueado_queda_en_la_auditoria(): void
    {
        [$headers] = $this->beginImpersonation();

        $this->patchJson('/api/auth/profile', ['phone' => '+507 6111-1111'], $headers)->assertStatus(403);
        $this->deleteJson('/api/crm/contacts/1', [], $headers)->assertStatus(403);

        $this->reMakeCurrent();
        $logs = AuditLog::query()->where('action', AuditService::AUTH_IMPERSONATION_WRITE_BLOCKED)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame('api/auth/profile', $logs[0]->entity_id);
        $this->assertSame('PATCH', $logs[0]->metadata['method']);
        $this->assertSame('DELETE', $logs[1]->metadata['method']);
    }

    // ── Red de seguridad para un GET futuro que escriba (sin uso en rutas reales desde SCRUM-1180) ────────

    public function test_el_alias_no_impersonation_sigue_negando_un_get_en_la_sesion_pero_no_fuera_de_ella(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['api', 'no_impersonation'])->get('/api/_test/get-que-escribe', fn () => response()->json(['ok' => true]));

        $own = $this->authHeaders($this->loginAs('designer@atlantic.test', self::PASSWORD, 'designer'));
        $this->getJson('/api/_test/get-que-escribe', $own)->assertOk()->assertJsonPath('ok', true);

        [$headers] = $this->beginImpersonation();
        $this->getJson('/api/_test/get-que-escribe', $headers)->assertStatus(403)->assertJsonPath('code', 'impersonation_read_only');
    }

    // ── CA13: cierre ──────────────────────────────────────────────────────────────────────────────────────

    public function test_stop_cierra_la_sesion_y_el_mismo_token_deja_de_valer_de_inmediato(): void
    {
        [$headers, $sessionId] = $this->beginImpersonation();
        $this->getJson('/api/auth/me', $headers)->assertOk();

        $this->postJson('/api/auth/impersonation/stop', [], $headers)->assertNoContent();

        $session = $this->sessionRow($sessionId);
        $this->assertNotNull($session->ended_at);
        $this->assertSame('manual', $session->ended_reason);
        $this->assertSame($this->userId('superadmin@atlantic.test'), $session->ended_by);

        $this->getJson('/api/auth/me', $headers)->assertStatus(401)->assertJsonPath('code', 'impersonation_ended');
        $this->postJson('/api/auth/impersonation/stop', [], $headers)->assertStatus(401)->assertJsonPath('code', 'impersonation_ended');

        $this->reMakeCurrent();
        $this->assertSame(1, AuditLog::query()->where('action', AuditService::AUTH_IMPERSONATION_ENDED)->count(), 'el cierre se audita una sola vez');
    }

    public function test_stop_con_un_token_normal_responde_409(): void
    {
        $headers = $this->authHeaders($this->loginAs('designer@atlantic.test', self::PASSWORD, 'designer'));

        $this->postJson('/api/auth/impersonation/stop', [], $headers)->assertStatus(409)->assertJsonPath('code', 'not_impersonating');
    }

    public function test_stop_sin_token_responde_401(): void
    {
        $this->postJson('/api/auth/impersonation/stop')->assertStatus(401);
    }

    // ── CA12: vencimiento ─────────────────────────────────────────────────────────────────────────────────

    public function test_una_sesion_vencida_responde_401_y_se_cierra_como_expired(): void
    {
        [$headers, $sessionId] = $this->beginImpersonation();
        $this->reMakeCurrent();
        DB::table('atlantic_auth.impersonation_sessions')->where('id', $sessionId)->update(['expires_at' => now()->subMinute()]);

        $this->getJson('/api/auth/me', $headers)->assertStatus(401)->assertJsonPath('code', 'impersonation_ended');

        $session = $this->sessionRow($sessionId);
        $this->assertSame('expired', $session->ended_reason);
        $this->assertNull($session->ended_by);
    }

    // ── C5: revalidación en cada request ──────────────────────────────────────────────────────────────────

    public function test_si_se_desactiva_al_actor_la_sesion_muere_y_se_cierra_como_revoked(): void
    {
        [$headers, $sessionId] = $this->beginImpersonation();
        DB::table('atlantic_auth.users')->where('email', 'superadmin@atlantic.test')->update(['is_active' => false]);

        $this->getJson('/api/auth/me', $headers)->assertStatus(401)->assertJsonPath('code', 'impersonation_ended');
        $this->assertSame('revoked', $this->sessionRow($sessionId)->ended_reason);
    }

    public function test_si_se_degrada_al_actor_la_sesion_muere(): void
    {
        [$headers, $sessionId] = $this->beginImpersonation();
        $this->reMakeCurrent();
        User::where('email', 'superadmin@atlantic.test')->firstOrFail()
            ->forceFill(['role_id' => Role::where('key', 'management')->value('id')])->save();

        $this->getJson('/api/auth/me', $headers)->assertStatus(401)->assertJsonPath('code', 'impersonation_ended');
        $this->assertSame('revoked', $this->sessionRow($sessionId)->ended_reason);
    }

    public function test_si_se_bloquea_por_seguridad_al_actor_la_sesion_muere(): void
    {
        [$headers, $sessionId] = $this->beginImpersonation();
        DB::table('atlantic_auth.users')->where('email', 'superadmin@atlantic.test')->update(['security_blocked_at' => now()]);

        $this->getJson('/api/auth/me', $headers)->assertStatus(401)->assertJsonPath('code', 'impersonation_ended');
        $this->assertSame('revoked', $this->sessionRow($sessionId)->ended_reason);
    }

    public function test_si_se_bloquea_por_seguridad_al_objetivo_la_sesion_muere_como_target_blocked(): void
    {
        [$headers, $sessionId] = $this->beginImpersonation();
        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->update(['security_blocked_at' => now()]);

        $this->getJson('/api/auth/me', $headers)->assertStatus(401)->assertJsonPath('code', 'impersonation_ended');
        $this->assertSame('target_blocked', $this->sessionRow($sessionId)->ended_reason);
    }

    /** Senior Review B2: desactivar al objetivo cierra la sesión (401 `impersonation_ended`) y reactivarlo después NO revive el token. */
    public function test_si_se_desactiva_al_objetivo_la_sesion_se_cierra_y_reactivarlo_no_la_revive(): void
    {
        [$headers, $sessionId] = $this->beginImpersonation();
        $this->getJson('/api/auth/me', $headers)->assertOk();

        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->update(['is_active' => false]);
        $this->getJson('/api/auth/me', $headers)->assertStatus(401)->assertJsonPath('code', 'impersonation_ended');

        $session = $this->sessionRow($sessionId);
        $this->assertSame('target_blocked', $session->ended_reason);
        $this->assertSame('ended', $session->status(), 'ya no figura como «active» en el listado');

        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->update(['is_active' => true]);
        $this->getJson('/api/auth/me', $headers)->assertStatus(401)->assertJsonPath('code', 'impersonation_ended');
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(401)->assertJsonPath('code', 'impersonation_ended');
    }

    public function test_sin_act_un_usuario_desactivado_sigue_recibiendo_el_401_generico(): void
    {
        $headers = $this->authHeaders($this->loginAs('designer@atlantic.test', self::PASSWORD, 'designer'));
        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->update(['is_active' => false]);

        $res = $this->getJson('/api/auth/me', $headers)->assertStatus(401);
        $this->assertNull($res->json('code'), 'sin act no hay código de impersonación');
    }

    /** CA15: el reset administrativo de contraseña del objetivo invalida el token (regla `iat`). */
    public function test_un_reset_de_contrasena_del_objetivo_invalida_la_sesion(): void
    {
        [$headers] = $this->beginImpersonation();
        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->update(['password_reset_at' => now()->addSeconds(5)]);

        $this->getJson('/api/auth/me', $headers)->assertStatus(401);
    }

    public function test_un_cambio_de_contrasena_del_objetivo_invalida_la_sesion(): void
    {
        [$headers] = $this->beginImpersonation();
        DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->update(['password_changed_at' => now()->addSeconds(5)]);

        $this->getJson('/api/auth/me', $headers)->assertStatus(401);
    }

    // ── Tokens manipulados ────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: callable(array<string, mixed>, string): array<string, mixed>}> */
    public static function actsManipulados(): array
    {
        return [
            'act null'                     => [static fn (array $c, string $id): array => ['act' => null] + $c],
            'act vacío'                    => [static fn (array $c, string $id): array => ['act' => []] + $c],
            'act sin imp_id'               => [static fn (array $c, string $id): array => ['act' => ['sub' => $c['act']['sub']]] + $c],
            'act sin sub'                  => [static fn (array $c, string $id): array => ['act' => ['imp_id' => $id]] + $c],
            'imp_id que no es uuid'        => [static fn (array $c, string $id): array => ['act' => ['sub' => $c['act']['sub'], 'imp_id' => 'no-es-uuid']] + $c],
            'imp_id numérico'              => [static fn (array $c, string $id): array => ['act' => ['sub' => $c['act']['sub'], 'imp_id' => 12345]] + $c],
            'sesión inexistente'           => [static fn (array $c, string $id): array => ['act' => ['sub' => $c['act']['sub'], 'imp_id' => (string) Str::uuid()]] + $c],
            'actor distinto al de la sesión' => [static fn (array $c, string $id): array => ['act' => ['sub' => 999999, 'imp_id' => $id]] + $c],
            'act es un texto'              => [static fn (array $c, string $id): array => ['act' => 'superadmin'] + $c],
        ];
    }

    /** @param callable(array<string, mixed>, string): array<string, mixed> $tamper */
    #[DataProvider('actsManipulados')]
    public function test_un_act_manipulado_se_rechaza_con_401(callable $tamper): void
    {
        [, $sessionId] = $this->beginImpersonation();
        $claims = $this->claimsOf($sessionId);

        $forged = app(JwtService::class)->encode($tamper($claims, $sessionId));

        $this->getJson('/api/auth/me', $this->authHeaders($forged))->assertStatus(401);
    }

    public function test_una_sesion_real_combinada_con_otro_sub_se_rechaza(): void
    {
        [, $sessionId] = $this->beginImpersonation('designer@atlantic.test');
        $this->reMakeCurrent();
        $supervisor = User::where('email', 'supervisor@atlantic.test')->firstOrFail();
        $claims = [...app(JwtClaimsBuilder::class)->claims($supervisor, 'atlantic'), 'act' => $this->claimsOf($sessionId)['act']];

        $this->getJson('/api/auth/me', $this->authHeaders(app(JwtService::class)->encode($claims)))->assertStatus(401);
    }

    public function test_un_token_normal_firmado_con_act_pero_sin_sesion_no_se_vuelve_valido_por_ser_de_superadmin(): void
    {
        $this->reMakeCurrent();
        $superadmin = User::where('email', 'superadmin@atlantic.test')->firstOrFail();
        $claims = [...app(JwtClaimsBuilder::class)->claims($superadmin, 'atlantic'), 'act' => ['sub' => $superadmin->id, 'imp_id' => (string) Str::uuid()]];

        $this->getJson('/api/auth/me', $this->authHeaders(app(JwtService::class)->encode($claims)))->assertStatus(401);
    }

    /** @return array<string, mixed> */
    private function claimsOf(string $sessionId): array
    {
        $session = $this->sessionRow($sessionId);
        $this->reMakeCurrent();
        $target = User::findOrFail($session->target_id);
        $actor  = User::findOrFail($session->actor_id);

        return app(JwtClaimsBuilder::class)->impersonationClaims($target, 'atlantic', $actor, $sessionId);
    }

    // ── C8 y CA16 ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_un_token_de_impersonacion_no_tiene_refresh_token_asociado(): void
    {
        $this->reMakeCurrent();
        $before = DB::table('atlantic_auth.refresh_tokens')->count();

        $this->beginImpersonation();

        $this->reMakeCurrent();
        // El login del superadmin crea UNO (el suyo); la impersonación no crea ninguno más.
        $this->assertSame($before + 1, DB::table('atlantic_auth.refresh_tokens')->count());
    }

    public function test_las_requests_normales_no_tocan_la_tabla_de_sesiones(): void
    {
        $headers = $this->authHeaders($this->loginAs('designer@atlantic.test', self::PASSWORD, 'designer'));
        $this->reMakeCurrent();

        DB::enableQueryLog();
        $this->getJson('/api/auth/me', $headers)->assertOk();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        $this->assertNotEmpty($queries);
        foreach ($queries as $query) {
            $this->assertStringNotContainsString('impersonation_sessions', $query, 'una request sin `act` no debe consultar sesiones de impersonación');
        }
    }

    public function test_una_request_con_impersonacion_si_consulta_la_sesion(): void
    {
        [$headers] = $this->beginImpersonation();
        $this->reMakeCurrent();

        DB::enableQueryLog();
        $this->getJson('/api/auth/me', $headers)->assertOk();
        $touched = collect(DB::getQueryLog())->contains(fn (array $q): bool => str_contains((string) $q['query'], 'impersonation_sessions'));
        DB::disableQueryLog();

        $this->assertTrue($touched);
    }
}
