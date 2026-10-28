<?php

declare(strict_types=1);

namespace Tests\Feature\CRM;

use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 3 de la auditoría del esquema de permisos (Atlantic/CLAUDE.md, sesión 2026-08-30) —
 * reemplaza el patrón `hasRole(MANAGEMENT)`/`isManagement` (13 archivos, enum legado `user_roles`)
 * scattered por todo CRM por `$user->canViewTeamV2('ventas_diseno')` — reusa la visibilidad de
 * Ventas & Diseño (ya sembrada correctamente, mismo dominio que el propio Sidebar del frontend ya
 * trata como uno solo con CRM), sin seeding nuevo. Este test verifica el comportamiento con roles
 * REALES del sistema nuevo (no solo los fixtures genéricos management/designer de
 * ReportControllerTest), y el permiso nuevo `crm.projects.delete`.
 */
class CrmPermissionMigrationTest extends FeatureTestCase
{
    private const GERENCIA1 = 'gerencia@test.com'; // management

    private const LIDERSERVICIOS = 'liderservicios@test.com'; // lider_servicios — ajeno a ventas_diseno/crm

    private const MILENA = 'vendedor@test.com'; // vendedor — can_view_team=false

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function tokenFor(string $email, string $roleKey): string
    {
        return $this->loginAs($email, $email, $roleKey);
    }

    // ── GET /api/crm/projects — alcance de equipo vs propio, con roles reales ───

    public function test_management_ve_todos_los_proyectos_no_solo_los_propios(): void
    {
        $tokenGerencia = $this->tokenFor(self::GERENCIA1, 'management');
        $tokenLiderServicios = $this->tokenFor(self::LIDERSERVICIOS, 'lider_servicios');

        // Lider Servicios (lider_servicios) crea un proyecto sin asignarse a sí mismo — no tiene alcance de
        // equipo en ventas_diseno, pero SÍ tiene crm.write genérico (nivel 14, "Power User —
        // Servicios").
        $this->withHeaders($this->authHeaders($tokenLiderServicios))
            ->postJson('/api/crm/projects', ['nombre' => 'Proyecto de Lider Servicios', 'tipo' => 'diseno', 'assignee_ids' => []])
            ->assertStatus(201);

        // Sin estar asignado, Lider Servicios no ve su propio proyecto en el listado — confirma que el
        // scoping es por asignación real, no por autoría (mismo comportamiento de siempre).
        $liderserviciosIndex = $this->withHeaders($this->authHeaders($tokenLiderServicios))->getJson('/api/crm/projects');
        $liderserviciosIndex->assertStatus(200);
        $this->assertNotContains('Proyecto de Lider Servicios', collect($liderserviciosIndex->json('data'))->pluck('nombre'));

        // Gerencia (management) sí lo ve, sin estar asignada — alcance de equipo.
        $gerencia1Index = $this->withHeaders($this->authHeaders($tokenGerencia))->getJson('/api/crm/projects');
        $gerencia1Index->assertStatus(200);
        $nombresGerencia = collect($gerencia1Index->json('data'))->pluck('nombre');
        $this->assertContains('Proyecto de Lider Servicios', $nombresGerencia);
    }

    // ── GET /api/crm/reports/team — permission:ventas_diseno.view_team ──────────

    public function test_reports_team_management_si_lider_servicios_no(): void
    {
        $this->withHeaders($this->authHeaders($this->tokenFor(self::LIDERSERVICIOS, 'lider_servicios')))
            ->getJson('/api/crm/reports/team')
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($this->tokenFor(self::GERENCIA1, 'management')))
            ->getJson('/api/crm/reports/team')
            ->assertStatus(200);
    }

    public function test_reports_team_vendedor_disenador_no_puede(): void
    {
        // vendedor SÍ tiene fila en role_module_visibility para ventas_diseno
        // (can_view=FULL) pero con can_view_team=false explícito — caso más estricto que un rol
        // sin ninguna fila (como lider_servicios en el test de arriba).
        $this->withHeaders($this->authHeaders($this->tokenFor(self::MILENA, 'vendedor')))
            ->getJson('/api/crm/reports/team')
            ->assertStatus(403);
    }

    // ── DELETE /api/crm/projects/{id} — permission:crm.projects.delete, nuevo ──

    public function test_delete_project_management_puede_lider_servicios_no(): void
    {
        $tokenLiderServicios = $this->tokenFor(self::LIDERSERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($tokenLiderServicios))
            ->postJson('/api/crm/projects', ['nombre' => 'A Borrar', 'tipo' => 'diseno', 'assignee_ids' => []]);
        $response->assertStatus(201);
        $projectId = (int) $response->json('id');

        // Lider Servicios tiene crm.delete genérico (nivel 14) pero NO crm.projects.delete (exclusivo nivel 9).
        $this->withHeaders($this->authHeaders($tokenLiderServicios))
            ->deleteJson("/api/crm/projects/{$projectId}")
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($this->tokenFor(self::GERENCIA1, 'management')))
            ->deleteJson("/api/crm/projects/{$projectId}")
            ->assertStatus(204);
    }

    // ── superadmin siempre pasa ───────────────────────────────────────────────────

    public function test_superadmin_pasa_team_y_delete(): void
    {
        $token = $this->loginAs('superadmin@test.com', 'superadmin@test.com');
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/crm/projects', ['nombre' => 'Superadmin Test', 'tipo' => 'diseno', 'assignee_ids' => []]);
        $response->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))->getJson('/api/crm/reports/team')->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->deleteJson('/api/crm/projects/'.$response->json('id'))
            ->assertStatus(204);
    }
}
