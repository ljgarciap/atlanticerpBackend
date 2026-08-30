<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Models\UserModuleMenuVisibility;
use App\Modules\Auth\Models\UserModuleVisibility;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-724 — override de visibilidad de módulos/menús por usuario, por encima de
 * la visibilidad por rol de RoleControllerTest (mismo permiso, superadmin.all).
 */
class UserModuleVisibilityControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTenant();
    }

    /** Ver nota equivalente en RoleControllerTest — search_path se resetea entre requests. */
    private function activateTenant(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function designerId(): int
    {
        return (int) User::where('email', 'designer@illuminations.test')->value('id');
    }

    // ── GET/PUT /api/admin/users/{id}/module-visibility ─────────────────────────

    public function test_module_visibility_sin_permiso_superadmin_retorna_403(): void
    {
        $token = $this->loginAs('designer@illuminations.test', 'Password123!');
        $this->activateTenant();

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin/users/{$this->designerId()}/module-visibility")
            ->assertStatus(403);
    }

    public function test_get_sin_overrides_devuelve_el_default_del_rol_para_los_7_modulos(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $designerId = $this->designerId();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin/users/{$designerId}/module-visibility")
            ->assertStatus(200);

        $modules = collect($response->json('modules'))->keyBy('module');
        $this->assertCount(7, $modules);
        // vendedor_disenador (rol de designer@) tiene ventas_diseno full, sin equipo.
        $this->assertSame(RoleModuleVisibility::VIEW_FULL, $modules['ventas_diseno']['can_view']);
        $this->assertFalse($modules['ventas_diseno']['can_view_team']);
        // Ningún otro módulo — cae en None por default de rol, no por override.
        $this->assertSame(RoleModuleVisibility::VIEW_NONE, $modules['compras']['can_view']);
        $this->assertSame([], $response->json('menuItems'));
    }

    public function test_put_que_coincide_con_el_default_del_rol_no_persiste_override(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $designerId = $this->designerId();

        // Manda exactamente lo que GET ya devuelve (simula "abrir el modal y Guardar
        // sin tocar nada") — no debe crear ninguna fila de override. A propósito no
        // hardcodea los valores por rol acá: SCRUM-330 (Servicios) le agregó a
        // vendedor_disenador visibilidad de solo lectura sobre `servicios` después de
        // que este test se escribió, así que asumir VIEW_NONE en todo lo que no sea
        // ventas_diseno quedó desactualizado — leer el default real vía GET es lo que
        // el frontend hace en la práctica, y no depende de qué rol tenga qué hoy.
        $modules = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin/users/{$designerId}/module-visibility")
            ->json('modules');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/users/{$designerId}/module-visibility", ['modules' => $modules, 'menuItems' => []])
            ->assertStatus(200);

        $this->activateTenant();
        $this->assertSame(0, UserModuleVisibility::where('user_id', $designerId)->count());
    }

    public function test_put_que_amplia_un_modulo_persiste_override_y_se_refleja_en_modulesPayload(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $designerId = $this->designerId();

        $modules = [
            ['module' => 'ventas_diseno', 'can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => false],
            // designer no tiene Compras por rol — se lo damos puntualmente.
            ['module' => 'compras', 'can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => true],
            ['module' => 'bodega', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'servicios', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'admin_contab', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'gerencia', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'operaciones', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
        ];

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/users/{$designerId}/module-visibility", ['modules' => $modules, 'menuItems' => []])
            ->assertStatus(200);

        $updated = collect($response->json('modules'))->keyBy('module');
        $this->assertSame(RoleModuleVisibility::VIEW_FULL, $updated['compras']['can_view']);
        $this->assertTrue($updated['compras']['can_view_team']);

        $this->activateTenant();
        $this->assertTrue(
            UserModuleVisibility::where('user_id', $designerId)->where('module', 'compras')->exists()
        );

        $designer = User::find($designerId);
        $payload  = $designer->modulesPayload();
        $this->assertTrue($payload['compras']['view']);
        $this->assertTrue($payload['compras']['view_team']);
    }

    public function test_put_que_restringe_un_modulo_del_rol_lo_saca_de_modulesPayload(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $designerId = $this->designerId();

        $modules = [
            // Le sacamos Ventas & Diseño puntualmente, aunque su rol se lo daría.
            ['module' => 'ventas_diseno', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'compras', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'bodega', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'servicios', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'admin_contab', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'gerencia', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'operaciones', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
        ];

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/users/{$designerId}/module-visibility", ['modules' => $modules, 'menuItems' => []])
            ->assertStatus(200);

        $this->activateTenant();
        $designer = User::find($designerId);
        $this->assertFalse($designer->modulesPayload()['ventas_diseno']['view']);
        $this->assertFalse($designer->effectivePermissionsV2() !== [] && in_array('ventas_diseno.view', $designer->effectivePermissionsV2(), true));
    }

    public function test_put_menu_items_oculta_item_explicito_ausente_sigue_visible(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $designerId = $this->designerId();

        $modules = [
            ['module' => 'ventas_diseno', 'can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => false],
            ['module' => 'compras', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'bodega', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'servicios', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'admin_contab', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'gerencia', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
            ['module' => 'operaciones', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false],
        ];

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/users/{$designerId}/module-visibility", [
                'modules'   => $modules,
                'menuItems' => [['module' => 'ventas_diseno', 'key' => 'reports', 'visible' => false]],
            ])
            ->assertStatus(200);

        $this->activateTenant();
        $designer = User::find($designerId);
        $this->assertSame(['ventas_diseno' => ['reports' => false]], $designer->menuVisibilityPayload());
        $this->assertTrue(
            UserModuleMenuVisibility::where('user_id', $designerId)->where('menu_item_key', 'reports')->where('visible', false)->exists()
        );

        // Un segundo PUT sin ese ítem lo vuelve a dejar visible (reemplazo total, no merge).
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/users/{$designerId}/module-visibility", ['modules' => $modules, 'menuItems' => []])
            ->assertStatus(200);

        $this->activateTenant();
        $this->assertSame(0, UserModuleMenuVisibility::where('user_id', $designerId)->count());
    }

    public function test_put_con_modulo_invalido_retorna_422(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $designerId = $this->designerId();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/users/{$designerId}/module-visibility", [
                'modules'   => [['module' => 'no_existe', 'can_view' => 2, 'can_view_team' => false]],
                'menuItems' => [],
            ])
            ->assertStatus(422);
    }
}
