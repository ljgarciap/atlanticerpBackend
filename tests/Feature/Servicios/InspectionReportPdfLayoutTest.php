<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\Ticket;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-891 (rebote 2026-09-16) — el PDF del Informe de Inspección mostraba una línea horizontal
 * sobrante justo antes del bloque de firmas (heredada del `border-top` de `.signature-box`, que
 * tiene sentido en la plantilla en blanco — ahí ES la línea de "firme aquí" — pero no en el
 * informe ya lleno, que muestra nombre real e imagen de firma cuando aplica) y muy poca
 * separación respecto a "Próximos pasos". Guardrail estructural — mismo patrón que
 * `OrderDocumentPdfLayoutTest` en Bodega: render directo del blade + assert sobre el CSS/HTML
 * resultante (PHPUnit no mide overlap visual real).
 */
class InspectionReportPdfLayoutTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function makeReport(): InspectionReport
    {
        $ticket = Ticket::create([
            'numero'                   => 'INS-2026-'.static::uniqueTestNumber(),
            'tipo'                     => Ticket::TIPO_INSTALLATION,
            'subtipo'                  => Ticket::SUBTIPO_INSPECTION,
            'tipo_instalacion'         => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion'              => 'Ticket de prueba — SCRUM-891',
            'cliente'                  => 'Cliente de prueba',
            'estado'                   => Ticket::ESTADO_ON_SITE,
            'quote_status'             => Ticket::QUOTE_STATUS_NOT_APPLICABLE,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);

        $report = InspectionReport::create([
            'numero'                => 'INF-2026-'.static::uniqueTestNumber(),
            'ticket_id'             => $ticket->id,
            'modo'                  => InspectionReport::MODO_FORMULARIO,
            'conclusion'            => 'Conclusión de prueba',
            'requiere_seguimiento'  => false,
            'firma_tecnico_nombre'  => 'Tecnico Servicios Test',
            'firma_cliente_nombre'  => 'Cliente de prueba',
            'estado'                => InspectionReport::ESTADO_COMPLETED,
        ]);

        return $report->fresh(['materials']);
    }

    public function test_pdf_del_informe_no_muestra_linea_horizontal_antes_de_firmas(): void
    {
        $report = $this->makeReport();

        $html = view('servicios.inspection-report-pdf', [
            'ticket'                    => $report->ticket,
            'report'                    => $report,
            'tipoLabel'                 => 'Instalación',
            'subtipoLabel'              => 'Inspección',
            'generatedAt'               => now(),
            'firmaClienteImagenDataUri' => null,
        ])->render();

        // Guardrail estructural: la línea "firme aquí" (border-top) no debe volver a este
        // documento — es correcta en la plantilla en blanco, no en el informe ya lleno.
        $this->assertStringNotContainsString('border-top: 1px solid #2a2520', $html);

        // El espaciado antes del bloque de firmas quedó en +2 líneas en blanco (~28px sobre los
        // 20px originales), no el valor viejo que Daniela reportó como insuficiente.
        $this->assertStringContainsString('.signatures { display: table; width: 100%; margin-top: 48px; }', $html);
    }
}
