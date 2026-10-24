<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\Ticket;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-924 (rebote reportado por Gerencia, ticket real INS-2026-0003 en test.atlanticerp.ai) — "No se
 * pudo generar el PDF" al ver/imprimir el ticket. Causa raíz confirmada en vivo contra
 * test.atlanticerp.ai (GAR-2026-0003, que sí tenía un adjunto en el momento del diagnóstico):
 * `ticket-pdf.blade.php` hacía `implode(', ', $detail['adjuntos'])`, pero `$detail['adjuntos']`
 * es un array de objetos (id/nombre_archivo/size_bytes/mime_type/created_at, ver
 * `TicketService::detail()`), nunca una lista plana de strings — el manejador de errores de
 * Laravel convierte el "Array to string conversion" resultante en una `ViewException` real, no
 * solo un warning. Afecta a CUALQUIER ticket con al menos un adjunto, no solo a INS-2026-0003
 * (que ya no reproduce porque su adjunto de prueba fue removido después de grabar el video del
 * reporte). Este test reproduce la condición real (ticket con 1 adjunto) contra el endpoint del
 * PDF para blindar contra que el bug vuelva.
 */
class TicketPdfAttachmentsTest extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        Storage::fake('s3-private');
    }

    private function makeSalesProject(): SalesProject
    {
        $unique = uniqid();
        $master = MasterClient::create(['name' => "Cliente {$unique}"]);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => "Cliente {$unique} SA", 'tax_id' => $unique]);

        return SalesProject::create(['sub_client_id' => $sub->id, 'name' => "Proyecto {$unique}"]);
    }

    public function test_pdf_del_ticket_se_genera_correctamente_cuando_tiene_adjuntos(): void
    {
        $salesProjectId = $this->makeSalesProject()->id;
        $token          = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $headers        = $this->authHeaders($token);

        $ticketId = (int) $this->withHeaders($headers)
            ->postJson('/api/servicios/tickets', [
                'tipo'             => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion'      => 'Breve descripción',
                'sales_project_id' => $salesProjectId,
            ])
            ->assertStatus(201)
            ->json('id');

        $this->withHeaders($headers)
            ->postJson("/api/servicios/tickets/{$ticketId}/attachments", [
                'file' => UploadedFile::fake()->create('foto-referencia.jpg', 200, 'image/jpeg'),
            ])
            ->assertStatus(201);

        // Antes del fix: 500 (ViewException "Array to string conversion") en vez del PDF.
        $this->withHeaders($headers)
            ->get("/api/servicios/tickets/{$ticketId}/pdf")
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_pdf_del_ticket_sin_adjuntos_sigue_funcionando(): void
    {
        $salesProjectId = $this->makeSalesProject()->id;
        $token          = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $headers        = $this->authHeaders($token);

        $ticketId = (int) $this->withHeaders($headers)
            ->postJson('/api/servicios/tickets', [
                'tipo'             => Ticket::TIPO_CLAIM,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                'descripcion'      => 'Breve descripción',
                'sales_project_id' => $salesProjectId,
            ])
            ->assertStatus(201)
            ->json('id');

        $this->withHeaders($headers)
            ->get("/api/servicios/tickets/{$ticketId}/pdf")
            ->assertStatus(200)
            ->assertHeader('content-type', 'application/pdf');
    }
}
