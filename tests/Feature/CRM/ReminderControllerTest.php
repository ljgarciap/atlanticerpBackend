<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use Tests\Feature\FeatureTestCase;

class ReminderControllerTest extends FeatureTestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createProject(string $token, array $extra = []): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', array_merge([
                'nombre'       => 'Proyecto Recordatorio',
                'tipo'         => 'diseno',
                'dialux'       => false,
                'assignee_ids' => [],
            ], $extra));

        $response->assertStatus(201);

        return (int) $response->json('id');
    }

    private function createReminder(string $token, int $projectId, array $extra = []): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/reminders", array_merge([
                'tipo'  => 'llamada',
                'fecha' => '2026-07-01',
            ], $extra));

        $response->assertStatus(201);

        return (int) $response->json('id');
    }

    // ── GET /api/crm/projects/{id}/reminders ─────────────────────────────────

    public function test_index_retorna_401_sin_autenticar(): void
    {
        $this->getJson('/api/crm/projects/1/reminders')->assertStatus(401);
    }

    public function test_index_retorna_404_si_proyecto_no_existe(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/projects/99999/reminders')
            ->assertStatus(404);
    }

    public function test_index_retorna_403_para_usuario_no_asignado(): void
    {
        $mgmtToken     = $this->loginAs();
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        // Project not assigned to the designer
        $projectId = $this->createProject($mgmtToken);

        $this->withHeaders($this->authHeaders($designerToken))
            ->getJson("/api/crm/projects/{$projectId}/reminders")
            ->assertStatus(403);
    }

    public function test_index_retorna_lista_vacia(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/reminders");

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_index_retorna_recordatorios_del_proyecto(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $this->createReminder($token, $projectId, ['fecha' => '2026-08-01']);
        $this->createReminder($token, $projectId, ['fecha' => '2026-07-15']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/reminders");

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_retorna_estructura_correcta(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $this->createReminder($token, $projectId, ['tipo' => 'email', 'fecha' => '2026-07-01', 'nota' => 'Seguimiento']);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/reminders");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'data' => [['id', 'project_id', 'tipo', 'fecha', 'nota', 'completado', 'created_at']],
        ]);

        $reminder = $response->json('data.0');
        $this->assertSame('email', $reminder['tipo']);
        $this->assertSame('2026-07-01', $reminder['fecha']);
        $this->assertSame('Seguimiento', $reminder['nota']);
        $this->assertFalse($reminder['completado']);
    }

    public function test_index_accesible_para_usuario_asignado(): void
    {
        $mgmtToken     = $this->loginAs();
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        // Get designer's user id via autocomplete users
        $usersResp = $this->withHeaders($this->authHeaders($mgmtToken))
            ->getJson('/api/crm/autocomplete/users?q=vendedor@atlantic.test');
        $designerId = (int) $usersResp->json('data.0.id');

        $projectId = $this->createProject($mgmtToken, ['assignee_ids' => [$designerId]]);

        $this->withHeaders($this->authHeaders($designerToken))
            ->getJson("/api/crm/projects/{$projectId}/reminders")
            ->assertStatus(200);
    }

    // ── POST /api/crm/projects/{id}/reminders ────────────────────────────────

    public function test_store_retorna_401_sin_autenticar(): void
    {
        $this->postJson('/api/crm/projects/1/reminders', [])->assertStatus(401);
    }

    public function test_store_retorna_404_si_proyecto_no_existe(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects/99999/reminders', ['tipo' => 'llamada', 'fecha' => '2026-07-01'])
            ->assertStatus(404);
    }

    public function test_store_retorna_403_para_usuario_no_asignado(): void
    {
        $mgmtToken     = $this->loginAs();
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $projectId = $this->createProject($mgmtToken);

        $this->withHeaders($this->authHeaders($designerToken))
            ->postJson("/api/crm/projects/{$projectId}/reminders", ['tipo' => 'llamada', 'fecha' => '2026-07-01'])
            ->assertStatus(403);
    }

    public function test_store_retorna_422_sin_tipo(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/reminders", ['fecha' => '2026-07-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tipo']);
    }

    public function test_store_retorna_422_sin_fecha(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/reminders", ['tipo' => 'llamada'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['fecha']);
    }

    public function test_store_retorna_422_con_tipo_invalido(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/reminders", ['tipo' => 'sms', 'fecha' => '2026-07-01'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tipo']);
    }

    public function test_store_crea_recordatorio_con_campos_minimos(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/reminders", [
                'tipo'  => 'llamada',
                'fecha' => '2026-07-01',
            ]);

        $response->assertStatus(201);
        $response->assertJsonFragment([
            'tipo'       => 'llamada',
            'fecha'      => '2026-07-01',
            'completado' => false,
        ]);
        $this->assertNull($response->json('nota'));
    }

    public function test_store_crea_recordatorio_con_nota(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/reminders", [
                'tipo'  => 'reunion',
                'fecha' => '2026-07-15',
                'nota'  => 'Reunión de cierre',
            ]);

        $response->assertStatus(201);
        $this->assertSame('Reunión de cierre', $response->json('nota'));
    }

    // ── PATCH /api/crm/projects/{id}/reminders/{remId}/toggle ────────────────

    public function test_toggle_retorna_401_sin_autenticar(): void
    {
        $this->patchJson('/api/crm/projects/1/reminders/1/toggle')->assertStatus(401);
    }

    public function test_toggle_retorna_404_si_proyecto_no_existe(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/crm/projects/99999/reminders/1/toggle')
            ->assertStatus(404);
    }

    public function test_toggle_retorna_404_si_recordatorio_no_existe(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/crm/projects/{$projectId}/reminders/99999/toggle")
            ->assertStatus(404);
    }

    public function test_toggle_retorna_403_para_usuario_no_asignado(): void
    {
        $mgmtToken     = $this->loginAs();
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $projectId  = $this->createProject($mgmtToken);
        $reminderId = $this->createReminder($mgmtToken, $projectId);

        $this->withHeaders($this->authHeaders($designerToken))
            ->patchJson("/api/crm/projects/{$projectId}/reminders/{$reminderId}/toggle")
            ->assertStatus(403);
    }

    public function test_toggle_marca_como_completado(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $reminderId = $this->createReminder($token, $projectId);

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/crm/projects/{$projectId}/reminders/{$reminderId}/toggle");

        $response->assertStatus(200);
        $this->assertTrue($response->json('completado'));
    }

    public function test_toggle_doble_vuelve_a_pendiente(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $reminderId = $this->createReminder($token, $projectId);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/crm/projects/{$projectId}/reminders/{$reminderId}/toggle");

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/crm/projects/{$projectId}/reminders/{$reminderId}/toggle");

        $response->assertStatus(200);
        $this->assertFalse($response->json('completado'));
    }

    // ── DELETE /api/crm/projects/{id}/reminders/{remId} ─────────────────────

    public function test_destroy_retorna_401_sin_autenticar(): void
    {
        $this->deleteJson('/api/crm/projects/1/reminders/1')->assertStatus(401);
    }

    public function test_destroy_retorna_404_si_proyecto_no_existe(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson('/api/crm/projects/99999/reminders/1')
            ->assertStatus(404);
    }

    public function test_destroy_retorna_404_si_recordatorio_no_existe(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/projects/{$projectId}/reminders/99999")
            ->assertStatus(404);
    }

    public function test_destroy_retorna_403_para_usuario_no_asignado(): void
    {
        $mgmtToken     = $this->loginAs();
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $projectId  = $this->createProject($mgmtToken);
        $reminderId = $this->createReminder($mgmtToken, $projectId);

        $this->withHeaders($this->authHeaders($designerToken))
            ->deleteJson("/api/crm/projects/{$projectId}/reminders/{$reminderId}")
            ->assertStatus(403);
    }

    public function test_destroy_elimina_recordatorio(): void
    {
        $token      = $this->loginAs();
        $projectId  = $this->createProject($token);
        $reminderId = $this->createReminder($token, $projectId);

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/crm/projects/{$projectId}/reminders/{$reminderId}")
            ->assertStatus(204);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/crm/projects/{$projectId}/reminders");

        $this->assertCount(0, $response->json('data'));
    }
}
