<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\ImpersonationSession;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Models\GlobalSetting;
use App\Shared\Auth\JwtService;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1158 (T2) — inicio de «Ver como usuario»: `POST /api/users/{id}/impersonate`. Cubre CA1 y CA5–CA8 de la spec, el orden fijo de las
 * reglas del diseño (§3.4) y la condición C3 (cupo de contraseñas incorrectas propio, separado del reset de contraseñas).
 */
final class ImpersonationStartTest extends FeatureTestCase
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

    /** @return array<string, string> */
    private function superadmin(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', self::PASSWORD, 'superadmin'));
    }

    private function userId(string $email): int
    {
        $this->reMakeCurrent();

        return (int) User::where('email', $email)->value('id');
    }

    /** @param array<string, mixed> $overrides */
    private function makeUser(array $overrides = []): User
    {
        $this->reMakeCurrent();

        return User::create([
            'first_name' => 'Persona', 'last_name' => 'Prueba', 'email' => uniqid('imp-') . '@atlantic.test',
            'password_hash' => self::PASSWORD, 'security_level_id' => SecurityLevel::where('level', 3)->firstOrFail()->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /** @param array<string, mixed> $body */
    private function start(int $targetId, array $body = [], ?array $headers = null)
    {
        return $this->postJson(
            "/api/users/{$targetId}/impersonate",
            $body + ['reason' => self::REASON, 'operator_password' => self::PASSWORD],
            $headers ?? $this->superadmin(),
        );
    }

    private function sessionCount(): int
    {
        $this->reMakeCurrent();

        return ImpersonationSession::query()->count();
    }

    // ── CA1: camino feliz ─────────────────────────────────────────────────────────────────────────────────

    public function test_inicia_la_sesion_y_emite_un_token_con_la_identidad_del_objetivo_y_el_actor_real(): void
    {
        $designer   = $this->userId('vendedor@atlantic.test');
        $superadmin = $this->userId('superadmin@atlantic.test');

        $response = $this->start($designer)->assertStatus(201);

        $response->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('expires_in_ms', 30 * 60_000)
            ->assertJsonPath('user.id', $designer)
            ->assertJsonPath('user.email', 'vendedor@atlantic.test')
            ->assertJsonPath('user.impersonation.actor_id', $superadmin);
        // RN4 / C8: sin refresh token, ni en el cuerpo ni dentro del usuario.
        $this->assertArrayNotHasKey('refresh_token', $response->json());
        $this->assertArrayNotHasKey('refresh_token', (array) $response->json('user'));

        $claims = app(JwtService::class)->decode((string) $response->json('token'));
        $this->assertNotNull($claims);
        $this->assertSame($designer, (int) $claims['sub'], 'sub = el objetivo');
        $this->assertSame($superadmin, (int) $claims['act']['sub'], 'act.sub = el actor real');
        $this->assertSame($response->json('user.impersonation.id'), $claims['act']['imp_id']);
        $this->assertSame('atlantic', $claims['tenant']);
        $this->assertSame(30 * 60, (int) $claims['exp'] - (int) $claims['iat']);

        // La vista sale idéntica a la del objetivo: mismos permisos, módulos, flags y rol que en su propio login.
        $own = app(JwtService::class)->decode($this->loginAs('vendedor@atlantic.test', self::PASSWORD, 'designer'));
        foreach (['role', 'role_id', 'permissions', 'modules', 'menuVisibility', 'flags', 'security_level', 'email'] as $claim) {
            $this->assertEquals($own[$claim], $claims[$claim], "claim {$claim} igual al del propio objetivo");
        }
        $this->assertArrayNotHasKey('act', $own);
    }

    public function test_la_respuesta_trae_el_bloque_de_impersonacion_del_contrato(): void
    {
        $response = $this->start($this->userId('vendedor@atlantic.test'))->assertStatus(201);

        $block = $response->json('user.impersonation');
        $this->assertEqualsCanonicalizing(['id', 'actor_id', 'actor_name', 'target_name', 'expires_at'], array_keys($block));
        $this->assertSame('Super Admin', $block['actor_name']);
        $this->assertSame('Vendedor Demo', $block['target_name']);
        $this->assertNotFalse(strtotime((string) $block['expires_at']));
    }

    public function test_la_sesion_queda_registrada_con_motivo_ip_y_vencimiento(): void
    {
        $designer = $this->userId('vendedor@atlantic.test');
        $response = $this->start($designer, ['reason' => '  SCRUM-1158 reproducir  ']);

        $this->reMakeCurrent();
        $session = ImpersonationSession::findOrFail($response->json('user.impersonation.id'));
        $this->assertSame($this->userId('superadmin@atlantic.test'), $session->actor_id);
        $this->assertSame($designer, $session->target_id);
        $this->assertSame('SCRUM-1158 reproducir', $session->reason, 'el motivo se guarda sin espacios sobrantes');
        $this->assertNull($session->ended_at);
        $this->assertNotNull($session->request_id);
        $this->assertEqualsWithDelta(30 * 60, now()->diffInSeconds($session->expires_at, false), 5);
    }

    public function test_el_inicio_queda_auditado_a_nombre_del_actor(): void
    {
        $designer   = $this->userId('vendedor@atlantic.test');
        $superadmin = $this->userId('superadmin@atlantic.test');
        $response   = $this->start($designer)->assertStatus(201);

        $this->reMakeCurrent();
        $log = AuditLog::query()->where('action', AuditService::AUTH_IMPERSONATION_STARTED)->firstOrFail();
        $this->assertSame($superadmin, $log->user_id);
        $this->assertSame($response->json('user.impersonation.id'), $log->entity_id);
        $this->assertSame($designer, $log->metadata['target_user_id']);
        $this->assertSame(self::REASON, $log->metadata['reason']);
        $this->assertStringNotContainsString(self::PASSWORD, (string) json_encode($log->toArray()), 'la contraseña nunca se registra');
    }

    public function test_la_duracion_sale_del_parametro_configurable(): void
    {
        GlobalSetting::updateOrCreate(['key' => 'impersonation_max_minutes'], ['value' => '10', 'updated_at' => now()]);

        $response = $this->start($this->userId('vendedor@atlantic.test'))->assertStatus(201);

        $response->assertJsonPath('expires_in_ms', 10 * 60_000);
        $claims = app(JwtService::class)->decode((string) $response->json('token'));
        $this->assertSame(10 * 60, (int) $claims['exp'] - (int) $claims['iat']);
    }

    // ── CA5: motivo ───────────────────────────────────────────────────────────────────────────────────────

    public function test_sin_motivo_o_con_motivo_corto_responde_422_y_no_crea_sesion(): void
    {
        $designer = $this->userId('vendedor@atlantic.test');
        $headers  = $this->superadmin();

        $this->postJson("/api/users/{$designer}/impersonate", ['operator_password' => self::PASSWORD], $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/users/{$designer}/impersonate", ['reason' => '', 'operator_password' => self::PASSWORD], $headers)
            ->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->start($designer, ['reason' => 'corto'], $headers)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        // 9 caracteres + espacios que no cuentan.
        $this->start($designer, ['reason' => '   123456789   '], $headers)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->start($designer, ['reason' => str_repeat('x', 501)], $headers)->assertStatus(422)->assertJsonValidationErrors(['reason']);

        $this->assertSame(0, $this->sessionCount());
    }

    public function test_el_largo_minimo_del_motivo_sale_del_parametro(): void
    {
        GlobalSetting::updateOrCreate(['key' => 'impersonation_reason_min_length'], ['value' => '25', 'updated_at' => now()]);
        $designer = $this->userId('vendedor@atlantic.test');
        $headers  = $this->superadmin();

        $this->start($designer, ['reason' => 'SCRUM-1158 reproducir'], $headers)->assertStatus(422)->assertJsonValidationErrors(['reason']);
        $this->start($designer, ['reason' => 'SCRUM-1158 reproducir la pantalla vacia'], $headers)->assertStatus(201);
    }

    // ── CA6: contraseña del actor ─────────────────────────────────────────────────────────────────────────

    public function test_sin_contrasena_o_con_contrasena_incorrecta_no_se_crea_la_sesion(): void
    {
        $designer = $this->userId('vendedor@atlantic.test');
        $headers  = $this->superadmin();

        $this->postJson("/api/users/{$designer}/impersonate", ['reason' => self::REASON], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'operator_password_required');
        $this->start($designer, ['operator_password' => 'incorrecta'], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'operator_reauth_failed');

        $this->assertSame(0, $this->sessionCount());
        $this->reMakeCurrent();
        $denied = AuditLog::query()->where('action', AuditService::AUTH_IMPERSONATION_DENIED)->get();
        $this->assertCount(1, $denied, 'solo la contraseña incorrecta se audita como fallo; la ausente es un error de formulario');
        $this->assertSame('operator_reauth_failed', $denied->first()->metadata['denied_reason']);
        $this->assertSame($this->userId('superadmin@atlantic.test'), $denied->first()->user_id);
    }

    public function test_los_intentos_incorrectos_se_limitan_y_el_bloqueo_responde_429(): void
    {
        $designer = $this->userId('vendedor@atlantic.test');
        $headers  = $this->superadmin();

        for ($i = 0; $i < 5; $i++) {
            $this->start($designer, ['operator_password' => 'incorrecta'], $headers)->assertStatus(422);
        }
        // Con el cupo agotado ni siquiera la contraseña correcta pasa.
        $this->start($designer, [], $headers)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        $this->assertSame(0, $this->sessionCount());
    }

    /** C3: el cupo de contraseñas incorrectas de «Ver como usuario» NO es el del reset de contraseñas (y viceversa). */
    public function test_el_cupo_de_contrasenas_incorrectas_es_independiente_del_reset_de_contrasenas(): void
    {
        $designer = $this->userId('vendedor@atlantic.test');
        $headers  = $this->superadmin();

        // Agota el cupo de «Ver como usuario».
        for ($i = 0; $i < 5; $i++) {
            $this->start($designer, ['operator_password' => 'incorrecta'], $headers)->assertStatus(422);
        }
        $this->start($designer, [], $headers)->assertStatus(429);

        // El reset masivo (exige la contraseña del operador) sigue disponible con su propio cupo.
        $count = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->json('data.count');
        $this->postJson('/api/users/reset-passwords', ['confirm' => "RESETEAR {$count}", 'operator_password' => 'incorrecta'], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'operator_reauth_failed');
    }

    public function test_agotar_el_cupo_del_reset_de_contrasenas_no_bloquea_ver_como_usuario(): void
    {
        $designer = $this->userId('vendedor@atlantic.test');
        $headers  = $this->superadmin();
        $count    = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->json('data.count');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/users/reset-passwords', ['confirm' => "RESETEAR {$count}", 'operator_password' => 'incorrecta'], $headers)->assertStatus(422);
        }
        $this->postJson('/api/users/reset-passwords', ['confirm' => "RESETEAR {$count}", 'operator_password' => self::PASSWORD], $headers)->assertStatus(429);

        $this->start($designer, [], $headers)->assertStatus(201);
    }

    public function test_una_contrasena_correcta_devuelve_el_cupo(): void
    {
        config(['throttle.impersonation.per_minute' => 100]); // aísla el cupo de contraseñas del límite de peticiones por minuto
        $designer = $this->userId('vendedor@atlantic.test');
        $headers  = $this->superadmin();

        for ($round = 0; $round < 3; $round++) {
            for ($i = 0; $i < 4; $i++) {
                $this->start($designer, ['operator_password' => 'incorrecta'], $headers)->assertStatus(422);
            }
            $this->start($designer, [], $headers)->assertStatus(201);
        }
    }

    public function test_el_limite_de_inicios_por_minuto_es_por_usuario(): void
    {
        config(['throttle.impersonation.per_minute' => 2]);
        $designer = $this->userId('vendedor@atlantic.test');
        $headers  = $this->superadmin();

        $this->start($designer, [], $headers)->assertStatus(201);
        $this->start($designer, [], $headers)->assertStatus(201);
        $this->start($designer, [], $headers)->assertStatus(429);
    }

    // ── CA7: quién puede ──────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: array<string, mixed>}> */
    public static function actoresSinSuperadmin(): array
    {
        return [
            'vendedor/diseñador (nivel 4)' => [['__login' => 'vendedor@atlantic.test']],
            'nivel 9'                      => [['__level' => 9]],
            'extra_permissions de gestión de usuarios' => [['__extra' => ['security.users', 'settings.global', 'security.levels']]],
        ];
    }

    /** @param array<string, mixed> $spec */
    #[\PHPUnit\Framework\Attributes\DataProvider('actoresSinSuperadmin')]
    public function test_quien_no_tiene_superadmin_all_recibe_403_y_queda_el_rastro_de_acceso_denegado(array $spec): void
    {
        if (isset($spec['__login'])) {
            $headers = $this->authHeaders($this->loginAs($spec['__login'], self::PASSWORD, 'designer'));
        } else {
            $user = $this->makeUser([
                'security_level_id' => SecurityLevel::where('level', $spec['__level'] ?? 3)->firstOrFail()->id,
                'extra_permissions' => $spec['__extra'] ?? [],
            ]);
            $headers = $this->authHeaders($this->loginAs($user->email, self::PASSWORD, 'designer'));
        }

        $this->start($this->userId('nivel5@atlantic.test'), [], $headers)->assertStatus(403);

        $this->assertSame(0, $this->sessionCount());
        $this->reMakeCurrent();
        $this->assertTrue(AuditLog::query()->where('action', AuditService::SECURITY_ACCESS_DENIED)->where('entity_id', 'api/users/' . $this->userId('nivel5@atlantic.test') . '/impersonate')->exists());
    }

    public function test_sin_token_responde_401(): void
    {
        $this->postJson('/api/users/' . $this->userId('vendedor@atlantic.test') . '/impersonate', ['reason' => self::REASON, 'operator_password' => self::PASSWORD])
            ->assertStatus(401);
    }

    public function test_el_rol_de_superadmin_se_revalida_en_la_base_de_datos(): void
    {
        $operador = $this->makeUser(['password_hash' => 'ClaveOperador9!x']);
        $operador->forceFill(['role_id' => Role::where('is_superadmin', true)->value('id')])->save();
        $headers = $this->authHeaders($this->loginAs($operador->email, 'ClaveOperador9!x', 'superadmin'));
        $designer = $this->userId('vendedor@atlantic.test');

        $this->start($designer, ['operator_password' => 'ClaveOperador9!x'], $headers)->assertStatus(201);

        // Le quitan el rol: el token emitido antes ya no alcanza.
        $this->reMakeCurrent();
        $operador->forceFill(['role_id' => Role::where('key', 'management')->value('id')])->save();
        $this->reMakeCurrent();

        $this->start($designer, ['operator_password' => 'ClaveOperador9!x'], $headers)
            ->assertStatus(403)->assertJsonPath('code', 'operator_role_revoked');
        $this->assertSame(1, $this->sessionCount());
    }

    // ── CA8: objetivos válidos ────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array{0: callable(User): void, 1: string}> */
    public static function objetivosInvalidos(): array
    {
        return [
            'inactivo' => [static fn (User $u) => $u->forceFill(['is_active' => false])->save(), 'inactive'],
            'bloqueado por seguridad' => [static fn (User $u) => $u->forceFill(['security_blocked_at' => now()])->save(), 'security_blocked'],
            'rol base superadmin' => [static fn (User $u) => $u->forceFill(['role_id' => Role::where('is_superadmin', true)->value('id')])->save(), 'top_privilege'],
            'security level 10' => [static fn (User $u) => $u->forceFill(['security_level_id' => SecurityLevel::where('level', 10)->firstOrFail()->id])->save(), 'top_privilege'],
            'superadmin.all en extra_permissions' => [static fn (User $u) => $u->forceFill(['extra_permissions' => ['superadmin.all']])->save(), 'top_privilege'],
        ];
    }

    /** @param callable(User): void $prepare */
    #[\PHPUnit\Framework\Attributes\DataProvider('objetivosInvalidos')]
    public function test_un_objetivo_no_valido_responde_422_y_se_audita_el_motivo(callable $prepare, string $problema): void
    {
        $target = $this->makeUser();
        $prepare($target);
        $this->reMakeCurrent();

        $this->start($target->id)->assertStatus(422)->assertJsonPath('code', 'impersonation_target_invalid');

        $this->assertSame(0, $this->sessionCount());
        $this->reMakeCurrent();
        $log = AuditLog::query()->where('action', AuditService::AUTH_IMPERSONATION_DENIED)->firstOrFail();
        $this->assertSame($problema, $log->metadata['denied_reason']);
        $this->assertSame($target->id, $log->metadata['target_user_id']);
    }

    public function test_no_se_puede_ver_como_uno_mismo(): void
    {
        $this->start($this->userId('superadmin@atlantic.test'))->assertStatus(422)->assertJsonPath('code', 'impersonation_target_invalid');

        $this->reMakeCurrent();
        $this->assertSame('self', AuditLog::query()->where('action', AuditService::AUTH_IMPERSONATION_DENIED)->firstOrFail()->metadata['denied_reason']);
    }

    public function test_los_superadmin_del_seeder_tampoco_son_objetivos(): void
    {
        foreach (['management@atlantic.test', 'gerencia@atlantic.test'] as $email) {
            $this->start($this->userId($email))->assertStatus(422);
        }
        $this->assertSame(0, $this->sessionCount());
    }

    public function test_un_objetivo_inexistente_responde_404(): void
    {
        $this->start(99999999)->assertStatus(404)->assertJsonPath('code', 'not_found');
        $this->assertSame(0, $this->sessionCount());
    }

    public function test_la_contrasena_se_pide_antes_que_validar_el_objetivo(): void
    {
        // Sin autenticar bien no se averigua si el objetivo existe o es válido: 422 de contraseña, no 404/422 de objetivo.
        $this->start(99999999, ['operator_password' => 'incorrecta'])->assertStatus(422)->assertJsonPath('code', 'operator_reauth_failed');
    }

    // ── CA11: sin encadenar ───────────────────────────────────────────────────────────────────────────────

    public function test_un_token_de_impersonacion_no_puede_iniciar_otra_sesion(): void
    {
        $designer = $this->userId('vendedor@atlantic.test');
        $token    = (string) $this->start($designer)->assertStatus(201)->json('token');

        $this->start($this->userId('nivel5@atlantic.test'), [], $this->authHeaders($token))->assertStatus(403);
        $this->assertSame(1, $this->sessionCount());
    }

    public function test_el_servicio_rechaza_el_encadenado_aunque_algo_dejara_pasar_la_peticion(): void
    {
        $request = \Illuminate\Http\Request::create('/api/users/1/impersonate', 'POST', ['reason' => self::REASON]);
        $request->attributes->set('jwt', ['sub' => $this->userId('vendedor@atlantic.test'), 'act' => ['sub' => 1, 'imp_id' => (string) \Illuminate\Support\Str::uuid()], 'tenant' => 'atlantic']);

        try {
            app(\App\Modules\Auth\Services\ImpersonationService::class)->authorizeStart($request, 1, self::REASON);
            $this->fail('debió lanzar ImpersonationException');
        } catch (\App\Modules\Auth\Services\ImpersonationException $e) {
            $this->assertSame(403, $e->status);
            $this->assertSame('impersonation_nested', $e->errorCode);
        }
    }
}
