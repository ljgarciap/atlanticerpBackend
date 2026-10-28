<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use Tests\Feature\FeatureTestCase;

class ReportControllerTest extends FeatureTestCase
{
    // ── Helpers ───────────────────────────────────────────────────────────────

    private function createProject(string $token, array $extra = []): int
    {
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', array_merge([
                'nombre'       => 'Proyecto Reporte',
                'tipo'         => 'diseno',
                'dialux'       => false,
                'assignee_ids' => [],
            ], $extra));

        $response->assertStatus(201);

        return (int) $response->json('id');
    }

    private function addActivity(string $token, int $projectId, string $tipo = 'llamada'): void
    {
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/crm/projects/{$projectId}/activities", [
                'tipo'  => $tipo,
                'fecha' => now()->format('Y-m-d'),
                'notas' => 'Actividad de prueba',
            ])
            ->assertStatus(201);
    }

    // ── GET /api/crm/reports/monthly ─────────────────────────────────────────

    public function test_monthly_retorna_401_sin_autenticar(): void
    {
        $this->getJson('/api/crm/reports/monthly')->assertStatus(401);
    }

    public function test_monthly_retorna_estructura_correcta(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/reports/monthly');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'month',
            'month_label',
            'user_title',
            'kpis' => [
                'leads_nuevos',
                'valor_nuevos',
                'cerrados',
                'valor_cerrado',
                'perdidos',
                'valor_perdido',
                'tasa_cierre',
                'actividades',
                'documentos',
            ],
            'actividades_por_tipo',
            'team_breakdown',
            'proyectos_nuevos',
            'bitacora',
        ]);
    }

    public function test_monthly_retorna_mes_actual_por_defecto(): void
    {
        $token    = $this->loginAs();
        $expected = now()->format('Y-m');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/reports/monthly');

        $response->assertStatus(200);
        $response->assertJsonPath('month', $expected);
    }

    public function test_monthly_acepta_parametro_mes(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/reports/monthly?month=2026-01');

        $response->assertStatus(200);
        $response->assertJsonPath('month', '2026-01');
    }

    public function test_monthly_rechaza_formato_mes_invalido(): void
    {
        $token = $this->loginAs();

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/reports/monthly?month=01-2026')
            ->assertStatus(422);
    }

    public function test_monthly_management_muestra_todo_el_equipo(): void
    {
        $token = $this->loginAs(); // management

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/reports/monthly');

        $response->assertStatus(200);
        $response->assertJsonPath('user_title', 'Todo el equipo');
    }

    public function test_monthly_con_accept_language_en_traduce_todo_el_equipo_y_mes(): void
    {
        // SCRUM-29 BUG-23: user_title y month_label venían hardcodeados en español
        // sin importar el idioma activo del frontend.
        $token = $this->loginAs(); // management

        $response = $this->withHeaders([...$this->authHeaders($token), 'Accept-Language' => 'en'])
            ->getJson('/api/crm/reports/monthly?month=2026-07');

        $response->assertStatus(200);
        $response->assertJsonPath('user_title', 'Whole team');
        $response->assertJsonPath('month_label', 'July 2026');
    }

    public function test_monthly_designer_muestra_solo_sus_proyectos(): void
    {
        $mgmtToken     = $this->loginAs();
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        // Two projects: one visible to management, one assigned to designer
        $projectAll      = $this->createProject($mgmtToken);
        $projectDesigner = $this->createProject($mgmtToken, ['assignee_ids' => [$this->userIdFromToken($designerToken)]]);

        // Management sees both
        $mgmtResponse = $this->withHeaders($this->authHeaders($mgmtToken))
            ->getJson('/api/crm/reports/monthly');
        $mgmtResponse->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, $mgmtResponse->json('kpis.leads_nuevos'));

        // Designer sees only their assigned project
        $designerResponse = $this->withHeaders($this->authHeaders($designerToken))
            ->getJson('/api/crm/reports/monthly');
        $designerResponse->assertStatus(200);
        $this->assertSame('Vendedor Demo', $designerResponse->json('user_title'));

        $designerIds = array_column((array) $designerResponse->json('proyectos_nuevos'), 'id');
        $this->assertContains($projectDesigner, $designerIds);
        $this->assertNotContains($projectAll, $designerIds);
    }

    public function test_monthly_management_puede_filtrar_por_user_id(): void
    {
        $mgmtToken     = $this->loginAs();
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $designerId    = $this->userIdFromToken($designerToken);

        // Capture baseline before creating the test project (demo seeder may have pre-seeded leads)
        $baseline = (int) $this->withHeaders($this->authHeaders($mgmtToken))
            ->getJson("/api/crm/reports/monthly?user_id={$designerId}")
            ->json('kpis.leads_nuevos');

        $this->createProject($mgmtToken, ['assignee_ids' => [$designerId]]);

        $response = $this->withHeaders($this->authHeaders($mgmtToken))
            ->getJson("/api/crm/reports/monthly?user_id={$designerId}");

        $response->assertStatus(200);
        $this->assertSame('Vendedor Demo', $response->json('user_title'));
        $this->assertSame($baseline + 1, $response->json('kpis.leads_nuevos'));
    }

    public function test_monthly_contabiliza_actividades_del_mes(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);

        $this->addActivity($token, $projectId, 'llamada');
        $this->addActivity($token, $projectId, 'email');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/reports/monthly');

        $response->assertStatus(200);
        $this->assertGreaterThanOrEqual(2, $response->json('kpis.actividades'));

        $actByType = (array) $response->json('actividades_por_tipo');
        $this->assertArrayHasKey('llamada', $actByType);
        $this->assertArrayHasKey('email', $actByType);
        $this->assertGreaterThanOrEqual(1, $actByType['llamada']);
        $this->assertGreaterThanOrEqual(1, $actByType['email']);
    }

    // ── GET /api/crm/reports/team ─────────────────────────────────────────────

    public function test_team_retorna_401_sin_autenticar(): void
    {
        $this->getJson('/api/crm/reports/team')->assertStatus(401);
    }

    public function test_team_retorna_403_para_designer(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/reports/team')
            ->assertStatus(403);
    }

    public function test_team_retorna_200_para_management(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/crm/reports/team');

        $response->assertStatus(200);
        $response->assertJsonStructure(['data']);
        $this->assertIsArray($response->json('data'));
    }

    public function test_team_incluye_usuarios_con_proyectos(): void
    {
        $mgmtToken     = $this->loginAs();
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $designerId    = $this->userIdFromToken($designerToken);

        $this->createProject($mgmtToken, ['assignee_ids' => [$designerId]]);

        $response = $this->withHeaders($this->authHeaders($mgmtToken))
            ->getJson('/api/crm/reports/team');

        $response->assertStatus(200);

        $data    = (array) $response->json('data');
        $userIds = array_column($data, 'user_id');
        $this->assertContains($designerId, $userIds);

        // Each entry has expected structure
        $entry = current(array_filter($data, fn (array $u): bool => $u['user_id'] === $designerId));
        $this->assertArrayHasKey('counts', $entry);
        $this->assertArrayHasKey('valor_activo', $entry);
        $this->assertArrayHasKey('valor_cerrado', $entry);
        $this->assertArrayHasKey('vencidos', $entry);
    }

    // ── GET /api/crm/reports/monthly/export ──────────────────────────────────

    public function test_export_retorna_401_sin_autenticar(): void
    {
        $this->getJson('/api/crm/reports/monthly/export')->assertStatus(401);
    }

    public function test_export_retorna_csv_con_bom_utf8(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/crm/reports/monthly/export');

        $response->assertStatus(200);
        $this->assertStringStartsWith(
            'text/csv',
            (string) $response->headers->get('Content-Type'),
        );
        $this->assertStringStartsWith("\xEF\xBB\xBF", $response->streamedContent());
    }

    public function test_export_nombre_archivo_incluye_mes(): void
    {
        $token = $this->loginAs();

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/crm/reports/monthly/export?month=2026-01');

        $response->assertStatus(200);
        $this->assertStringContainsString(
            'reporte-2026-01.csv',
            (string) $response->headers->get('Content-Disposition'),
        );
    }

    public function test_export_contiene_secciones_esperadas(): void
    {
        $token     = $this->loginAs();
        $projectId = $this->createProject($token);
        $this->addActivity($token, $projectId, 'llamada');

        $response = $this->withHeaders($this->authHeaders($token))
            ->get('/api/crm/reports/monthly/export');

        $response->assertStatus(200);
        $body = $response->streamedContent();

        $this->assertStringContainsString('KPIs', $body);
        $this->assertStringContainsString('Actividades por tipo', $body);
        $this->assertStringContainsString('Proyectos nuevos del mes', $body);
        $this->assertStringContainsString("Bit\xC3\xA1cora de actividades", $body);
    }

    public function test_export_management_incluye_desglose_equipo(): void
    {
        $mgmtToken     = $this->loginAs();
        $designerToken = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');
        $designerId    = $this->userIdFromToken($designerToken);

        $this->createProject($mgmtToken, ['assignee_ids' => [$designerId]]);

        $response = $this->withHeaders($this->authHeaders($mgmtToken))
            ->get('/api/crm/reports/monthly/export');

        $response->assertStatus(200);
        $this->assertStringContainsString('Desglose por equipo', $response->streamedContent());
    }

    public function test_export_rechaza_formato_mes_invalido(): void
    {
        $token = $this->loginAs();

        $this->withHeaders(array_merge($this->authHeaders($token), ['Accept' => 'application/json']))
            ->get('/api/crm/reports/monthly/export?month=01-2026')
            ->assertStatus(422);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function userIdFromToken(string $token): int
    {
        $parts   = explode('.', $token);
        $padded  = str_pad(strtr($parts[1], '-_', '+/'), (int) ceil(strlen($parts[1]) / 4) * 4, '=');
        $payload = (array) json_decode((string) base64_decode($padded), true);

        return (int) ($payload['sub'] ?? 0);
    }
}
