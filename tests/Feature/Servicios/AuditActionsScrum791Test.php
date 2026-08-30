<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\ClaimSheet;
use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\Ticket;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Support\Facades\DB;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-791 (Epic SCRUM-788 — Logs y Telemetría). Cubre los 2 call sites de este batch que
 * ningún test existente ejercitaba por HTTP todavía (ClaimSheetController::pdf y
 * InspectionReportController::pdf) — el resto de las 11 acciones nuevas ya tenían cobertura real
 * vía tests preexistentes (TicketBatch3Test, ServiceQuoteBatch12Test, ExternalTechnicianBatch5Test,
 * OrderExportControllerTest, PurchaseOrderPdfTest, PurchaseOrderClaimControllerTest,
 * ReportControllerTest) — la suite completa pasando confirma que el wiring no rompió esos
 * endpoints; acá se agrega la aserción puntual sobre audit_logs que esos tests no hacían.
 */
class AuditActionsScrum791Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'servicio@illuminations.com.pa';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function makeTicket(array $overrides = []): Ticket
    {
        return Ticket::create([
            'numero'                   => 'REC-2026-'.static::uniqueTestNumber(),
            'tipo'                     => Ticket::TIPO_CLAIM,
            'tipo_instalacion'         => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion'              => 'Ticket de prueba — SCRUM-791',
            'cliente'                  => 'Cliente de prueba',
            'estado'                   => Ticket::ESTADO_ON_SITE,
            'quote_status'             => Ticket::QUOTE_STATUS_NOT_APPLICABLE,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
            ...$overrides,
        ]);
    }

    public function test_descargar_pdf_de_hoja_de_reclamo_logea_accion(): void
    {
        $ticket = $this->makeTicket();
        $sheet  = ClaimSheet::create([
            'ticket_id'             => $ticket->id,
            'descripcion_problema'  => 'Producto llegó dañado',
            'fecha_reclamo'         => now()->toDateString(),
            'diagnostico'           => 'Golpe en tránsito',
            'firma_responsable'     => 'Aaron Leis',
            'estado'                => ClaimSheet::ESTADO_COMPLETED,
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->get("/api/servicios/tickets/{$ticket->id}/claim-sheet/pdf")
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');

        $log = DB::table('illuminations_auth.audit_logs')
            ->where('action', 'servicios.claim_sheet.pdf_downloaded')
            ->where('entity_id', (string) $sheet->id)
            ->exists();

        $this->assertTrue($log, 'Se esperaba servicios.claim_sheet.pdf_downloaded en audit_logs.');
    }

    public function test_descargar_pdf_de_informe_de_inspeccion_logea_accion(): void
    {
        $ticket = $this->makeTicket(['tipo' => Ticket::TIPO_INSTALLATION, 'subtipo' => Ticket::SUBTIPO_INSPECTION]);
        $report = InspectionReport::create([
            'numero'                => 'INSP-2026-'.static::uniqueTestNumber(),
            'ticket_id'             => $ticket->id,
            'modo'                  => 'digital',
            'fecha_inspeccion'      => now()->toDateString(),
            'hora_inicio'           => '09:00',
            'hora_fin'              => '10:00',
            'findings'              => [],
            'firma_tecnico_nombre'  => 'Carlos Vergara',
            'firma_cliente_nombre'  => 'Cliente de prueba',
            'estado'                => InspectionReport::ESTADO_COMPLETED,
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->get("/api/servicios/tickets/{$ticket->id}/inspection-report/pdf")
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');

        $log = DB::table('illuminations_auth.audit_logs')
            ->where('action', 'servicios.inspection_report.pdf_downloaded')
            ->where('entity_id', (string) $report->id)
            ->exists();

        $this->assertTrue($log, 'Se esperaba servicios.inspection_report.pdf_downloaded en audit_logs.');
    }
}
