<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Tests\Feature\FeatureTestCase;

/**
 * Hallazgo Arquitecto (2026-08-31, análisis SCRUM-625/627/631) — /api/departments nunca tuvo
 * ningún test, ni ningún gate de permiso en el backend (solo el frontend restringía el acceso vía
 * RequireRole). Cualquier usuario autenticado, de cualquier rol, podía crear/editar/desactivar
 * departamentos llamando la API directo. Corregido con permission:security.users (mismo gate que
 * /api/users, ver routes/departments.php) — este archivo cubre tanto el camino feliz como el
 * gate nuevo, que antes no existía en absoluto.
 */
class DepartmentControllerTest extends FeatureTestCase
{
    // ── gate de permiso (el hallazgo) ───────────────────────────────────────────────────────────

    public function test_listar_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/departments')->assertStatus(401);
    }

    public function test_listar_con_rol_designer_sin_permiso_retorna_403(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/departments')
            ->assertStatus(403);
    }

    public function test_crear_con_rol_designer_sin_permiso_retorna_403(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/departments', ['name' => 'Intento sin permiso'])
            ->assertStatus(403);
    }

    public function test_editar_con_rol_designer_sin_permiso_retorna_403(): void
    {
        $token = $this->loginAs('management@atlantic.test');
        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/departments', ['name' => 'Departamento Original'])
            ->assertStatus(201);
        $id = $created->json('id');

        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $this->withHeaders($this->authHeaders($designerToken))
            ->putJson("/api/departments/{$id}", ['name' => 'Hackeado'])
            ->assertStatus(403);
    }

    public function test_cambiar_estado_con_rol_electrician_sin_permiso_retorna_403(): void
    {
        $token = $this->loginAs('management@atlantic.test');
        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/departments', ['name' => 'Departamento Estado'])
            ->assertStatus(201);
        $id = $created->json('id');

        $electricianToken = $this->loginAs('nivel2@atlantic.test', 'Password123!', 'electrician');
        $this->withHeaders($this->authHeaders($electricianToken))
            ->patchJson("/api/departments/{$id}/status", ['is_active' => false])
            ->assertStatus(403);
    }

    // ── camino feliz (con permiso security.users) ───────────────────────────────────────────────

    public function test_crear_departamento_retorna_201(): void
    {
        $token = $this->loginAs('management@atlantic.test');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/departments', ['name' => 'Logística'])
            ->assertStatus(201);

        $response->assertJsonPath('name', 'Logística');
        $response->assertJsonPath('is_active', true);
    }

    public function test_editar_nombre_de_departamento(): void
    {
        $token = $this->loginAs('management@atlantic.test');
        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/departments', ['name' => 'Nombre Viejo'])
            ->assertStatus(201);
        $id = $created->json('id');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/departments/{$id}", ['name' => 'Nombre Nuevo'])
            ->assertStatus(200)
            ->assertJsonPath('name', 'Nombre Nuevo');
    }

    /** RN3 del ticket REQ-554: desactivar no reasigna ni pierde la relación con usuarios ya vinculados. */
    public function test_desactivar_departamento_no_afecta_usuarios_ya_asignados(): void
    {
        $token = $this->loginAs('management@atlantic.test');
        $created = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/departments', ['name' => 'Departamento Con Gente'])
            ->assertStatus(201);
        $id = $created->json('id');

        $user = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/users', [
                'first_name' => 'Con', 'last_name' => 'Departamento',
                'email' => 'con.departamento@atlantic.test', 'role_id' => (int) \Illuminate\Support\Facades\DB::table('atlantic_auth.roles')->where('key', 'vendedor')->value('id'),
                'department_id' => $id,
            ])
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/departments/{$id}/status", ['is_active' => false])
            ->assertStatus(200)
            ->assertJsonPath('is_active', false);

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/users/{$user->json('id')}")
            ->assertStatus(200)
            ->assertJsonPath('department.id', $id);
    }
}
