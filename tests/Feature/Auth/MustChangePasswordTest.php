<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\FeatureTestCase;

/**
 * Indicador `must_change_password` — middleware AuthenticateJwt, GET /api/auth/me, PUT /api/auth/password y payload de login.
 */
class MustChangePasswordTest extends FeatureTestCase
{
    private const EMAIL = 'vendedor@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /**
     * Deja al usuario demo en el estado que produce un reset administrativo (contraseña = correo + cambio obligatorio) y
     * devuelve el token de un login NUEVO con esa contraseña. Un token emitido antes del reset ya no vale (H1, ver
     * PasswordResetTokenInvalidationTest): lo que ve el usuario real es este login posterior.
     */
    private function tokenTrasElReset(): string
    {
        $this->reMakeCurrent();
        $user = User::where('email', self::EMAIL)->firstOrFail();
        $user->password_hash = self::EMAIL;
        $user->must_change_password = true;
        $user->save();

        return $this->loginAs(self::EMAIL, self::EMAIL, 'designer');
    }

    public function test_con_cambio_obligatorio_me_y_cambiar_contrasena_pasan(): void
    {
        $headers = $this->authHeaders($this->tokenTrasElReset());

        $this->getJson('/api/auth/me', $headers)
            ->assertStatus(200)
            ->assertJsonPath('must_change_password', true);

        $this->putJson('/api/auth/password', [
            'current_password' => self::EMAIL, 'new_password' => 'NuevaClave99!', 'new_password_confirmation' => 'NuevaClave99!',
        ], $headers)->assertStatus(204);
    }

    public function test_con_cambio_obligatorio_cualquier_otra_ruta_da_403_password_change_required(): void
    {
        $headers = $this->authHeaders($this->tokenTrasElReset());

        foreach ([
            ['GET', '/api/auth/mfa/status'],
            ['PATCH', '/api/auth/profile'],
            ['GET', '/api/ventas-diseno/pipeline'],
            ['GET', '/api/compras/inventory'],
        ] as [$method, $url]) {
            $res = $this->json($method, $url, [], $headers);
            $res->assertStatus(403)->assertJsonPath('code', 'password_change_required');
            $this->assertNotSame('', (string) $res->json('message'));
        }

        // Otra ruta de /api/auth (editar el perfil) tampoco pasa: solo GET /me y PUT /password.
        $this->patchJson('/api/auth/profile', ['first_name' => 'Otro'], $headers)->assertStatus(403)->assertJsonPath('code', 'password_change_required');
    }

    public function test_sin_cambio_obligatorio_todo_sigue_normal(): void
    {
        $headers = $this->authHeaders($this->loginAs(self::EMAIL, 'Password123!', 'designer'));

        $this->getJson('/api/auth/me', $headers)->assertStatus(200)->assertJsonPath('must_change_password', false);
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(200);
    }

    public function test_cambiar_la_contrasena_limpia_el_indicador_y_libera_las_rutas(): void
    {
        $headers = $this->authHeaders($this->tokenTrasElReset());

        $this->putJson('/api/auth/password', [
            'current_password' => self::EMAIL, 'new_password' => 'NuevaClave99!', 'new_password_confirmation' => 'NuevaClave99!',
        ], $headers)->assertStatus(204);

        $this->reMakeCurrent();
        $user = User::where('email', self::EMAIL)->firstOrFail();
        $this->assertFalse($user->must_change_password);
        $this->assertTrue(Hash::check('NuevaClave99!', $user->password_hash));

        // Con un login NUEVO tras el cambio, no con el token anterior: `password_changed_at` invalida los tokens emitidos antes del cambio (SCRUM-1033, A-02) y
        // se compara en segundos; reusar el token de antes del cambio solo pasaba si el login y el cambio caían en el mismo segundo (el gate lo vio fallar
        // una vez en 17 minutos de suite, 401 en vez de 200). Un login posterior es determinista y comprueba lo mismo: las rutas quedan libres.
        $fresh = $this->authHeaders($this->loginAs(self::EMAIL, 'NuevaClave99!', 'designer'));
        $this->getJson('/api/auth/mfa/status', $fresh)->assertStatus(200);
        $this->getJson('/api/auth/me', $fresh)->assertJsonPath('must_change_password', false);
    }

    public function test_la_nueva_contrasena_no_puede_ser_el_correo_ni_la_actual(): void
    {
        $headers = $this->authHeaders($this->tokenTrasElReset());

        // Igual al correo (sin distinguir mayúsculas). La nueva debe cumplir primero la política de complejidad
        // (mayúscula, minúscula y número), así que el correo de este usuario se fija con esa forma.
        $this->reMakeCurrent();
        $user = User::where('email', self::EMAIL)->firstOrFail();
        $user->password_hash = 'Designer1@Atlantic.Test'; // actual distinta del correo para aislar la regla del correo
        $user->save();
        $user->forceFill(['email' => 'Designer9@atlantic.test'])->save();

        $this->putJson('/api/auth/password', [
            'current_password' => 'Designer1@Atlantic.Test',
            'new_password' => 'Designer9@Atlantic.test', 'new_password_confirmation' => 'Designer9@Atlantic.test',
        ], $headers)->assertStatus(422)->assertJsonPath('errors.new_password.0', trans('auth.password_same_as_email'));

        // Igual a la actual.
        $this->putJson('/api/auth/password', [
            'current_password' => 'Designer1@Atlantic.Test',
            'new_password' => 'Designer1@Atlantic.Test', 'new_password_confirmation' => 'Designer1@Atlantic.Test',
        ], $headers)->assertStatus(422)->assertJsonPath('errors.new_password.0', trans('auth.password_same_as_current'));

        $this->reMakeCurrent();
        $this->assertTrue(User::where('email', 'Designer9@atlantic.test')->firstOrFail()->must_change_password, 'sigue obligado a cambiarla');
    }

    public function test_un_usuario_inactivo_sigue_recibiendo_401_aunque_tenga_el_indicador(): void
    {
        $headers = $this->authHeaders($this->tokenTrasElReset());
        $this->reMakeCurrent();
        User::where('email', self::EMAIL)->update(['is_active' => false]);

        $this->getJson('/api/auth/me', $headers)->assertStatus(401);
    }
}
