<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\SecurityLevel;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\FeatureTestCase;

/** API de reset de contraseñas — solo superadmin (POST /api/users/{id}/reset-password y /api/users/reset-passwords). */
class PasswordResetApiTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $overrides */
    private function makeUser(array $overrides = []): User
    {
        return User::create([
            'first_name' => 'Persona', 'last_name' => 'Prueba', 'email' => uniqid('api-') . '@atlantic.test',
            'password_hash' => 'ClaveOriginal99!', 'security_level_id' => SecurityLevel::where('level', 3)->firstOrFail()->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    /** @return array<string, string> */
    private function superadminHeaders(): array
    {
        return $this->authHeaders($this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin'));
    }

    private function sinCambios(User $user): bool
    {
        $this->reMakeCurrent();
        $fresh = $user->fresh();

        return $fresh !== null && Hash::check('ClaveOriginal99!', $fresh->password_hash) && ! $fresh->must_change_password;
    }

    // ── Individual ───────────────────────────────────────────────────────────────────────────────────────

    public function test_individual_sin_token_da_401(): void
    {
        $user = $this->makeUser();

        $this->postJson("/api/users/{$user->id}/reset-password")->assertStatus(401);
        $this->assertTrue($this->sinCambios($user));
    }

    public function test_individual_solo_superadmin_otros_roles_reciben_403(): void
    {
        $user = $this->makeUser();

        foreach ([['management@atlantic.test', 'management'], ['designer@atlantic.test', 'designer']] as [$email, $role]) {
            $headers = $this->authHeaders($this->loginAs($email, 'Password123!', $role));
            $this->postJson("/api/users/{$user->id}/reset-password", [], $headers)->assertStatus(403);
        }
        $this->assertTrue($this->sinCambios($user));
    }

    public function test_individual_resetea_y_responde_el_contrato(): void
    {
        $user = $this->makeUser();

        $this->postJson("/api/users/{$user->id}/reset-password", [], $this->superadminHeaders())
            ->assertStatus(200)
            ->assertExactJson(['data' => ['id' => $user->id, 'email' => $user->email, 'must_change_password' => true]]);

        $this->reMakeCurrent();
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check($user->email, $fresh->password_hash));
        $this->assertTrue($fresh->must_change_password);
    }

    public function test_individual_usuario_inexistente_da_404(): void
    {
        $this->postJson('/api/users/99999999/reset-password', [], $this->superadminHeaders())->assertStatus(404);
    }

    public function test_individual_usuario_inactivo_da_422_y_no_cambia_nada(): void
    {
        $user = $this->makeUser(['is_active' => false]);

        $this->postJson("/api/users/{$user->id}/reset-password", [], $this->superadminHeaders())
            ->assertStatus(422)
            ->assertJsonStructure(['message']);

        $this->reMakeCurrent();
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_el_reseteado_puede_ingresar_con_su_correo_y_el_login_avisa_que_debe_cambiarla(): void
    {
        $this->postJson('/api/users/' . User::where('email', 'designer@atlantic.test')->value('id') . '/reset-password', [], $this->superadminHeaders())
            ->assertStatus(200);

        $this->postJson('/api/auth/login', ['email' => 'designer@atlantic.test', 'password' => 'designer@atlantic.test'])
            ->assertStatus(200)
            ->assertJsonPath('user.must_change_password', true);

        // La contraseña vieja ya no sirve.
        $this->postJson('/api/auth/login', ['email' => 'designer@atlantic.test', 'password' => 'Password123!'])->assertStatus(401);
    }

    // ── Masivo ───────────────────────────────────────────────────────────────────────────────────────────

    public function test_masivo_solo_superadmin(): void
    {
        $this->postJson('/api/users/reset-passwords', ['dry_run' => true])->assertStatus(401);

        $headers = $this->authHeaders($this->loginAs('management@atlantic.test', 'Password123!', 'management'));
        $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->assertStatus(403);
    }

    public function test_masivo_dry_run_lista_sin_cambiar_nada_y_excluye_inactivos_y_al_operador(): void
    {
        $a = $this->makeUser();
        $inactivo = $this->makeUser(['is_active' => false]);
        $actorId = (int) User::where('email', 'superadmin@atlantic.test')->value('id');

        $res = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $this->superadminHeaders())
            ->assertStatus(200)
            ->assertJsonStructure(['data' => ['count', 'users' => [['id', 'email', 'name']]]]);

        $ids = collect($res->json('data.users'))->pluck('id')->all();
        $this->assertSame(count($ids), $res->json('data.count'));
        $this->assertContains($a->id, $ids);
        $this->assertNotContains($inactivo->id, $ids);
        $this->assertNotContains($actorId, $ids, 'por defecto el operador no se incluye a sí mismo');
        $this->assertTrue($this->sinCambios($a));
    }

    public function test_masivo_include_self_y_except_ids(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $actorId = (int) User::where('email', 'superadmin@atlantic.test')->value('id');
        $headers = $this->superadminHeaders();

        // El actor es superadmin: para alcanzarlo con include_self hace falta además include_superadmins (H5).
        $res = $this->postJson('/api/users/reset-passwords', ['dry_run' => true, 'include_self' => true, 'include_superadmins' => true, 'except_ids' => [$a->id]], $headers)
            ->assertStatus(200);
        $ids = collect($res->json('data.users'))->pluck('id')->all();
        $this->assertContains($actorId, $ids);
        $this->assertNotContains($a->id, $ids);
        $this->assertContains($b->id, $ids);

        // include_self SIN include_superadmins: el actor (superadmin) sigue fuera.
        $res = $this->postJson('/api/users/reset-passwords', ['dry_run' => true, 'include_self' => true], $headers)->assertStatus(200);
        $this->assertNotContains($actorId, collect($res->json('data.users'))->pluck('id')->all());
    }

    public function test_masivo_excluye_nivel_10_y_superadmin_all_por_defecto_y_el_apply_usa_el_mismo_conjunto(): void
    {
        // N1: cuentas con bypass total sin rol superadmin.
        $nivel10 = $this->makeUser(['security_level_id' => \App\Modules\Auth\Models\SecurityLevel::where('level', 10)->firstOrFail()->id]);
        $conExtra = $this->makeUser(['extra_permissions' => ['superadmin.all']]);
        $normal = $this->makeUser();
        $headers = $this->superadminHeaders();

        $dry = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->assertStatus(200);
        $ids = collect($dry->json('data.users'))->pluck('id')->all();
        $this->assertContains($normal->id, $ids);
        $this->assertNotContains($nivel10->id, $ids);
        $this->assertNotContains($conExtra->id, $ids);
        $n = (int) $dry->json('data.count');

        // Con la opción, el conjunto crece exactamente en esas dos cuentas (la confirmación cuenta el conjunto ampliado).
        $dryAmpliado = $this->postJson('/api/users/reset-passwords', ['dry_run' => true, 'include_superadmins' => true], $headers)->assertStatus(200);
        $this->assertContains($nivel10->id, collect($dryAmpliado->json('data.users'))->pluck('id')->all());
        $this->assertContains($conExtra->id, collect($dryAmpliado->json('data.users'))->pluck('id')->all());

        // Apply por defecto: N = el del dry_run por defecto y esas cuentas no se tocan.
        $this->postJson('/api/users/reset-passwords', ['operator_password' => 'Password123!', 'confirm' => 'RESETEAR ' . ($n + 1)], $headers)->assertStatus(422);
        $this->postJson('/api/users/reset-passwords', ['operator_password' => 'Password123!', 'confirm' => 'RESETEAR ' . $n], $headers)->assertStatus(200)->assertJsonPath('data.reset_count', $n);
        $this->reMakeCurrent();
        $this->assertTrue($this->sinCambios($nivel10));
        $this->assertTrue($this->sinCambios($conExtra));
        $this->assertTrue((bool) $normal->fresh()?->must_change_password);
    }

    public function test_masivo_excluye_superadmins_por_defecto_y_los_incluye_con_la_opcion(): void
    {
        $normal = $this->makeUser();
        $superId = (int) User::where('email', 'superadmin@atlantic.test')->value('id');
        $otroSuper = $this->makeUser(['email' => uniqid('super2-') . '@atlantic.test']);
        $otroSuper->forceFill(['role_id' => \App\Modules\Auth\Models\Role::where('is_superadmin', true)->value('id')])->save();
        $headers = $this->superadminHeaders();

        $defecto = $this->postJson('/api/users/reset-passwords', ['dry_run' => true, 'include_self' => true], $headers)->assertStatus(200);
        $idsDefecto = collect($defecto->json('data.users'))->pluck('id')->all();
        $this->assertContains($normal->id, $idsDefecto);
        $this->assertNotContains($superId, $idsDefecto, 'el superadmin demo queda fuera por defecto');
        $this->assertNotContains($otroSuper->id, $idsDefecto, 'cualquier usuario con rol superadmin queda fuera por defecto');

        $con = $this->postJson('/api/users/reset-passwords', ['dry_run' => true, 'include_self' => true, 'include_superadmins' => true], $headers)->assertStatus(200);
        $idsCon = collect($con->json('data.users'))->pluck('id')->all();
        $this->assertContains($superId, $idsCon);
        $this->assertContains($otroSuper->id, $idsCon);
        $this->assertGreaterThan($defecto->json('data.count'), $con->json('data.count'));

        // El apply sigue el mismo criterio: con el N de la simulación por defecto el superadmin NO se toca.
        $this->postJson('/api/users/reset-passwords', ['operator_password' => 'Password123!', 'confirm' => 'RESETEAR ' . $defecto->json('data.count')], $headers)->assertStatus(200);
        $this->reMakeCurrent();
        $this->assertTrue($normal->fresh()->must_change_password);
        $this->assertFalse($otroSuper->fresh()->must_change_password, 'el apply por defecto no resetea superadmins');

        // Con include_superadmins el N de confirmación es el del conjunto ampliado (mismo criterio en dry_run y apply).
        $dryCon = $this->postJson('/api/users/reset-passwords', ['dry_run' => true, 'include_superadmins' => true], $headers)->json('data.count');
        $this->postJson('/api/users/reset-passwords', ['include_superadmins' => true, 'operator_password' => 'Password123!', 'confirm' => 'RESETEAR ' . $dryCon], $headers)->assertStatus(200);
        $this->reMakeCurrent();
        $this->assertTrue($otroSuper->fresh()->must_change_password);
    }

    public function test_un_individual_a_un_superadmin_puntual_sigue_permitido(): void
    {
        $otroSuper = $this->makeUser(['email' => uniqid('super3-') . '@atlantic.test']);
        $otroSuper->forceFill(['role_id' => \App\Modules\Auth\Models\Role::where('is_superadmin', true)->value('id')])->save();

        // H3: resetear a una cuenta de máximo privilegio exige la contraseña del operador.
        $this->postJson("/api/users/{$otroSuper->id}/reset-password", ['operator_password' => 'Password123!'], $this->superadminHeaders())->assertStatus(200);
        $this->reMakeCurrent();
        $this->assertTrue($otroSuper->fresh()->must_change_password);
    }

    public function test_m1_nivel_10_sin_rol_base_superadmin_recibe_403_en_ambas_rutas(): void
    {
        // El gate es `role:superadmin` (claim `role` del JWT = rol base): más estricto que `superadmin.all`; falla cerrado.
        $objetivo = $this->makeUser();
        $nivel10 = $this->makeUser(['email' => uniqid('n10-') . '@atlantic.test', 'password_hash' => 'ClaveNivel10!x']);
        $nivel10->forceFill(['security_level_id' => SecurityLevel::where('level', 10)->firstOrFail()->id, 'role_id' => \App\Modules\Auth\Models\Role::where('key', 'management')->value('id')])->save();
        $this->reMakeCurrent();
        $headers = $this->authHeaders($this->loginAs($nivel10->email, 'ClaveNivel10!x', 'management'));

        $this->postJson("/api/users/{$objetivo->id}/reset-password", [], $headers)->assertStatus(403);
        $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->assertStatus(403);
        $this->assertTrue($this->sinCambios($objetivo));
    }

    public function test_el_limite_de_peticiones_devuelve_429(): void
    {
        config(['throttle.password_reset_admin.per_minute' => 6]);
        $objetivo = $this->makeUser();
        $headers = $this->superadminHeaders();
        $this->reMakeCurrent();

        $ultimo = 200;
        for ($i = 0; $i < 8; $i++) {
            $ultimo = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->getStatusCode();
            if ($ultimo === 429) {
                break;
            }
        }
        $this->assertSame(429, $ultimo, 'el limitador por usuario (6/min configurado en el test) debe cortar antes de la 8ª petición del minuto');
        $this->postJson("/api/users/{$objetivo->id}/reset-password", [], $headers)->assertStatus(429);
    }

    public function test_masivo_confirmacion_incorrecta_da_422_confirmation_mismatch_y_no_cambia_nada(): void
    {
        $a = $this->makeUser();
        $headers = $this->superadminHeaders();
        $count = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->json('data.count');

        foreach ([null, '', 'RESETEAR', 'RESETEAR ' . ($count + 1), 'resetear ' . $count . ' ya'] as $confirm) {
            $this->postJson('/api/users/reset-passwords', ['operator_password' => 'Password123!', 'confirm' => $confirm], $headers)
                ->assertStatus(422)
                ->assertJsonPath('code', 'confirmation_mismatch');
        }
        $this->assertTrue($this->sinCambios($a));
    }

    public function test_masivo_con_confirmacion_correcta_aplica_y_responde_el_contrato(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $inactivo = $this->makeUser(['is_active' => false]);
        $headers = $this->superadminHeaders();
        $count = $this->postJson('/api/users/reset-passwords', ['dry_run' => true], $headers)->json('data.count');

        $this->postJson('/api/users/reset-passwords', ['operator_password' => 'Password123!', 'confirm' => "RESETEAR {$count}"], $headers)
            ->assertStatus(200)
            ->assertExactJson(['data' => ['reset_count' => $count]]);

        $this->reMakeCurrent();
        $this->assertTrue($a->fresh()->must_change_password);
        $this->assertTrue($b->fresh()->must_change_password);
        $this->assertFalse($inactivo->fresh()->must_change_password);
        $actor = User::where('email', 'superadmin@atlantic.test')->firstOrFail();
        $this->assertFalse($actor->must_change_password, 'el operador no se incluye por defecto');
    }

    public function test_masivo_valida_tipos_del_cuerpo(): void
    {
        $headers = $this->superadminHeaders();

        $this->postJson('/api/users/reset-passwords', ['dry_run' => 'quizas'], $headers)->assertStatus(422);
        $this->postJson('/api/users/reset-passwords', ['dry_run' => true, 'except_ids' => 'uno'], $headers)->assertStatus(422);
        $this->postJson('/api/users/reset-passwords', ['dry_run' => true, 'except_ids' => ['x']], $headers)->assertStatus(422);
        $this->postJson('/api/users/reset-passwords', ['dry_run' => true, 'include_self' => 'si'], $headers)->assertStatus(422);
    }
}
