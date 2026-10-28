<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Models\UserAdditionalRole;
use Firebase\JWT\JWT;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * ADR-006, Fase C — daily 2026-07-10. Antes de este cutover, un usuario con >1 fila en la tabla
 * legada `user_roles` disparaba `requires_role_selection` en login() y necesitaba llamar
 * select-role/switch-role para completar la sesión. Decisión 1 revisada (confirmada por Luis en
 * el daily): con el multi-rol nuevo (`role_id` base + `user_additional_roles`, exclusivo de
 * superadmin), `modulesPayload()`/`effectivePermissionsV2()` ya UNEN automáticamente todos los
 * roles del usuario — no hace falta elegir uno. login() ya no chequea `count(roles()) > 1`, así
 * que SIEMPRE devuelve el JWT en un solo paso, sin importar cuántos roles legados o nuevos tenga
 * el usuario.
 *
 * SCRUM-1035 (A-06): select-role/switch-role y `JwtService::encodeSelectionToken()` se eliminaron (código muerto: login() ya no
 * emite tokens de selección). Abajo se prueba que ya no existen y que un token de selección fabricado no vale como token de acceso.
 */
class MultiRoleLoginTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // ── Login — nunca requiere selección, incluso con roles legados múltiples ──────────────────

    public function test_login_nunca_requiere_seleccion_aunque_el_usuario_tenga_multiples_roles_legados(): void
    {
        // management@atlantic.test tiene ['management', 'superadmin'] en user_roles (legado) —
        // antes del cutover esto disparaba requires_role_selection.
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'management@atlantic.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'token_type', 'expires_in_ms', 'refresh_token', 'user']);
        $this->assertArrayNotHasKey('requires_role_selection', $response->json());
    }

    public function test_login_un_solo_rol_retorna_jwt_directo(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'vendedor@atlantic.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'token_type', 'expires_in_ms', 'user']);
        $this->assertArrayNotHasKey('requires_role_selection', $response->json());
    }

    public function test_login_con_rol_adicional_v2_no_requiere_seleccion_y_las_capacidades_se_unen(): void
    {
        // Rol base: vendedor (solo ve ventas_diseno, sin alcance de equipo).
        // Rol adicional (solo superadmin puede asignarlo): management (ve los 7 módulos con equipo).
        $base       = Role::where('key', 'vendedor')->firstOrFail();
        $additional = Role::where('key', 'management')->firstOrFail();
        $level      = SecurityLevel::where('level', 3)->firstOrFail();

        $user = User::create([
            'first_name' => 'MultiRol', 'last_name' => 'V2',
            'email' => 'multirol-v2@atlantic.test', 'password_hash' => 'Password123!',
            'is_active' => true, 'security_level_id' => $level->id, 'role_id' => $base->id,
        ]);
        UserAdditionalRole::create(['user_id' => $user->id, 'role_id' => $additional->id]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'multirol-v2@atlantic.test', 'password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('requires_role_selection', $response->json());
        $response->assertJsonPath('user.role', 'vendedor');
        // Unión: vendedor no da view_team en ventas_diseno, pero management sí.
        $response->assertJsonPath('user.modules.ventas_diseno.view_team', true);
        // management ve los 7 módulos — la unión también otorga vista en un módulo que
        // vendedor solo no alcanzaba (ej. compras).
        $response->assertJsonPath('user.modules.compras.view', true);
    }

    // ── SCRUM-1035 (A-06): select-role/switch-role eliminados; un token con `type` no es un token de acceso ───────

    private function tokenDeSeleccionFabricado(): string
    {
        $user   = User::where('email', 'management@atlantic.test')->firstOrFail();
        $now    = time();

        // Mismo formato que acuñaba el antiguo encodeSelectionToken(): sin rol ni permisos, con `type = selection`.
        return JWT::encode([
            'sub' => $user->id, 'tenant' => 'atlantic', 'roles' => ['management', 'superadmin'],
            'type' => 'selection', 'iat' => $now, 'exp' => $now + 300,
        ], (string) config('jwt.secret'), 'HS256');
    }

    public function test_select_role_ya_no_existe(): void
    {
        $this->postJson('/api/auth/select-role', ['selection_token' => $this->tokenDeSeleccionFabricado(), 'role' => 'management'])
            ->assertStatus(404);
    }

    public function test_switch_role_ya_no_existe(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))->postJson('/api/auth/switch-role', ['role' => 'management'])
            ->assertStatus(404);
    }

    public function test_un_token_de_seleccion_da_401_en_rutas_protegidas(): void
    {
        $headers = $this->authHeaders($this->tokenDeSeleccionFabricado());

        $this->getJson('/api/auth/me', $headers)->assertStatus(401);
        $this->getJson('/api/crm/projects', $headers)->assertStatus(401);
    }

    public function test_cualquier_token_con_claim_type_se_rechaza_aunque_la_firma_sea_valida(): void
    {
        $user = User::where('email', 'management@atlantic.test')->firstOrFail();
        $now  = time();
        $token = JWT::encode([
            'sub' => $user->id, 'tenant' => 'atlantic', 'email' => $user->email, 'role' => 'management',
            'permissions' => ['superadmin.all'], 'type' => 'otro', 'iat' => $now, 'exp' => $now + 300,
        ], (string) config('jwt.secret'), 'HS256');

        $this->getJson('/api/auth/me', $this->authHeaders($token))->assertStatus(401);
    }

    public function test_un_token_de_acceso_normal_sigue_funcionando(): void
    {
        $token = $this->loginAs('management@atlantic.test');

        $this->getJson('/api/auth/me', $this->authHeaders($token))->assertStatus(200);
    }
}
