<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\AuditLog;
use App\Modules\Auth\Models\RefreshToken;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Services\AdminPasswordResetService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;
use Tests\Feature\FeatureTestCase;

/** Reset administrativo de contraseñas — AdminPasswordResetService (docs/runbooks/reset-password.md). */
class AdminPasswordResetServiceTest extends FeatureTestCase
{
    private AdminPasswordResetService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->service = app(AdminPasswordResetService::class);
    }

    /** @param array<string, mixed> $overrides */
    private function makeUser(array $overrides = []): User
    {
        return User::create([
            'first_name' => 'Persona', 'last_name' => 'Prueba', 'email' => uniqid('reset-') . '@atlantic.test',
            'password_hash' => 'ClaveOriginal99!', 'security_level_id' => SecurityLevel::where('level', 3)->firstOrFail()->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function makeRefreshToken(User $user): RefreshToken
    {
        return RefreshToken::create([
            'user_id' => $user->id, 'token_hash' => hash('sha256', uniqid('rt', true)),
            'expires_at' => now()->addDays(10), 'revoked' => false, 'login_at' => now(), 'last_activity_at' => now(),
        ]);
    }

    public function test_reset_registra_password_reset_at_para_invalidar_tokens_previos(): void
    {
        $user = $this->makeUser();
        $this->assertNull($user->fresh()?->password_reset_at);

        $antes = now()->subSecond();
        $this->service->resetUser($user, 'luis@example.test', 'comando');

        $at = $user->fresh()?->password_reset_at;
        $this->assertNotNull($at);
        $this->assertTrue($at->greaterThanOrEqualTo($antes));
    }

    public function test_resolve_targets_excluye_superadmin_por_rol_base_y_rol_adicional(): void
    {
        $superRoleId = (int) \App\Modules\Auth\Models\Role::where('is_superadmin', true)->value('id');
        $porBase = $this->makeUser();
        $porBase->forceFill(['role_id' => $superRoleId])->save();
        $porAdicional = $this->makeUser();
        \App\Modules\Auth\Models\UserAdditionalRole::create(['user_id' => $porAdicional->id, 'role_id' => $superRoleId]);
        $normal = $this->makeUser();

        $ids = $this->service->resolveTargets()->pluck('id')->all();
        $this->assertContains($normal->id, $ids);
        $this->assertNotContains($porBase->id, $ids);
        $this->assertNotContains($porAdicional->id, $ids);

        $ids = $this->service->resolveTargets([], [], null, true)->pluck('id')->all();
        foreach ([$normal, $porBase, $porAdicional] as $u) {
            $this->assertContains($u->id, $ids);
        }
    }

    public function test_resolve_targets_excluye_cuentas_con_bypass_total_aunque_no_tengan_rol_superadmin(): void
    {
        // N1 (Ronda 2 de Cybersecurity): nivel 10 y `superadmin.all` en extra_permissions dan bypass total (User::hasPermission()).
        $nivel10 = $this->makeUser(['security_level_id' => SecurityLevel::where('level', 10)->firstOrFail()->id]);
        $conExtra = $this->makeUser(['extra_permissions' => ['crm.read', 'superadmin.all']]);
        $normal = $this->makeUser();
        $conOtrosExtra = $this->makeUser(['extra_permissions' => ['crm.read', 'compras.limited.view']]);
        $sinExtra = $this->makeUser(['extra_permissions' => []]);

        $ids = $this->service->resolveTargets()->pluck('id')->all();
        $this->assertNotContains($nivel10->id, $ids, 'nivel 10 queda fuera por defecto');
        $this->assertNotContains($conExtra->id, $ids, 'superadmin.all en extra_permissions queda fuera por defecto');
        foreach ([$normal, $conOtrosExtra, $sinExtra] as $u) {
            $this->assertContains($u->id, $ids, 'un usuario sin bypass total sigue incluido');
        }

        $ids = $this->service->resolveTargets([], [], null, true)->pluck('id')->all();
        $this->assertContains($nivel10->id, $ids, 'con includeSuperadmins vuelven');
        $this->assertContains($conExtra->id, $ids);
        $this->assertContains($normal->id, $ids);
    }

    // SCRUM-1142 — un reset masivo las reactivaría con contraseña = correo: Tecnico Servicios/Joel/Personal de Bodega quedan siempre fuera.
    public function test_resolve_targets_excluye_las_cuentas_sin_acceso_de_bodega(): void
    {
        $normal = $this->makeUser();

        foreach ([false, true] as $includeSuperadmins) {
            $emails = $this->service->resolveTargets([], [], null, $includeSuperadmins)->pluck('email')->map(fn ($e) => mb_strtolower((string) $e))->all();

            $this->assertContains(mb_strtolower((string) $normal->email), $emails);
            foreach (\App\Modules\Auth\Support\NoLoginAccounts::EMAILS as $noLogin) {
                $this->assertNotContains($noLogin, $emails, "{$noLogin} no debe entrar a un reset masivo");
            }
        }
    }

    public function test_el_reset_individual_si_alcanza_a_una_cuenta_con_bypass_total(): void
    {
        $nivel10 = $this->makeUser(['security_level_id' => SecurityLevel::where('level', 10)->firstOrFail()->id]);

        $this->service->resetUser($nivel10, 'luis@example.test', 'comando');

        $this->assertTrue((bool) $nivel10->fresh()?->must_change_password);
    }

    public function test_reset_deja_la_contrasena_igual_al_correo_y_exige_cambiarla(): void
    {
        $user = $this->makeUser();

        $this->service->resetUser($user, 'luis@example.test', 'comando');

        $fresh = $user->fresh();
        $this->assertNotNull($fresh);
        $this->assertTrue(Hash::check($fresh->email, $fresh->password_hash));
        $this->assertFalse(Hash::check('ClaveOriginal99!', $fresh->password_hash));
        $this->assertTrue($fresh->must_change_password);
    }

    public function test_reset_revoca_todos_los_refresh_tokens_del_usuario_y_no_los_de_otros(): void
    {
        $user = $this->makeUser();
        $otro = $this->makeUser();
        $a = $this->makeRefreshToken($user);
        $b = $this->makeRefreshToken($user);
        $ajeno = $this->makeRefreshToken($otro);

        $this->service->resetUser($user, 'op', 'api');

        $this->assertTrue($a->fresh()->revoked);
        $this->assertTrue($b->fresh()->revoked);
        $this->assertFalse($ajeno->fresh()->revoked);
    }

    public function test_reset_no_toca_is_active_ni_el_bloqueo_de_seguridad(): void
    {
        $bloqueo = now()->subDay();
        $user = $this->makeUser();
        $user->forceFill(['security_blocked_at' => $bloqueo])->save();

        $this->service->resetUser($user->fresh(), 'op', 'comando');

        $fresh = $user->fresh();
        $this->assertTrue($fresh->is_active);
        $this->assertNotNull($fresh->security_blocked_at, 'un reset no levanta un bloqueo de seguridad');
    }

    public function test_reset_de_un_usuario_inactivo_se_rechaza_y_no_cambia_nada(): void
    {
        $user = $this->makeUser(['is_active' => false]);
        $hashAntes = $user->password_hash;

        try {
            $this->service->resetUser($user, 'op', 'api');
            $this->fail('Debió lanzar InvalidArgumentException');
        } catch (InvalidArgumentException) {
            // esperado
        }

        $fresh = $user->fresh();
        $this->assertSame($hashAntes, $fresh->password_hash);
        $this->assertFalse($fresh->must_change_password);
    }

    public function test_la_auditoria_registra_quien_y_como_sin_contrasenas_ni_hashes(): void
    {
        $user = $this->makeUser();

        $this->service->resetUser($user, 'op@example.test', 'comando');

        $logs = AuditLog::query()->where('entity_type', 'user')->where('entity_id', (string) $user->id)->get();
        $reset = $logs->firstWhere('action', 'auth.user.password_reset');
        $this->assertNotNull($reset, 'debe existir el evento auth.user.password_reset');
        $this->assertSame('op@example.test', $reset->metadata['operador']);
        $this->assertSame('comando', $reset->metadata['origen']);
        $this->assertSame('individual', $reset->metadata['modo']);

        // Nada de lo registrado para este usuario contiene la contraseña, el correo-como-clave ni un hash.
        $hashNuevo = $user->fresh()->password_hash;
        foreach ($logs as $log) {
            $blob = json_encode([$log->old_values, $log->new_values, $log->metadata]);
            $this->assertStringNotContainsString($hashNuevo, (string) $blob);
            $this->assertStringNotContainsString('ClaveOriginal99!', (string) $blob);
            $this->assertStringNotContainsString('password_hash', (string) $blob);
            $this->assertStringNotContainsString('$2y$', (string) $blob);
        }
    }

    public function test_resolve_targets_solo_activos_y_aplica_exclusiones(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        $c = $this->makeUser();
        $inactivo = $this->makeUser(['is_active' => false]);

        $todos = $this->service->resolveTargets()->pluck('id')->all();
        $this->assertContains($a->id, $todos);
        $this->assertNotContains($inactivo->id, $todos, 'los inactivos nunca entran en un reset masivo');

        $this->assertNotContains($a->id, $this->service->resolveTargets([$a->id])->pluck('id')->all());
        $this->assertNotContains($b->id, $this->service->resolveTargets([], [strtoupper($b->email)])->pluck('id')->all(), 'el correo se compara sin distinguir mayúsculas');
        $this->assertNotContains($c->id, $this->service->resolveTargets([], [], $c->id)->pluck('id')->all());
        $this->assertContains($c->id, $this->service->resolveTargets([], [], $a->id)->pluck('id')->all());
    }

    public function test_reset_many_resetea_a_todos_y_deja_un_resumen(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $n = $this->service->resetMany(collect([$a, $b]), 'op', 'api', ['except_ids' => []]);

        $this->assertSame(2, $n);
        $this->assertTrue($a->fresh()->must_change_password);
        $this->assertTrue($b->fresh()->must_change_password);
        $resumen = AuditLog::query()->where('action', 'auth.user.password_reset_bulk')->latest('id')->first();
        $this->assertNotNull($resumen);
        $this->assertSame(2, $resumen->metadata['count']);
    }
}
