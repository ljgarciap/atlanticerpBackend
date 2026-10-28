<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Module;
use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Modules\Auth\Support\ModuleCatalog;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Catálogo de módulos de negocio como datos (hallazgo de la auditoría del esquema de permisos,
 * Atlantic/CLAUDE.md sesión 2026-08-30) — reemplaza el array hardcodeado que tenía
 * ModuleCatalog::keys(), CRUD exclusivo de permission:superadmin.all, mismo criterio que
 * RoleControllerTest.
 */
class ModuleControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTenant();
    }

    private function activateTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // ── GET /api/modules — acceso general ───────────────────────────────────────

    public function test_index_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/modules')->assertStatus(401);
    }

    public function test_index_cualquier_autenticado_ve_los_7_modulos_sembrados(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/modules');

        $response->assertStatus(200);
        $this->assertCount(7, $response->json());
        $keys = collect($response->json())->pluck('key')->sort()->values()->all();
        $this->assertSame(
            ['admin_contab', 'bodega', 'compras', 'gerencia', 'operaciones', 'servicios', 'ventas_diseno'],
            $keys,
        );
    }

    // ── ModuleCatalog::keys() ahora lee la tabla, no un array hardcodeado ────────

    public function test_module_catalog_keys_lee_la_tabla(): void
    {
        $this->assertSame(
            ['ventas_diseno', 'compras', 'bodega', 'servicios', 'admin_contab', 'gerencia', 'operaciones'],
            ModuleCatalog::keys(),
        );
    }

    public function test_module_catalog_keys_refleja_un_modulo_nuevo_sin_deploy(): void
    {
        Module::create(['key' => 'logistica', 'name' => 'Logística', 'is_system' => false, 'is_active' => true, 'sort_order' => 99]);

        $this->assertContains('logistica', ModuleCatalog::keys());
    }

    // ── POST /api/admin/modules ──────────────────────────────────────────────────

    public function test_store_sin_permiso_superadmin_retorna_403(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/modules', ['key' => 'logistica', 'name' => 'Logística'])
            ->assertStatus(403);
    }

    public function test_store_con_superadmin_crea_modulo(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/modules', ['key' => 'logistica', 'name' => 'Logística']);

        $response->assertStatus(201);
        $response->assertJsonPath('key', 'logistica');
        $response->assertJsonPath('is_system', false);

        $this->activateTenant();
        $this->assertTrue(Module::where('key', 'logistica')->exists());
    }

    public function test_store_con_key_duplicada_retorna_422(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/modules', ['key' => 'bodega', 'name' => 'Otra Bodega'])
            ->assertStatus(422);
    }

    public function test_store_con_key_en_formato_invalido_retorna_422(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/modules', ['key' => 'Modulo Con Espacios', 'name' => 'X'])
            ->assertStatus(422);
    }

    // ── PUT /api/admin/modules/{id} ──────────────────────────────────────────────

    public function test_update_modulo_de_sistema_puede_renombrarse(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $module = Module::where('key', 'bodega')->firstOrFail();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/modules/{$module->id}", ['name' => 'Bodega y Almacén']);

        $response->assertStatus(200);
        $response->assertJsonPath('name', 'Bodega y Almacén');
    }

    public function test_update_no_puede_desactivar_modulo_de_sistema(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $module = Module::where('key', 'bodega')->firstOrFail();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/modules/{$module->id}", ['is_active' => false])
            ->assertStatus(403);
    }

    // ── DELETE /api/admin/modules/{id} ───────────────────────────────────────────

    public function test_destroy_modulo_de_sistema_retorna_403(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $module = Module::where('key', 'bodega')->firstOrFail();

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/admin/modules/{$module->id}")
            ->assertStatus(403);
    }

    public function test_destroy_modulo_con_visibilidad_de_rol_asignada_retorna_403(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $module = Module::create(['key' => 'logistica', 'name' => 'Logística', 'is_system' => false, 'is_active' => true]);
        $role = Role::where('key', 'lider_compras')->firstOrFail();
        RoleModuleVisibility::create(['role_id' => $role->id, 'module' => 'logistica', 'can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => false]);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/admin/modules/{$module->id}")
            ->assertStatus(403);
    }

    public function test_destroy_modulo_sin_uso_retorna_204(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $module = Module::create(['key' => 'temporal', 'name' => 'Temporal', 'is_system' => false, 'is_active' => true]);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/admin/modules/{$module->id}")
            ->assertStatus(204);

        $this->activateTenant();
        $this->assertNull(Module::find($module->id));
    }
}
