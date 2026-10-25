<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1034 (H7): al crear un usuario, la contraseña temporal (= correo, ver WelcomeMail) obliga a cambiarla en el primer ingreso y NO
 * caduca (decisión de Luis, 2026-10-05: no se sabe si la persona entrará en 48 h). Sin el cambio obligatorio, quien conoce el correo entra
 * como la persona indefinidamente. Fase 2.2: esta política vivía en `approve()` (flujo de registro pendiente, retirado); ahora la aplica `create()`.
 */
class CreatedUserTemporaryPasswordTest extends FeatureTestCase
{
    private const EMAIL = 'creada@atlantic.test';

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function crearUsuario(): void
    {
        Mail::fake();
        $admin = $this->authHeaders($this->loginAs());

        $this->postJson('/api/users', [
            'first_name' => 'Persona', 'last_name' => 'Creada', 'email' => self::EMAIL,
            'role_id' => (int) DB::table('atlantic_auth.roles')->where('key', 'vendedor_disenador')->value('id'),
            'security_level_id' => 3,
        ], $admin)->assertStatus(201);
    }

    public function test_la_cuenta_creada_queda_con_cambio_obligatorio_y_sin_marca_de_caducidad(): void
    {
        $this->crearUsuario();

        $row = DB::table('atlantic_auth.users')->where('email', self::EMAIL)->first();
        $this->assertTrue((bool) $row->is_active);
        $this->assertTrue((bool) $row->must_change_password);
        $this->assertNull($row->password_reset_at, 'sin password_reset_at no hay caducidad');
    }

    public function test_el_primer_ingreso_con_la_temporal_solo_permite_cambiar_la_contrasena(): void
    {
        $this->crearUsuario();
        $headers = $this->authHeaders($this->loginAs(self::EMAIL, self::EMAIL, 'designer'));

        $this->getJson('/api/auth/me', $headers)->assertStatus(200)->assertJsonPath('must_change_password', true);
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(403)->assertJsonPath('code', 'password_change_required');
    }

    public function test_la_contrasena_inicial_no_caduca_aunque_pasen_meses(): void
    {
        $this->crearUsuario();

        Carbon::setTestNow(Carbon::now()->addDays(200));

        $headers = $this->authHeaders($this->loginAs(self::EMAIL, self::EMAIL, 'designer'));
        $this->getJson('/api/auth/me', $headers)->assertStatus(200)->assertJsonPath('must_change_password', true);
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(403)->assertJsonPath('code', 'password_change_required');
    }

    public function test_crear_no_cambia_a_los_usuarios_ya_activos(): void
    {
        $this->assertFalse((bool) DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->value('must_change_password'));
        $this->assertNull(DB::table('atlantic_auth.users')->where('email', 'designer@atlantic.test')->value('password_reset_at'));
    }
}
