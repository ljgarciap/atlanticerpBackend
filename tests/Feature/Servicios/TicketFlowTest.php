<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\Ticket;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 1 (REQ-216→221, REQ-249). Usuarios reales de Servicios (Aaron Leis/
 * lider_servicios, Tecnico Servicios Test/tecnico_servicios, Garantias Servicios Test/garantias_servicios) y de
 * Ventas & Diseño (vendedor_disenador) ya existen vía `BusinessRoleUserSeeder` en TODOS los
 * entornos incl. testing — password = el email (cast `hashed`, ver `User::casts()`). Los permisos
 * de módulo (`RoleModuleVisibility`/`SecurityLevelModulePermission`) ya vienen sembrados por las
 * migraciones de tenant de esta misma fecha — no hace falta ningún grant manual acá.
 */
class TicketFlowTest extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const TECNICO_SERVICIOS = 'tecnicoservicios@test.com';

    private const GARANTIAS_SERVICIOS = 'garantiasservicios@test.com';

    private const MANAGEMENT = 'gerencia@test.com';

    private const VENDEDOR_DISENADOR = 'vendedordisenador@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    private function makeCurrentTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** Batch 3 parte 2 — `sales_project_id` es obligatorio en POST /tickets desde REQ-246 RN6. */
    private function makeSalesProject(): SalesProject
    {
        $unique = uniqid();
        $master = MasterClient::create(['name' => "Cliente {$unique}"]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => "Cliente {$unique} SA", 'tax_id' => $unique]);

        return SalesProject::create(['sub_client_id' => $sub->id, 'name' => "Proyecto {$unique}"]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeTicket(array $overrides = []): Ticket
    {
        return Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(),
            'tipo' => Ticket::TIPO_INSTALLATION,
            'subtipo' => Ticket::SUBTIPO_INSPECTION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion' => 'Ticket de prueba',
            'cliente' => 'Cliente de prueba',
            'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
            ...$overrides,
        ]);
    }

    // ── REQ-249 — creación + numeración + derivación ────────────────────────────────────────

    public function test_crear_ticket_instalacion_subtipo_instalacion_deriva_ambos_no_aplica(): void
    {
        $project = $this->makeSalesProject();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_INSTALLATION,
                'subtipo' => Ticket::SUBTIPO_INSTALLATION,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion' => 'Instalación nueva por venta',
                'sales_project_id' => $project->id,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('estado', Ticket::ESTADO_REPORTED);
        $response->assertJsonPath('quote_status', Ticket::QUOTE_STATUS_NOT_APPLICABLE);
        $response->assertJsonPath('inspection_report_status', Ticket::INSPECTION_STATUS_NOT_APPLICABLE);
        $response->assertJsonPath('internal_technician_id', null);
        $response->assertJsonPath('scheduled_at', null);

        $numero = $response->json('numero');
        $this->assertMatchesRegularExpression('/^INS-'.date('Y').'-\d{4}$/', $numero);
    }

    public function test_crear_ticket_reclamo_deriva_informe_no_aplica_cotizacion_pendiente(): void
    {
        $project = $this->makeSalesProject();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion' => 'Reclamo de producto defectuoso',
                'sales_project_id' => $project->id,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('quote_status', Ticket::QUOTE_STATUS_PENDING);
        $response->assertJsonPath('inspection_report_status', Ticket::INSPECTION_STATUS_NOT_APPLICABLE);

        $numero = $response->json('numero');
        $this->assertMatchesRegularExpression('/^REC-'.date('Y').'-\d{4}$/', $numero);
    }

    public function test_crear_ticket_garantia_deriva_ambos_pendientes(): void
    {
        $project = $this->makeSalesProject();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_WARRANTY,
                'subtipo' => Ticket::SUBTIPO_WARRANTY_GENERIC,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_SUBCONTRACTED,
                'descripcion' => 'Garantía genérica',
                'sales_project_id' => $project->id,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('quote_status', Ticket::QUOTE_STATUS_PENDING);
        $response->assertJsonPath('inspection_report_status', Ticket::INSPECTION_STATUS_PENDING);
    }

    public function test_crear_ticket_sin_subtipo_requerido_retorna_422(): void
    {
        $project = $this->makeSalesProject();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_WARRANTY,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion' => 'Sin subtipo',
                'sales_project_id' => $project->id,
            ])
            ->assertStatus(422);
    }

    /** REQ-246 RN6 — sales_project_id es obligatorio para crear un ticket. */
    public function test_crear_ticket_sin_sales_project_id_retorna_422(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion' => 'Sin proyecto asociado',
            ])
            ->assertStatus(422);
    }

    public function test_numeracion_es_consecutiva_por_prefijo_y_anio(): void
    {
        $project = $this->makeSalesProject();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $headers = $this->authHeaders($token);

        $payload = [
            'tipo' => Ticket::TIPO_INSTALLATION,
            'subtipo' => Ticket::SUBTIPO_INSPECTION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion' => 'Inspección',
            'sales_project_id' => $project->id,
        ];

        $first = $this->withHeaders($headers)->postJson('/api/servicios/tickets', $payload)->assertStatus(201);
        $second = $this->withHeaders($headers)->postJson('/api/servicios/tickets', $payload)->assertStatus(201);

        $warranty = $this->withHeaders($headers)->postJson('/api/servicios/tickets', [
            'tipo' => Ticket::TIPO_WARRANTY,
            'subtipo' => Ticket::SUBTIPO_WARRANTY_GENERIC,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion' => 'Garantía',
            'sales_project_id' => $project->id,
        ])->assertStatus(201);

        $year = date('Y');
        $this->assertSame("INS-{$year}-0001", $first->json('numero'));
        $this->assertSame("INS-{$year}-0002", $second->json('numero'));
        // Prefijo distinto -> consecutivo propio, no comparte contador con INS.
        $this->assertSame("GAR-{$year}-0001", $warranty->json('numero'));
    }

    // ── REQ-217/REQ-216/REQ-221 — listado, filtros, tablero ─────────────────────────────────

    public function test_index_filtra_por_tipo_y_estado(): void
    {
        $this->makeTicket(['numero' => 'INS-2026-90001', 'tipo' => Ticket::TIPO_INSTALLATION, 'estado' => Ticket::ESTADO_REPORTED]);
        $this->makeTicket(['numero' => 'GAR-2026-90002', 'tipo' => Ticket::TIPO_WARRANTY, 'estado' => Ticket::ESTADO_SCHEDULED]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tickets?tipo='.Ticket::TIPO_WARRANTY)
            ->assertStatus(200);

        $numeros = collect($response->json('data'))->pluck('numero')->all();
        $this->assertContains('GAR-2026-90002', $numeros);
        $this->assertNotContains('INS-2026-90001', $numeros);
    }

    public function test_index_orden_reportado_sin_agendar_primero(): void
    {
        $scheduled = $this->makeTicket([
            'numero' => 'INS-2026-90010', 'estado' => Ticket::ESTADO_REPORTED,
            'scheduled_at' => now(), 'created_at' => now()->subDays(5),
        ]);
        $unscheduled = $this->makeTicket([
            'numero' => 'INS-2026-90011', 'estado' => Ticket::ESTADO_REPORTED,
            'scheduled_at' => null, 'created_at' => now()->subDays(10),
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tickets?per_page=50')
            ->assertStatus(200);

        $numeros = collect($response->json('data'))->pluck('numero')->all();
        $posUnscheduled = array_search($unscheduled->numero, $numeros, true);
        $posScheduled = array_search($scheduled->numero, $numeros, true);

        $this->assertNotFalse($posUnscheduled);
        $this->assertNotFalse($posScheduled);
        $this->assertLessThan($posScheduled, $posUnscheduled, 'Reportado sin agendar debe listarse antes.');
    }

    public function test_board_agrupa_por_estado_con_conteos(): void
    {
        $this->makeTicket(['numero' => 'INS-2026-90020', 'estado' => Ticket::ESTADO_REPORTED]);
        $this->makeTicket(['numero' => 'INS-2026-90021', 'estado' => Ticket::ESTADO_SCHEDULED]);
        $this->makeTicket(['numero' => 'INS-2026-90022', 'estado' => Ticket::ESTADO_CANCELLED]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tickets/board')
            ->assertStatus(200);

        $counts = $response->json('counts_by_estado');
        $this->assertArrayHasKey(Ticket::ESTADO_REPORTED, $counts);
        $this->assertArrayHasKey(Ticket::ESTADO_CANCELLED, $counts);
        $this->assertGreaterThanOrEqual(1, $counts[Ticket::ESTADO_REPORTED]);
        $this->assertGreaterThanOrEqual(1, $counts[Ticket::ESTADO_CANCELLED]);
    }

    public function test_vendedor_disenador_puede_ver(): void
    {
        $token = $this->loginAs(self::VENDEDOR_DISENADOR, self::VENDEDOR_DISENADOR, 'vendedor_disenador');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tickets')
            ->assertStatus(200);
    }

    /**
     * REQ-245 RN4 — Vendedor/Diseñador SÍ puede crear tickets (única excepción a su acceso de
     * solo lectura en Servicios, ver docblock de
     * 2026_08_02_100003_grant_servicios_view_to_vendedor_disenador.php, que dejó esto pendiente
     * a propósito hasta este batch).
     */
    public function test_vendedor_disenador_puede_crear_ticket(): void
    {
        $project = $this->makeSalesProject();
        $token = $this->loginAs(self::VENDEDOR_DISENADOR, self::VENDEDOR_DISENADOR, 'vendedor_disenador');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_INSTALLATION,
                'subtipo' => Ticket::SUBTIPO_INSTALLATION,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion' => 'Garantía detectada en visita de venta',
                'sales_project_id' => $project->id,
            ])
            ->assertStatus(201);
    }

    /** REQ-245 RN4 — Gerencia (`management`) también puede crear tickets. */
    public function test_management_puede_crear_ticket(): void
    {
        $project = $this->makeSalesProject();
        $token = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion' => 'Creado por Gerencia',
                'sales_project_id' => $project->id,
            ])
            ->assertStatus(201);
    }

    // ── REQ-218 — gate de cierre ─────────────────────────────────────────────────────────────

    public function test_cambio_a_resuelto_bloqueado_si_falta_informe(): void
    {
        $ticket = $this->makeTicket([
            'quote_status' => Ticket::QUOTE_STATUS_APPROVED,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => Ticket::ESTADO_RESOLVED])
            ->assertStatus(422);

        $response->assertJsonFragment(['message' => 'No se puede cerrar: falta completar el informe de inspección.']);

        $this->makeCurrentTenant();
        $this->assertSame(Ticket::ESTADO_REPORTED, $ticket->fresh()->estado);
    }

    public function test_cambio_a_cerrado_bloqueado_si_cotizacion_rechazada(): void
    {
        $ticket = $this->makeTicket([
            'quote_status' => Ticket::QUOTE_STATUS_REJECTED,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_COMPLETED,
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => Ticket::ESTADO_CLOSED])
            ->assertStatus(422);

        $response->assertJsonFragment(['message' => 'No se puede cerrar: falta aprobar la cotización.']);
    }

    public function test_cambio_a_resuelto_permitido_cuando_cotizacion_y_informe_cumplen(): void
    {
        $ticket = $this->makeTicket([
            'quote_status' => Ticket::QUOTE_STATUS_APPROVED,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_COMPLETED,
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => Ticket::ESTADO_RESOLVED])
            ->assertStatus(200)
            ->assertJsonPath('estado', Ticket::ESTADO_RESOLVED);
    }

    public function test_cambio_a_cerrado_permitido_cuando_no_aplica(): void
    {
        $ticket = $this->makeTicket([
            'quote_status' => Ticket::QUOTE_STATUS_NOT_APPLICABLE,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_NOT_APPLICABLE,
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => Ticket::ESTADO_CLOSED])
            ->assertStatus(200);
    }

    /** RN5 — cancelar nunca pasa por el gate, sin importar el estado de cotización/informe. */
    public function test_cancelar_no_valida_el_gate(): void
    {
        $ticket = $this->makeTicket([
            'quote_status' => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => Ticket::ESTADO_CANCELLED])
            ->assertStatus(200)
            ->assertJsonPath('estado', Ticket::ESTADO_CANCELLED);
    }

    /** REQ-218 RN6 — transición a un estado que no es Resuelto/Cerrado no valida nada. */
    public function test_cambio_a_agendado_no_valida_el_gate(): void
    {
        $ticket = $this->makeTicket([
            'quote_status' => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => Ticket::ESTADO_SCHEDULED])
            ->assertStatus(200);
    }

    // ── REQ-218 RN7 / REQ-221 RN6 — solo lider_servicios/superadmin cambian el estado ──────────

    public function test_tecnico_servicios_no_puede_cambiar_estado(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => Ticket::ESTADO_SCHEDULED])
            ->assertStatus(403);
    }

    public function test_garantias_servicios_no_puede_cambiar_estado(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::GARANTIAS_SERVICIOS, self::GARANTIAS_SERVICIOS, 'garantias_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => Ticket::ESTADO_SCHEDULED])
            ->assertStatus(403);
    }

    public function test_management_no_puede_cambiar_estado(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => Ticket::ESTADO_SCHEDULED])
            ->assertStatus(403);
    }

    public function test_lider_servicios_si_puede_cambiar_estado(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => Ticket::ESTADO_SCHEDULED])
            ->assertStatus(200);
    }

    // ── REQ-245 RN4 — quién puede crear: Aaron/Líder de Servicios, superadmin, Gerencia y
    //    Vendedor/Diseñador. Técnico interno/Garantías tienen servicios.write (editan su propio
    //    ticket asignado) pero "no tienen el botón de Nuevo ticket disponible" — `role:` en la
    //    ruta es más angosto que el permiso de módulo a propósito, ver routes/servicios.php. ──

    public function test_tecnico_servicios_no_puede_crear_ticket(): void
    {
        $project = $this->makeSalesProject();
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion' => 'No debería poder crear',
                'sales_project_id' => $project->id,
            ])
            ->assertStatus(403);
    }

    public function test_garantias_servicios_no_puede_crear_ticket(): void
    {
        $project = $this->makeSalesProject();
        $token = $this->loginAs(self::GARANTIAS_SERVICIOS, self::GARANTIAS_SERVICIOS, 'garantias_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/tickets', [
                'tipo' => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion' => 'No debería poder crear',
                'sales_project_id' => $project->id,
            ])
            ->assertStatus(403);
    }
}
