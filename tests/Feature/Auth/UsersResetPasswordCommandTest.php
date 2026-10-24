<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\SecurityLevel;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\FeatureTestCase;

/** `php artisan users:reset-password` — simulación por defecto, confirmación escrita, individual y masivo. */
class UsersResetPasswordCommandTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** El comando hace Tenant::forgetCurrent() al terminar: se restablece para seguir consultando. */
    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $overrides */
    private function makeUser(array $overrides = []): User
    {
        return User::create([
            'first_name' => 'Persona', 'last_name' => 'Prueba', 'email' => uniqid('cmd-') . '@atlantic.test',
            'password_hash' => 'ClaveOriginal99!', 'security_level_id' => SecurityLevel::where('level', 3)->firstOrFail()->id,
            'is_active' => true,
            ...$overrides,
        ]);
    }

    private function sinCambios(User $user): bool
    {
        $this->reMakeCurrent();
        $fresh = $user->fresh();

        return $fresh !== null && Hash::check('ClaveOriginal99!', $fresh->password_hash) && ! $fresh->must_change_password;
    }

    public function test_sin_apply_es_simulacion_y_no_cambia_nada(): void
    {
        $user = $this->makeUser();

        $this->artisan('users:reset-password', ['--email' => $user->email])
            ->expectsOutputToContain('SIMULACIÓN')
            ->expectsOutputToContain($user->email)
            ->assertExitCode(0);

        $this->assertTrue($this->sinCambios($user));
    }

    public function test_masivo_sin_apply_tampoco_cambia_nada(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();

        $this->artisan('users:reset-password', ['--all-active' => true])->assertExitCode(0);

        $this->assertTrue($this->sinCambios($a));
        $this->assertTrue($this->sinCambios($b));
    }

    public function test_exige_exactamente_una_de_email_o_all_active(): void
    {
        $this->artisan('users:reset-password')->assertExitCode(1);
        $this->artisan('users:reset-password', ['--email' => 'x@y.test', '--all-active' => true])->assertExitCode(1);
    }

    public function test_except_solo_se_acepta_con_all_active(): void
    {
        $user = $this->makeUser();

        $this->artisan('users:reset-password', ['--email' => $user->email, '--except' => 'otro@x.test'])->assertExitCode(1);
    }

    public function test_apply_sin_operador_falla_y_no_cambia_nada(): void
    {
        $user = $this->makeUser();

        $this->artisan('users:reset-password', ['--email' => $user->email, '--apply' => true])
            ->expectsOutputToContain('--operador es obligatorio')
            ->assertExitCode(1);

        $this->assertTrue($this->sinCambios($user));
    }

    public function test_confirmacion_incorrecta_no_aplica_nada(): void
    {
        $user = $this->makeUser();

        $this->artisan('users:reset-password', ['--email' => $user->email, '--apply' => true, '--operador' => 'Luis'])
            ->expectsQuestion('Para continuar escribe exactamente: RESETEAR TESTING 1', 'RESETEAR 1')
            ->expectsOutputToContain('Confirmación incorrecta')
            ->assertExitCode(1);

        $this->assertTrue($this->sinCambios($user));
    }

    public function test_individual_con_confirmacion_correcta_resetea_a_ese_usuario(): void
    {
        $user = $this->makeUser();
        $otro = $this->makeUser();

        $this->artisan('users:reset-password', ['--email' => strtoupper($user->email), '--apply' => true, '--operador' => 'Luis'])
            ->expectsQuestion('Para continuar escribe exactamente: RESETEAR TESTING 1', 'RESETEAR TESTING 1')
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $fresh = $user->fresh();
        $this->assertTrue(Hash::check($user->email, $fresh->password_hash));
        $this->assertTrue($fresh->must_change_password);
        $this->assertTrue($this->sinCambios($otro), 'solo se resetea al usuario indicado');
    }

    public function test_correo_inexistente_falla(): void
    {
        $this->artisan('users:reset-password', ['--email' => 'no-existe@atlantic.test', '--apply' => true, '--operador' => 'Luis'])
            ->expectsOutputToContain('No existe un usuario')
            ->assertExitCode(1);
    }

    public function test_individual_de_un_usuario_inactivo_falla(): void
    {
        $user = $this->makeUser(['is_active' => false]);

        $this->artisan('users:reset-password', ['--email' => $user->email, '--apply' => true, '--operador' => 'Luis'])
            ->expectsOutputToContain('inactivo')
            ->assertExitCode(1);

        $this->reMakeCurrent();
        $this->assertFalse($user->fresh()->must_change_password);
    }

    public function test_masivo_resetea_activos_salta_inactivos_y_respeta_except(): void
    {
        $a = $this->makeUser();
        $excluido = $this->makeUser();
        $inactivo = $this->makeUser(['is_active' => false]);
        // Todos los activos menos el excluido y, por defecto, menos los superadmin (H5).
        $service = app(\App\Modules\Auth\Services\AdminPasswordResetService::class);
        $total = $service->resolveTargets([], [$excluido->email])->count();
        $this->assertLessThan($service->resolveTargets([], [$excluido->email], null, true)->count(), $total, 'hay superadmins demo que el masivo deja fuera');

        $this->artisan('users:reset-password', ['--all-active' => true, '--except' => $excluido->email, '--apply' => true, '--operador' => 'Luis'])
            ->expectsQuestion("Para continuar escribe exactamente: RESETEAR TESTING {$total}", "RESETEAR TESTING {$total}")
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertTrue($a->fresh()->must_change_password);
        $this->assertTrue($this->sinCambios($excluido), 'el correo excluido no se toca');
        $this->assertFalse($inactivo->fresh()->must_change_password, 'los inactivos no se resetean');
    }

    public function test_masivo_include_superadmins_los_alcanza_y_la_confirmacion_cuenta_el_conjunto_ampliado(): void
    {
        $a = $this->makeUser();
        $superId = (int) User::where('email', 'superadmin@atlantic.test')->value('id');
        $service = app(\App\Modules\Auth\Services\AdminPasswordResetService::class);
        $total = $service->resolveTargets([], [], null, true)->count();

        $this->artisan('users:reset-password', ['--all-active' => true, '--include-superadmins' => true, '--apply' => true, '--operador' => 'Luis'])
            ->expectsQuestion("Para continuar escribe exactamente: RESETEAR TESTING {$total}", "RESETEAR TESTING {$total}")
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertTrue($a->fresh()->must_change_password);
        $this->assertTrue(User::findOrFail($superId)->must_change_password, 'con --include-superadmins sí se resetea');
    }

    public function test_masivo_por_defecto_no_toca_nivel_10_ni_superadmin_all_y_la_confirmacion_cuenta_ese_conjunto(): void
    {
        // N1: cuentas con bypass total sin rol superadmin.
        $nivel10 = $this->makeUser(['security_level_id' => \App\Modules\Auth\Models\SecurityLevel::where('level', 10)->firstOrFail()->id]);
        $conExtra = $this->makeUser(['extra_permissions' => ['superadmin.all']]);
        $normal = $this->makeUser();
        $service = app(\App\Modules\Auth\Services\AdminPasswordResetService::class);
        $total = $service->resolveTargets()->count();

        $this->artisan('users:reset-password', ['--all-active' => true, '--apply' => true, '--operador' => 'Luis'])
            ->expectsQuestion("Para continuar escribe exactamente: RESETEAR TESTING {$total}", "RESETEAR TESTING {$total}")
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertTrue($normal->fresh()->must_change_password);
        $this->assertFalse($nivel10->fresh()->must_change_password, 'nivel 10 queda fuera por defecto');
        $this->assertFalse($conExtra->fresh()->must_change_password, 'superadmin.all en extra_permissions queda fuera por defecto');
    }

    public function test_include_superadmins_solo_se_usa_con_all_active(): void
    {
        $user = $this->makeUser();

        $this->artisan('users:reset-password', ['--email' => $user->email, '--include-superadmins' => true])
            ->assertExitCode(1);
        $this->assertTrue($this->sinCambios($user));
    }

    public function test_en_produccion_la_confirmacion_exige_la_palabra_produccion(): void
    {
        config(['app.env' => 'production']);
        $user = $this->makeUser();

        // "TESTING"/"PRODUCTION" no sirven: la etiqueta exacta es PRODUCCION.
        $this->artisan('users:reset-password', ['--email' => $user->email, '--apply' => true, '--operador' => 'Luis'])
            ->expectsQuestion('Para continuar escribe exactamente: RESETEAR PRODUCCION 1', 'RESETEAR PRODUCTION 1')
            ->assertExitCode(1);
        $this->assertTrue($this->sinCambios($user));

        $this->artisan('users:reset-password', ['--email' => $user->email, '--apply' => true, '--operador' => 'Luis'])
            ->expectsQuestion('Para continuar escribe exactamente: RESETEAR PRODUCCION 1', 'RESETEAR PRODUCCION 1')
            ->assertExitCode(0);
        $this->reMakeCurrent();
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_yes_se_rechaza_fuera_de_local_y_testing(): void
    {
        config(['app.env' => 'production']);
        $user = $this->makeUser();

        $this->artisan('users:reset-password', ['--email' => $user->email, '--apply' => true, '--yes' => true, '--operador' => 'Luis'])
            ->expectsOutputToContain('--yes no se acepta')
            ->assertExitCode(1);
        $this->assertTrue($this->sinCambios($user));
    }

    public function test_yes_en_testing_omite_la_confirmacion(): void
    {
        $user = $this->makeUser();

        $this->artisan('users:reset-password', ['--email' => $user->email, '--apply' => true, '--yes' => true, '--operador' => 'Luis'])
            ->assertExitCode(0);

        $this->reMakeCurrent();
        $this->assertTrue($user->fresh()->must_change_password);
    }

    public function test_la_salida_nunca_imprime_contrasenas_ni_hashes(): void
    {
        $user = $this->makeUser();

        $this->artisan('users:reset-password', ['--email' => $user->email, '--apply' => true, '--yes' => true, '--operador' => 'Luis'])
            ->doesntExpectOutputToContain('$2y$')
            ->doesntExpectOutputToContain('ClaveOriginal99!')
            ->assertExitCode(0);
    }
}
