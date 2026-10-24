<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Services\AdminPasswordResetService;
use App\Shared\Auth\JwtService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Carbon;
use Tests\Feature\FeatureTestCase;

/**
 * H1 (revisión de seguridad del reset administrativo): un access token emitido ANTES de un reset administrativo ya no vale.
 * `AuthenticateJwt` compara el `iat` del token con `users.password_reset_at`.
 */
class PasswordResetTokenInvalidationTest extends FeatureTestCase
{
    private const EMAIL = 'designer@atlantic.test';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function reMakeCurrent(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function usuario(): User
    {
        $this->reMakeCurrent();

        return User::where('email', self::EMAIL)->firstOrFail();
    }

    private function resetAdministrativo(): void
    {
        app(AdminPasswordResetService::class)->resetUser($this->usuario(), 'luis@example.test', 'comando');
        $this->reMakeCurrent();
    }

    public function test_token_previo_al_reset_da_401_en_rutas_normales_me_y_cambio_de_contrasena(): void
    {
        $headers = $this->authHeaders($this->loginAs(self::EMAIL, 'Password123!', 'designer'));
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(200); // antes del reset funciona

        sleep(1); // el reset cae en un segundo posterior al `iat` del token
        $this->resetAdministrativo();

        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(401);
        $this->getJson('/api/auth/me', $headers)->assertStatus(401);
        // El atacante con el token viejo NO puede fijar su propia contraseña usando el correo como actual.
        $this->putJson('/api/auth/password', [
            'current_password' => self::EMAIL, 'new_password' => 'TomaDeCuenta99!', 'new_password_confirmation' => 'TomaDeCuenta99!',
        ], $headers)->assertStatus(401);

        $this->reMakeCurrent();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check(self::EMAIL, $this->usuario()->password_hash), 'la contraseña sigue siendo el correo');
    }

    public function test_un_login_nuevo_despues_del_reset_funciona_y_pide_cambiar_la_contrasena(): void
    {
        $this->resetAdministrativo();
        sleep(1); // `iat` (segundos) debe quedar por encima de `password_reset_at` (microsegundos)

        $headers = $this->authHeaders($this->loginAs(self::EMAIL, self::EMAIL, 'designer'));

        $this->getJson('/api/auth/me', $headers)->assertStatus(200)->assertJsonPath('must_change_password', true);
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(403)->assertJsonPath('code', 'password_change_required');
    }

    public function test_el_cambio_de_contrasena_propio_no_toca_password_reset_at_pero_invalida_el_token_previo(): void
    {
        // SCRUM-1033 (A-02): antes este test afirmaba que el token seguía valiendo; ahora lo invalida `password_changed_at`.
        $headers = $this->authHeaders($this->loginAs(self::EMAIL, 'Password123!', 'designer'));
        sleep(1);

        $this->putJson('/api/auth/password', [
            'current_password' => 'Password123!', 'new_password' => 'OtraClave2026x', 'new_password_confirmation' => 'OtraClave2026x',
        ], $headers)->assertStatus(204);

        $this->reMakeCurrent();
        $this->assertNull($this->usuario()->password_reset_at, 'changePassword no toca password_reset_at');
        $this->assertNotNull($this->usuario()->password_changed_at);
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(401);
    }

    public function test_un_usuario_sin_password_reset_at_no_se_ve_afectado(): void
    {
        $headers = $this->authHeaders($this->loginAs(self::EMAIL, 'Password123!', 'designer'));
        $this->assertNull($this->usuario()->password_reset_at);

        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(200);
        $this->getJson('/api/auth/me', $headers)->assertStatus(200);
    }

    public function test_borde_del_iat_con_password_reset_at_manipulado(): void
    {
        $token = $this->loginAs(self::EMAIL, 'Password123!', 'designer');
        $headers = $this->authHeaders($token);
        $payload = app(JwtService::class)->decode($token);
        $this->assertIsArray($payload);
        $iat = (int) $payload['iat'];

        // Reset en el futuro: el token es anterior -> 401.
        $this->reMakeCurrent();
        User::where('email', self::EMAIL)->update(['password_reset_at' => Carbon::now()->addHour()]);
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(401);

        // Reset en el pasado: el token es posterior -> pasa.
        $this->reMakeCurrent();
        User::where('email', self::EMAIL)->update(['password_reset_at' => Carbon::now()->subHour()]);
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(200);

        // Igualdad exacta (iat == reset_at, microsegundos 0): pasa.
        $this->reMakeCurrent();
        User::where('email', self::EMAIL)->update(['password_reset_at' => Carbon::createFromTimestamp($iat, (string) config('app.timezone'))->format('Y-m-d H:i:s.uP')]);
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(200);

        // Un microsegundo después del `iat`: el token es anterior al reset -> 401.
        $this->reMakeCurrent();
        User::where('email', self::EMAIL)->update(['password_reset_at' => Carbon::createFromTimestamp($iat, (string) config('app.timezone'))->addMicrosecond()->format('Y-m-d H:i:s.uP')]);
        $this->getJson('/api/auth/mfa/status', $headers)->assertStatus(401);
    }
}
