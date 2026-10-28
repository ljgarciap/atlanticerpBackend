<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Services\AuditService;
use Illuminate\Http\Request;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-792 (Epic SCRUM-788 — Logs y Telemetría). A diferencia de
 * Tests\Feature\VentasDiseno\AuditLogControllerTest (endpoint scoped, sigue intacto), este cubre
 * el visor generalizado: filtros de módulo/tipo nuevos + que de verdad muestre TODOS los módulos
 * (no solo ventas_diseno.%) + el gate superadmin.all.
 */
class AuditLogControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function actingForAudit(string $email): void
    {
        $user    = User::where('email', $email)->firstOrFail();
        $request = Request::create('/');
        $request->attributes->set('jwt', ['sub' => $user->id]);
        $this->app->instance('request', $request);
    }

    public function test_sin_autenticar_retorna_401(): void
    {
        $this->getJson('/api/admin/audit/logs')->assertStatus(401);
    }

    public function test_sin_permiso_superadmin_retorna_403(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/audit/logs')
            ->assertStatus(403);
    }

    public function test_entity_types_retorna_catalogo_distinct_sin_nulos(): void
    {
        $this->actingForAudit('management@atlantic.test');
        MasterClient::create(['name' => '[SCRUM-813] Cliente Test']);
        MasterClient::create(['name' => '[SCRUM-813] Cliente Test 2']);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/audit/logs/entity-types')
            ->assertStatus(200);

        $types = $response->json('data');
        $this->assertContains('master_client', $types);
        $this->assertSame($types, array_values(array_unique($types)));
        $this->assertSame($types, collect($types)->sort()->values()->all());
    }

    public function test_entity_types_sin_permiso_superadmin_retorna_403(): void
    {
        $token = $this->loginAs('vendedor@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/audit/logs/entity-types')
            ->assertStatus(403);
    }

    public function test_muestra_filas_de_cualquier_modulo_no_solo_ventas_diseno(): void
    {
        $this->actingForAudit('management@atlantic.test');
        $ticket = Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Ticket SCRUM-792',
            'cliente' => 'Cliente de prueba', 'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING, 'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);

        $this->actingForAudit('management@atlantic.test');
        MasterClient::create(['name' => '[SCRUM-792] Cliente Test']);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/audit/logs')
            ->assertStatus(200);

        $actions = collect($response->json('data'))->pluck('action');
        $this->assertTrue($actions->contains('servicios.ticket.created'));
        $this->assertTrue($actions->contains('ventas_diseno.master_client.created'));
    }

    public function test_metadata_de_uso_de_ia_llega_en_la_respuesta(): void
    {
        // SCRUM-814 — el prompt_preview capturado por AiUsageAuditor::log() se guardaba en
        // audit_logs.metadata pero el endpoint nunca lo devolvía, así que la pantalla de Logs
        // nunca podía mostrarlo (hallazgo real de Luis, 2026-09-03).
        $user = \App\Models\User::where('email', 'management@atlantic.test')->firstOrFail();
        app(AuditService::class)->log(
            action:       AuditService::AI_USAGE_REQUESTED,
            entityType:   'ai_analysis',
            entityId:     123,
            metadata:     ['job' => 'App\\Modules\\AI\\Jobs\\SummarizeJob', 'prompt_preview' => 'Resumí el estado del proyecto X'],
            actingUserId: $user->id,
        );

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/audit/logs?module=ai')
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $row  = $rows->firstWhere('action', AuditService::AI_USAGE_REQUESTED);
        $this->assertNotNull($row);
        $this->assertSame('Resumí el estado del proyecto X', $row['metadata']['prompt_preview']);
    }

    public function test_filtro_por_modulo(): void
    {
        $this->actingForAudit('management@atlantic.test');
        Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Ticket SCRUM-792',
            'cliente' => 'Cliente de prueba', 'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING, 'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);
        $this->actingForAudit('management@atlantic.test');
        MasterClient::create(['name' => '[SCRUM-792] Cliente Test 2']);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/audit/logs?module=servicios')
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->isNotEmpty());
        $this->assertTrue($rows->every(fn (array $r) => str_starts_with($r['action'], 'servicios.')));
        $this->assertTrue($rows->every(fn (array $r) => $r['module'] === 'servicios'));
    }

    public function test_filtro_por_tipo_cambio_dato_vs_accion(): void
    {
        $this->actingForAudit('management@atlantic.test');
        $ticket = Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Ticket SCRUM-792',
            'cliente' => 'Cliente de prueba', 'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING, 'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);

        // Acción sin escritura (SCRUM-791) — sufijo distinto de created/updated/deleted.
        app(AuditService::class)->log(
            action:     AuditService::SERVICIOS_TICKET_PDF_DOWNLOADED,
            entityType: 'ticket',
            entityId:   $ticket->id,
        );

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $cambiosDato = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/audit/logs?tipo=cambio_dato&entity_type=ticket&entity_id='.$ticket->id)
            ->assertStatus(200)->json('data');
        $this->assertTrue(collect($cambiosDato)->contains('action', 'servicios.ticket.created'));
        $this->assertFalse(collect($cambiosDato)->contains('action', 'servicios.ticket.pdf_downloaded'));

        $acciones = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/audit/logs?tipo=accion&entity_type=ticket&entity_id='.$ticket->id)
            ->assertStatus(200)->json('data');
        $this->assertTrue(collect($acciones)->contains('action', 'servicios.ticket.pdf_downloaded'));
        $this->assertFalse(collect($acciones)->contains('action', 'servicios.ticket.created'));
    }

    public function test_filtro_por_entity_type_y_entity_id(): void
    {
        $this->actingForAudit('management@atlantic.test');
        $ticketA = Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Ticket A',
            'cliente' => 'Cliente A', 'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING, 'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);
        $this->actingForAudit('management@atlantic.test');
        Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Ticket B',
            'cliente' => 'Cliente B', 'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING, 'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin/audit/logs?entity_type=ticket&entity_id={$ticketA->id}")
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->isNotEmpty());
        $this->assertTrue($rows->every(fn (array $r) => $r['entity_id'] === (string) $ticketA->id));
    }

    public function test_filtro_por_usuario(): void
    {
        $managerId = (int) User::where('email', 'management@atlantic.test')->value('id');

        $this->actingForAudit('management@atlantic.test');
        MasterClient::create(['name' => '[SCRUM-792] Cliente por usuario']);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin/audit/logs?user_id={$managerId}")
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->isNotEmpty());
        $this->assertTrue($rows->every(fn (array $r) => $r['user']['id'] === $managerId));
    }

    /** SCRUM-1020 (Pre-QA M2) — un rango inválido en la URL ya no revienta con 500. */
    public function test_rango_de_fechas_invalido_retorna_422_y_no_500(): void
    {
        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');
        $h = $this->authHeaders($token);

        $this->withHeaders($h)->getJson('/api/admin/audit/logs?from=garbage')->assertStatus(422);
        $this->withHeaders($h)->getJson('/api/admin/audit/logs?to=2026-13-45')->assertStatus(422);
        $this->withHeaders($h)->getJson('/api/admin/audit/logs?from=2026-09-01&to=2026-09-30')->assertStatus(200);
    }
}
