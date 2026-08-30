<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Models\UserAdditionalRole;
use App\Shared\Auth\JwtService;
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
 * select-role/switch-role (y `JwtService::encodeSelectionToken()`) quedan en el código — no se
 * borraron por decisión explícita de Luis ("no se borran, pero no se ejercitan") — pero login()
 * ya no los invoca. Se prueban acá de forma aislada (token de selección armado a mano) solo para
 * confirmar que siguen funcionando si alguna vez se re-wirean, no porque un usuario real hoy
 * pueda llegar a ellos vía login.
 */
class MultiRoleLoginTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    // ── Login — nunca requiere selección, incluso con roles legados múltiples ──────────────────

    public function test_login_nunca_requiere_seleccion_aunque_el_usuario_tenga_multiples_roles_legados(): void
    {
        // management@illuminations.test tiene ['management', 'superadmin'] en user_roles (legado) —
        // antes del cutover esto disparaba requires_role_selection.
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'management@illuminations.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'token_type', 'expires_in_ms', 'refresh_token', 'user']);
        $this->assertArrayNotHasKey('requires_role_selection', $response->json());
    }

    public function test_login_un_solo_rol_retorna_jwt_directo(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email'    => 'designer@illuminations.test',
            'password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'token_type', 'expires_in_ms', 'user']);
        $this->assertArrayNotHasKey('requires_role_selection', $response->json());
    }

    public function test_login_con_rol_adicional_v2_no_requiere_seleccion_y_las_capacidades_se_unen(): void
    {
        // Rol base: vendedor_disenador (solo ve ventas_diseno, sin alcance de equipo).
        // Rol adicional (solo superadmin puede asignarlo): management (ve los 7 módulos con equipo).
        $base       = Role::where('key', 'vendedor_disenador')->firstOrFail();
        $additional = Role::where('key', 'management')->firstOrFail();
        $level      = SecurityLevel::where('level', 3)->firstOrFail();

        $user = User::create([
            'first_name' => 'MultiRol', 'last_name' => 'V2',
            'email' => 'multirol-v2@illuminations.test', 'password_hash' => 'Password123!',
            'is_active' => true, 'security_level_id' => $level->id, 'role_id' => $base->id,
        ]);
        UserAdditionalRole::create(['user_id' => $user->id, 'role_id' => $additional->id]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'multirol-v2@illuminations.test', 'password' => 'Password123!',
        ]);

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('requires_role_selection', $response->json());
        $response->assertJsonPath('user.role', 'vendedor_disenador');
        // Unión: vendedor_disenador no da view_team en ventas_diseno, pero management sí.
        $response->assertJsonPath('user.modules.ventas_diseno.view_team', true);
        // management ve los 7 módulos — la unión también otorga vista en un módulo que
        // vendedor_disenador solo no alcanzaba (ej. compras).
        $response->assertJsonPath('user.modules.compras.view', true);
    }

    // ── select-role / switch-role — sin llamador real desde login(), se prueban aislados ───────

    public function test_select_role_valido_retorna_jwt(): void
    {
        $user = User::where('email', 'management@illuminations.test')->firstOrFail();
        $tenant = Tenant::where('slug', 'illuminations')->firstOrFail();
        $selectionToken = app(JwtService::class)->encodeSelectionToken($user->id, ['management', 'superadmin'], $tenant->slug);

        $response = $this->postJson('/api/auth/select-role', [
            'selection_token' => $selectionToken,
            'role'            => 'management',
        ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['token', 'refresh_token', 'user']);
        $response->assertJsonPath('user.role', 'management');
    }

    public function test_select_role_con_rol_no_disponible_retorna_403(): void
    {
        $user = User::where('email', 'management@illuminations.test')->firstOrFail();
        $tenant = Tenant::where('slug', 'illuminations')->firstOrFail();
        $selectionToken = app(JwtService::class)->encodeSelectionToken($user->id, ['management'], $tenant->slug);

        $this->postJson('/api/auth/select-role', [
            'selection_token' => $selectionToken,
            'role'            => 'superadmin',
        ])->assertStatus(403);
    }

    public function test_select_role_con_token_invalido_retorna_401(): void
    {
        $this->postJson('/api/auth/select-role', [
            'selection_token' => 'token.invalido.aqui',
            'role'            => 'management',
        ])->assertStatus(401);
    }

    public function test_select_role_sin_campos_retorna_422(): void
    {
        $this->postJson('/api/auth/select-role', [])->assertStatus(422);
    }

    public function test_switch_role_valido_retorna_nuevo_token(): void
    {
        $user = User::where('email', 'management@illuminations.test')->firstOrFail();
        $tenant = Tenant::where('slug', 'illuminations')->firstOrFail();
        $selectionToken = app(JwtService::class)->encodeSelectionToken($user->id, ['management', 'designer'], $tenant->slug);

        $selectResponse = $this->postJson('/api/auth/select-role', [
            'selection_token' => $selectionToken,
            'role'            => 'management',
        ]);
        $selectResponse->assertStatus(200);
        $jwt = $selectResponse->json('token');

        $switchResponse = $this->withHeaders($this->authHeaders($jwt))
            ->postJson('/api/auth/switch-role', ['role' => 'designer']);

        $switchResponse->assertStatus(200);
        $switchResponse->assertJsonPath('user.role', 'designer');
        $this->assertNotEquals($jwt, $switchResponse->json('token'));
    }

    public function test_switch_role_con_rol_no_disponible_retorna_403(): void
    {
        $user = User::where('email', 'management@illuminations.test')->firstOrFail();
        $tenant = Tenant::where('slug', 'illuminations')->firstOrFail();
        $selectionToken = app(JwtService::class)->encodeSelectionToken($user->id, ['management'], $tenant->slug);

        $selectResponse = $this->postJson('/api/auth/select-role', [
            'selection_token' => $selectionToken,
            'role'            => 'management',
        ]);
        $jwt = $selectResponse->json('token');

        $this->withHeaders($this->authHeaders($jwt))
            ->postJson('/api/auth/switch-role', ['role' => 'electrician'])
            ->assertStatus(403);
    }

    public function test_switch_role_sin_autenticar_retorna_401(): void
    {
        $this->postJson('/api/auth/switch-role', ['role' => 'management'])
            ->assertStatus(401);
    }

    /**
     * Hallazgo de Senior Review 2026-07-10 (3 ángulos de revisión independientes lo encontraron):
     * un JWT emitido por login() normal (JwtClaimsBuilder, sin 'roles' plural) pegándole a
     * switch-role crasheaba con 500 (in_array() con null) en vez de devolver 403. Corregido en
     * AuthController::switchRole() con un default a array vacío.
     */
    public function test_switch_role_con_jwt_de_login_normal_sin_roles_retorna_403_no_500(): void
    {
        $token = $this->loginAs('designer@illuminations.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/auth/switch-role', ['role' => 'management'])
            ->assertStatus(403);
    }
}
