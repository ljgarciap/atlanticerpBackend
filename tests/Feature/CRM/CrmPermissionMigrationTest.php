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

    private const VENDEDOR = 'vendedor@test.com'; // vendedor — can_view_team=false

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
        $tokenVendedor = $this->tokenFor(self::VENDEDOR, 'vendedor');

        // El vendedor crea un proyecto sin asignarse: queda asignado a él (Fase 11) y lo ve en su listado.
        $this->withHeaders($this->authHeaders($tokenVendedor))
            ->postJson('/api/crm/projects', ['nombre' => 'Proyecto del Vendedor', 'tipo' => 'diseno', 'assignee_ids' => []])
            ->assertStatus(201);
        $vendedorIndex = $this->withHeaders($this->authHeaders($tokenVendedor))->getJson('/api/crm/projects');
        $vendedorIndex->assertStatus(200);
        $this->assertContains('Proyecto del Vendedor', collect($vendedorIndex->json('data'))->pluck('nombre'));

        // Gerencia (management) lo ve también, sin estar asignada — alcance de equipo.
        $gerencia1Index = $this->withHeaders($this->authHeaders($tokenGerencia))->getJson('/api/crm/projects');
        $gerencia1Index->assertStatus(200);
        $this->assertContains('Proyecto del Vendedor', collect($gerencia1Index->json('data'))->pluck('nombre'));
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
        $this->withHeaders($this->authHeaders($this->tokenFor(self::VENDEDOR, 'vendedor')))
            ->getJson('/api/crm/reports/team')
            ->assertStatus(403);
    }

    // ── DELETE /api/crm/projects/{id} — permission:crm.projects.delete, nuevo ──

    public function test_delete_project_management_puede_vendedor_no(): void
    {
        $tokenVendedor = $this->tokenFor(self::VENDEDOR, 'vendedor');
        $response = $this->withHeaders($this->authHeaders($tokenVendedor))
            ->postJson('/api/crm/projects', ['nombre' => 'A Borrar', 'tipo' => 'diseno', 'assignee_ids' => []]);
        $response->assertStatus(201);
        $projectId = (int) $response->json('id');

        // El vendedor tiene crm.delete genérico pero NO crm.projects.delete (exclusivo nivel 9).
        $this->withHeaders($this->authHeaders($tokenVendedor))
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
