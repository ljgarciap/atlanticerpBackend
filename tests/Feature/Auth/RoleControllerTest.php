<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\Role;
use App\Modules\Auth\Models\RoleModuleVisibility;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * ADR-006, Decisión 10 — CRUD del catálogo de roles + matriz de visibilidad, exclusivo de
 * permission:superadmin.all.
 */
class RoleControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTenant();
    }

    /**
     * login()/AuthenticateJwt resetean el search_path de Postgres en su `finally` al terminar
     * cada request (ver Tenant::forgetCurrent()) -- hay que re-activar el tenant antes de
     * cualquier query directa a un modelo tenant-scoped después de loginAs()/una llamada HTTP.
     */
    private function activateTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // ── GET /api/roles — acceso general ─────────────────────────────────────────

    public function test_index_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/roles')->assertStatus(401);
    }

    public function test_index_cualquier_autenticado_ve_los_14_roles(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/roles');

        $response->assertStatus(200);
        $this->assertCount(14, $response->json());
    }

    // ── POST /api/admin/roles ────────────────────────────────────────────────────

    public function test_store_sin_permiso_superadmin_retorna_403(): void
    {
        // management@atlantic.test tiene nivel 10 (Super Admin, bypass vía superadmin.all
        // en su JSONB plano) -- usamos designer (nivel 3, sin bypass) para probar el 403 real.
        $token = $this->loginAs('designer@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/roles', ['key' => 'nuevo_rol', 'name' => 'Nuevo Rol'])
            ->assertStatus(403);
    }

    public function test_store_con_superadmin_crea_rol(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/roles', ['key' => 'nuevo_rol', 'name' => 'Nuevo Rol']);

        $response->assertStatus(201);
        $response->assertJsonPath('key', 'nuevo_rol');
        $response->assertJsonPath('is_system', false);

        $this->activateTenant();
        $this->assertTrue(Role::where('key', 'nuevo_rol')->exists());
    }

    public function test_store_con_key_duplicada_retorna_422(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/roles', ['key' => 'management', 'name' => 'Otra Gerencia'])
            ->assertStatus(422);
    }

    public function test_store_con_key_en_formato_invalido_retorna_422(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/admin/roles', ['key' => 'Rol Con Espacios', 'name' => 'X'])
            ->assertStatus(422);
    }

    // ── PUT /api/admin/roles/{id} ────────────────────────────────────────────────

    public function test_update_rol_de_sistema_retorna_403(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $role  = Role::where('key', 'vendedor')->firstOrFail();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/roles/{$role->id}", ['name' => 'Otro nombre'])
            ->assertStatus(403);
    }

    public function test_update_rol_no_sistema_actualiza_nombre(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $role  = Role::create(['key' => 'temporal', 'name' => 'Temporal', 'is_system' => false, 'is_active' => true]);

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/roles/{$role->id}", ['name' => 'Renombrado']);

        $response->assertStatus(200);
        $response->assertJsonPath('name', 'Renombrado');
    }

    // ── DELETE /api/admin/roles/{id} ─────────────────────────────────────────────

    public function test_destroy_rol_de_sistema_retorna_403(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $role  = Role::where('key', 'vendedor')->firstOrFail();

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/admin/roles/{$role->id}")
            ->assertStatus(403);
    }

    public function test_destroy_rol_con_usuarios_asignados_retorna_403(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $role  = Role::where('key', 'management')->firstOrFail();

        // 'management' tiene usuarios reales asignados (Gerencia/David/Daniela) -- no es is_system
        // en este fixture porque es del catálogo de negocio, pero SÍ es is_system=true realmente.
        // Probamos con un rol no-sistema con usuario para aislar la guardia de "tiene usuarios".
        $role->update(['is_system' => false]);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/admin/roles/{$role->id}")
            ->assertStatus(403);
    }

    public function test_destroy_rol_sin_uso_retorna_204(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $role  = Role::create(['key' => 'temporal2', 'name' => 'Temporal 2', 'is_system' => false, 'is_active' => true]);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/admin/roles/{$role->id}")
            ->assertStatus(204);

        $this->activateTenant();
        $this->assertNull(Role::find($role->id));
    }

    // ── GET/PUT /api/admin/roles/{id}/visibility ─────────────────────────────────

    public function test_visibility_sin_permiso_superadmin_retorna_403(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!');
        $this->activateTenant();
        $role  = Role::where('key', 'vendedor')->firstOrFail();

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin/roles/{$role->id}/visibility")
            ->assertStatus(403);
    }

    public function test_get_visibility_retorna_la_fila_sembrada(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $role  = Role::where('key', 'vendedor')->firstOrFail();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin/roles/{$role->id}/visibility");

        $response->assertStatus(200);
        // Fase 4 — Servicios (2026-08-02): además de ventas_diseno (Fase A), ahora también ve
        // servicios en modo solo lectura — ver
        // 2026_08_02_100003_grant_servicios_view_to_vendedor_disenador.php.
        // Batch 14 Admin&Cont (2026-08-25): admin_contab en VIEW_READONLY (Comisiones Internas,
        // REQ-575/577) — ver 2026_08_25_100002_grant_admin_contab_readonly_view_to_vendedor_disenador.php.
        $this->assertCount(3, $response->json());
        $modules = collect($response->json())->pluck('module')->sort()->values()->all();
        $this->assertSame(['admin_contab', 'servicios', 'ventas_diseno'], $modules);
    }

    public function test_update_visibility_hace_upsert_masivo(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $role  = Role::where('key', 'lider_compras')->firstOrFail();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/roles/{$role->id}/visibility", [
                'rows' => [
                    ['module' => 'compras', 'can_view' => RoleModuleVisibility::VIEW_FULL, 'can_view_team' => true],
                ],
            ]);

        $response->assertStatus(200);

        $this->activateTenant();
        $this->assertTrue(
            RoleModuleVisibility::where('role_id', $role->id)
                ->where('module', 'compras')
                ->where('can_view_team', true)
                ->exists()
        );
    }

    public function test_update_visibility_con_modulo_invalido_retorna_422(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!');
        $this->activateTenant();
        $role  = Role::where('key', 'lider_compras')->firstOrFail();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/admin/roles/{$role->id}/visibility", [
                'rows' => [['module' => 'no_existe', 'can_view' => 2, 'can_view_team' => false]],
            ])
            ->assertStatus(422);
    }
}
