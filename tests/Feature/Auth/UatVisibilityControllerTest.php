<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Models\UserModuleVisibility;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-739 (UAT-2) — GET/POST /api/admin/module-visibility/bulk, máscara global de rollout
 * (UatVisibilityService), independiente del override individual de SCRUM-724
 * (UserModuleVisibilityControllerTest). Reemplaza el diseño original (que reusaba
 * UserModuleVisibility para el bulk) tras un hallazgo real de Pre-QA: ese diseño permitía que un
 * "restore" masivo borrara un override individual preexistente sin relación con UAT. Este
 * mecanismo nunca toca esa tabla — ver docblock de UatVisibilityService.
 */
class UatVisibilityControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTenant();
    }

    private function activateTenant(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function designerId(): int
    {
        return (int) User::where('email', 'designer@illuminations.test')->value('id');
    }

    public function test_bulk_sin_permiso_superadmin_retorna_403(): void
    {
        $token = $this->loginAs('designer@illuminations.test', 'Password123!');
        $this->activateTenant();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['ventas_diseno'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(403);
        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/module-visibility/bulk')
            ->assertStatus(403);
    }

    public function test_get_arranca_vacio_y_hide_lo_refleja(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/module-visibility/bulk')
            ->assertStatus(200)
            ->assertJson(['hidden_modules' => [], 'hidden_menu_items' => []]);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/module-visibility/bulk', [
                'modules'   => ['compras', 'bodega'],
                'menuItems' => [['module' => 'ventas_diseno', 'key' => 'catalogo_inventario_compras']],
                'action'    => 'hide',
            ])
            ->assertStatus(200)
            ->assertJson([
                'hidden_modules'    => ['compras', 'bodega'],
                'hidden_menu_items' => [['module' => 'ventas_diseno', 'key' => 'catalogo_inventario_compras']],
            ]);

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/module-visibility/bulk')
            ->assertStatus(200)
            ->assertJson(['hidden_modules' => ['compras', 'bodega']]);
    }

    public function test_hide_oculta_el_modulo_para_no_superadmin_y_nunca_toca_superadmin(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $designerId = $this->designerId();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['ventas_diseno'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(200);

        $this->activateTenant();
        $designer = User::find($designerId);
        $this->assertFalse($designer->modulesPayload()['ventas_diseno']['view']);

        $superadmin = User::where('email', 'superadmin@illuminations.test')->first();
        $this->assertTrue($superadmin->modulesPayload()['ventas_diseno']['view']);
    }

    public function test_hide_nunca_escribe_en_user_module_visibility(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['ventas_diseno', 'compras'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(200);

        $this->activateTenant();
        $this->assertSame(0, UserModuleVisibility::count());
    }

    /**
     * El escenario exacto que encontró Pre-QA: un override individual preexistente (sin relación
     * con UAT) debe sobrevivir intacto a un ciclo completo hide→restore del mismo módulo, porque
     * el mecanismo nuevo nunca toca esa tabla.
     */
    public function test_restore_no_borra_un_override_individual_preexistente_del_mismo_modulo(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $designerId = $this->designerId();

        // Override individual previo: un admin restringe Servicios (FULL por rol) a NONE para
        // este usuario puntual, por una razón sin relación con UAT.
        $modules = collect($this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin/users/{$designerId}/module-visibility")
            ->json('modules'))
            ->map(fn (array $r) => $r['module'] === 'servicios'
                ? ['module' => 'servicios', 'can_view' => RoleModuleVisibility::VIEW_NONE, 'can_view_team' => false]
                : $r)
            ->values()->all();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/users/{$designerId}/module-visibility", ['modules' => $modules, 'menuItems' => []])
            ->assertStatus(200);

        $this->activateTenant();
        $this->assertTrue(UserModuleVisibility::where('user_id', $designerId)->where('module', 'servicios')->exists());

        // Ciclo completo de UAT: hide, después restore del mismo módulo.
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['servicios'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['servicios'], 'menuItems' => [], 'action' => 'restore'])
            ->assertStatus(200);

        $this->activateTenant();
        // El override individual sigue ahí, intacto — nunca se tocó.
        $this->assertTrue(UserModuleVisibility::where('user_id', $designerId)->where('module', 'servicios')->exists());
        $designer = User::find($designerId);
        $this->assertFalse($designer->modulesPayload()['servicios']['view']);
    }

    public function test_restore_saca_el_modulo_de_la_lista_y_el_usuario_vuelve_a_ver_lo_que_su_rol_le_daba(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $designerId = $this->designerId();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['ventas_diseno'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['ventas_diseno'], 'menuItems' => [], 'action' => 'restore'])
            ->assertStatus(200)
            ->assertJson(['hidden_modules' => []]);

        $this->activateTenant();
        $designer = User::find($designerId);
        $this->assertTrue($designer->modulesPayload()['ventas_diseno']['view']);
    }

    public function test_hide_de_configuracion_via_menuItems_oculta_para_designer(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $designerId = $this->designerId();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/module-visibility/bulk', [
                'modules'   => [],
                'menuItems' => [['module' => 'configuracion', 'key' => 'root']],
                'action'    => 'hide',
            ])
            ->assertStatus(200);

        $this->activateTenant();
        $designer = User::find($designerId);
        $this->assertSame(['configuracion' => ['root' => false]], $designer->menuVisibilityPayload());

        $superadmin = User::where('email', 'superadmin@illuminations.test')->first();
        $this->assertSame([], $superadmin->menuVisibilityPayload());
    }

    /**
     * Hallazgo real de Pre-QA (re-corrida contra el diseño nuevo, 2026-08-07): modulesPayload()
     * ya apagaba 'view', pero effectivePermissionsV2() (permissions del JWT) nunca pasa por la
     * máscara UAT — a propósito, es un eje separado (la máscara es cosmética, nunca debe tocar un
     * permiso real de ruta). Sidebar.tsx usa un OR (`modules.X.view === true ||
     * usePermission('X.read')`) para ventas_diseno/bodega/servicios (pensado para el caso de una
     * extra_permission individual sin visibilidad de rol) — ese mismo OR hacía que ocultar
     * cualquiera de esos 3 módulos vía UAT no tuviera ningún efecto real en el Sidebar para un
     * usuario con acceso por rol (el permiso plano seguía presente). Fix: menuVisibilityPayload()
     * emite ahora una señal separada {module: {__module__: false}} cuando UAT oculta un módulo,
     * que el Sidebar usa para ganarle al OR sin tocar el eje de permissions.
     */
    public function test_hide_de_modulo_de_negocio_emite_senal_module_en_menu_visibility(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();
        $designerId = $this->designerId();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/module-visibility/bulk', [
                'modules'   => ['servicios'],
                'menuItems' => [],
                'action'    => 'hide',
            ])
            ->assertStatus(200);

        $this->activateTenant();
        $designer = User::find($designerId);
        // effectivePermissionsV2() sigue intacto (la máscara nunca debe tocar permisos reales) —
        // el permiso plano servicios.read/servicios.view sigue presente si el rol lo otorga.
        $this->assertContains('servicios.read', $designer->effectivePermissionsV2());

        // Pero menuVisibilityPayload() ahora sí trae la señal separada que el Sidebar necesita.
        $this->assertSame(false, $designer->menuVisibilityPayload()['servicios']['__module__'] ?? null);

        $superadmin = User::where('email', 'superadmin@illuminations.test')->first();
        $this->assertArrayNotHasKey('servicios', $superadmin->menuVisibilityPayload());

        // Restore saca la señal, igual que saca el resto de la máscara.
        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/module-visibility/bulk', [
                'modules'   => ['servicios'],
                'menuItems' => [],
                'action'    => 'restore',
            ])
            ->assertStatus(200);

        $this->activateTenant();
        $designer = User::find($designerId);
        $this->assertArrayNotHasKey('servicios', $designer->menuVisibilityPayload());
    }

    public function test_bulk_con_modulo_invalido_retorna_422(): void
    {
        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!');
        $this->activateTenant();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/module-visibility/bulk', ['modules' => ['no_existe'], 'menuItems' => [], 'action' => 'hide'])
            ->assertStatus(422);
    }
}
