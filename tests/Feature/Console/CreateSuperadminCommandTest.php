<?php

declare(strict_types=1);

namespace Tests\Feature\Console;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4d (docs/specs/fase4d-superadmin-inicial.md) — `tenant:create-superadmin`: alta del primer superadmin con contraseña aleatoria mostrada una vez.
 */
class CreateSuperadminCommandTest extends FeatureTestCase
{
    private const EMAIL = 'admin.inicial@empresa.test';

    private function usuario(): ?User
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return User::query()->where('email', self::EMAIL)->first();
    }

    public function test_crea_un_superadmin_nivel_10_con_cambio_de_contrasena_obligatorio(): void
    {
        $this->artisan('tenant:create-superadmin', ['slug' => 'atlantic', 'email' => self::EMAIL])
            ->expectsOutputToContain('creado en el tenant [atlantic]')
            ->assertExitCode(0);

        $u = $this->usuario();
        $this->assertNotNull($u);
        $this->assertTrue($u->is_active);
        $this->assertTrue($u->must_change_password);
        $this->assertSame((int) DB::table('atlantic_auth.roles')->where('key', 'superadmin')->value('id'), (int) $u->role_id);
        $this->assertSame((int) DB::table('atlantic_auth.security_levels')->where('level', 10)->value('id'), (int) $u->security_level_id);
        $this->assertContains('superadmin.all', $u->effectivePermissionsV2());
    }

    public function test_la_contrasena_generada_es_aleatoria_se_muestra_una_vez_y_permite_ingresar(): void
    {
        $this->assertSame(0, Artisan::call('tenant:create-superadmin', ['slug' => 'atlantic', 'email' => self::EMAIL]));
        $out = Artisan::output();
        $this->assertMatchesRegularExpression('/no queda guardada\): (\S{24})/', $out);
        preg_match('/no queda guardada\): (\S{24})/', $out, $m);

        $u = $this->usuario();
        $this->assertNotNull($u);
        $this->assertTrue(Hash::check($m[1], $u->password_hash));
        $this->assertNotSame(self::EMAIL, $m[1]);
    }

    public function test_con_password_explicita_no_la_imprime(): void
    {
        $this->artisan('tenant:create-superadmin', ['slug' => 'atlantic', 'email' => self::EMAIL, '--password' => 'una-clave-larga-123'])
            ->doesntExpectOutputToContain('una-clave-larga-123')
            ->assertExitCode(0);

        $this->assertTrue(Hash::check('una-clave-larga-123', (string) $this->usuario()?->password_hash));
    }

    public function test_no_escribe_la_contrasena_en_logs(): void
    {
        Log::spy();
        $this->artisan('tenant:create-superadmin', ['slug' => 'atlantic', 'email' => self::EMAIL, '--password' => 'una-clave-larga-123'])->assertExitCode(0);

        Log::shouldNotHaveReceived('info', fn ($message, $context = []) => str_contains(json_encode([$message, $context]) ?: '', 'una-clave-larga-123'));
        $this->assertTrue(true);
    }

    public function test_no_pisa_un_usuario_existente(): void
    {
        $this->artisan('tenant:create-superadmin', ['slug' => 'atlantic', 'email' => self::EMAIL, '--password' => 'primera-clave-123'])->assertExitCode(0);
        $this->artisan('tenant:create-superadmin', ['slug' => 'atlantic', 'email' => self::EMAIL, '--password' => 'otra-clave-distinta-1'])
            ->expectsOutputToContain('Ya existe un usuario')
            ->assertExitCode(1);

        $this->assertTrue(Hash::check('primera-clave-123', (string) $this->usuario()?->password_hash));
    }

    public function test_rechaza_tenant_inexistente_correo_invalido_y_contrasena_corta(): void
    {
        $this->artisan('tenant:create-superadmin', ['slug' => 'no-existe', 'email' => self::EMAIL])->assertExitCode(1);
        $this->artisan('tenant:create-superadmin', ['slug' => 'atlantic', 'email' => 'no-es-correo'])->assertExitCode(1);
        $this->artisan('tenant:create-superadmin', ['slug' => 'atlantic', 'email' => self::EMAIL, '--password' => 'corta'])->assertExitCode(1);

        $this->assertNull($this->usuario());
    }
}
