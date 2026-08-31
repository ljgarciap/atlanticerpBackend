<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Models\User;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-789 (Epic SCRUM-788 — Logs y Telemetría): LogsAuditTrail aplicado a los modelos de
 * escritura de Servicios. No repite el trait entero (ya cubierto genéricamente por su uso en
 * VentasDiseno/Compras) — verifica lo específico de esta instrumentación: el `action` logeado
 * usa el prefijo `servicios.` (antes hardcodeado a `ventas_diseno.` en el trait, ver docblock de
 * App\Shared\Support\LogsAuditTrail), y que created/updated/deleted quedan enganchados sin tocar
 * ningún controller.
 */
class LogsAuditTrailTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** Mismo patrón que TestDataCleanupServiceTest::actingForAudit() — sin esto, resolveUserId() no tiene JWT que leer fuera de un endpoint HTTP real. */
    private function actingForAudit(): void
    {
        $user = User::where('email', 'liderservicios@test.com')->firstOrFail();
        $request = Request::create('/');
        $request->attributes->set('jwt', ['sub' => $user->id]);
        $this->app->instance('request', $request);
    }

    private function makeSalesProject(): SalesProject
    {
        $unique = uniqid();
        $master = MasterClient::create(['name' => "Cliente {$unique}"]);
        $sub = SubClient::create(['master_client_id' => $master->id, 'business_name' => "Cliente {$unique} SA", 'tax_id' => $unique]);

        return SalesProject::create(['sub_client_id' => $sub->id, 'name' => "Proyecto {$unique}"]);
    }

    public function test_crear_ticket_logea_con_prefijo_servicios(): void
    {
        $this->actingForAudit();
        $project = $this->makeSalesProject();

        $this->actingForAudit();
        $ticket = Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(),
            'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion' => 'Ticket de prueba — audit trail',
            'cliente' => 'Cliente de prueba',
            'sales_project_id' => $project->id,
            'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'servicios.ticket.created')
            ->where('entity_id', (string) $ticket->id)
            ->first();

        $this->assertNotNull($log, 'Se esperaba una entrada servicios.ticket.created en audit_logs.');
        $this->assertSame('ticket', $log->entity_type);
    }

    public function test_actualizar_ticket_logea_diff_sin_timestamps(): void
    {
        $this->actingForAudit();
        $project = $this->makeSalesProject();

        $this->actingForAudit();
        $ticket = Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(),
            'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion' => 'Ticket de prueba — audit trail',
            'cliente' => 'Cliente de prueba',
            'sales_project_id' => $project->id,
            'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);

        $this->actingForAudit();
        $ticket->update(['observaciones' => 'Nota agregada tras inspección']);

        $log = DB::table('atlantic_auth.audit_logs')
            ->where('action', 'servicios.ticket.updated')
            ->where('entity_id', (string) $ticket->id)
            ->first();

        $this->assertNotNull($log, 'Se esperaba una entrada servicios.ticket.updated en audit_logs.');
        $newValues = json_decode($log->new_values, true);
        $this->assertArrayHasKey('observaciones', $newValues);
        $this->assertArrayNotHasKey('updated_at', $newValues);
    }
}
