<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Mail\AccountActivationMail;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1034 (H7): al crear un usuario, la cuenta queda SIN clave utilizable y la persona elige la suya con un enlace de un solo uso
 * (Fase 12c, decisión de Luis 2026-10-30: reemplaza la clave inicial = correo, que no caducaba y dejaba entrar a quien conociera el correo). Fase 2.2: esta política vivía en `approve()` (flujo de registro pendiente, retirado); ahora la aplica `create()`.
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
            'role_id' => (int) DB::table('atlantic_auth.roles')->where('key', 'vendedor')->value('id'),
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

    public function test_la_cuenta_creada_no_entra_con_su_correo_y_recibe_el_enlace_de_activacion(): void
    {
        $this->crearUsuario();

        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => self::EMAIL])->assertStatus(401);
        Mail::assertQueued(AccountActivationMail::class, fn (AccountActivationMail $m): bool => $m->hasTo(self::EMAIL) && $m->validHours === 168);
    }

    public function test_el_enlace_deja_elegir_la_clave_una_sola_vez_y_despues_entra_sin_cambio_pendiente(): void
    {
        $this->crearUsuario();
        $enlace = $this->enlaceDeActivacion();

        $this->postJson('/api/auth/reset-password', ['token' => $enlace, 'password' => 'ClaveElegida99!x', 'password_confirmation' => 'ClaveElegida99!x'])->assertStatus(200);
        $this->postJson('/api/auth/reset-password', ['token' => $enlace, 'password' => 'OtraClave99!xx', 'password_confirmation' => 'OtraClave99!xx'])->assertStatus(422);

        $this->postJson('/api/auth/login', ['email' => self::EMAIL, 'password' => 'ClaveElegida99!x'])
            ->assertStatus(200)->assertJsonPath('user.must_change_password', false);
    }

    public function test_el_enlace_vence_con_la_vigencia_configurada_y_uno_nuevo_anula_al_anterior(): void
    {
        $this->crearUsuario();
        $viejo = $this->enlaceDeActivacion();
        $nuevo = $this->enlaceDeActivacion();

        $this->postJson('/api/auth/reset-password', ['token' => $viejo, 'password' => 'ClaveElegida99!x', 'password_confirmation' => 'ClaveElegida99!x'])->assertStatus(422);

        Carbon::setTestNow(Carbon::now()->addHours(169));
        $this->postJson('/api/auth/reset-password', ['token' => $nuevo, 'password' => 'ClaveElegida99!x', 'password_confirmation' => 'ClaveElegida99!x'])->assertStatus(422);
    }

    public function test_superadmin_puede_reenviar_el_enlace_solo_a_una_cuenta_que_no_ha_elegido_su_clave(): void
    {
        $this->crearUsuario();
        $id = (int) DB::table('atlantic_auth.users')->where('email', self::EMAIL)->value('id');
        $admin = $this->authHeaders($this->loginAs());
        Mail::fake();

        $this->postJson("/api/users/{$id}/activation-link", [], $admin)->assertStatus(200);
        Mail::assertQueued(AccountActivationMail::class, fn (AccountActivationMail $m): bool => $m->hasTo(self::EMAIL));

        $activa = (int) DB::table('atlantic_auth.users')->where('email', 'vendedor@atlantic.test')->value('id');
        $this->postJson("/api/users/{$activa}/activation-link", [], $admin)->assertStatus(422);

        $lista = $this->getJson('/api/users?search=vendedor@atlantic.test', $admin)->assertStatus(200)->json('data');
        $this->assertFalse($lista[0]['pending_activation'], 'una cuenta que ya eligió su clave no está pendiente');
        $pendiente = $this->getJson('/api/users?search='.self::EMAIL, $admin)->assertStatus(200)->json('data');
        $this->assertTrue($pendiente[0]['pending_activation'], 'la recién creada sí');
    }

    public function test_el_comando_de_soporte_imprime_un_enlace_que_funciona_sin_enviar_correo(): void
    {
        $this->crearUsuario();
        Mail::fake();

        $this->artisan('users:activation-link', ['slug' => 'atlantic', 'email' => self::EMAIL])->assertExitCode(0);
        $this->artisan('users:activation-link', ['slug' => 'atlantic', 'email' => 'no-existe@atlantic.test'])->assertExitCode(1);
        Mail::assertNothingQueued();
    }

    /** El token en claro solo existe en el enlace: se emite uno nuevo con el servicio, igual que el comando de soporte. */
    private function enlaceDeActivacion(): string
    {
        \App\Shared\Multitenancy\Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $user = \App\Models\User::where('email', self::EMAIL)->firstOrFail();
        $url = (string) app(\App\Modules\Auth\Services\PasswordResetService::class)->activationLink($user);

        return substr($url, (int) strpos($url, '#token=') + 7);
    }

    public function test_crear_no_cambia_a_los_usuarios_ya_activos(): void
    {
        $this->assertFalse((bool) DB::table('atlantic_auth.users')->where('email', 'vendedor@atlantic.test')->value('must_change_password'));
        $this->assertNull(DB::table('atlantic_auth.users')->where('email', 'vendedor@atlantic.test')->value('password_reset_at'));
    }
}
