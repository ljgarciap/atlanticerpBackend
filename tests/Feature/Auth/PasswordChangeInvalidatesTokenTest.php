<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-1033 (A-02): tras el cambio de contraseña propio, los access tokens emitidos antes dejan de valer
 * (`users.password_changed_at` vs `iat`), pero uno emitido después sí. Mismo mecanismo que H1.
 */
class PasswordChangeInvalidatesTokenTest extends FeatureTestCase
{
    private const EMAIL = 'designer@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function cambiar(array $headers, string $actual, string $nueva): void
    {
        $this->putJson('/api/auth/password', [
            'current_password' => $actual, 'new_password' => $nueva, 'new_password_confirmation' => $nueva,
        ], $headers)->assertStatus(204);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_el_token_previo_al_cambio_da_401_y_el_posterior_funciona(): void
    {
        $viejo = $this->authHeaders($this->loginAs(self::EMAIL, 'Password123!', 'designer'));
        $otraSesion = $this->authHeaders($this->loginAs(self::EMAIL, 'Password123!', 'designer'));
        $this->getJson('/api/auth/mfa/status', $viejo)->assertStatus(200);

        sleep(1);   // el cambio cae en un segundo posterior al `iat` de los tokens previos
        $this->cambiar($viejo, 'Password123!', 'OtraClave2026x');

        $this->getJson('/api/auth/mfa/status', $viejo)->assertStatus(401);
        $this->getJson('/api/auth/me', $otraSesion)->assertStatus(401);   // cualquier otra sesión abierta también

        sleep(1);
        $nuevo = $this->authHeaders($this->loginAs(self::EMAIL, 'OtraClave2026x', 'designer'));
        $this->getJson('/api/auth/mfa/status', $nuevo)->assertStatus(200);
    }

    public function test_un_cambio_rechazado_no_invalida_nada(): void
    {
        $headers = $this->authHeaders($this->loginAs(self::EMAIL, 'Password123!', 'designer'));
        sleep(1);

        $this->putJson('/api/auth/password', [
            'current_password' => 'incorrecta', 'new_password' => 'OtraClave2026x', 'new_password_confirmation' => 'OtraClave2026x',
        ], $headers)->assertStatus(422);
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $this->assertNull(User::where('email', self::EMAIL)->firstOrFail()->password_changed_at);
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(200);
    }

    public function test_un_usuario_sin_cambios_de_contrasena_no_se_ve_afectado(): void
    {
        $headers = $this->authHeaders($this->loginAs(self::EMAIL, 'Password123!', 'designer'));

        $this->assertNull(DB::table('atlantic_auth.users')->where('email', self::EMAIL)->value('password_changed_at'));
        $this->getJson('/api/auth/me', $headers)->assertStatus(200);
    }

    public function test_un_login_inmediato_tras_el_cambio_vale_sin_esperar(): void
    {
        $headers = $this->authHeaders($this->loginAs(self::EMAIL, 'Password123!', 'designer'));
        $this->cambiar($headers, 'Password123!', 'OtraClave2026x');

        // Sin sleep: es el flujo de ForcePasswordChangePage (cambia y vuelve a entrar al instante).
        $nuevo = $this->authHeaders($this->loginAs(self::EMAIL, 'OtraClave2026x', 'designer'));
        $this->getJson('/api/auth/mfa/status', $nuevo)->assertStatus(200);
    }
}
