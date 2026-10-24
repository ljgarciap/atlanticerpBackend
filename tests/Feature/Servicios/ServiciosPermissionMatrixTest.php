<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\Ticket;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 2 de la auditoría del esquema de permisos (Atlantic/CLAUDE.md, sesión 2026-08-30) —
 * reemplaza los `role:clave1,clave2,...` hardcodeados de routes/servicios.php por permisos finos
 * asignados por nivel de seguridad (ver migración 2026_08_30_100001). Este test verifica que la
 * frontera real de acceso (quién entra, quién no) se mantiene idéntica a la de los `role:` que
 * reemplaza — no es un test de negocio, es la regresión de seguridad del propio refactor.
 *
 * Roles reales usados (BusinessRoleUserSeeder): lider_servicios=Aldo, tecnico_servicios=Carlos,
 * garantias_servicios=Miguel, vendedor_disenador=Milena. management=Daniela (CoreUserSeeder).
 */
class ServiciosPermissionMatrixTest extends FeatureTestCase
{
    private const SUPERADMIN = 'superadmin@test.com';

    private const MANAGEMENT = 'gerencia@test.com';

    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const TECNICO = 'tecnicoservicios@test.com';

    private const GARANTIAS = 'garantiasservicios@test.com';

    private const VENDEDOR = 'vendedordisenador@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->activateTenant();
        (new \Database\Seeders\ServiciosDemoSeeder)->seedParaTests();
    }

    private function activateTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, string> $roles email => role_key para loginAs */
    private function tokenFor(string $email, string $roleKey): string
    {
        return $this->loginAs($email, $email, $roleKey);
    }

    // ── servicios.settings.configure — exclusivo management/superadmin ──────────

    public function test_settings_solo_management_y_superadmin(): void
    {
        $this->withHeaders($this->authHeaders($this->tokenFor(self::MANAGEMENT, 'management')))
            ->getJson('/api/servicios/settings')->assertStatus(200);

        $this->withHeaders($this->authHeaders($this->tokenFor(self::LIDER_SERVICIOS, 'lider_servicios')))
            ->getJson('/api/servicios/settings')->assertStatus(403);

        $this->withHeaders($this->authHeaders($this->tokenFor(self::TECNICO, 'tecnico_servicios')))
            ->getJson('/api/servicios/settings')->assertStatus(403);
    }

    // ── servicios.tickets.create — lider/management/vendedor, NO tecnico/garantias ──

    public function test_crear_ticket_lider_management_y_vendedor_pueden(): void
    {
        $payload = ['tipo' => 'instalacion', 'sub_client_id' => null];

        foreach ([
            [self::LIDER_SERVICIOS, 'lider_servicios'],
            [self::MANAGEMENT, 'management'],
            [self::VENDEDOR, 'vendedor_disenador'],
        ] as [$email, $role]) {
            $status = $this->withHeaders($this->authHeaders($this->tokenFor($email, $role)))
                ->postJson('/api/servicios/tickets', $payload)
                ->status();
            $this->assertNotSame(403, $status, "{$email} no debería recibir 403 al crear ticket");
        }
    }

    public function test_crear_ticket_tecnico_y_garantias_no_pueden(): void
    {
        foreach ([
            [self::TECNICO, 'tecnico_servicios'],
            [self::GARANTIAS, 'garantias_servicios'],
        ] as [$email, $role]) {
            $this->withHeaders($this->authHeaders($this->tokenFor($email, $role)))
                ->postJson('/api/servicios/tickets', ['tipo' => 'instalacion'])
                ->assertStatus(403);
        }
    }

    // ── servicios.tickets.manage — exclusivo lider_servicios, NI tecnico NI management ──

    public function test_cambiar_estado_ticket_solo_lider_servicios(): void
    {
        $this->activateTenant();
        $ticket = Ticket::query()->first();
        $this->assertNotNull($ticket, 'ServiciosDemoSeeder debe dejar al menos un ticket.');

        $this->withHeaders($this->authHeaders($this->tokenFor(self::TECNICO, 'tecnico_servicios')))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => 'agendado'])
            ->assertStatus(403);

        $this->withHeaders($this->authHeaders($this->tokenFor(self::MANAGEMENT, 'management')))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => 'agendado'])
            ->assertStatus(403);

        $status = $this->withHeaders($this->authHeaders($this->tokenFor(self::LIDER_SERVICIOS, 'lider_servicios')))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => 'agendado'])
            ->status();
        $this->assertNotSame(403, $status);
    }

    // ── servicios.claim_sheets.manage — tecnico SÍ, garantias NO (el matiz que motivó la Fase 2) ──

    public function test_claim_sheet_tecnico_puede_garantias_no(): void
    {
        $this->activateTenant();
        $ticket = Ticket::query()->first();

        $this->withHeaders($this->authHeaders($this->tokenFor(self::GARANTIAS, 'garantias_servicios')))
            ->putJson("/api/servicios/tickets/{$ticket->id}/claim-sheet", ['descripcion' => 'x'])
            ->assertStatus(403);

        $status = $this->withHeaders($this->authHeaders($this->tokenFor(self::TECNICO, 'tecnico_servicios')))
            ->putJson("/api/servicios/tickets/{$ticket->id}/claim-sheet", ['descripcion' => 'x'])
            ->status();
        $this->assertNotSame(403, $status);
    }

    public function test_inspection_report_pdf_blank_tecnico_puede_garantias_no(): void
    {
        $this->activateTenant();
        $ticket = Ticket::query()->first();

        $this->withHeaders($this->authHeaders($this->tokenFor(self::GARANTIAS, 'garantias_servicios')))
            ->getJson("/api/servicios/tickets/{$ticket->id}/inspection-report/pdf/blank")
            ->assertStatus(403);

        $status = $this->withHeaders($this->authHeaders($this->tokenFor(self::TECNICO, 'tecnico_servicios')))
            ->getJson("/api/servicios/tickets/{$ticket->id}/inspection-report/pdf/blank")
            ->status();
        $this->assertNotSame(403, $status);
    }

    // ── servicios.field_work.edit — lider + tecnico + garantias (compartido, sin excluir a nadie) ──

    public function test_inspection_report_save_lider_tecnico_y_garantias_pueden(): void
    {
        $this->activateTenant();
        $ticket = Ticket::query()->first();

        foreach ([
            [self::LIDER_SERVICIOS, 'lider_servicios'],
            [self::TECNICO, 'tecnico_servicios'],
            [self::GARANTIAS, 'garantias_servicios'],
        ] as [$email, $role]) {
            $status = $this->withHeaders($this->authHeaders($this->tokenFor($email, $role)))
                ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", [])
                ->status();
            $this->assertNotSame(403, $status, "{$email} no debería recibir 403 en inspection-report save");
        }
    }

    // ── servicios.technicians.commission.view_own — tecnico/garantias/management, NO lider ──

    public function test_comision_carlos_vergara_lider_no_puede_tecnico_si(): void
    {
        $this->withHeaders($this->authHeaders($this->tokenFor(self::LIDER_SERVICIOS, 'lider_servicios')))
            ->getJson('/api/servicios/reportes/comision-carlos-vergara')
            ->assertStatus(403);

        $status = $this->withHeaders($this->authHeaders($this->tokenFor(self::TECNICO, 'tecnico_servicios')))
            ->getJson('/api/servicios/reportes/comision-carlos-vergara')
            ->status();
        $this->assertNotSame(403, $status);
    }

    // ── servicios.external_technicians.view — todos los roles del módulo (lider/tecnico/garantias/management) ──

    public function test_external_technicians_index_visible_a_todo_el_modulo(): void
    {
        foreach ([
            [self::LIDER_SERVICIOS, 'lider_servicios'],
            [self::TECNICO, 'tecnico_servicios'],
            [self::GARANTIAS, 'garantias_servicios'],
            [self::MANAGEMENT, 'management'],
        ] as [$email, $role]) {
            $this->withHeaders($this->authHeaders($this->tokenFor($email, $role)))
                ->getJson('/api/servicios/external-technicians')
                ->assertStatus(200);
        }
    }

    public function test_external_technicians_index_vendedor_no_puede(): void
    {
        $this->withHeaders($this->authHeaders($this->tokenFor(self::VENDEDOR, 'vendedor_disenador')))
            ->getJson('/api/servicios/external-technicians')
            ->assertStatus(403);
    }

    // ── servicios.field_ops.admin — lider + management, NO tecnico ──

    public function test_registrar_tecnico_externo_lider_y_management_pueden_tecnico_no(): void
    {
        $payload = ['nombre' => 'Externo Test', 'empresa' => 'X'];

        $this->withHeaders($this->authHeaders($this->tokenFor(self::TECNICO, 'tecnico_servicios')))
            ->postJson('/api/servicios/external-technicians', $payload)
            ->assertStatus(403);

        foreach ([[self::LIDER_SERVICIOS, 'lider_servicios'], [self::MANAGEMENT, 'management']] as [$email, $role]) {
            $status = $this->withHeaders($this->authHeaders($this->tokenFor($email, $role)))
                ->postJson('/api/servicios/external-technicians', $payload)
                ->status();
            $this->assertNotSame(403, $status, "{$email} no debería recibir 403 al registrar técnico externo");
        }
    }

    // ── lider_compras y lider_bodega (comparten nivel con lider_servicios ANTES de esta Fase 2)
    //    no deben ganar NADA de Servicios — el bug real que se encontró y corrigió en el camino:
    //    el escape hatch JSONB no se filtra por role_module_visibility, así que un permiso puesto
    //    en un nivel compartido se filtraba a los 4 roles líder de ese nivel, no solo al dueño. ──

    public function test_lider_compras_y_lider_bodega_no_ganan_ningun_permiso_de_servicios(): void
    {
        foreach ([['lider_compras', 'lidercompras@test.com'], ['lider_bodega', 'liderbodega@test.com']] as [$role, $email]) {
            $token = $this->tokenFor($email, $role);
            $this->withHeaders($this->authHeaders($token))
                ->getJson('/api/servicios/settings')->assertStatus(403);
            $this->withHeaders($this->authHeaders($token))
                ->postJson('/api/servicios/tickets', ['tipo' => 'instalacion'])->assertStatus(403);
            $this->withHeaders($this->authHeaders($token))
                ->getJson('/api/servicios/external-technicians')->assertStatus(403);
        }
    }

    // ── superadmin siempre pasa, en cualquier permiso angosto ────────────────────

    public function test_superadmin_pasa_todos_los_permisos_finos(): void
    {
        $token = $this->loginAs(self::SUPERADMIN, self::SUPERADMIN);

        $this->withHeaders($this->authHeaders($token))->getJson('/api/servicios/settings')->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))->getJson('/api/servicios/external-technicians')->assertStatus(200);
    }
}
