<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use Tests\Feature\FeatureTestCase;

class DashboardControllerTest extends FeatureTestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createProject(string $token, string $etapa = 'lead', float|null $valor = null): int
    {
        $payload = [
            'nombre'       => "Proyecto Dashboard {$etapa}",
            'tipo'         => 'ambos',
            'etapa'        => $etapa,
            'dialux'       => false,
            'assignee_ids' => [],
        ];

        if ($valor !== null) {
            $payload['valor'] = $valor;
        }

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', $payload);

        $response->assertStatus(201);

        return (int) $response->json('id');
    }

    private function addReminder(string $token, int $projectId): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/reminders", [
                'tipo'  => 'llamada',
                'fecha' => '2026-06-25',
                'nota'  => 'Recordatorio de prueba',
            ]);
        $response->assertStatus(201);
        return (int) $response->json('id');
    }

    private function createProjectWithFechaEntrega(string $token, string $fechaEntrega, string $etapa = 'diseno'): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', [
                'nombre'        => 'Proyecto Dashboard Fecha',
                'contacto'      => 'Cliente de Prueba',
                'tipo'          => 'ambos',
                'etapa'         => $etapa,
                'dialux'        => false,
                'fecha_entrega' => $fechaEntrega,
                'assignee_ids'  => [],
            ]);
        $response->assertStatus(201);
        return (int) $response->json('id');
    }

    // ── GET /api/crm/dashboard/kpis ───────────────────────────────────────────

    public function test_kpis_retorna_200_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/crm/dashboard/kpis')->assertStatus(401);
    }

    public function test_kpis_retorna_200_con_estructura_correcta(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/dashboard/kpis');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'counts_by_stage',
            'counts_by_tipo',
            'pipeline_activo',
            'pipeline_cerrado',
            'alerts_vencidos',
            'alerts_proximos',
            'alerts_frios',
            'alerts_vencidos_projects',
            'alerts_proximos_projects',
            'alerts_frios_projects',
            'management_emails',
        ]);
    }

    // SCRUM-14 — QA 2026-07-05: el dashboard solo mostraba el conteo de alertas, sin el detalle
    // de proyectos ni los datos necesarios para el botón "Enviar recordatorios" del mock.

    public function test_kpis_alerts_vencidos_projects_incluye_proyecto_con_fecha_vencida(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProjectWithFechaEntrega($token, now()->subDays(5)->toDateString());

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/dashboard/kpis');

        $response->assertStatus(200);
        $projects = $response->json('alerts_vencidos_projects');
        $this->assertIsArray($projects);

        $ids = array_column($projects, 'id');
        $this->assertContains($projectId, $ids);

        $found = collect($projects)->firstWhere('id', $projectId);
        $this->assertSame('Proyecto Dashboard Fecha', $found['nombre']);
        $this->assertSame('Cliente de Prueba', $found['contacto']);
        $this->assertLessThan(0, $found['dias'], 'dias debe ser negativo para un proyecto vencido');
    }

    public function test_kpis_alerts_proximos_projects_incluye_proyecto_por_vencer(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProjectWithFechaEntrega($token, now()->addDays(2)->toDateString());

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/dashboard/kpis');

        $response->assertStatus(200);
        $projects = $response->json('alerts_proximos_projects');
        $ids      = array_column($projects, 'id');
        $this->assertContains($projectId, $ids);

        $found = collect($projects)->firstWhere('id', $projectId);
        $this->assertGreaterThanOrEqual(0, $found['dias'], 'dias debe ser >= 0 para un proyecto por vencer');
    }

    public function test_kpis_management_emails_incluye_usuarios_de_gerencia(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/dashboard/kpis');

        $response->assertStatus(200);
        $emails = $response->json('management_emails');
        $this->assertIsArray($emails);
        $this->assertNotEmpty($emails, 'Debe incluir al menos un email de gerencia (el usuario logueado)');
    }

    public function test_kpis_counts_by_stage_tiene_todas_las_etapas(): void
    {
        $token = $this->loginAs();
        $this->createProject($token, 'lead');
        $this->createProject($token, 'diseno');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/dashboard/kpis');

        $response->assertStatus(200);
        $countsByStage = $response->json('counts_by_stage');
        $this->assertIsArray($countsByStage);

        foreach (['lead', 'diseno', 'cotizacion', 'propuesta', 'cerrado', 'perdido'] as $etapa) {
            $this->assertArrayHasKey($etapa, $countsByStage, "Falta la etapa '{$etapa}' en counts_by_stage");
        }
    }

    public function test_kpis_counts_by_tipo_tiene_todos_los_tipos(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/dashboard/kpis');

        $response->assertStatus(200);
        $countsByTipo = $response->json('counts_by_tipo');
        $this->assertIsArray($countsByTipo);

        foreach (['diseno', 'cotizacion', 'ambos'] as $tipo) {
            $this->assertArrayHasKey($tipo, $countsByTipo, "Falta el tipo '{$tipo}' en counts_by_tipo");
        }
    }

    public function test_kpis_pipeline_activo_suma_proyectos_abiertos(): void
    {
        $token = $this->loginAs();
        $this->createProject($token, 'lead',      10000.0);
        $this->createProject($token, 'diseno',    20000.0);
        $this->createProject($token, 'cerrado',   99999.0); // no debe sumarse al activo

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/dashboard/kpis');

        $response->assertStatus(200);

        $pipelineActivo = (float) $response->json('pipeline_activo');
        $this->assertGreaterThanOrEqual(30000.0, $pipelineActivo, 'pipeline_activo debe incluir proyectos lead y diseno');
    }

    public function test_kpis_pipeline_cerrado_suma_proyectos_ganados(): void
    {
        $token = $this->loginAs();
        $this->createProject($token, 'cerrado', 15000.0);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/dashboard/kpis');

        $response->assertStatus(200);
        $pipelineCerrado = (float) $response->json('pipeline_cerrado');
        $this->assertGreaterThanOrEqual(15000.0, $pipelineCerrado);
    }

    public function test_kpis_valores_numericos_son_numericos(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/dashboard/kpis');

        $response->assertStatus(200);

        foreach (['pipeline_activo', 'pipeline_cerrado', 'alerts_vencidos', 'alerts_proximos', 'alerts_frios'] as $key) {
            $this->assertIsNumeric($response->json($key), "{$key} debe ser numérico");
        }
    }

    // ── GET /api/crm/dashboard/reminders ──────────────────────────────────────

    public function test_reminders_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/crm/dashboard/reminders')->assertStatus(401);
    }

    public function test_reminders_retorna_200_con_estructura(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/dashboard/reminders');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertIsArray($response->json('data'));
    }

    public function test_reminders_incluye_recordatorios_pendientes(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $this->addReminder($token, $projectId);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/dashboard/reminders');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertNotEmpty($data, 'Debe retornar al menos el recordatorio creado');

        $first = $data[0];
        $this->assertArrayHasKey('id',            $first);
        $this->assertArrayHasKey('project_id',    $first);
        $this->assertArrayHasKey('project_name',  $first);
        $this->assertArrayHasKey('project_etapa', $first);
        $this->assertArrayHasKey('tipo',          $first);
        $this->assertArrayHasKey('fecha',         $first);
        $this->assertArrayHasKey('nota',          $first);
    }

    public function test_reminders_no_incluye_completados(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $remId     = $this->addReminder($token, $projectId);

        // Gerencia as done
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/crm/projects/{$projectId}/reminders/{$remId}/toggle")
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/dashboard/reminders');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Completed reminder should not appear
        $ids = array_column($data, 'id');
        $this->assertNotContains($remId, $ids, 'El recordatorio completado no debe aparecer en el dashboard');
    }

    public function test_designer_ve_solo_sus_propios_recordatorios(): void
    {
        // Management creates project + reminder (management only, not assigned to designer)
        $mgmtToken = $this->loginAs();
        $projectId = $this->createProject($mgmtToken);
        $remId     = $this->addReminder($mgmtToken, $projectId);

        // Designer may see reminders from demo-seeded projects they are assigned to,
        // but must NOT see the reminder from this unassigned project.
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $response = $this->withHeaders($this->authHeaders($designerToken))
            ->getJson('/api/crm/dashboard/reminders');

        $response->assertStatus(200);
        $ids = array_column($response->json('data') ?? [], 'id');
        $this->assertNotContains(
            $remId,
            $ids,
            'Designer no debe ver recordatorios de proyectos a los que no está asignado'
        );
    }
}
