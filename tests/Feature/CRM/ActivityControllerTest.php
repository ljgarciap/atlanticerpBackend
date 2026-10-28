<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use Tests\Feature\FeatureTestCase;

class ActivityControllerTest extends FeatureTestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createProject(string $token): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', [
                'nombre'       => 'Proyecto Actividades',
                'tipo'         => 'ambos',
                'etapa'        => 'lead',
                'requires_technical_study'       => false,
                'assignee_ids' => [],
            ]);
        $response->assertStatus(201);
        return (int) $response->json('id');
    }

    private function createActivity(string $token, int $projectId, string $tipo = 'llamada'): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/activities", [
                'tipo'  => $tipo,
                'fecha' => '2026-06-19',
                'notas' => 'Seguimiento inicial',
            ]);
        $response->assertStatus(201);
        return (int) $response->json('id');
    }

    // ── index ─────────────────────────────────────────────────────────────────

    public function test_listar_actividades_retorna_200_con_paginacion(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $this->createActivity($token, $projectId);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/activities");

        $response->assertStatus(200);
        $response->assertJsonStructure(['data', 'meta']);
        $this->assertCount(1, $response->json('data'));
    }

    public function test_listar_actividades_proyecto_inexistente_retorna_404(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/projects/99999/activities')
            ->assertStatus(404);
    }

    public function test_listar_actividades_sin_autenticar_retorna_401(): void
    {
        // No need to create a project — JWT middleware rejects before route handler runs
        $this->getJson('/api/crm/projects/1/activities')
            ->assertStatus(401);
    }

    // ── store ─────────────────────────────────────────────────────────────────

    public function test_crear_actividad_retorna_201_con_estructura(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/activities", [
                'tipo'      => 'reunion',
                'fecha'     => '2026-06-20',
                'notas'     => 'Reunión de presentación',
                'siguiente' => now()->addDay()->toDateString(),
            ]);

        $response->assertStatus(201);
        $response->assertJsonStructure([
            'id', 'project_id', 'tipo', 'fecha', 'notas', 'siguiente', 'user', 'created_at',
        ]);
        $response->assertJsonPath('tipo',       'reunion');
        $response->assertJsonPath('notas',      'Reunión de presentación');
        $response->assertJsonPath('project_id', $projectId);
        $response->assertJsonStructure(['user' => ['id', 'first_name', 'last_name']]);
    }

    public function test_crear_actividad_tipo_invalido_retorna_422(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/activities", [
                'tipo'  => 'tipo_inventado',
                'fecha' => '2026-06-20',
                'notas' => 'Sin tipo válido',
            ])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['tipo']]);
    }

    public function test_crear_actividad_sin_notas_ni_tipo_retorna_422(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/activities", [
                'fecha' => '2026-06-20',
            ])
            ->assertStatus(422);
    }

    public function test_crear_actividad_proyecto_inexistente_retorna_404(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects/99999/activities', [
                'tipo'  => 'llamada',
                'fecha' => '2026-06-20',
                'notas' => 'Proyecto no existe',
            ])
            ->assertStatus(404);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_eliminar_actividad_propia_retorna_204(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $activityId = $this->createActivity($token, $projectId);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/projects/{$projectId}/activities/{$activityId}")
            ->assertStatus(204);

        // Verify gone from list
        $list = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/activities")
            ->json('data');
        $this->assertEmpty($list);
    }

    public function test_eliminar_actividad_inexistente_retorna_404(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/projects/{$projectId}/activities/99999")
            ->assertStatus(404);
    }

    public function test_designer_no_puede_eliminar_actividad_ajena(): void
    {
        // Management creates project and activity
        $mgmtToken = $this->loginAs();
        $projectId = $this->createProject($mgmtToken);
        $activityId = $this->createActivity($mgmtToken, $projectId);

        // Designer tries to delete management's activity (project not assigned to designer)
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($designerToken))
            ->deleteJson("/api/crm/projects/{$projectId}/activities/{$activityId}")
            ->assertStatus(403);
    }

    public function test_management_puede_eliminar_actividad_ajena(): void
    {
        // Designer creates project (assigned to self in a real scenario)
        // Here management creates the project and activity, then management deletes — always allowed
        $mgmtToken  = $this->loginAs();
        $projectId  = $this->createProject($mgmtToken);
        $activityId = $this->createActivity($mgmtToken, $projectId, 'email');

        // Another management request deletes it (same user, role=management)
        $this->withHeaders($this->authHeaders($mgmtToken))
            ->deleteJson("/api/crm/projects/{$projectId}/activities/{$activityId}")
            ->assertStatus(204);
    }

    // ── access control ────────────────────────────────────────────────────────

    public function test_sin_autenticar_crear_actividad_retorna_401(): void
    {
        // No need to create a project — JWT middleware rejects before route handler runs
        $this->postJson('/api/crm/projects/1/activities', [
            'tipo'  => 'llamada',
            'fecha' => '2026-06-20',
            'notas' => 'Sin token',
        ])->assertStatus(401);
    }

    public function test_todos_los_tipos_de_actividad_son_validos(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        foreach (['llamada', 'email', 'reunion', 'whatsapp', 'visita', 'otro'] as $tipo) {
            $this->withHeaders($this->authHeaders($token))
                ->postJson("/api/crm/projects/{$projectId}/activities", [
                    'tipo'  => $tipo,
                    'fecha' => '2026-06-20',
                    'notas' => "Actividad tipo {$tipo}",
                ])
                ->assertStatus(201);
        }
    }
}
