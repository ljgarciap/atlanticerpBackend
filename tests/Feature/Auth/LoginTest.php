<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Models\SystemSetting;
use App\Modules\Auth\Models\UserRole;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Tests del endpoint POST /api/auth/login.
 */
class LoginTest extends FeatureTestCase
{
    public function test_login_con_credenciales_validas_retorna_200_y_token(): void
    {
        // designer has a single role → login returns JWT directly
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'designer@atlantic.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'token_type', 'expires_in_ms', 'user']);
        $response->assertJsonPath('token_type', 'Bearer');
        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_sin_preferencia_personal_usa_idioma_por_defecto_de_organizacion(): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            SystemSetting::updateOrCreate(
                ['key' => 'default_language'],
                ['value' => 'en', 'updated_by' => null, 'updated_at' => now()],
            );

            $user = User::where('email', 'designer@atlantic.test')->firstOrFail();
            $user->preferences = [];
            $user->save();
        } finally {
            Tenant::forgetCurrent();
        }

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'designer@atlantic.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('user.language', 'en');
    }

    public function test_login_sin_preferencia_personal_usa_tiempo_de_sesion_de_organizacion(): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            SystemSetting::updateOrCreate(
                ['key' => 'session_timeout_min'],
                ['value' => 120, 'updated_by' => null, 'updated_at' => now()],
            );

            $user = User::where('email', 'designer@atlantic.test')->firstOrFail();
            $user->preferences = [];
            $user->save();
        } finally {
            Tenant::forgetCurrent();
        }

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'designer@atlantic.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('expires_in_ms', 120 * 60_000);

        $payload = json_decode(base64_decode(strtr(explode('.', (string) $response->json('token'))[1], '-_', '+/')), true);
        $this->assertSame(120 * 60, $payload['exp'] - $payload['iat']);
    }

    public function test_login_con_preferencia_personal_de_sesion_ignora_default_de_organizacion(): void
    {
        /** @var Tenant $tenant */
        $tenant = Tenant::where('slug', 'atlantic')->firstOrFail();
        $tenant->makeCurrent();

        try {
            SystemSetting::updateOrCreate(
                ['key' => 'session_timeout_min'],
                ['value' => 120, 'updated_by' => null, 'updated_at' => now()],
            );

            $user = User::where('email', 'designer@atlantic.test')->firstOrFail();
            $user->preferences = ['session_timeout_min' => 15];
            $user->save();
        } finally {
            Tenant::forgetCurrent();
        }

        $response = $this->postJson('/api/auth/login', [
            'email'    => 'designer@atlantic.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('expires_in_ms', 15 * 60_000);
    }

    public function test_login_con_password_incorrecto_retorna_401(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'management@atlantic.test',
            'password' => 'password_incorrecto',
        ]);

        $response->assertStatus(401);
        $response->assertJsonStructure(['message']);
    }

    public function test_login_con_password_incorrecto_y_accept_language_en_retorna_mensaje_en_ingles(): void
    {
        // SCRUM-29 BUG-06/09: sin este header el mensaje siempre volvía en
        // español, incluso con la UI del frontend en inglés (login sin sesión).
        $response = $this->withHeaders(['Accept-Language' => 'en'])->postJson('/api/auth/login', [
            'email' => 'management@atlantic.test',
            'password' => 'password_incorrecto',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', trans('auth.failed', [], 'en'));
    }

    public function test_login_con_password_incorrecto_y_accept_language_es_retorna_mensaje_en_espanol(): void
    {
        $response = $this->withHeaders(['Accept-Language' => 'es'])->postJson('/api/auth/login', [
            'email' => 'management@atlantic.test',
            'password' => 'password_incorrecto',
        ]);

        $response->assertStatus(401);
        $response->assertJsonPath('message', trans('auth.failed', [], 'es'));
    }

    public function test_login_con_email_inexistente_retorna_401(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'noexiste@atlantic.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(401);
    }

    public function test_login_sin_campos_retorna_422(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(422);
        $response->assertJsonStructure(['message', 'errors']);
    }

    /**
     * Hallazgo de Senior Review 2026-07-10: UserService::approve() (registro público) solo asigna
     * `user_roles` (legado), nunca `role_id`. Sin fallback en JwtClaimsBuilder, un usuario en ese
     * estado (típico de cualquiera aprobado desde el cutover de Fase C en adelante) perdía el
     * claim `role` del JWT -- y con él, el acceso a rutas de CRM gateadas por
     * `role:superadmin,management` que sí tenía antes.
     */
    public function test_login_usuario_sin_role_id_cae_al_rol_legado_de_user_roles(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        $levelId = SecurityLevel::where('level', 9)->firstOrFail()->id;
        $user = User::create([
            'first_name' => 'Aprobado', 'last_name' => 'SinRoleId',
            'email' => 'aprobado.sin.role.id@atlantic.test', 'password_hash' => 'Password123!',
            'is_active' => true, 'security_level_id' => $levelId, 'role_id' => null,
        ]);
        UserRole::create(['user_id' => $user->id, 'role' => 'management']);

        Tenant::forgetCurrent();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'aprobado.sin.role.id@atlantic.test', 'password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('user.role', 'management');
        $response->assertJsonPath('user.role_id', null);

        // Confirma que el fallback realmente restaura el acceso a una ruta de CRM gateada por
        // role:superadmin,management (routes/crm.php), no solo que el claim se vea bien.
        $token = (string) $response->json('token');
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/reports/team')
            ->assertStatus(200);
    }
}
