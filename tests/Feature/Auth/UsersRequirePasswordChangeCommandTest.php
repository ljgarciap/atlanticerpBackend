<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1034 (H7): `users:require-password-change` marca el cambio obligatorio a las cuentas con contraseña = correo, SIN caducidad,
 * sin tocar la contraseña y sin cerrar sesiones. Simulación por defecto.
 */
class UsersRequirePasswordChangeCommandTest extends FeatureTestCase
{
    private const CON_CLAVE_IGUAL_AL_CORREO = 'inicial-h7@atlantic.test';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function crearCuenta(string $email, string $clave, bool $activa = true, bool $obligatorio = false): int
    {
        $levelId = (int) DB::table('atlantic_auth.security_levels')->where('level', 3)->value('id');

        return (int) DB::table('atlantic_auth.users')->insertGetId([
            'first_name' => 'Prueba', 'last_name' => 'H7', 'email' => $email, 'password_hash' => Hash::make($clave),
            'security_level_id' => $levelId, 'is_active' => $activa, 'must_change_password' => $obligatorio,
            'extra_permissions' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function fila(string $email): object
    {
        return DB::table('atlantic_auth.users')->where('email', $email)->first();
    }

    /** @return array{int, string} */
    private function correr(array $opciones = []): array
    {
        $codigo = Artisan::call('users:require-password-change', $opciones);

        return [$codigo, Artisan::output()];
    }

    /** Cuántas cuentas alcanzaría hoy (la base local trae cuentas semilla con contraseña = correo). */
    private function objetivos(): int
    {
        [, $salida] = $this->correr();
        preg_match('/sin cambio obligatorio: (\d+)/', $salida, $m);

        return (int) ($m[1] ?? -1);
    }

    public function test_la_simulacion_lista_y_no_cambia_nada(): void
    {
        $this->crearCuenta(self::CON_CLAVE_IGUAL_AL_CORREO, self::CON_CLAVE_IGUAL_AL_CORREO);

        [$codigo, $salida] = $this->correr();

        $this->assertSame(0, $codigo);
        $this->assertStringContainsString(self::CON_CLAVE_IGUAL_AL_CORREO, $salida);
        $this->assertStringContainsString('SIMULACIÓN', $salida);
        $this->assertFalse((bool) $this->fila(self::CON_CLAVE_IGUAL_AL_CORREO)->must_change_password);
    }

    public function test_apply_marca_solo_las_cuentas_con_clave_igual_al_correo_y_activas(): void
    {
        $this->crearCuenta(self::CON_CLAVE_IGUAL_AL_CORREO, self::CON_CLAVE_IGUAL_AL_CORREO);
        $this->crearCuenta('propia-h7@atlantic.test', 'MiClavePropia2026x');                         // ya eligió la suya
        $this->crearCuenta('inactiva-h7@atlantic.test', 'inactiva-h7@atlantic.test', activa: false);
        $this->crearCuenta('ya-marcada-h7@atlantic.test', 'ya-marcada-h7@atlantic.test', obligatorio: true);

        [$codigo] = $this->correr(['--apply' => true, '--yes' => true, '--operador' => 'Luis']);

        $this->assertSame(0, $codigo);
        $this->assertTrue((bool) $this->fila(self::CON_CLAVE_IGUAL_AL_CORREO)->must_change_password);
        $this->assertFalse((bool) $this->fila('propia-h7@atlantic.test')->must_change_password, 'una clave propia no se marca');
        $this->assertFalse((bool) $this->fila('inactiva-h7@atlantic.test')->must_change_password, 'una cuenta inactiva no se toca');
        $id = (int) $this->fila(self::CON_CLAVE_IGUAL_AL_CORREO)->id;
        $this->assertSame(1, DB::table('atlantic_auth.audit_logs')->where('action', 'auth.user.password_change_required')->where('entity_id', (string) $id)->count());
        $this->assertSame(1, DB::table('atlantic_auth.audit_logs')->where('action', 'auth.user.password_change_required_bulk')->count());
    }

    public function test_no_toca_la_contrasena_ni_pone_caducidad_ni_cierra_sesiones(): void
    {
        $id = $this->crearCuenta(self::CON_CLAVE_IGUAL_AL_CORREO, self::CON_CLAVE_IGUAL_AL_CORREO);
        $hashAntes = $this->fila(self::CON_CLAVE_IGUAL_AL_CORREO)->password_hash;
        DB::table('atlantic_auth.refresh_tokens')->insert([
            'user_id' => $id, 'token_hash' => hash('sha256', 'x'), 'expires_at' => now()->addDay(), 'revoked' => false, 'created_at' => now(),
            'last_activity_at' => now(), 'login_at' => now(),
        ]);

        $this->correr(['--apply' => true, '--yes' => true, '--operador' => 'Luis']);

        $fila = $this->fila(self::CON_CLAVE_IGUAL_AL_CORREO);
        $this->assertSame($hashAntes, $fila->password_hash, 'la contraseña no cambia');
        $this->assertNull($fila->password_reset_at, 'sin caducidad');
        $this->assertFalse((bool) DB::table('atlantic_auth.refresh_tokens')->where('user_id', $id)->value('revoked'), 'las sesiones siguen abiertas');
    }

    public function test_la_persona_entra_con_la_inicial_queda_restringida_y_no_caduca(): void
    {
        $this->crearCuenta(self::CON_CLAVE_IGUAL_AL_CORREO, self::CON_CLAVE_IGUAL_AL_CORREO);
        $this->correr(['--apply' => true, '--yes' => true, '--operador' => 'Luis']);

        Carbon::setTestNow(Carbon::now()->addDays(200));

        $headers = $this->authHeaders($this->loginAs(self::CON_CLAVE_IGUAL_AL_CORREO, self::CON_CLAVE_IGUAL_AL_CORREO, 'designer'));
        $this->getJson('/api/auth/me', $headers)->assertOk()->assertJsonPath('must_change_password', true);
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(403)->assertJsonPath('code', 'password_change_required');

        // al cambiarla queda libre
        $this->putJson('/api/auth/password', [
            'current_password' => self::CON_CLAVE_IGUAL_AL_CORREO, 'new_password' => 'LaMiaPropia2026x', 'new_password_confirmation' => 'LaMiaPropia2026x',
        ], $headers)->assertStatus(204);
        $this->assertFalse((bool) $this->fila(self::CON_CLAVE_IGUAL_AL_CORREO)->must_change_password);
    }

    public function test_except_excluye_cuentas(): void
    {
        $this->crearCuenta(self::CON_CLAVE_IGUAL_AL_CORREO, self::CON_CLAVE_IGUAL_AL_CORREO);

        $this->correr(['--apply' => true, '--yes' => true, '--operador' => 'Luis', '--except' => 'INICIAL-H7@atlantic.test']);

        $this->assertFalse((bool) $this->fila(self::CON_CLAVE_IGUAL_AL_CORREO)->must_change_password);
    }

    public function test_apply_exige_operador(): void
    {
        [$codigo, $salida] = $this->correr(['--apply' => true, '--yes' => true]);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('--operador es obligatorio', $salida);
    }

    public function test_sin_yes_pide_confirmacion_escrita_y_una_incorrecta_no_aplica(): void
    {
        $this->crearCuenta(self::CON_CLAVE_IGUAL_AL_CORREO, self::CON_CLAVE_IGUAL_AL_CORREO);

        $n = $this->objetivos();
        $this->artisan('users:require-password-change', ['--apply' => true, '--operador' => 'Luis'])
            ->expectsQuestion("Para continuar escribe exactamente: MARCAR TESTING {$n}", 'no')
            ->assertFailed();

        $this->assertFalse((bool) $this->fila(self::CON_CLAVE_IGUAL_AL_CORREO)->must_change_password);
    }

    public function test_yes_se_rechaza_fuera_de_local_y_testing(): void
    {
        config(['app.env' => 'production']);

        [$codigo, $salida] = $this->correr(['--apply' => true, '--yes' => true, '--operador' => 'Luis']);

        $this->assertSame(1, $codigo);
        $this->assertStringContainsString('--yes no se acepta', $salida);
    }
}
