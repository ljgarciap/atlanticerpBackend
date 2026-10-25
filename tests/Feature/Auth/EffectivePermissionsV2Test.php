<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Models\SecurityLevel;
use App\Modules\Auth\Models\SecurityLevelModulePermission;
use App\Modules\Auth\Models\UserAdditionalRole;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * ADR-006, Decisión 7 — Fase B. Prueba el algoritmo nuevo (effectivePermissionsV2/
 * modulesPayload/canViewTeamV2) de forma aislada, con fixtures propios — NO se apoya en el
 * seed de producción de role_module_visibility (que sí testea RolesSeedTest) porque
 * security_level_module_permissions queda vacía en Fase A a propósito, así que ningún usuario
 * real hoy ejercita la mitad "capacidad" del algoritmo. Nada de esto está wireado a JWT/
 * middleware todavía — son solo los métodos nuevos en User, construidos y verificados.
 */
class EffectivePermissionsV2Test extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function makeUser(?int $roleId, int $securityLevelId, array $extraPermissions = []): User
    {
        return User::create([
            'first_name' => 'Test', 'last_name' => 'User', 'email' => uniqid('v2-test-').'@atlantic.test',
            'password_hash' => 'x', 'security_level_id' => $securityLevelId, 'role_id' => $roleId,
            'extra_permissions' => $extraPermissions,
        ]);
    }

    public function test_rol_superadmin_retorna_solo_superadmin_all(): void
    {
        $role = Role::where('key', 'superadmin')->firstOrFail();
        $level = SecurityLevel::where('level', 10)->firstOrFail();
        $user = $this->makeUser($role->id, $level->id, ['crm.write']);

        $this->assertSame(['superadmin.all'], $user->effectivePermissionsV2());
    }

    public function test_usuario_sin_role_id_solo_obtiene_el_escape_hatch_plano(): void
    {
        $level = SecurityLevel::where('level', 3)->firstOrFail();
        $user = $this->makeUser(null, $level->id, ['ventas_diseno.write']);

        $perms = $user->effectivePermissionsV2();

        $this->assertContains('ventas_diseno.write', $perms);
        $this->assertTrue(array_intersect(['ventas_diseno.view', 'ventas_diseno.edit', 'ventas_diseno.approve'], $perms) === []);
    }

    /** Fase 2 — `role_id` es el único mecanismo: un usuario sin `role_id` ni roles adicionales no resuelve ningún rol. */
    public function test_usuario_sin_role_id_no_resuelve_roles(): void
    {
        $level = SecurityLevel::where('level', 9)->firstOrFail();
        $user = $this->makeUser(null, $level->id);
        $user = $user->fresh();

        $this->assertSame([], $user->roleIds());
        $this->assertFalse($user->canViewTeamV2('ventas_diseno'));
    }

    /**
     * Contraparte: un rol legado sin equivalente en el catálogo nuevo (designer/supervisor/
     * electrician/guest — fixtures de demo de AuthUserSeeder, nunca un rol real de negocio) no
     * debe resolver a nada — mismo comportamiento (escape hatch plano únicamente) que antes del
     * fallback, sin over-grant.
     */
    public function test_usuario_sin_role_id_con_rol_legado_sin_equivalente_no_resuelve_nada(): void
    {
        $level = SecurityLevel::where('level', 3)->firstOrFail();
        $user = $this->makeUser(null, $level->id, ['ventas_diseno.write']);
        $user = $user->fresh();

        $this->assertSame([], $user->roleIds());
        $this->assertFalse($user->canViewTeamV2('ventas_diseno'));
        $perms = $user->effectivePermissionsV2();
        $this->assertContains('ventas_diseno.write', $perms); // escape hatch plano, sin cambios
        $this->assertTrue(array_intersect(['ventas_diseno.view', 'ventas_diseno.edit', 'ventas_diseno.approve', 'ventas_diseno.view_team'], $perms) === []);
    }

    public function test_rol_con_vista_de_equipo_mas_nivel_con_editar_y_aprobar(): void
    {
        $role = Role::create(['key' => 'test_role_v2', 'name' => 'Test Role', 'is_superadmin' => false, 'is_system' => false, 'is_active' => true]);
        RoleModuleVisibility::create(['role_id' => $role->id, 'module' => 'ventas_diseno', 'can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => true]);

        $level = SecurityLevel::create(['level' => 51, 'name' => 'Test Level V2', 'permissions' => [], 'is_active' => true]);
        SecurityLevelModulePermission::create(['security_level_id' => $level->id, 'module' => 'ventas_diseno', 'can_edit' => true, 'can_approve' => true]);

        $user = $this->makeUser($role->id, $level->id);
        $perms = $user->effectivePermissionsV2();

        $this->assertContains('ventas_diseno.view', $perms);
        $this->assertContains('ventas_diseno.view_team', $perms);
        $this->assertContains('ventas_diseno.edit', $perms);
        $this->assertContains('ventas_diseno.approve', $perms);
    }

    /**
     * Resuelto con Luis 2026-07-10: las rutas reales (routes/ventas-diseno.php) exigen el
     * vocabulario plano viejo (.read/.write), no el nuevo (.view/.edit) -- sin este puente, un
     * usuario con solo rol+visibilidad (sin extra_permissions individual) pasaba el chequeo de
     * módulo pero el middleware `permission:ventas_diseno.read` de la ruta real lo rechazaba.
     */
    public function test_view_y_edit_generan_tambien_el_vocabulario_viejo_read_y_write(): void
    {
        $role = Role::create(['key' => 'test_role_bridge', 'name' => 'Test Role Bridge', 'is_superadmin' => false, 'is_system' => false, 'is_active' => true]);
        RoleModuleVisibility::create(['role_id' => $role->id, 'module' => 'ventas_diseno', 'can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => false]);

        $level = SecurityLevel::create(['level' => 53, 'name' => 'Test Level Bridge', 'permissions' => [], 'is_active' => true]);
        SecurityLevelModulePermission::create(['security_level_id' => $level->id, 'module' => 'ventas_diseno', 'can_edit' => true, 'can_approve' => false]);

        // Sin extra_permissions -- el acceso real debe venir solo de rol + nivel.
        $user = $this->makeUser($role->id, $level->id, []);
        $perms = $user->effectivePermissionsV2();

        $this->assertContains('ventas_diseno.view', $perms);
        $this->assertContains('ventas_diseno.read', $perms);
        $this->assertContains('ventas_diseno.edit', $perms);
        $this->assertContains('ventas_diseno.write', $perms);
    }

    public function test_nivel_no_puede_otorgar_capacidad_en_modulo_que_el_rol_no_alcanza(): void
    {
        // El rol solo ve ventas_diseno; el nivel tiene can_edit=true en compras (no en su rol) —
        // el rol es el techo, "compras.edit" nunca debe aparecer.
        $role = Role::create(['key' => 'test_role_ceiling', 'name' => 'Test Role Ceiling', 'is_superadmin' => false, 'is_system' => false, 'is_active' => true]);
        RoleModuleVisibility::create(['role_id' => $role->id, 'module' => 'ventas_diseno', 'can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => false]);

        $level = SecurityLevel::create(['level' => 52, 'name' => 'Test Level Ceiling', 'permissions' => [], 'is_active' => true]);
        SecurityLevelModulePermission::create(['security_level_id' => $level->id, 'module' => 'compras', 'can_edit' => true, 'can_approve' => true]);

        $user = $this->makeUser($role->id, $level->id);
        $perms = $user->effectivePermissionsV2();

        $this->assertNotContains('compras.edit', $perms);
        $this->assertNotContains('compras.approve', $perms);
        $this->assertNotContains('compras.view', $perms);
    }

    public function test_can_view_readonly_otorga_vista_sin_read_amplio(): void
    {
        // SCRUM-771 (2026-08-18) — primer uso real de can_view=1 (VIEW_READONLY, reservado desde
        // ADR-006 Decisión 2): el módulo queda navegable (`.view`, el Sidebar lo muestra) pero
        // SIN el permiso amplio `.read` que desbloquearía todas las rutas `permission:{module}.read`
        // del módulo — a diferencia de VIEW_FULL. El acceso real a pantallas puntuales viene de un
        // permiso angosto en extra_permissions (ver compras.limited.view), no de este `can_view`.
        $role = Role::create(['key' => 'test_role_readonly', 'name' => 'Test Role Readonly', 'is_superadmin' => false, 'is_system' => false, 'is_active' => true]);
        RoleModuleVisibility::create(['role_id' => $role->id, 'module' => 'bodega', 'can_view' => RoleModuleVisibility::VIEW_READONLY, 'can_view_team' => false]);

        $level = SecurityLevel::where('level', 3)->firstOrFail();
        SecurityLevelModulePermission::updateOrCreate(
            ['security_level_id' => $level->id, 'module' => 'bodega'],
            ['can_edit' => true, 'can_approve' => true],
        );
        $user = $this->makeUser($role->id, $level->id);
        $perms = $user->effectivePermissionsV2();

        $this->assertContains('bodega.view', $perms);
        $this->assertNotContains('bodega.read', $perms);
        // Readonly nunca gana edit/approve, aunque el nivel sí tenga esa capacidad para el módulo.
        $this->assertNotContains('bodega.edit', $perms);
        $this->assertNotContains('bodega.approve', $perms);
    }

    public function test_can_view_full_en_otro_rol_prevalece_sobre_readonly(): void
    {
        // SCRUM-771 — si CUALQUIER rol del usuario ya da VIEW_FULL sobre un módulo, ese nivel
        // manda: nunca se degrada un módulo que algún rol ya volvió full a solo `.view`.
        $roleReadonly = Role::create(['key' => 'test_role_readonly_2', 'name' => 'Test Role Readonly 2', 'is_superadmin' => false, 'is_system' => false, 'is_active' => true]);
        $roleFull = Role::create(['key' => 'test_role_full_2', 'name' => 'Test Role Full 2', 'is_superadmin' => false, 'is_system' => false, 'is_active' => true]);
        RoleModuleVisibility::create(['role_id' => $roleReadonly->id, 'module' => 'bodega', 'can_view' => RoleModuleVisibility::VIEW_READONLY, 'can_view_team' => false]);
        RoleModuleVisibility::create(['role_id' => $roleFull->id, 'module' => 'bodega', 'can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => false]);

        $level = SecurityLevel::where('level', 3)->firstOrFail();
        $user = $this->makeUser($roleReadonly->id, $level->id);
        UserAdditionalRole::create(['user_id' => $user->id, 'role_id' => $roleFull->id]);

        $perms = $user->fresh()->effectivePermissionsV2();

        $this->assertContains('bodega.view', $perms);
        $this->assertContains('bodega.read', $perms);
    }

    public function test_flags_transversales_se_agregan_independiente_de_rol_y_nivel(): void
    {
        $level = SecurityLevel::where('level', 3)->firstOrFail();
        $user = User::create([
            'first_name' => 'Flags', 'last_name' => 'User', 'email' => uniqid('v2-flags-').'@atlantic.test',
            'password_hash' => 'x', 'security_level_id' => $level->id, 'role_id' => null,
            'extra_permissions' => [], 'approve_large_amounts' => true, 'manage_users' => true,
        ]);

        $perms = $user->effectivePermissionsV2();
        $this->assertContains('flag.approve_large_amounts', $perms);
        $this->assertContains('flag.manage_users', $perms);
    }

    public function test_manage_users_otorga_el_permiso_real_de_administrar_usuarios(): void
    {
        // SCRUM-145 (hallazgo Pre-QA 2026-07-16): el flag transversal "Gestionar usuarios"
        // no otorgaba ninguna capacidad real -- routes/users.php sigue gateado por el
        // permiso legado security.users, sin relacion con este flag. Un actor como Contabilidad
        // (Lider Admin & Contab, manage_users=true por el criterio de aceptacion del
        // ticket) no podia acceder a la administracion de usuarios pese a tener el flag.
        $level = SecurityLevel::where('level', 3)->firstOrFail();
        $user = User::create([
            'first_name' => 'Manage', 'last_name' => 'Users', 'email' => uniqid('v2-manage-').'@atlantic.test',
            'password_hash' => 'x', 'security_level_id' => $level->id, 'role_id' => null,
            'extra_permissions' => [], 'manage_users' => true,
        ]);

        $this->assertContains('security.users', $user->effectivePermissionsV2());
    }

    public function test_sin_manage_users_no_otorga_security_users(): void
    {
        $level = SecurityLevel::where('level', 3)->firstOrFail();
        $user = User::create([
            'first_name' => 'No', 'last_name' => 'Manage', 'email' => uniqid('v2-nomanage-').'@atlantic.test',
            'password_hash' => 'x', 'security_level_id' => $level->id, 'role_id' => null,
            'extra_permissions' => [], 'manage_users' => false,
        ]);

        $this->assertNotContains('security.users', $user->effectivePermissionsV2());
    }

    /**
     * Senior Review, 2026-08-02 (Fase 4 — Servicios, hallazgo CRÍTICO antes de push): un usuario
     * CON `role_id` (el camino real de todo usuario de negocio hoy, ver ADR-006 Fase C) también
     * pasa por `plainEscapeHatchPermissions()` al final de `effectivePermissionsV2()` — el catálogo
     * legado de `SecurityLevel::permissions` se mezcla SIEMPRE, no solo para el fallback de
     * cuentas sin rol. Reasignar un rol a un Nivel 1-7 sin revisar su catálogo legado (ver
     * `2026_08_02_100003`/`100004`) puede otorgar `security.users` sin pasar por el flag
     * `manage_users`, que es el mecanismo real. `2026_08_02_100005` corrigió los Niveles 5/6/7
     * para que ya no lo tengan — este test asegura que no vuelva a colarse en silencio.
     */
    public function test_rol_con_role_id_en_nivel_5_6_o_7_no_obtiene_security_users_del_catalogo_legado(): void
    {
        $role = Role::where('key', 'vendedor_disenador')->firstOrFail();

        foreach ([5, 6, 7] as $levelNumber) {
            $level = SecurityLevel::where('level', $levelNumber)->firstOrFail();
            $user = $this->makeUser($role->id, $level->id);

            $this->assertNotContains(
                'security.users',
                $user->effectivePermissionsV2(),
                "Nivel {$levelNumber} no debería otorgar security.users a un usuario con role_id sin manage_users=true.",
            );
        }
    }

    /**
     * Contraparte del test de arriba: Gerencia real (rol `management`, Nivel 9 "Admin") SÍ depende
     * hoy del catálogo legado para `security.levels`/`settings.global` (ver
     * `SecurityLevelControllerTest`, no hay flag transversal equivalente para esos 2) — el fix de
     * `2026_08_02_100005` NO debe tocar Niveles 8-10.
     */
    public function test_nivel_9_admin_sigue_otorgando_security_levels_y_settings_global(): void
    {
        $role = Role::where('key', 'management')->firstOrFail();
        $level = SecurityLevel::where('level', 9)->firstOrFail();
        $user = $this->makeUser($role->id, $level->id);

        $perms = $user->effectivePermissionsV2();
        $this->assertContains('security.levels', $perms);
        $this->assertContains('settings.global', $perms);
        $this->assertContains('security.users', $perms);
    }

    public function test_modules_payload_incluye_los_7_modulos_con_false_por_defecto(): void
    {
        $level = SecurityLevel::where('level', 3)->firstOrFail();
        $user = $this->makeUser(null, $level->id);

        $payload = $user->modulesPayload();

        $this->assertCount(7, $payload);
        $this->assertArrayHasKey('ventas_diseno', $payload);
        $this->assertSame(['view' => false, 'view_team' => false, 'edit' => false, 'approve' => false], $payload['ventas_diseno']);
    }

    public function test_modules_payload_superadmin_todo_true(): void
    {
        $role = Role::where('key', 'superadmin')->firstOrFail();
        $level = SecurityLevel::where('level', 10)->firstOrFail();
        $user = $this->makeUser($role->id, $level->id);

        $payload = $user->modulesPayload();
        foreach ($payload as $module) {
            $this->assertTrue($module['view'] && $module['view_team'] && $module['edit'] && $module['approve']);
        }
    }

    public function test_modules_payload_refleja_rol_real_del_seed_vendedor_disenador(): void
    {
        $role = Role::where('key', 'vendedor_disenador')->firstOrFail();
        $level = SecurityLevel::where('level', 3)->firstOrFail();
        $user = $this->makeUser($role->id, $level->id);

        $payload = $user->modulesPayload();
        $this->assertTrue($payload['ventas_diseno']['view']);
        $this->assertFalse($payload['ventas_diseno']['view_team']);
        $this->assertFalse($payload['compras']['view']);
    }

    public function test_can_view_team_v2_true_solo_si_el_rol_lo_confirma(): void
    {
        $liderBodega = Role::where('key', 'lider_bodega')->firstOrFail();
        $vendedor = Role::where('key', 'vendedor_disenador')->firstOrFail();
        $level = SecurityLevel::where('level', 3)->firstOrFail();

        $userBodega = $this->makeUser($liderBodega->id, $level->id);
        $userVentas = $this->makeUser($vendedor->id, $level->id);
        $userSinRol = $this->makeUser(null, $level->id);

        $this->assertTrue($userBodega->canViewTeamV2('bodega'));
        $this->assertFalse($userVentas->canViewTeamV2('ventas_diseno'));
        $this->assertFalse($userSinRol->canViewTeamV2('ventas_diseno'));
    }

    public function test_can_view_team_v2_superadmin_siempre_true(): void
    {
        $role = Role::where('key', 'superadmin')->firstOrFail();
        $level = SecurityLevel::where('level', 10)->firstOrFail();
        $user = $this->makeUser($role->id, $level->id);

        $this->assertTrue($user->canViewTeamV2('cualquier_modulo_inventado'));
    }

    // ── Multi-rol — ADR-006, Decisión 1 revisada (2026-07-09) ──────────────────────────────────

    public function test_union_de_visibilidad_entre_rol_base_y_rol_adicional(): void
    {
        $vendedor = Role::where('key', 'vendedor_disenador')->firstOrFail(); // ve solo ventas_diseno
        $liderBodega = Role::where('key', 'lider_bodega')->firstOrFail();    // ve solo bodega, view_team=true
        $level = SecurityLevel::where('level', 3)->firstOrFail();

        $user = $this->makeUser($vendedor->id, $level->id);
        UserAdditionalRole::create(['user_id' => $user->id, 'role_id' => $liderBodega->id]);

        $perms = $user->fresh()->effectivePermissionsV2();

        $this->assertContains('ventas_diseno.view', $perms);
        $this->assertContains('bodega.view', $perms);
        $this->assertContains('bodega.view_team', $perms);
    }

    public function test_can_view_team_v2_true_si_rol_adicional_lo_da_aunque_el_base_no(): void
    {
        $vendedor = Role::where('key', 'vendedor_disenador')->firstOrFail(); // ventas_diseno, view_team=false
        $liderServicios = Role::where('key', 'lider_servicios')->firstOrFail(); // servicios, view_team=true
        $level = SecurityLevel::where('level', 3)->firstOrFail();

        $user = $this->makeUser($vendedor->id, $level->id);
        UserAdditionalRole::create(['user_id' => $user->id, 'role_id' => $liderServicios->id]);
        $user = $user->fresh();

        $this->assertFalse($user->canViewTeamV2('ventas_diseno'));
        $this->assertTrue($user->canViewTeamV2('servicios'));
    }

    public function test_modules_payload_union_de_rol_base_y_adicional(): void
    {
        $vendedor = Role::where('key', 'vendedor_disenador')->firstOrFail();
        $liderBodega = Role::where('key', 'lider_bodega')->firstOrFail();
        $level = SecurityLevel::where('level', 3)->firstOrFail();

        $user = $this->makeUser($vendedor->id, $level->id);
        UserAdditionalRole::create(['user_id' => $user->id, 'role_id' => $liderBodega->id]);
        $payload = $user->fresh()->modulesPayload();

        $this->assertTrue($payload['ventas_diseno']['view']);
        $this->assertTrue($payload['bodega']['view']);
        $this->assertTrue($payload['bodega']['view_team']);
        $this->assertFalse($payload['compras']['view']);
    }

    public function test_rol_adicional_superadmin_da_bypass_total(): void
    {
        $vendedor = Role::where('key', 'vendedor_disenador')->firstOrFail();
        $superadmin = Role::where('key', 'superadmin')->firstOrFail();
        $level = SecurityLevel::where('level', 3)->firstOrFail();

        $user = $this->makeUser($vendedor->id, $level->id);
        UserAdditionalRole::create(['user_id' => $user->id, 'role_id' => $superadmin->id]);

        $this->assertSame(['superadmin.all'], $user->fresh()->effectivePermissionsV2());
    }

    public function test_role_ids_deduplica_si_el_rol_adicional_coincide_con_el_base(): void
    {
        // Caso borde: un admin agrega como "adicional" el mismo rol que ya es el base — no debe
        // contarse dos veces (la tabla user_additional_roles no sabe qué es el rol base del
        // usuario, así que la deduplicación vive en roleIds(), no en una constraint de BD).
        $vendedor = Role::where('key', 'vendedor_disenador')->firstOrFail();
        $level = SecurityLevel::where('level', 3)->firstOrFail();

        $user = $this->makeUser($vendedor->id, $level->id);
        UserAdditionalRole::create(['user_id' => $user->id, 'role_id' => $vendedor->id]);

        $ids = $user->fresh()->roleIds();

        $this->assertSame([$vendedor->id], $ids);
    }
}
