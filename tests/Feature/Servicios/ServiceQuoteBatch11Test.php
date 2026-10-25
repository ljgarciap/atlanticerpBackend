<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Models\User;
use App\Modules\Servicios\Models\ExternalTechnician;
use App\Modules\Servicios\Models\ServiceQuote;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\ExternalTechnicianService;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Modules\VentasDiseno\Models\MasterClient;
use App\Modules\VentasDiseno\Models\SalesProject;
use App\Modules\VentasDiseno\Models\SubClient;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 11 (REQ-229→234, SCRUM-292→297). Usuarios reales de Servicios, mismo
 * roster que el resto de la fase (ver TicketFlowTest/ExternalTechnicianBatch5Test).
 */
class ServiceQuoteBatch11Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com'; // Lider Servicios Leis

    private const TECNICO_SERVICIOS = 'tecnicoservicios@test.com'; // Tecnico Servicios Test

    private const OTRO_TECNICO = 'tecnicoservicios2@test.com'; // Tecnico Servicios Test 2

    private const MANAGEMENT = 'gerencia3@test.com'; // Gerencia — desglose de costos

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    private function makeCurrentTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

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
        $project = $this->makeSalesProject();

        $carlos = User::where('email', self::TECNICO_SERVICIOS)->first();

        return Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(),
            'tipo' => Ticket::TIPO_WARRANTY,
            'subtipo' => Ticket::SUBTIPO_WARRANTY_GENERIC,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion' => 'Ticket de prueba Batch 11',
            'sales_project_id' => $project->id,
            'cliente' => 'Cliente de prueba',
            'contacto' => 'Contacto de prueba',
            'telefono' => '6000-0000',
            'direccion' => 'Ciudad de Panamá',
            'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_NOT_APPLICABLE,
            'internal_technician_id' => $carlos?->id,
            ...$overrides,
        ]);
    }

    private function makeCatalogProduct(float $priceFull, float $cost): CatalogProduct
    {
        // Un request HTTP previo (withHeaders()->postJson()) limpia el search_path del tenant —
        // mismo gotcha ya documentado en ExternalTechnicianBatch5Test — hay que reactivarlo antes
        // de cualquier escritura Eloquent directa que siga a una llamada HTTP.
        $this->makeCurrentTenant();

        $unique = uniqid();

        return CatalogProduct::create([
            'reference' => "REF-{$unique}",
            'name' => "Producto {$unique}",
            'price_full' => $priceFull,
            'cost' => $cost,
            'is_active' => true,
        ]);
    }

    private function makeExternalTechnician(float $tarifaDia = 100, string $estado = ExternalTechnician::ESTADO_ACTIVE): ExternalTechnician
    {
        $this->makeCurrentTenant();

        return ExternalTechnician::create([
            'nombre' => 'Técnico Externo '.uniqid(),
            'empresa' => 'Empresa X',
            'tarifa_dia' => $tarifaDia,
            'tarifa_visible' => true,
            'estado' => $estado,
        ]);
    }

    // ── REQ-229 — generar cotización (gate + folio + permisos) ──────────────────────────────

    public function test_gate_bloquea_generar_si_informe_pendiente(): void
    {
        $ticket = $this->makeTicket(['inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(422);
    }

    /**
     * SCRUM-292 (rebote QA 2026-08-13) — "Generar cotización" (solo abrir el formulario) NO
     * consume folio; el número se asigna recién en el primer guardado real (ver
     * ServiceQuoteService::ensureFolioAssigned()), para no dejar borradores huérfanos de $0 con
     * número ya consumido si el usuario cierra sin tocar nada.
     */
    public function test_generar_cotizacion_precarga_datos_cliente_sin_consumir_folio(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(201);

        $response->assertJsonPath('quote.estado', ServiceQuote::ESTADO_DRAFT);
        $response->assertJsonPath('quote.cliente', 'Cliente de prueba');
        $response->assertJsonPath('quote.telefono', '6000-0000');
        $response->assertJsonPath('quote.numero', null);

        // Primer guardado real (Guardar) -> recién ahí se asigna el folio.
        $saved = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/quote", ['observations' => 'Nota de prueba'])
            ->assertStatus(200);

        $numero = $saved->json('quote.numero');
        $this->assertMatchesRegularExpression('/^COT-SERV-'.date('Y').'-\d{4}$/', $numero);
    }

    public function test_tecnico_no_asignado_no_puede_generar(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::OTRO_TECNICO, self::OTRO_TECNICO, 'tecnico');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(403);
    }

    public function test_tecnico_asignado_si_puede_generar(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(201);
    }

    // ── REQ-230 — ítem Producto ──────────────────────────────────────────────────────────────

    public function test_item_producto_bloqueo_duro_por_margen(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        // margen = (100-90)/100 = 10%, por debajo del mínimo (30% default)
        $product = $this->makeCatalogProduct(priceFull: 100, cost: 90);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'product',
                'catalog_product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => 100,
            ])
            ->assertStatus(422)
            ->assertJsonPath('message', fn (string $m) => str_contains($m, $product->name) && str_contains($m, '30'));
    }

    public function test_item_producto_margen_suficiente_se_guarda(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        // margen = (100-50)/100 = 50%, por encima del mínimo
        $product = $this->makeCatalogProduct(priceFull: 100, cost: 50);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'product',
                'catalog_product_id' => $product->id,
                'quantity' => 2,
                'unit_price' => 100,
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);

        $this->assertEquals(200.0, $detail->json('quote.subtotal'));
    }

    public function test_item_producto_duplicado_es_rechazado(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $product = $this->makeCatalogProduct(priceFull: 100, cost: 50);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'product', 'catalog_product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100,
            ])->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'product', 'catalog_product_id' => $product->id, 'quantity' => 1, 'unit_price' => 100,
            ])->assertStatus(422);
    }

    public function test_item_producto_otro_referencia_libre_sin_validar_margen(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'product',
                'is_custom' => true,
                'description' => 'Producto sin catálogo',
                'quantity' => 1,
                'unit_price' => 5, // "costo" implícito sería mayor, pero sin catalog no hay margen que validar
            ])
            ->assertStatus(201);
    }

    // ── REQ-231 — ítem Mano de obra ──────────────────────────────────────────────────────────

    public function test_item_mano_de_obra_sin_validacion_de_margen(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'labor', 'description' => 'Instalación eléctrica', 'quantity' => 4, 'unit_price' => 15,
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);

        $this->assertEquals(60.0, $detail->json('quote.subtotal'));
    }

    // ── REQ-232 — ítem Subcontratado ─────────────────────────────────────────────────────────

    public function test_item_subcontratado_solo_tecnicos_activos(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $inactivo = $this->makeExternalTechnician(estado: ExternalTechnician::ESTADO_INACTIVE);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'subcontracted', 'external_technician_id' => $inactivo->id, 'quantity' => 2,
            ])
            ->assertStatus(422);
    }

    public function test_item_subcontratado_calculo_con_margen_default(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $technician = $this->makeExternalTechnician(tarifaDia: 100);

        // Sin margin_percent explícito -> usa el default parametrizado (30%, PricingSettings).
        // unit_price = 100 * 1.30 = 130; subtotal = 130 * 2 días = 260.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'subcontracted', 'external_technician_id' => $technician->id, 'quantity' => 2,
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);

        $this->assertEquals(260.0, $detail->json('quote.subtotal'));
        $this->assertStringContainsString($technician->nombre, $detail->json('quote.items.0.description'));
    }

    public function test_item_subcontratado_margen_editable_por_linea(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $technician = $this->makeExternalTechnician(tarifaDia: 100);

        // margen 50% -> unit_price = 150; subtotal = 150 * 1 = 150.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'subcontracted', 'external_technician_id' => $technician->id, 'quantity' => 1, 'margin_percent' => 50,
            ])
            ->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);

        $this->assertEquals(150.0, $detail->json('quote.subtotal'));
    }

    /**
     * SCRUM-781 (punto 3, REQ-232) — costo/día editable por ítem, distinto del costo maestro del
     * técnico. Override aplica solo a esta cotización, nunca toca $technician->tarifa_dia.
     */
    public function test_item_subcontratado_costo_editable_por_linea_no_afecta_maestro(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $technician = $this->makeExternalTechnician(tarifaDia: 100);

        // Costo override 80 (no la tarifa real 100) + margen 30% -> unit_price = 80*1.30 = 104;
        // subtotal = 104 * 1 = 104.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'subcontracted', 'external_technician_id' => $technician->id, 'quantity' => 1,
                'margin_percent' => 30, 'cost_reference' => 80,
            ])->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);
        $this->assertEquals(80.0, $detail->json('quote.items.0.cost_reference'));
        $this->assertEquals(104.0, $detail->json('quote.items.0.subtotal'));

        $this->makeCurrentTenant();
        $this->assertSame('100.00', $technician->refresh()->tarifa_dia);
    }

    /** Sin cost_reference explícito, sigue usando la tarifa real del técnico (comportamiento ya existente). */
    public function test_item_subcontratado_sin_costo_explicito_usa_tarifa_del_tecnico(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $technician = $this->makeExternalTechnician(tarifaDia: 100);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'subcontracted', 'external_technician_id' => $technician->id, 'quantity' => 1, 'margin_percent' => 30,
            ])->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);
        $this->assertEquals(100.0, $detail->json('quote.items.0.cost_reference'));
    }

    /** Mismo criterio ya establecido para margin_percent (ver test de abajo, "preserva el valor guardado"). */
    public function test_editar_item_subcontratado_sin_cost_reference_preserva_el_override_guardado(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $technician = $this->makeExternalTechnician(tarifaDia: 100);
        $item = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'subcontracted', 'external_technician_id' => $technician->id, 'quantity' => 1,
                'margin_percent' => 30, 'cost_reference' => 70,
            ])->assertStatus(201)->json('id');

        // Solo cambia quantity — cost_reference ausente no debe resetear el override a tarifa_dia (100).
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/quote/items/{$item}", [
                'tipo' => 'subcontracted', 'external_technician_id' => $technician->id, 'quantity' => 3,
            ])->assertStatus(200);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);
        $this->assertEquals(70.0, $detail->json('quote.items.0.cost_reference'));
    }

    public function test_desglose_de_costo_visible_solo_para_permiso_especial(): void
    {
        $ticket = $this->makeTicket();
        $aaronToken = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($aaronToken))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $technician = $this->makeExternalTechnician(tarifaDia: 100);
        $this->withHeaders($this->authHeaders($aaronToken))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'subcontracted', 'external_technician_id' => $technician->id, 'quantity' => 1, 'margin_percent' => 50,
            ])->assertStatus(201);

        // Lider Servicios tiene el grant especial (SpecialPermissionSeeder) -> ve cost_reference Y margin_percent.
        $aaronDetail = $this->withHeaders($this->authHeaders($aaronToken))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);
        $this->assertArrayHasKey('cost_reference', $aaronDetail->json('quote.items.0'));
        $this->assertEquals(50.0, $aaronDetail->json('quote.items.0.margin_percent'));

        // El técnico interno asignado NO tiene el grant -> no ve cost_reference NI margin_percent.
        // Regresión: `unit_price` SIEMPRE queda visible (precio final) — si `margin_percent`
        // quedara expuesto igual, cualquiera podría recalcular `cost_reference` (tarifa_dia) como
        // unit_price / (1 + margin/100), sorteando el mismo gate que oculta `cost_reference` dos
        // líneas más abajo. Ver ServiceQuoteController::formatItem().
        $tecnicoToken = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');
        $tecnicoDetail = $this->withHeaders($this->authHeaders($tecnicoToken))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);
        $this->assertArrayNotHasKey('cost_reference', $tecnicoDetail->json('quote.items.0'));
        $this->assertNull($tecnicoDetail->json('quote.items.0.margin_percent'));
    }

    /**
     * Regresión — antes del fix, `updateItem()` usaba `array_key_exists('margin_percent', $data)`
     * para decidir si pisar el margen guardado: como el técnico sin el grant especial ya no recibe
     * `margin_percent` real en el GET (ver test de arriba), su formulario de edición SIEMPRE reenvía
     * `margin_percent: null` (nunca tuvo el valor real para prellenar) — eso pisaba la línea al
     * margen mínimo global en cada guardado, sin que nadie lo pidiera. Editar solo `quantity` no
     * debe alterar un `margin_percent` ya guardado explícitamente.
     */
    public function test_editar_item_subcontratado_sin_margin_percent_preserva_el_valor_guardado(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $technician = $this->makeExternalTechnician(tarifaDia: 100);
        $item = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'subcontracted', 'external_technician_id' => $technician->id, 'quantity' => 1, 'margin_percent' => 45,
            ])->assertStatus(201)->json('id');

        // Mismo payload que enviaría el formulario del técnico sin el grant: margin_percent null
        // (el campo nunca tuvo el valor real para prellenar), solo cambia quantity.
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/quote/items/{$item}", [
                'tipo' => 'subcontracted', 'external_technician_id' => $technician->id,
                'quantity' => 3, 'margin_percent' => null,
            ])->assertStatus(200);

        // unit_price = 100 * 1.45 = 145 (margen preservado, NO reseteado a 30% default) -> subtotal 3*145=435.
        $detail = $this->withHeaders($this->authHeaders($this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios')))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);
        $this->assertEquals(45.0, $detail->json('quote.items.0.margin_percent'));
        $this->assertEquals(435.0, $detail->json('quote.items.0.subtotal'));
    }

    public function test_sincroniza_proyectos_activos_del_tecnico_externo(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $technician = $this->makeExternalTechnician(tarifaDia: 100);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'subcontracted', 'external_technician_id' => $technician->id, 'quantity' => 1,
            ])->assertStatus(201);

        $this->makeCurrentTenant();
        $service = app(ExternalTechnicianService::class);
        /** @var ExternalTechnician $fresh */
        $fresh = ExternalTechnician::find($technician->id);

        $this->assertSame(1, $service->activeProjectsCount($fresh));
        $this->assertCount(1, $service->assignedProjects($fresh, true));
    }

    /**
     * SCRUM-337 (rebote QA 2026-08-13, CRÍTICO) — "proyectos_asignados" nunca traía costo_dia/
     * margen_percent/precio_final (docblock viejo: "vive en el detalle de la Cotización, no se
     * duplica acá" — pero el propio título del REQ-267 pide ese desglose acá). Ahora precio_final
     * siempre viaja (no es un costo); costo_dia/margen_percent solo cuando el actor tiene
     * `puede_ver_costos` — mismo gate que ya protegía `cost_reference`/`margin_percent` en el
     * detalle de la Cotización (Senior Review Batch 11).
     */
    public function test_proyectos_asignados_expone_costo_margen_solo_a_quien_puede_verlos(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $technician = $this->makeExternalTechnician(tarifaDia: 25);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'subcontracted', 'external_technician_id' => $technician->id, 'quantity' => 3,
            ])->assertStatus(201);

        // $25/día x 30% margen default x 3 días = $97.50 (mismo ejemplo ya verificado en Batch 11).
        $ficha = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/external-technicians/{$technician->id}")
            ->assertStatus(200);
        $row = $ficha->json('proyectos_asignados.0');
        $this->assertEquals(97.5, $row['precio_final']);
        $this->assertEquals(25.0, $row['costo_dia']);
        $this->assertEquals(30.0, $row['margen_percent']);

        // Tecnico Servicios (tecnico) no tiene puede_ver_costos: precio_final sigue visible, pero
        // costo_dia/margen_percent no viajan en absoluto (ni siquiera en null) — mismo criterio
        // "ausente, no oculto" que margin_percent en ServiceQuoteController::formatItem().
        $tecnicoToken = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');
        $fichaCarlos = $this->withHeaders($this->authHeaders($tecnicoToken))
            ->getJson("/api/servicios/external-technicians/{$technician->id}")
            ->assertStatus(200);
        $rowCarlos = $fichaCarlos->json('proyectos_asignados.0');
        $this->assertEquals(97.5, $rowCarlos['precio_final']);
        $this->assertArrayNotHasKey('costo_dia', $rowCarlos);
        $this->assertArrayNotHasKey('margen_percent', $rowCarlos);
    }

    /**
     * SCRUM-337 (rebote QA 2026-08-13, BUG medio) — la tarifa/día del técnico externo dependía
     * SOLO de `tarifa_visible`, sin importar el rol de quien mira la ficha: Tecnico Servicios (sin
     * `puede_ver_costos`) veía la tarifa real con el toggle activado, aunque RN2 la reserva a
     * Lider Servicios/Mark/David/Whil. Decisión de Luis (daily 2026-08-18): es un bug, se gatea por RN2.
     */
    public function test_tarifa_visible_no_expone_costo_a_quien_no_puede_verlo(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $technician = $this->makeExternalTechnician(tarifaDia: 25);
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/external-technicians/{$technician->id}/tarifa", ['tarifa_visible' => true])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/external-technicians/{$technician->id}")
            ->assertJsonPath('tarifa_dia', '25.00');

        $tecnicoToken = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');
        $this->withHeaders($this->authHeaders($tecnicoToken))
            ->getJson("/api/servicios/external-technicians/{$technician->id}")
            ->assertJsonPath('tarifa_dia', null)
            ->assertJsonPath('tarifa_visible', true);
    }

    /**
     * SCRUM-337 (retest QA 2026-08-19) — el fix de arriba dejó tapado `tarifa_dia` pero
     * `rate_history` seguía exponiendo la tarifa real por otra vía. Debe estar ausente del
     * payload para quien no puede ver costos, presente con datos reales para quien sí puede.
     */
    public function test_rate_history_no_expone_tarifa_a_quien_no_puede_verla(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $technician = $this->makeExternalTechnician(tarifaDia: 25);
        // rateHistory() no se puebla en create() directo del factory — solo vía
        // ExternalTechnicianService en altas/cambios reales de tarifa. Sembrado directo, es fixture.
        $technician->rateHistory()->create(['tarifa_dia' => 25]);

        $tecnicoToken = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');
        $this->withHeaders($this->authHeaders($tecnicoToken))
            ->getJson("/api/servicios/external-technicians/{$technician->id}")
            ->assertStatus(200)
            ->assertJsonMissing(['rate_history']);

        $ficha = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/external-technicians/{$technician->id}")
            ->assertStatus(200);
        $this->assertSame('25.00', $ficha->json('rate_history.0.tarifa_dia'));
    }

    // ── REQ-233 — totales / ITBMS ────────────────────────────────────────────────────────────

    public function test_totales_escenario_1000_descuento_10_por_ciento(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'labor', 'description' => 'Mano de obra', 'quantity' => 1, 'unit_price' => 1000,
            ])->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/quote", ['discount_percent' => 10])
            ->assertStatus(200);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);

        $this->assertEquals(1000.0, $detail->json('quote.subtotal'));
        $this->assertEquals(63.0, $detail->json('quote.itbms'));
        $this->assertEquals(963.0, $detail->json('quote.total'));
    }

    public function test_totales_escenario_500_sin_descuento(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'labor', 'description' => 'Mano de obra', 'quantity' => 1, 'unit_price' => 500,
            ])->assertStatus(201);

        $detail = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(200);

        $this->assertEquals(35.0, $detail->json('quote.itbms'));
        $this->assertEquals(535.0, $detail->json('quote.total'));
    }

    // ── REQ-234 — ciclo de vida ──────────────────────────────────────────────────────────────

    public function test_guardar_no_promueve_el_estado(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/quote", ['observations' => 'Nota interna'])
            ->assertStatus(200)
            ->assertJsonPath('quote.estado', ServiceQuote::ESTADO_DRAFT);
    }

    public function test_enviar_exige_al_menos_un_item(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/quote/enviar")
            ->assertStatus(422);
    }

    public function test_enviar_cambia_estado_y_actualiza_ticket(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'labor', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10,
            ])->assertStatus(201);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/quote/enviar")
            ->assertStatus(200)
            ->assertJsonPath('quote.estado', ServiceQuote::ESTADO_SENT);

        $this->makeCurrentTenant();
        $fresh = Ticket::find($ticket->id);
        $this->assertNotNull($fresh);
        $this->assertSame(Ticket::QUOTE_STATUS_SENT, $fresh->quote_status);
    }

    public function test_tecnico_no_puede_decidir(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'labor', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10,
            ])->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))->patchJson("/api/servicios/tickets/{$ticket->id}/quote/enviar")->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/quote/decidir", ['estado' => 'approved'])
            ->assertStatus(403);
    }

    public function test_aaron_aprueba_y_actualiza_ticket(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'labor', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10,
            ])->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))->patchJson("/api/servicios/tickets/{$ticket->id}/quote/enviar")->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/quote/decidir", ['estado' => 'approved'])
            ->assertStatus(200)
            ->assertJsonPath('quote.estado', ServiceQuote::ESTADO_APPROVED);

        $this->makeCurrentTenant();
        $fresh = Ticket::find($ticket->id);
        $this->assertNotNull($fresh);
        $this->assertSame(Ticket::QUOTE_STATUS_APPROVED, $fresh->quote_status);
    }

    public function test_generar_nueva_solo_si_la_mas_reciente_fue_rechazada(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);

        // Sin decidir -> generar de nuevo está bloqueado.
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'labor', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10,
            ])->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))->patchJson("/api/servicios/tickets/{$ticket->id}/quote/enviar")->assertStatus(200);
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/quote/decidir", ['estado' => 'rejected'])
            ->assertStatus(200);

        // La más reciente está Rechazada -> ahora sí se puede generar una nueva.
        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote")
            ->assertStatus(201);

        // SCRUM-292 — abrir el formulario todavía no consume folio ni cambia el indicador del
        // ticket (sigue reflejando la Rechazada anterior) hasta el primer guardado real.
        $this->assertSame(null, $response->json('quote.numero'));

        $this->makeCurrentTenant();
        $fresh = Ticket::find($ticket->id);
        $this->assertNotNull($fresh);
        $this->assertSame(Ticket::QUOTE_STATUS_REJECTED, $fresh->quote_status);

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'labor', 'description' => 'Y', 'quantity' => 1, 'unit_price' => 20,
            ])->assertStatus(201);

        $this->makeCurrentTenant();
        $freshQuote = ServiceQuote::where('id', $response->json('quote.id'))->firstOrFail();
        $this->assertNotNull($freshQuote->numero);

        $fresh = Ticket::find($ticket->id);
        $this->assertNotNull($fresh);
        $this->assertSame(Ticket::QUOTE_STATUS_DRAFT, $fresh->quote_status);
        $this->assertSame(2, ServiceQuote::where('ticket_id', $ticket->id)->count());
    }

    public function test_editar_cotizacion_ya_enviada_queda_bloqueado(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->postJson("/api/servicios/tickets/{$ticket->id}/quote")->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/quote/items", [
                'tipo' => 'labor', 'description' => 'X', 'quantity' => 1, 'unit_price' => 10,
            ])->assertStatus(201);
        $this->withHeaders($this->authHeaders($token))->patchJson("/api/servicios/tickets/{$ticket->id}/quote/enviar")->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/quote", ['observations' => 'Cambio tardío'])
            ->assertStatus(422);
    }
}
