<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use Tests\Feature\FeatureTestCase;

class ProjectControllerTest extends FeatureTestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function projectPayload(string $suffix = ''): array
    {
        return [
            'nombre'        => "Proyecto Test{$suffix}",
            'tipo'          => 'ambos',
            'etapa'         => 'lead',
            'dialux'        => false,
            'contacto'      => 'Cliente Test',
            'email'         => "cliente{$suffix}@test.com",
            'valor'         => 50000,
            'ubicacion'     => 'Ciudad de Panamá',
            'assignee_ids'  => [],
        ];
    }

    private function createProject(string $token, string $suffix = ''): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', $this->projectPayload($suffix));

        $response->assertStatus(201);

        return (int) $response->json('id');
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_listar_proyectos_retorna_200_con_paginacion(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/projects')
            ->assertStatus(200)
            ->assertJsonStructure(['data', 'meta'])
            ->assertJsonStructure(['meta' => ['total', 'per_page', 'current_page', 'last_page']]);
    }

    public function test_listar_proyectos_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/crm/projects')->assertStatus(401);
    }

    public function test_listar_proyectos_incluye_campos_requeridos(): void
    {
        $token = $this->loginAs();
        $this->createProject($token, '_list');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/projects');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data);

        $first = $data[0];
        $this->assertArrayHasKey('id',              $first);
        $this->assertArrayHasKey('nombre',          $first);
        $this->assertArrayHasKey('tipo',            $first);
        $this->assertArrayHasKey('etapa',           $first);
        $this->assertArrayHasKey('urgency',         $first);
        $this->assertArrayHasKey('freshness',       $first);
        $this->assertArrayHasKey('assignees',       $first);
        $this->assertArrayHasKey('latest_activity', $first);
    }

    public function test_filtro_por_etapa_retorna_solo_esa_etapa(): void
    {
        $token = $this->loginAs();
        $this->createProject($token, '_etapa');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/projects?etapa=lead');

        $response->assertStatus(200);
        foreach ((array) $response->json('data') as $p) {
            $this->assertSame('lead', $p['etapa']);
        }
    }

    public function test_filtro_search_retorna_proyectos_que_coincidan(): void
    {
        $token = $this->loginAs();
        $this->createProject($token, '_search');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/projects?search=Proyecto Test_search');

        $response->assertStatus(200);
        $this->assertNotEmpty($response->json('data'));
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_crear_proyecto_retorna_201(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', $this->projectPayload('_create'));

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'id', 'nombre', 'tipo', 'etapa', 'dialux',
            'urgency', 'freshness', 'assignees', 'latest_activity',
        ]);
        $response->assertJsonPath('nombre', 'Proyecto Test_create');
        $response->assertJsonPath('tipo',   'ambos');
        $response->assertJsonPath('etapa',  'lead');
    }

    public function test_crear_proyecto_sin_nombre_retorna_422(): void
    {
        $token = $this->loginAs();

        $payload = $this->projectPayload();
        unset($payload['nombre']);

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', $payload)
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['nombre']]);
    }

    public function test_crear_proyecto_tipo_invalido_retorna_422(): void
    {
        $token   = $this->loginAs();
        $payload = $this->projectPayload();
        $payload['tipo'] = 'invalido';

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', $payload)
            ->assertStatus(422);
    }

    public function test_crear_proyecto_sin_autenticar_retorna_401(): void
    {
        $this->postJson('/api/crm/projects', $this->projectPayload())
            ->assertStatus(401);
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_obtener_proyecto_por_id_retorna_200(): void
    {
        $token = $this->loginAs();
        $id    = $this->createProject($token, '_show');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$id}");

        $response->assertStatus(200);
        $response->assertJsonPath('id', $id);
        $response->assertJsonStructure(['id', 'nombre', 'reminders', 'assignees']);
    }

    public function test_proyecto_inexistente_retorna_404(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/projects/99999')
            ->assertStatus(404);
    }

    // ── update ────────────────────────────────────────────────────────────────

    public function test_actualizar_proyecto_retorna_200(): void
    {
        $token = $this->loginAs();
        $id    = $this->createProject($token, '_update');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/crm/projects/{$id}", [
                'nombre'    => 'Nombre Actualizado',
                'tipo'      => 'diseno',
                'contacto'  => 'Nuevo Contacto',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('nombre',   'Nombre Actualizado');
        $response->assertJsonPath('tipo',     'diseno');
        $response->assertJsonPath('contacto', 'Nuevo Contacto');
    }

    public function test_actualizar_proyecto_ajeno_sin_ser_management_retorna_403(): void
    {
        $mgmtToken     = $this->loginAs();
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $id = $this->createProject($mgmtToken, '_rbac');

        $this->withHeaders($this->authHeaders($designerToken))
            ->putJson("/api/crm/projects/{$id}", ['nombre' => 'Hack'])
            ->assertStatus(403);
    }

    // ── changeStage ───────────────────────────────────────────────────────────

    public function test_cambiar_etapa_retorna_200_y_nueva_etapa(): void
    {
        $token = $this->loginAs();
        $id    = $this->createProject($token, '_stage');

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/crm/projects/{$id}/stage", ['etapa' => 'diseno']);

        $response->assertStatus(200);
        $response->assertJsonPath('etapa', 'diseno');
    }

    public function test_cambiar_etapa_invalida_retorna_422(): void
    {
        $token = $this->loginAs();
        $id    = $this->createProject($token, '_stage_invalid');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/crm/projects/{$id}/stage", ['etapa' => 'fase_que_no_existe'])
            ->assertStatus(422);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_eliminar_proyecto_retorna_204(): void
    {
        $token = $this->loginAs();
        $id    = $this->createProject($token, '_delete');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/projects/{$id}")
            ->assertStatus(204);

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$id}")
            ->assertStatus(404);
    }

    public function test_eliminar_proyecto_con_rol_designer_retorna_403(): void
    {
        $mgmtToken     = $this->loginAs();
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $id = $this->createProject($mgmtToken, '_del_rbac');

        $this->withHeaders($this->authHeaders($designerToken))
            ->deleteJson("/api/crm/projects/{$id}")
            ->assertStatus(403);
    }

    // ── Permission gates ──────────────────────────────────────────────────────

    public function test_crear_proyecto_sin_permiso_crm_write_retorna_403(): void
    {
        $token = $this->createLevel1Token();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', $this->projectPayload('_perm_write'))
            ->assertStatus(403);
    }

    public function test_listar_proyectos_sin_permiso_crm_write_retorna_200(): void
    {
        $token = $this->createLevel1Token();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/projects')
            ->assertStatus(200);
    }

    public function test_actualizar_proyecto_sin_permiso_crm_edit_retorna_403(): void
    {
        $mgmtToken  = $this->loginAs();
        $level1Token = $this->createLevel1Token();

        $id = $this->createProject($mgmtToken, '_perm_edit');

        $this->withHeaders($this->authHeaders($level1Token))
            ->putJson("/api/crm/projects/{$id}", ['nombre' => 'Intento sin permiso'])
            ->assertStatus(403);
    }

    public function test_exportar_reporte_sin_permiso_reports_export_retorna_403(): void
    {
        // nivel1@ (nivel 1, sin reports.export) en vez de vendedor@ -- desde SCRUM-83,
        // vendedor@ es un vendedor real en nivel 4, que sí incluye reports.export.
        $token = $this->createLevel1Token();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/reports/monthly/export')
            ->assertStatus(403);
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function createLevel1Token(): string
    {
        // nivel1@atlantic.test existe en AuthUserSeeder (level 1, role electrician)
        return $this->loginAs('nivel1@atlantic.test', 'Password123!');
    }
}
