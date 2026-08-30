<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\ExternalTechnician;
use App\Modules\Servicios\Models\ServiceQuote;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\ServiceQuotePdfService;
use App\Modules\Servicios\Services\ServiciosSettingsService;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, resto del Batch 12 (REQ-235/236/237/250, SCRUM-298/299/300/313). Usuarios
 * reales de Servicios, mismo roster que `ServiceQuoteBatch11Test`.
 */
class ServiceQuoteBatch12Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'servicio@illuminations.com.pa'; // Aaron Leis

    private const TECNICO_SERVICIOS = 'carlos@illuminations.com.pa'; // Carlos Vergara

    private const OTRO_TECNICO = 'santopedro181994@gmail.com'; // Pedro Santos

    private const MANAGEMENT = 'mbekhar@illuminations.com.pa'; // Mark — Gerencia

    // SCRUM-300 (rebote QA 2026-08-13) — texto real del RN3 (ver docblock del constant en
    // ServiciosSettingsService), no el texto de ejemplo del mockup que se sembraba antes.
    private const DEFAULT_CONDITIONS =
        "Cotización válida por 10 días a partir de la fecha de emisión.\n"
        ."Forma de pago: 100% de pago completo antes de generar la instalación.\n"
        ."Garantía de mano de obra e instalación: 30 días desde la fecha de entrega.\n"
        ."Los productos suministrados mantienen la garantía de fábrica correspondiente (no la garantía del servicio).\n"
        ."Precios sujetos a cambio sin previo aviso una vez vencida la fecha de validez de la cotización.";

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    private function makeCurrentTenant(): void
    {
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    private function makeSalesProject(): SalesProject
    {
        $unique = uniqid();
        $master = MasterClient::create(['name' => "Cliente {$unique}"]);
        $sub    = SubClient::create(['master_client_id' => $master->id, 'business_name' => "Cliente {$unique} SA", 'tax_id' => $unique]);

        return SalesProject::create(['sub_client_id' => $sub->id, 'name' => "Proyecto {$unique}"]);
    }

    /** @param array<string, mixed> $overrides */
    private function makeTicket(array $overrides = []): Ticket
    {
        $this->makeCurrentTenant();
        $project = $this->makeSalesProject();

        $carlos = \App\Models\User::where('email', self::TECNICO_SERVICIOS)->first();

        return Ticket::create([
            'numero'                   => 'INS-2026-'.static::uniqueTestNumber(),
            'tipo'                     => Ticket::TIPO_WARRANTY,
            'subtipo'                  => Ticket::SUBTIPO_WARRANTY_GENERIC,
            'tipo_instalacion'         => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion'              => 'Ticket de prueba Batch 12',
            'sales_project_id'         => $project->id,
            'cliente'                  => 'Cliente de prueba',
            'contacto'                 => 'Contacto de prueba',
            'telefono'                 => '6000-0000',
            'direccion'                => 'Ciudad de Panamá',
            'estado'                   => Ticket::ESTADO_REPORTED,
            'quote_status'             => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_NOT_APPLICABLE,
            'internal_technician_id'   => $carlos?->id,
            ...$overrides,
        ]);
    }

    private function makeExternalTechnician(float $tarifaDia = 100): ExternalTechnician
    {
        $this->makeCurrentTenant();

        return ExternalTechnician::create([
            'nombre'         => 'Técnico Externo '.uniqid(),
            'empresa'        => 'Empresa X',
            'tarifa_dia'     => $tarifaDia,
            'tarifa_visible' => true,
            'estado'         => ExternalTechnician::ESTADO_ACTIVE,
        ]);
    }

    /** Genera + agrega 1 ítem de mano de obra (sin catálogo, sin costo) + envía. Devuelve la cotización ya Enviada. */
    private function generateAndSendQuote(Ticket $ticket, string $token): ServiceQuote
    {
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo'        => 'labor',
                'description' => 'Instalación',
                'quantity'    => 1,
                'unit_price'  => 100,
            ])
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/quote/enviar")
            ->assertStatus(200);

        $this->makeCurrentTenant();

        return ServiceQuote::where('ticket_id', $ticket->id)->latest('created_at')->firstOrFail();
    }

    // ── REQ-237 — Condiciones configurables por Gerencia ────────────────────────────────────

    public function test_condiciones_valor_semilla_exacto(): void
    {
        $token = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/settings')
            ->assertStatus(200);

        $entry = collect($response->json('data'))->firstWhere('key', 'condiciones_cotizacion_servicios');

        $this->assertNotNull($entry);
        $this->assertSame('text', $entry['type']);
        $this->assertSame(self::DEFAULT_CONDITIONS, $entry['value']);
    }

    public function test_management_puede_editar_condiciones(): void
    {
        $token = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/servicios/settings', ['condiciones_cotizacion_servicios' => 'Condiciones de prueba actualizadas'])
            ->assertStatus(200)
            ->assertJsonPath('data.*.value', fn (array $values) => in_array('Condiciones de prueba actualizadas', $values, true));

        // Un request HTTP previo limpia el search_path del tenant (mismo gotcha ya documentado en
        // ServiceQuoteBatch11Test) — hay que reactivarlo antes de cualquier lectura Eloquent
        // directa que siga a la llamada HTTP.
        $this->makeCurrentTenant();
        $this->assertSame(
            'Condiciones de prueba actualizadas',
            app(ServiciosSettingsService::class)->conditionsCotizacionServicios(),
        );
    }

    /**
     * SCRUM-780 (rebote de Daniela 2026-08-19) — reemplaza la decisión original de Batch 10
     * (lectura amplia con permission:servicios.read). Ajustes de Servicios son parámetros
     * administrativos que ningún perfil operativo debe poder leer, ni siquiera Aaron/Líder de
     * Servicios, mismo gate que ya tenía la escritura.
     */
    public function test_lider_servicios_no_puede_leer_ajustes(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/settings')
            ->assertStatus(403);
    }

    public function test_lider_servicios_no_puede_editar_condiciones(): void
    {
        // REQ-237 — "Edición exclusiva superadmin/management": ni siquiera Aaron/Líder de
        // Servicios, pese a poder casi todo lo demás del módulo.
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/servicios/settings', ['condiciones_cotizacion_servicios' => 'Intento no autorizado'])
            ->assertStatus(403);

        $this->makeCurrentTenant();
        $this->assertSame(self::DEFAULT_CONDITIONS, app(ServiciosSettingsService::class)->conditionsCotizacionServicios());
    }

    public function test_tecnico_no_puede_editar_condiciones(): void
    {
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/servicios/settings', ['condiciones_cotizacion_servicios' => 'Intento no autorizado'])
            ->assertStatus(403);
    }

    public function test_texto_de_condiciones_no_se_valida_como_numerico_pero_otras_keys_si(): void
    {
        $token = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');

        // El texto libre no debe disparar el "debe ser numérico" que sí sigue aplicando a las
        // demás keys del catálogo (regresión sobre el comportamiento pre-Batch 12).
        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/servicios/settings', ['condiciones_cotizacion_servicios' => 'Texto no numérico, con comas y todo'])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/servicios/settings', ['itbms_percent' => 'no-numerico'])
            ->assertStatus(422);
    }

    public function test_condiciones_se_congela_al_enviar_y_no_cambia_con_ediciones_posteriores(): void
    {
        $ticket        = $this->makeTicket();
        $tecnicoToken  = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');
        $managementToken = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');

        // Antes de generar/enviar, el preview siempre refleja el valor vigente.
        $preview = $this->withHeaders($this->authHeaders($tecnicoToken))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200)
            ->json('conditions_preview');
        $this->assertSame(self::DEFAULT_CONDITIONS, $preview);

        // Gerencia cambia las condiciones ANTES de enviar la cotización.
        $this->withHeaders($this->authHeaders($managementToken))
            ->patchJson('/api/servicios/settings', ['condiciones_cotizacion_servicios' => 'Condiciones vigentes al enviar'])
            ->assertStatus(200);

        $quote = $this->generateAndSendQuote($ticket, $tecnicoToken);
        $this->assertSame('Condiciones vigentes al enviar', $quote->conditions);

        // Gerencia cambia las condiciones DESPUÉS de enviar — la cotización ya enviada no debe
        // cambiar (nunca es una referencia viva).
        $this->withHeaders($this->authHeaders($managementToken))
            ->patchJson('/api/servicios/settings', ['condiciones_cotizacion_servicios' => 'Condiciones cambiadas después del envío'])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($tecnicoToken))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);

        $response->assertJsonPath('quote.conditions', 'Condiciones vigentes al enviar');
        $response->assertJsonPath('conditions_preview', 'Condiciones cambiadas después del envío');
    }

    public function test_condiciones_null_mientras_esta_en_borrador(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(201);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);

        $response->assertJsonPath('quote.conditions', null);
    }

    // ── REQ-235 — Documento formal ──────────────────────────────────────────────────────────

    public function test_documento_pdf_se_genera_para_cotizacion_enviada(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');
        $quote  = $this->generateAndSendQuote($ticket, $token);

        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/servicios/tickets/{$ticket->id}/quote/{$quote->id}/document")
            ->assertStatus(200);

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_documento_pdf_disponible_incluso_en_borrador(): void
    {
        // "Ver/Imprimir" no está restringido a un estado — REQ-236 abre cualquier versión del
        // historial (incluida una que siga en Borrador) en modo solo lectura via este mismo endpoint.
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(201);

        // SCRUM-292 — un borrador sin ningún guardado real todavía no tiene número, y el
        // documento formal se trata como "no existe" hasta ese punto (ver
        // ServiceQuoteController::document()). Un guardado real (Guardar) ya cuenta.
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/quote", ['observations' => 'Nota'])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $quote = ServiceQuote::where('ticket_id', $ticket->id)->firstOrFail();

        $this->withHeaders($this->authHeaders($token))
            ->get("/api/servicios/tickets/{$ticket->id}/quote/{$quote->id}/document")
            ->assertStatus(200);
    }

    /**
     * SCRUM-292 (rebote QA 2026-08-13) — un borrador sin ningún guardado real (numero todavía
     * null) no tiene nada real que imprimir; el documento se trata como "no existe" (404), igual
     * que el historial lo excluye por completo.
     */
    public function test_documento_404_para_borrador_sin_ningun_guardado_real(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(201);

        $this->makeCurrentTenant();
        $quote = ServiceQuote::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertNull($quote->numero);

        $this->withHeaders($this->authHeaders($token))
            ->get("/api/servicios/tickets/{$ticket->id}/quote/{$quote->id}/document")
            ->assertStatus(404);
    }

    public function test_documento_404_si_la_cotizacion_no_pertenece_al_ticket(): void
    {
        $ticketA = $this->makeTicket();
        $ticketB = $this->makeTicket();
        $token   = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $quoteB = $this->generateAndSendQuote($ticketB, $token);

        $this->withHeaders($this->authHeaders($token))
            ->get("/api/servicios/tickets/{$ticketA->id}/quote/{$quoteB->id}/document")
            ->assertStatus(404);
    }

    public function test_documento_404_si_ticket_no_existe(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->get('/api/servicios/tickets/999999/quote/1/document')
            ->assertStatus(404);
    }

    public function test_pdf_view_data_nunca_expone_costo_ni_margen_de_ningun_item(): void
    {
        // Regla dura de la spec, "sin excepción de permiso" — se verifica sobre el array real que
        // se le pasa a dompdf (ver docblock de `ServiceQuotePdfService::viewData()`), en vez de
        // intentar parsear bytes de un PDF ya comprimido.
        $ticket     = $this->makeTicket();
        $technician = $this->makeExternalTechnician(tarifaDia: 250);
        $token      = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo'                    => 'subcontracted',
                'external_technician_id'  => $technician->id,
                'quantity'                => 2,
                'margin_percent'          => 40,
            ])
            ->assertStatus(201);

        $this->makeCurrentTenant();
        $quote = ServiceQuote::where('ticket_id', $ticket->id)->with('items')->firstOrFail();

        $this->assertCount(1, $quote->items);
        $this->assertSame(250.0, (float) $quote->items->first()->cost_reference);
        $this->assertSame(40.0, (float) $quote->items->first()->margin_percent);

        $ticketService  = app(\App\Modules\Servicios\Services\TicketService::class);
        $pdfService     = app(ServiceQuotePdfService::class);
        $viewData       = $pdfService->viewData($quote, $ticket, $ticketService->detail($ticket));

        $this->assertNotEmpty($viewData['items']);
        foreach ($viewData['items'] as $item) {
            $this->assertArrayNotHasKey('cost_reference', $item);
            $this->assertArrayNotHasKey('margin_percent', $item);
            $this->assertSame(['description', 'quantity', 'unit_price', 'subtotal'], array_keys($item));
        }
    }

    /**
     * SCRUM-298 (rebote QA 2026-08-13) — 3 hallazgos en el documento que recibe el cliente:
     * faltaba la sección "Información de pago" (RN1), el pie de página tenía "Designe" mal
     * escrito, y la fila de Descuento mostraba siempre "—" sin importar el % real. Se renderiza
     * el HTML real (mismo que recibe dompdf) en vez de parsear bytes de un PDF ya comprimido.
     */
    public function test_documento_incluye_informacion_de_pago_footer_correcto_y_monto_de_descuento(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(201);

        $this->makeCurrentTenant();
        $quote = ServiceQuote::where('ticket_id', $ticket->id)->firstOrFail();
        $quote->update(['subtotal' => 1000, 'discount_percent' => 10]);

        $ticketService = app(\App\Modules\Servicios\Services\TicketService::class);
        $pdfService    = app(ServiceQuotePdfService::class);
        $html          = view('servicios.service-quote-pdf', $pdfService->viewData($quote, $ticket, $ticketService->detail($ticket)))->render();

        $this->assertStringContainsString('Información de pago', $html);
        $this->assertStringContainsString('Cheque a nombre de', $html);
        $this->assertStringNotContainsString('Designe', $html);
        $this->assertStringContainsString('Illuminations · Panamá', $html);
        $this->assertStringContainsString('-$100.00', $html);
    }

    /**
     * SCRUM-781 (punto 9, REQ-235) — el encabezado mostraba "ILLUMINATIONS" como texto plano sin
     * logo. El primer intento reusó el SVG inline de ventas-diseno/quote-pdf.blade.php, pero
     * dompdf en este entorno no renderiza `<svg>` inline en absoluto (verificado con un render
     * real) — mecanismo real: `<img src="data:...">` vía App\Shared\Support\PdfLogo.
     */
    public function test_documento_muestra_el_logo_no_solo_texto_plano(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(201);

        $this->makeCurrentTenant();
        $quote = ServiceQuote::where('ticket_id', $ticket->id)->firstOrFail();

        $ticketService = app(\App\Modules\Servicios\Services\TicketService::class);
        $pdfService    = app(ServiceQuotePdfService::class);
        $html          = view('servicios.service-quote-pdf', $pdfService->viewData($quote, $ticket, $ticketService->detail($ticket)))->render();

        $this->assertStringContainsString('<img src="data:image/png;base64,', $html);
        $this->assertStringContainsString('ILLUMINATIONS', $html);
    }

    public function test_pdf_firma_simulada_solo_si_aprobada(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');
        $quote  = $this->generateAndSendQuote($ticket, $token);

        $ticketService = app(\App\Modules\Servicios\Services\TicketService::class);
        $pdfService    = app(ServiceQuotePdfService::class);

        $sentViewData = $pdfService->viewData($quote, $ticket, $ticketService->detail($ticket));
        $this->assertFalse($sentViewData['firmada']);

        $liderToken = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($liderToken))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/quote/decidir", ['estado' => 'approved'])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $quote->refresh();

        $approvedViewData = $pdfService->viewData($quote, $ticket, $ticketService->detail($ticket));
        $this->assertTrue($approvedViewData['firmada']);
    }

    // ── REQ-236 — Historial de cotizaciones del ticket ──────────────────────────────────────

    public function test_historial_de_ticket_devuelve_todas_las_versiones_mas_reciente_primero(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $quote1 = $this->generateAndSendQuote($ticket, $token);
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/quote/decidir", ['estado' => 'rejected'])
            ->assertStatus(200);

        // REQ-234 — solo se puede generar una nueva si la más reciente está Rechazada.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(201);

        // SCRUM-292 — un borrador sin ningún guardado real (numero todavía null) no aparece en el
        // historial (ver ServiceQuoteService::historyForTicket()); un ítem real ya cuenta.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'labor', 'description' => 'Mantenimiento', 'quantity' => 1, 'unit_price' => 50,
            ])->assertStatus(201);

        $this->makeCurrentTenant();
        $quote2 = ServiceQuote::where('ticket_id', $ticket->id)->where('id', '!=', $quote1->id)->firstOrFail();
        $this->assertNotNull($quote2->numero);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quotes")
            ->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$quote2->id, $quote1->id], $ids);
        $response->assertJsonPath('data.0.estado', ServiceQuote::ESTADO_DRAFT);
        $response->assertJsonPath('data.1.estado', ServiceQuote::ESTADO_REJECTED);
    }

    public function test_historial_de_ticket_vacio_si_no_hay_cotizaciones(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quotes")
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    public function test_historial_de_ticket_404_si_ticket_no_existe(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/tickets/999999/quotes')
            ->assertStatus(404);
    }

    public function test_editar_la_cotizacion_actual_no_afecta_versiones_anteriores(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $quote1 = $this->generateAndSendQuote($ticket, $token);
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/quote/decidir", ['estado' => 'rejected'])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(201);

        $this->makeCurrentTenant();
        $originalQuote1Observations = $quote1->fresh()->observations;
        $originalQuote1DiscountPct  = $quote1->fresh()->discount_percent;
        $originalQuote1ItemsCount   = $quote1->fresh()->items()->count();

        // Cualquier endpoint de escritura (guardar/ítems) SIEMPRE resuelve `current()` —
        // estructuralmente no hay forma de direccionar una versión vieja por id vía estas rutas
        // (ver `ServiceQuoteController`), así que esta escritura solo puede afectar la nueva
        // (quote2), nunca quote1.
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/quote", [
                'discount_percent' => 15,
                'observations'     => 'Observación editada en la versión actual',
            ])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo'        => 'labor',
                'description' => 'Ítem agregado a la versión actual',
                'quantity'    => 1,
                'unit_price'  => 50,
            ])
            ->assertStatus(201);

        $this->makeCurrentTenant();
        $quote1->refresh();
        $this->assertSame($originalQuote1Observations, $quote1->observations);
        $this->assertEquals($originalQuote1DiscountPct, $quote1->discount_percent);
        $this->assertSame($originalQuote1ItemsCount, $quote1->items()->count());
        $this->assertSame(ServiceQuote::ESTADO_REJECTED, $quote1->estado);
    }

    // ── REQ-250 — Historial global de cotizaciones ──────────────────────────────────────────

    public function test_historial_global_incluye_todas_las_versiones_sin_filtrar_por_tecnico(): void
    {
        $ticketCarlos = $this->makeTicket(); // asignado a Carlos por defecto (ver makeTicket())
        $tokenCarlos  = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');
        $this->generateAndSendQuote($ticketCarlos, $tokenCarlos);

        // Pedro (otro técnico interno, NO asignado a ningún ticket de arriba) consulta el
        // historial global — RN de REQ-250: visibilidad transversal, nunca filtrada por técnico.
        $tokenPedro = $this->loginAs(self::OTRO_TECNICO, self::OTRO_TECNICO, 'tecnico_servicios');

        $response = $this->withHeaders($this->authHeaders($tokenPedro))
            ->getJson('/api/servicios/quotes')
            ->assertStatus(200);

        $this->assertGreaterThanOrEqual(1, count($response->json('data')));
        $this->assertContains($ticketCarlos->id, collect($response->json('data'))->pluck('ticket.id')->all());
    }

    public function test_historial_global_filtra_por_estado_pero_los_conteos_reflejan_el_total(): void
    {
        $ticket1 = $this->makeTicket();
        $ticket2 = $this->makeTicket();
        $token   = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        // ticket1: Enviada. ticket2: Enviada -> Rechazada.
        $this->generateAndSendQuote($ticket1, $token);
        $this->generateAndSendQuote($ticket2, $token);
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket2->id}/quote/decidir", ['estado' => 'rejected'])
            ->assertStatus(200);

        $allResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/quotes')
            ->assertStatus(200);
        $totalAll = $allResponse->json('counts.all');

        $rejectedResponse = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/quotes?estado=rejected')
            ->assertStatus(200);

        foreach ($rejectedResponse->json('data') as $row) {
            $this->assertSame('rejected', $row['estado']);
        }
        $this->assertGreaterThanOrEqual(1, $rejectedResponse->json('counts.rejected'));
        // Los conteos NUNCA se filtran por el `estado` de la query — mismo total que sin filtro.
        $this->assertSame($totalAll, $rejectedResponse->json('counts.all'));
    }

    public function test_historial_global_estado_invalido_retorna_422(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/quotes?estado=no-existe')
            ->assertStatus(422);
    }
}
