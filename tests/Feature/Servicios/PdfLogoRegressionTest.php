<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\ClaimSheet;
use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\InspectionReportService;
use App\Modules\Servicios\Services\TicketService;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use App\Shared\Support\PdfLogo;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-781 (punto 5/8) — 3 PDFs de Tickets mostraban "ILLUMINATIONS" como texto plano en vez del
 * logo. El primer intento reusó el SVG inline de `ventas-diseno/quote-pdf.blade.php`, pero dompdf
 * en este entorno NO renderiza `<svg>` inline en absoluto (verificado con un render real: un SVG
 * mínimo aislado produce una página en blanco — ese patrón nunca funcionó de verdad, solo estaba
 * en el código sin verificación visual). Mecanismo real: `<img src="data:...">` vía `PdfLogo`
 * (mismo patrón ya probado en este proyecto para `firma_cliente_imagen`).
 */
class PdfLogoRegressionTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function makeTicket(array $overrides = []): Ticket
    {
        $master  = MasterClient::create(['name' => 'Cliente PDF']);
        $sub     = SubClient::create(['master_client_id' => $master->id, 'business_name' => 'Cliente PDF SA', 'tax_id' => uniqid()]);
        $project = SalesProject::create(['sub_client_id' => $sub->id, 'name' => 'Proyecto PDF']);

        return Ticket::create([
            'numero'                   => 'INS-2026-'.static::uniqueTestNumber(),
            'tipo'                     => Ticket::TIPO_INSTALLATION,
            'subtipo'                  => Ticket::SUBTIPO_INSPECTION,
            'tipo_instalacion'         => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion'              => 'Ticket de prueba PDF',
            'sales_project_id'         => $project->id,
            'cliente'                  => 'Cliente PDF',
            'estado'                   => Ticket::ESTADO_REPORTED,
            'quote_status'             => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
            ...$overrides,
        ]);
    }

    public function test_informe_de_inspeccion_en_blanco_muestra_el_logo(): void
    {
        $ticket = $this->makeTicket();

        $html = view('servicios.inspection-report-pdf-blank', [
            'ticket'       => $ticket,
            'fields'       => app(InspectionReportService::class)->fieldsForTicket($ticket),
            'tipoLabel'    => 'Instalación',
            'subtipoLabel' => 'Inspección',
            'generatedAt'  => now(),
            'logoDataUri'  => PdfLogo::dataUri(),
        ])->render();

        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
        $this->assertStringContainsString('ILLUMINATIONS', $html);
    }

    public function test_informe_de_inspeccion_completado_muestra_el_logo(): void
    {
        $ticket = $this->makeTicket(['inspection_report_status' => Ticket::INSPECTION_STATUS_COMPLETED]);
        $report = InspectionReport::create([
            'numero'    => 'INF-2026-'.static::uniqueTestNumber(),
            'ticket_id' => $ticket->id,
            'estado'    => InspectionReport::ESTADO_COMPLETED,
        ]);

        $html = view('servicios.inspection-report-pdf', [
            'ticket'                    => $ticket,
            'report'                    => $report,
            'tipoLabel'                 => 'Instalación',
            'subtipoLabel'              => 'Inspección',
            'generatedAt'               => now(),
            'firmaClienteImagenDataUri' => null,
            'logoDataUri'               => PdfLogo::dataUri(),
        ])->render();

        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
        $this->assertStringContainsString('ILLUMINATIONS', $html);
    }

    public function test_hoja_de_reclamo_muestra_el_logo(): void
    {
        $ticket = $this->makeTicket(['tipo' => Ticket::TIPO_CLAIM, 'subtipo' => null]);
        $sheet  = ClaimSheet::create([
            'ticket_id' => $ticket->id,
            'estado'    => ClaimSheet::ESTADO_COMPLETED,
        ]);

        $ticketService = app(TicketService::class);

        $html = view('servicios.claim-sheet-pdf', [
            'ticket'           => $ticket,
            'sheet'            => $sheet,
            'detail'           => $ticketService->detail($ticket),
            'diagnosticoLabel' => 'Pendiente de evaluación',
            'generatedAt'      => now(),
            'logoDataUri'      => PdfLogo::dataUri(),
        ])->render();

        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
        $this->assertStringContainsString('ILLUMINATIONS', $html);
    }

    /**
     * SCRUM-781 (rebote de Daniela/QA 2026-08-24) — este PDF (botón "Ver/Imprimir" del detalle del
     * ticket, REQ-228) es distinto de los 3 de arriba y quedó fuera del fix original: QA descargó
     * justo este ("Ticket-{numero}.pdf") y siguió viendo texto plano.
     */
    public function test_ticket_pdf_generico_muestra_el_logo(): void
    {
        $ticket        = $this->makeTicket();
        $ticketService = app(TicketService::class);

        $html = view('servicios.ticket-pdf', [
            'ticket'        => $ticket,
            'tipoLabel'     => 'Instalación',
            'subtipoLabel'  => 'Inspección',
            'detail'        => $ticketService->detail($ticket),
            'horario'       => 'Sin agendar',
            'fechaServicio' => 'Sin agendar',
            'generatedAt'   => now(),
            'logoDataUri'   => PdfLogo::dataUri(),
        ])->render();

        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
        $this->assertStringContainsString('ILLUMINATIONS', $html);
    }

    public function test_pdf_logo_data_uri_lee_el_archivo_real(): void
    {
        $uri = PdfLogo::dataUri();

        $this->assertStringStartsWith('data:image/png;base64,', $uri);
        $this->assertGreaterThan(1000, strlen($uri));
    }
}
