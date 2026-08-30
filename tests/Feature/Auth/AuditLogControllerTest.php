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
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
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
        $token = $this->loginAs('designer@illuminations.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/audit/logs')
            ->assertStatus(403);
    }

    public function test_muestra_filas_de_cualquier_modulo_no_solo_ventas_diseno(): void
    {
        $this->actingForAudit('management@illuminations.test');
        $ticket = Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Ticket SCRUM-792',
            'cliente' => 'Cliente de prueba', 'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING, 'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);

        $this->actingForAudit('management@illuminations.test');
        MasterClient::create(['name' => '[SCRUM-792] Cliente Test']);

        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!', 'superadmin');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/admin/audit/logs')
            ->assertStatus(200);

        $actions = collect($response->json('data'))->pluck('action');
        $this->assertTrue($actions->contains('servicios.ticket.created'));
        $this->assertTrue($actions->contains('ventas_diseno.master_client.created'));
    }

    public function test_filtro_por_modulo(): void
    {
        $this->actingForAudit('management@illuminations.test');
        Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Ticket SCRUM-792',
            'cliente' => 'Cliente de prueba', 'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING, 'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);
        $this->actingForAudit('management@illuminations.test');
        MasterClient::create(['name' => '[SCRUM-792] Cliente Test 2']);

        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!', 'superadmin');
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
        $this->actingForAudit('management@illuminations.test');
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

        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!', 'superadmin');

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
        $this->actingForAudit('management@illuminations.test');
        $ticketA = Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Ticket A',
            'cliente' => 'Cliente A', 'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING, 'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);
        $this->actingForAudit('management@illuminations.test');
        Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'Ticket B',
            'cliente' => 'Cliente B', 'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING, 'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);

        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!', 'superadmin');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin/audit/logs?entity_type=ticket&entity_id={$ticketA->id}")
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->isNotEmpty());
        $this->assertTrue($rows->every(fn (array $r) => $r['entity_id'] === (string) $ticketA->id));
    }

    public function test_filtro_por_usuario(): void
    {
        $managerId = (int) User::where('email', 'management@illuminations.test')->value('id');

        $this->actingForAudit('management@illuminations.test');
        MasterClient::create(['name' => '[SCRUM-792] Cliente por usuario']);

        $token = $this->loginAs('superadmin@illuminations.test', 'Password123!', 'superadmin');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/admin/audit/logs?user_id={$managerId}")
            ->assertStatus(200);

        $rows = collect($response->json('data'));
        $this->assertTrue($rows->isNotEmpty());
        $this->assertTrue($rows->every(fn (array $r) => $r['user']['id'] === $managerId));
    }
}
