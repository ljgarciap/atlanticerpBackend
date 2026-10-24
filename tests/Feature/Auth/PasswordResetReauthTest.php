<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\SecurityLevel;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

/**
 * H3 del cierre de la revisión de seguridad: el masivo y el reset de una cuenta de máximo privilegio exigen la contraseña del operador,
 * el rol del operador se revalida en BD y los intentos fallidos se limitan POR USUARIO.
 */
class PasswordResetReauthTest extends FeatureTestCase
{
    private const OPERATOR_PASSWORD = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Mail::fake();
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
            'first_name' => 'Persona', 'last_name' => 'Prueba', 'email' => uniqid('reauth-') . '@atlantic.test',
            'password_hash' => 'ClaveOriginal99!', 'security_level_id' => SecurityLevel::where('level', 3)->firstOrFail()->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /** @return array<string, string> */
    private function superadminHeaders(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', self::OPERATOR_PASSWORD, 'superadmin'));
    }

    private function conReset(User $user): bool
    {
        $this->reMakeCurrent();

        return (bool) $user->fresh()?->must_change_password;
    }

    /** @return array<string, array{0: callable(User): void}> */
    public static function cuentasDeMaximoPrivilegio(): array
    {
        return [
            'rol base superadmin' => [static function (User $u): void {
                $u->forceFill(['role_id' => Role::where('is_superadmin', true)->value('id')])->save();
            }],
            'security level 10' => [static function (User $u): void {
                $u->forceFill(['security_level_id' => SecurityLevel::where('level', 10)->firstOrFail()->id])->save();
            }],
            'superadmin.all en extra_permissions' => [static function (User $u): void {
                $u->forceFill(['extra_permissions' => ['superadmin.all']])->save();
            }],
        ];
    }

    // ── Masivo ────────────────────────────────────────────────────────────────────────────────────────────

    public function test_el_masivo_exige_la_contrasena_del_operador_al_aplicar(): void
    {
        $a = $this->makeUser();
        $headers = $this->superadminHeaders();
        $count = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->json('data.count');

        $this->postJson('/api/users/reset-passwords', ['confirm' => "RESETEAR {$count}"], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'operator_password_required');
        $this->postJson('/api/users/reset-passwords', ['confirm' => "RESETEAR {$count}", 'operator_password' => ''], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'operator_password_required');
        $this->postJson('/api/users/reset-passwords', ['confirm' => "RESETEAR {$count}", 'operator_password' => 'incorrecta'], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'operator_reauth_failed');
        $this->assertFalse($this->conReset($a), 'ningún intento sin la contraseña correcta cambia algo');

        $this->postJson('/api/users/reset-passwords', ['confirm' => "RESETEAR {$count}", 'operator_password' => self::OPERATOR_PASSWORD], $headers)
            ->assertStatus(200)->assertJsonPath('data.reset_count', $count);
        $this->assertTrue($this->conReset($a));
    }

    public function test_la_simulacion_del_masivo_no_pide_contrasena(): void
    {
        $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $this->superadminHeaders())->assertStatus(200);
    }

    public function test_la_confirmacion_incorrecta_se_rechaza_antes_de_pedir_la_contrasena(): void
    {
        $this->postJson('/api/users/reset-passwords', ['confirm' => 'RESETEAR 9999'], $this->superadminHeaders())
            ->assertStatus(422)->assertJsonPath('code', 'confirmation_mismatch');
    }

    // ── R3-1: tope de resets individuales por hora sin contraseña ─────────────────────────────────────────

    public function test_el_individual_de_personas_normales_pide_la_contrasena_pasado_el_tope_por_hora(): void
    {
        $headers = $this->superadminHeaders();
        $this->setFreeQuota(3);

        $objetivos = [$this->makeUser(), $this->makeUser(), $this->makeUser(), $this->makeUser()];
        foreach (array_slice($objetivos, 0, 3) as $u) {
            $this->postJson("/api/users/{$u->id}/reset-password", [], $headers)->assertStatus(200);
        }

        // El 4.º en la misma hora exige la contraseña del operador y no se aplica sin ella.
        $this->postJson("/api/users/{$objetivos[3]->id}/reset-password", [], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'operator_password_required');
        $this->assertFalse($this->conReset($objetivos[3]));

        $this->postJson("/api/users/{$objetivos[3]->id}/reset-password", ['operator_password' => 'incorrecta'], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'operator_reauth_failed');
        $this->assertFalse($this->conReset($objetivos[3]));

        $this->postJson("/api/users/{$objetivos[3]->id}/reset-password", ['operator_password' => self::OPERATOR_PASSWORD], $headers)->assertStatus(200);
        $this->assertTrue($this->conReset($objetivos[3]));
    }

    public function test_el_tope_por_hora_se_libera_al_pasar_la_ventana(): void
    {
        $headers = $this->superadminHeaders();
        $this->setFreeQuota(1);
        [$a, $b] = [$this->makeUser(), $this->makeUser()];

        $this->postJson("/api/users/{$a->id}/reset-password", [], $headers)->assertStatus(200);
        $this->postJson("/api/users/{$b->id}/reset-password", [], $headers)->assertStatus(422);

        \Illuminate\Support\Carbon::setTestNow(now()->addMinutes(61));
        $this->reMakeCurrent();
        $this->postJson("/api/users/{$b->id}/reset-password", [], $headers)->assertStatus(200);
        \Illuminate\Support\Carbon::setTestNow();
    }

    public function test_con_el_tope_en_cero_siempre_pide_la_contrasena(): void
    {
        $headers = $this->superadminHeaders();
        $this->setFreeQuota(0);
        $u = $this->makeUser();

        $this->postJson("/api/users/{$u->id}/reset-password", [], $headers)->assertStatus(422)->assertJsonPath('code', 'operator_password_required');
    }

    public function test_el_tope_por_hora_es_el_default_de_5_y_configurable_en_ajustes_globales(): void
    {
        $this->reMakeCurrent();
        $this->assertSame(5, app(\App\Modules\Auth\Services\PasswordResetPolicy::class)->freeResetsPerHour());

        $headers = $this->superadminHeaders();
        // No exige contraseña: el tope no es un ajuste crítico (R3-6 solo cubre interruptor y caducidad).
        $this->putJson('/api/admin/global-settings/password_reset_free_per_hour', ['value' => 2], $headers)->assertStatus(200)->assertJsonPath('value', 2);
        $this->reMakeCurrent();
        $this->assertSame(2, app(\App\Modules\Auth\Services\PasswordResetPolicy::class)->freeResetsPerHour());
    }

    private function setFreeQuota(int $n): void
    {
        $this->reMakeCurrent();
        \App\Modules\Auth\Models\GlobalSetting::updateOrCreate(
            ['key' => 'password_reset_free_per_hour'],
            ['value' => (string) $n, 'updated_by' => null, 'updated_at' => now()],
        );
        \Illuminate\Support\Facades\Cache::forget('global:password_reset_free_per_hour');
    }

    // ── R3-6: interruptor y caducidad piden la contraseña del operador ─────────────────────────────────────

    /** @return array<string, array{0: string, 1: int}> */
    public static function ajustesCriticos(): array
    {
        return [
            'interruptor' => ['password_reset_enabled', 1],
            'caducidad'   => ['password_reset_expiration_hours', 720],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('ajustesCriticos')]
    public function test_cambiar_el_interruptor_o_la_caducidad_exige_la_contrasena_del_operador(string $key, int $valor): void
    {
        $headers = $this->superadminHeaders();

        $this->putJson("/api/admin/global-settings/{$key}", ['value' => $valor], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'operator_password_required');
        $this->putJson("/api/admin/global-settings/{$key}", ['value' => $valor, 'operator_password' => 'incorrecta'], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'operator_reauth_failed');
        $this->reMakeCurrent();
        $this->assertNull(\App\Modules\Auth\Models\GlobalSetting::find($key), 'sin contraseña correcta no se guarda nada');

        $this->putJson("/api/admin/global-settings/{$key}", ['value' => $valor, 'operator_password' => self::OPERATOR_PASSWORD], $headers)
            ->assertStatus(200)->assertJsonPath('value', $valor);
    }

    public function test_los_fallos_de_contrasena_en_ajustes_comparten_el_tope_por_usuario_y_dan_429(): void
    {
        config(['throttle.password_reset_admin.reauth_max' => 3]);
        $headers = $this->superadminHeaders();

        for ($i = 0; $i < 3; $i++) {
            $this->putJson('/api/admin/global-settings/password_reset_enabled', ['value' => 1, 'operator_password' => 'mala'], $headers)->assertStatus(422);
        }

        $this->putJson('/api/admin/global-settings/password_reset_enabled', ['value' => 1, 'operator_password' => self::OPERATOR_PASSWORD], $headers)
            ->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
    }

    public function test_los_demas_ajustes_globales_no_piden_contrasena_y_el_listado_marca_los_criticos(): void
    {
        $headers = $this->superadminHeaders();

        $this->putJson('/api/admin/global-settings/documents.max_size_mb', ['value' => 250], $headers)->assertStatus(200);

        $rows = collect($this->getJson('/api/admin/global-settings', $headers)->assertStatus(200)->json('data'))->keyBy('key');
        $this->assertTrue($rows['password_reset_enabled']['requires_reauth']);
        $this->assertTrue($rows['password_reset_expiration_hours']['requires_reauth']);
        $this->assertFalse($rows['documents.max_size_mb']['requires_reauth']);
        $this->assertFalse($rows['password_reset_free_per_hour']['requires_reauth']);
    }

    public function test_el_conteo_de_contrasenas_de_operador_incorrectas_se_incrementa_antes_de_evaluar(): void
    {
        $headers = $this->superadminHeaders();
        $cuenta  = $this->makeUser(['extra_permissions' => ['superadmin.all']]);

        $this->postJson("/api/users/{$cuenta->id}/reset-password", ['operator_password' => 'mala'], $headers)->assertStatus(422);

        $this->reMakeCurrent();
        $actor = User::where('email', 'superadmin@atlantic.test')->firstOrFail();
        $this->assertSame(1, \Illuminate\Support\Facades\RateLimiter::attempts('pwreset-reauth:u' . $actor->id));
    }

    // ── Individual ────────────────────────────────────────────────────────────────────────────────────────

    public function test_el_individual_de_una_persona_normal_no_pide_contrasena(): void
    {
        $normal = $this->makeUser();

        $this->postJson("/api/users/{$normal->id}/reset-password", [], $this->superadminHeaders())->assertStatus(200);
        $this->assertTrue($this->conReset($normal));
    }

    /** @param callable(User): void $volver */
    #[\PHPUnit\Framework\Attributes\DataProvider('cuentasDeMaximoPrivilegio')]
    public function test_el_individual_de_una_cuenta_de_maximo_privilegio_exige_la_contrasena_del_operador(callable $volver): void
    {
        $objetivo = $this->makeUser();
        $volver($objetivo);
        $this->reMakeCurrent();
        $headers = $this->superadminHeaders();

        $this->postJson("/api/users/{$objetivo->id}/reset-password", [], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'operator_password_required');
        $this->postJson("/api/users/{$objetivo->id}/reset-password", ['operator_password' => 'incorrecta'], $headers)
            ->assertStatus(422)->assertJsonPath('code', 'operator_reauth_failed');
        $this->assertFalse($this->conReset($objetivo));

        $this->postJson("/api/users/{$objetivo->id}/reset-password", ['operator_password' => self::OPERATOR_PASSWORD], $headers)->assertStatus(200);
        $this->assertTrue($this->conReset($objetivo));
    }

    // ── Revalidación del rol del operador en BD ───────────────────────────────────────────────────────────

    public function test_un_operador_al_que_le_quitaron_el_rol_recibe_403_aunque_su_token_siga_vigente(): void
    {
        $operador = $this->makeUser(['password_hash' => 'ClaveOperador9!x']);
        $operador->forceFill(['role_id' => Role::where('is_superadmin', true)->value('id')])->save();
        $this->reMakeCurrent();
        $headers  = $this->authHeaders($this->loginAs($operador->email, 'ClaveOperador9!x', 'superadmin'));
        $objetivo = $this->makeUser();

        $this->postJson("/api/users/{$objetivo->id}/reset-password", [], $headers)->assertStatus(200);

        // Le quitan el rol de superadmin en BD: el token emitido antes ya no sirve para resetear nada.
        $this->reMakeCurrent();
        $operador->forceFill(['role_id' => Role::where('key', 'management')->value('id')])->save();
        $this->reMakeCurrent();
        $otro = $this->makeUser();

        $this->postJson("/api/users/{$otro->id}/reset-password", [], $headers)
            ->assertStatus(403)->assertJsonPath('code', 'operator_role_revoked');
        $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)
            ->assertStatus(403)->assertJsonPath('code', 'operator_role_revoked');
        $this->assertFalse($this->conReset($otro));
    }

    public function test_un_operador_desactivado_pierde_el_acceso(): void
    {
        $operador = $this->makeUser(['password_hash' => 'ClaveOperador9!x']);
        $operador->forceFill(['role_id' => Role::where('is_superadmin', true)->value('id')])->save();
        $this->reMakeCurrent();
        $headers = $this->authHeaders($this->loginAs($operador->email, 'ClaveOperador9!x', 'superadmin'));
        $this->reMakeCurrent();
        $operador->forceFill(['is_active' => false])->save();
        $objetivo = $this->makeUser();

        $this->postJson("/api/users/{$objetivo->id}/reset-password", [], $headers)->assertStatus(401);
        $this->assertFalse($this->conReset($objetivo));
    }

    // ── Límite de intentos por usuario ────────────────────────────────────────────────────────────────────

    public function test_las_contrasenas_de_operador_incorrectas_se_limitan_por_usuario(): void
    {
        config(['throttle.password_reset_admin.reauth_max' => 3]);
        $a = $this->makeUser();
        $headers = $this->superadminHeaders();
        $count = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->json('data.count');
        $cuerpo = ['confirm' => "RESETEAR {$count}", 'operator_password' => 'incorrecta'];

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/users/reset-passwords', $cuerpo, $headers)->assertStatus(422)->assertJsonPath('code', 'operator_reauth_failed');
        }

        $bloqueado = $this->postJson('/api/users/reset-passwords', $cuerpo, $headers)->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        $this->assertGreaterThan(0, $bloqueado->json('retry_after'));
        $this->assertNotNull($bloqueado->headers->get('Retry-After'));

        // Aun con la contraseña correcta, mientras dure la ventana el operador sigue bloqueado.
        $this->postJson('/api/users/reset-passwords', ['confirm' => "RESETEAR {$count}", 'operator_password' => self::OPERATOR_PASSWORD], $headers)->assertStatus(429);
        $this->assertFalse($this->conReset($a));
    }

    public function test_la_contrasena_correcta_reinicia_el_contador_de_fallos(): void
    {
        config(['throttle.password_reset_admin.reauth_max' => 3]);
        $this->makeUser();
        $headers = $this->superadminHeaders();
        $count = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->json('data.count');

        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/users/reset-passwords', ['confirm' => "RESETEAR {$count}", 'operator_password' => 'incorrecta'], $headers)->assertStatus(422);
        }
        $this->postJson('/api/users/reset-passwords', ['confirm' => "RESETEAR {$count}", 'operator_password' => self::OPERATOR_PASSWORD], $headers)->assertStatus(200);

        $count2 = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->json('data.count');
        for ($i = 0; $i < 2; $i++) {
            $this->postJson('/api/users/reset-passwords', ['confirm' => "RESETEAR {$count2}", 'operator_password' => 'incorrecta'], $headers)->assertStatus(422);
        }
    }

    public function test_el_limite_de_peticiones_es_por_usuario_y_no_por_ip(): void
    {
        config(['throttle.password_reset_admin.per_minute' => 3]);
        $segundo = $this->makeUser(['password_hash' => 'ClaveOperador9!x']);
        $segundo->forceFill(['role_id' => Role::where('is_superadmin', true)->value('id')])->save();
        $this->reMakeCurrent();
        $headersA = $this->superadminHeaders();
        $headersB = $this->authHeaders($this->loginAs($segundo->email, 'ClaveOperador9!x', 'superadmin'));

        for ($i = 0; $i < 3; $i++) {
            $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headersA)->assertStatus(200);
        }
        $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headersA)->assertStatus(429);

        // La misma IP, otro operador: su cupo es propio.
        $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headersB)->assertStatus(200);
    }

    // ── Auditoría ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_la_auditoria_indica_la_reautenticacion_y_nunca_guarda_la_contrasena(): void
    {
        $objetivo = $this->makeUser();
        $this->postJson("/api/users/{$objetivo->id}/reset-password", [], $this->superadminHeaders())->assertStatus(200);
        $this->reMakeCurrent();
        $normal = AuditLog::query()->where('action', 'auth.user.password_reset')->where('entity_id', (string) $objetivo->id)->latest('id')->firstOrFail();
        $this->assertFalse($normal->metadata['reautenticado']);

        $super = $this->makeUser();
        $super->forceFill(['role_id' => Role::where('is_superadmin', true)->value('id')])->save();
        $this->reMakeCurrent();
        $this->postJson("/api/users/{$super->id}/reset-password", ['operator_password' => self::OPERATOR_PASSWORD], $this->superadminHeaders())->assertStatus(200);
        $this->reMakeCurrent();
        $log = AuditLog::query()->where('action', 'auth.user.password_reset')->where('entity_id', (string) $super->id)->latest('id')->firstOrFail();

        $this->assertTrue($log->metadata['reautenticado']);
        $json = json_encode($log->toArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString(self::OPERATOR_PASSWORD, $json);
        $this->assertStringNotContainsString('operator_password', $json);
    }
}
