<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Modules\Auth\Models\SecurityLevelModulePermission;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

class SecurityLevelControllerTest extends FeatureTestCase
{
    /**
     * login()/AuthenticateJwt resetean el search_path de Postgres en su `finally` al terminar
     * cada request -- hay que re-activar el tenant antes de cualquier query directa después de
     * loginAs()/una llamada HTTP.
     */
    private function activateTenant(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    // ── POST /api/security-levels — ADR-006 Decisión 9 ─────────────────────────

    public function test_crear_nivel_nuevo_asigna_el_siguiente_numero_disponible(): void
    {
        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/security-levels', [
                'name'        => 'Gerencia — Whil',
                'description' => 'Mismo rol Gerencia que Mark/David, aprobaciones restringidas',
                'permissions' => ['crm.read'],
            ])
            ->assertStatus(201);

        $response->assertJsonPath('name', 'Gerencia — Whil');
        // Corrección 2026-07-10: el mapeo rol→nivel de ADR-006 reusa la escala 1-10 existente
        // (ver 2026_07_18_100002_reuse_existing_levels_for_role_defaults.php) en vez de crear
        // niveles nuevos — el siguiente número disponible sigue siendo 11.
        $response->assertJsonPath('level', 11);
        $response->assertJsonPath('is_active', true);
        $response->assertJsonPath('permissions', ['crm.read']);
    }

    public function test_crear_nivel_sin_nombre_retorna_422(): void
    {
        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/security-levels', [])
            ->assertStatus(422);
    }

    public function test_crear_nivel_con_permiso_fuera_del_catalogo_retorna_422(): void
    {
        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/security-levels', ['name' => 'X', 'permissions' => ['no.existe']])
            ->assertStatus(422);
    }

    public function test_crear_nivel_sin_permiso_security_levels_retorna_403(): void
    {
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/security-levels', ['name' => 'X'])
            ->assertStatus(403);
    }

    // ── GET/PUT /api/security-levels/{id}/permissions — ADR-006, Decisión 10 ───────────

    public function test_get_permissions_retorna_las_7_filas_del_nivel_9(): void
    {
        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');
        $this->activateTenant();
        $levelId = DB::table('security_levels')->where('level', 9)->value('id');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/security-levels/{$levelId}/permissions");

        $response->assertStatus(200);
        $this->assertCount(7, $response->json());
    }

    public function test_update_permissions_hace_upsert_masivo(): void
    {
        $token = $this->loginAs('management@illuminations.test', 'Password123!', 'management');
        $this->activateTenant();
        $levelId = DB::table('security_levels')->where('level', 4)->value('id');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/security-levels/{$levelId}/permissions", [
                'rows' => [
                    ['module' => 'compras', 'can_edit' => true, 'can_approve' => true],
                ],
            ]);

        $response->assertStatus(200);

        $this->activateTenant();
        $this->assertTrue(
            SecurityLevelModulePermission::where('security_level_id', $levelId)
                ->where('module', 'compras')
                ->where('can_approve', true)
                ->exists()
        );
    }

    public function test_update_permissions_sin_permiso_security_levels_retorna_403(): void
    {
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');
        $this->activateTenant();
        $levelId = DB::table('security_levels')->where('level', 4)->value('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/security-levels/{$levelId}/permissions", [
                'rows' => [['module' => 'compras', 'can_edit' => true, 'can_approve' => false]],
            ])
            ->assertStatus(403);
    }
}
