<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Models\User;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Models\TicketProduct;
use App\Modules\VentasDiseno\Models\CatalogProduct;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 2 (REQ-222/224/225/226): tarjetas de estadísticas, modal de detalle,
 * edición global, Agendar/Reagendar. Mismos usuarios reales que TicketFlowTest (Batch 1).
 */
class TicketBatch2Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const TECNICO_SERVICIOS = 'tecnicoservicios@test.com';

    private const GARANTIAS_SERVICIOS = 'garantiasservicios@test.com';

    private const MANAGEMENT = 'gerencia@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /**
     * `created_at` no es mass-assignable (no está en Ticket::$fillable) — pasarlo dentro de
     * `$overrides` a `Ticket::create()` se ignora en silencio (Eloquent lo pisa con `now()` vía
     * timestamps automáticos). Se extrae acá y se aplica por asignación directa + save() después,
     * que sí lo respeta.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeTicket(array $overrides = []): Ticket
    {
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $ticket = Ticket::create([
            'numero' => Ticket::PREFIX_BY_TIPO[$overrides['tipo'] ?? Ticket::TIPO_INSTALLATION].'-2026-'.static::uniqueTestNumber(),
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

        if ($createdAt !== null) {
            $ticket->created_at = $createdAt;
            $ticket->save();
        }

        return $ticket;
    }

    private function makeCatalogProduct(array $overrides = []): CatalogProduct
    {
        return CatalogProduct::create([
            'reference' => 'REF-'.uniqid(), 'name' => 'Producto de prueba', 'price_full' => 100,
            'cost' => 50, 'is_active' => true,
            ...$overrides,
        ]);
    }

    private function technicianId(string $email): int
    {
        return (int) User::where('email', $email)->value('id');
    }

    // El round-trip HTTP resetea el tenant actual — re-activarlo antes de cualquier `->fresh()`
    // posterior a una llamada `withHeaders()->...Json(...)` (mismo patrón que TicketFlowTest).
    private function makeCurrentTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // ── REQ-222 — tarjetas de estadísticas ──────────────────────────────────────────────────

    public function test_stats_cuenta_abiertos_excluyendo_cerrados_y_cancelados(): void
    {
        $this->makeTicket(['estado' => Ticket::ESTADO_REPORTED]);
        $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED]);
        $this->makeTicket(['estado' => Ticket::ESTADO_CLOSED]);
        $this->makeTicket(['estado' => Ticket::ESTADO_CANCELLED]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/servicios/tickets/stats')->assertStatus(200);

        $this->assertSame(4, $response->json('total_tickets'));
        $this->assertSame(2, $response->json('tickets_abiertos'));
    }

    public function test_stats_cotizaciones_por_generar_informe_listo_cotizacion_pendiente(): void
    {
        $this->makeTicket([
            'inspection_report_status' => Ticket::INSPECTION_STATUS_COMPLETED,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING,
        ]);
        $this->makeTicket([
            'inspection_report_status' => Ticket::INSPECTION_STATUS_NOT_APPLICABLE,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING,
        ]);
        // No cuenta: informe todavía pendiente.
        $this->makeTicket([
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
            'quote_status' => Ticket::QUOTE_STATUS_PENDING,
        ]);
        // No cuenta: cotización ya no está pending (ya se generó, en algún estado del ciclo).
        $this->makeTicket([
            'inspection_report_status' => Ticket::INSPECTION_STATUS_COMPLETED,
            'quote_status' => Ticket::QUOTE_STATUS_DRAFT,
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/servicios/tickets/stats')->assertStatus(200);

        $this->assertSame(2, $response->json('cotizaciones_por_generar'));
    }

    public function test_stats_informes_por_generar_cuenta_inspection_pending(): void
    {
        $this->makeTicket(['inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING]);
        $this->makeTicket(['inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING]);
        $this->makeTicket(['inspection_report_status' => Ticket::INSPECTION_STATUS_COMPLETED]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/servicios/tickets/stats')->assertStatus(200);

        $this->assertSame(2, $response->json('informes_por_generar'));
    }

    public function test_stats_sin_agendar_respeta_umbral_configurable_y_excluye_cerrados(): void
    {
        // Reportado hace 5 días, sin fecha -> cuenta (umbral default 3 días).
        $this->makeTicket(['scheduled_at' => null, 'created_at' => now()->subDays(5), 'estado' => Ticket::ESTADO_REPORTED]);
        // Reportado hace 1 día, sin fecha -> NO cuenta todavía.
        $this->makeTicket(['scheduled_at' => null, 'created_at' => now()->subDay(), 'estado' => Ticket::ESTADO_REPORTED]);
        // Sin fecha pero ya cancelado -> NO cuenta.
        $this->makeTicket(['scheduled_at' => null, 'created_at' => now()->subDays(10), 'estado' => Ticket::ESTADO_CANCELLED]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/servicios/tickets/stats')->assertStatus(200);

        $this->assertSame(1, $response->json('sin_agendar'));
    }

    // ── REQ-224 — modal de detalle ──────────────────────────────────────────────────────────

    public function test_detalle_sin_sales_project_colapsa_cliente_master_y_subcliente_al_mismo_valor(): void
    {
        $ticket = $this->makeTicket(['cliente' => 'Torre Business Park', 'sales_project_id' => null, 'telefono' => '+507 6000-1111']);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}")
            ->assertStatus(200);

        $this->assertSame('Torre Business Park', $response->json('cliente_master'));
        $this->assertSame('Torre Business Park', $response->json('subcliente'));
        $this->assertSame([], $response->json('productos'));
        $this->assertSame([], $response->json('adjuntos'));
        // Visual Review 2026-08-03 — el mockup muestra teléfono junto al contacto.
        $this->assertSame('+507 6000-1111', $response->json('telefono'));
    }

    public function test_detalle_404_si_no_existe(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))->getJson('/api/servicios/tickets/999999')->assertStatus(404);
    }

    public function test_detalle_visible_para_tecnico_interno(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}")
            ->assertStatus(200);
    }

    // ── REQ-225 — edición global ─────────────────────────────────────────────────────────────

    public function test_editar_ticket_exitoso_cambia_subtipo(): void
    {
        $ticket = $this->makeTicket(['tipo' => Ticket::TIPO_INSTALLATION, 'subtipo' => Ticket::SUBTIPO_INSTALLATION]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}", [
                'tipo' => Ticket::TIPO_INSTALLATION,
                'subtipo' => Ticket::SUBTIPO_INSPECTION,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
                // Batch 3 parte 2 — formato estructurado (antes era texto libre).
                'requerimientos_especiales' => ['catalog' => ['andamio'], 'otros' => ['Requiere grúa']],
            ])
            ->assertStatus(200);

        $response->assertJsonPath('subtipo', Ticket::SUBTIPO_INSPECTION);
        $response->assertJsonPath('requerimientos_especiales.catalog.0.key', 'andamio');
        $response->assertJsonPath('requerimientos_especiales.catalog.0.label', 'Andamio');
        $response->assertJsonPath('requerimientos_especiales.otros.0', 'Requiere grúa');
    }

    public function test_editar_subtipo_invalido_para_el_tipo_retorna_422(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}", [
                'tipo' => Ticket::TIPO_CLAIM,
                'subtipo' => Ticket::SUBTIPO_INSTALLATION, // claim no maneja subtipo
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            ])
            ->assertStatus(422);
    }

    public function test_editar_cambio_de_tipo_desasigna_tecnico_sin_especialidad_valida(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS); // tecnico_servicios, NO atiende Garantías
        $ticket = $this->makeTicket([
            'tipo' => Ticket::TIPO_INSTALLATION, 'subtipo' => Ticket::SUBTIPO_INSTALLATION,
            'internal_technician_id' => $carlosId,
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}", [
                'tipo' => Ticket::TIPO_WARRANTY,
                'subtipo' => Ticket::SUBTIPO_WARRANTY_GENERIC,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            ])
            ->assertStatus(200);

        $response->assertJsonPath('technician_unassigned', true);
        $response->assertJsonPath('internal_technician', null);
        $this->makeCurrentTenant();
        $this->assertNull($ticket->fresh()->internal_technician_id);
    }

    public function test_editar_mantiene_tecnico_si_sigue_siendo_valido_para_el_nuevo_tipo(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS);
        $ticket = $this->makeTicket([
            'tipo' => Ticket::TIPO_INSTALLATION, 'subtipo' => Ticket::SUBTIPO_INSTALLATION,
            'internal_technician_id' => $carlosId,
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}", [
                'tipo' => Ticket::TIPO_RETROFIT,
                'subtipo' => null,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            ])
            ->assertStatus(200);

        $response->assertJsonPath('technician_unassigned', false);
        $this->makeCurrentTenant();
        $this->assertSame($carlosId, $ticket->fresh()->internal_technician_id);
    }

    public function test_tecnico_interno_no_puede_editar_ticket(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}", [
                'tipo' => Ticket::TIPO_INSTALLATION, 'subtipo' => Ticket::SUBTIPO_INSTALLATION,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            ])
            ->assertStatus(403);
    }

    public function test_management_no_puede_editar_ticket(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}", [
                'tipo' => Ticket::TIPO_INSTALLATION, 'subtipo' => Ticket::SUBTIPO_INSTALLATION,
                'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            ])
            ->assertStatus(403);
    }

    // ── SCRUM-781 punto 1 (REQ-225 RN2) — productos reclamados/afectados desde la edición ─────

    public function test_agregar_producto_a_ticket_existente(): void
    {
        $ticket = $this->makeTicket();
        $product = $this->makeCatalogProduct(['reference' => 'LUM-COL-014', 'name' => 'Colgante LED', 'brand' => 'Atlantic']);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/products", [
                'catalog_product_id' => $product->id, 'cantidad_reclamo' => 3,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('productos.0.referencia', 'LUM-COL-014');
        $response->assertJsonPath('productos.0.descripcion', 'Colgante LED');
        $response->assertJsonPath('productos.0.cantidad_reclamada', 3);
        $response->assertJsonPath('productos.0.cantidad_pendiente', 3);
    }

    public function test_editar_cantidad_de_producto_sin_recepcion(): void
    {
        $ticket = $this->makeTicket();
        $product = $this->makeCatalogProduct();
        $row = TicketProduct::create([
            'ticket_id' => $ticket->id, 'catalog_product_id' => $product->id,
            'cantidad_reclamo' => 2, 'cantidad_recibida' => 0, 'cantidad_pendiente' => 2,
        ]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/products/{$row->id}", ['cantidad_reclamo' => 5])
            ->assertStatus(200);

        $response->assertJsonPath('productos.0.cantidad_reclamada', 5);
        $response->assertJsonPath('productos.0.cantidad_pendiente', 5);
    }

    public function test_quitar_producto_sin_recepcion(): void
    {
        $ticket = $this->makeTicket();
        $product = $this->makeCatalogProduct();
        $row = TicketProduct::create([
            'ticket_id' => $ticket->id, 'catalog_product_id' => $product->id,
            'cantidad_reclamo' => 2, 'cantidad_recibida' => 0, 'cantidad_pendiente' => 2,
        ]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/servicios/tickets/{$ticket->id}/products/{$row->id}")
            ->assertStatus(200);

        $this->assertSame([], $response->json('productos'));
    }

    /**
     * Decisión de Luis (2026-08-20) — una fila con cantidad_recibida > 0 (ya avanzó por el flujo
     * real de recepción de otro batch) queda de solo lectura: ni editar ni quitar, para no perder
     * esa trazabilidad.
     */
    public function test_no_se_puede_editar_producto_con_recepcion_registrada(): void
    {
        $ticket = $this->makeTicket();
        $product = $this->makeCatalogProduct();
        $row = TicketProduct::create([
            'ticket_id' => $ticket->id, 'catalog_product_id' => $product->id,
            'cantidad_reclamo' => 5, 'cantidad_recibida' => 2, 'cantidad_pendiente' => 3,
        ]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/products/{$row->id}", ['cantidad_reclamo' => 9])
            ->assertStatus(422);

        $this->makeCurrentTenant();
        $this->assertSame(5, $row->fresh()->cantidad_reclamo);
    }

    public function test_no_se_puede_quitar_producto_con_recepcion_registrada(): void
    {
        $ticket = $this->makeTicket();
        $product = $this->makeCatalogProduct();
        $row = TicketProduct::create([
            'ticket_id' => $ticket->id, 'catalog_product_id' => $product->id,
            'cantidad_reclamo' => 5, 'cantidad_recibida' => 2, 'cantidad_pendiente' => 3,
        ]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->deleteJson("/api/servicios/tickets/{$ticket->id}/products/{$row->id}")
            ->assertStatus(422);

        $this->makeCurrentTenant();
        $this->assertNotNull(TicketProduct::find($row->id));
    }

    public function test_tecnico_interno_no_puede_agregar_producto(): void
    {
        $ticket = $this->makeTicket();
        $product = $this->makeCatalogProduct();
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson("/api/servicios/tickets/{$ticket->id}/products", [
                'catalog_product_id' => $product->id, 'cantidad_reclamo' => 1,
            ])
            ->assertStatus(403);
    }

    // ── REQ-226 — Agendar / Reagendar ────────────────────────────────────────────────────────

    public function test_primer_agendamiento_pasa_reportado_a_agendado(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS);
        $ticket = $this->makeTicket(['estado' => Ticket::ESTADO_REPORTED, 'scheduled_at' => null]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $carlosId,
                'scheduled_at' => now()->addDays(2)->toIso8601String(),
            ])
            ->assertStatus(200);

        $response->assertJsonPath('estado', Ticket::ESTADO_SCHEDULED);
        $this->makeCurrentTenant();
        $this->assertNotNull($ticket->fresh()->scheduled_at);
    }

    public function test_reagendar_no_cambia_el_estado(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS);
        $ticket = $this->makeTicket([
            'estado' => Ticket::ESTADO_ON_SITE, 'scheduled_at' => now()->addDay(),
            'internal_technician_id' => $carlosId,
        ]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $nuevaFecha = now()->addDays(5)->toIso8601String();
        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $carlosId,
                'scheduled_at' => $nuevaFecha,
                'motivo_reagendamiento' => 'Cliente pidió mover la visita.',
            ])
            ->assertStatus(200);

        $response->assertJsonPath('estado', Ticket::ESTADO_ON_SITE);
    }

    // ── SCRUM-804 — Motivo obligatorio al reagendar ─────────────────────────────────────────────

    public function test_reagendar_sin_motivo_retorna_422(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS);
        $ticket = $this->makeTicket([
            'estado' => Ticket::ESTADO_SCHEDULED, 'scheduled_at' => now()->addDay(),
            'internal_technician_id' => $carlosId,
        ]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $carlosId,
                'scheduled_at' => now()->addDays(5)->toIso8601String(),
            ])
            ->assertStatus(422);
    }

    public function test_primer_agendamiento_no_exige_motivo(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS);
        $ticket = $this->makeTicket(['estado' => Ticket::ESTADO_REPORTED, 'scheduled_at' => null]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $carlosId,
                'scheduled_at' => now()->addDays(2)->toIso8601String(),
            ])
            ->assertStatus(200);
    }

    public function test_reagendar_con_motivo_registra_historial_con_fecha_anterior_y_nueva(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS);
        $fechaOriginal = now()->addDay();
        $ticket = $this->makeTicket([
            'estado' => Ticket::ESTADO_SCHEDULED, 'scheduled_at' => $fechaOriginal,
            'internal_technician_id' => $carlosId,
        ]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $nuevaFecha = now()->addDays(5)->toIso8601String();
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $carlosId,
                'scheduled_at' => $nuevaFecha,
                'motivo_reagendamiento' => 'Cliente pidió mover la visita.',
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $history = $ticket->fresh()->rescheduleHistory;
        $this->assertCount(1, $history);
        $this->assertSame('Cliente pidió mover la visita.', $history->first()->motivo);
        // Precisión de segundo — la columna es timestamp sin microsegundos (mismo criterio que
        // el resto de columnas de fecha del módulo), equalTo() con el Carbon original fallaría.
        $this->assertSame($fechaOriginal->format('Y-m-d H:i:s'), $history->first()->previous_scheduled_at->format('Y-m-d H:i:s'));
        $this->assertSame(Carbon::parse($nuevaFecha)->format('Y-m-d H:i:s'), $history->first()->new_scheduled_at->format('Y-m-d H:i:s'));
    }

    public function test_primer_agendamiento_no_genera_historial(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS);
        $ticket = $this->makeTicket(['estado' => Ticket::ESTADO_REPORTED, 'scheduled_at' => null]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $carlosId,
                'scheduled_at' => now()->addDays(2)->toIso8601String(),
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $this->assertCount(0, $ticket->fresh()->rescheduleHistory);
    }

    public function test_reagendar_dos_veces_deja_dos_filas_de_historial(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS);
        $ticket = $this->makeTicket([
            'estado' => Ticket::ESTADO_SCHEDULED, 'scheduled_at' => now()->addDay(),
            'internal_technician_id' => $carlosId,
        ]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $carlosId,
                'scheduled_at' => now()->addDays(5)->toIso8601String(),
                'motivo_reagendamiento' => 'Primer reagendamiento.',
            ])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $carlosId,
                'scheduled_at' => now()->addDays(8)->toIso8601String(),
                'motivo_reagendamiento' => 'Segundo reagendamiento.',
            ])
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $this->assertCount(2, $ticket->fresh()->rescheduleHistory);
    }

    public function test_show_expone_reschedule_history_ordenado_del_mas_reciente(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS);
        $ticket = $this->makeTicket([
            'estado' => Ticket::ESTADO_SCHEDULED, 'scheduled_at' => now()->addDay(),
            'internal_technician_id' => $carlosId,
        ]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $carlosId,
                'scheduled_at' => now()->addDays(5)->toIso8601String(),
                'motivo_reagendamiento' => 'Motivo A.',
            ])
            ->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/tickets/{$ticket->id}")
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $response->assertJsonPath('reschedule_history.0.motivo', 'Motivo A.');
        $response->assertJsonPath('reschedule_history.0.rescheduled_by.id', $this->technicianId(self::LIDER_SERVICIOS));
    }

    public function test_agendar_garantia_solo_acepta_tecnico_de_garantias(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS); // NO atiende Garantías
        $ticket = $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY, 'subtipo' => Ticket::SUBTIPO_WARRANTY_GENERIC]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $carlosId,
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(422);
    }

    public function test_agendar_garantia_acepta_tecnico_de_garantias(): void
    {
        $miguelId = $this->technicianId(self::GARANTIAS_SERVICIOS);
        $ticket = $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY, 'subtipo' => Ticket::SUBTIPO_WARRANTY_GENERIC]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $miguelId,
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(200);
    }

    public function test_agendar_sin_fecha_retorna_422(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS);
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $carlosId,
            ])
            ->assertStatus(422);
    }

    public function test_tecnico_interno_no_puede_agendar(): void
    {
        $carlosId = $this->technicianId(self::TECNICO_SERVICIOS);
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/agendar", [
                'internal_technician_id' => $carlosId,
                'scheduled_at' => now()->addDay()->toIso8601String(),
            ])
            ->assertStatus(403);
    }

    // ── Técnicos filtrados por tipo (REQ-226 RN2) ───────────────────────────────────────────

    public function test_technicians_internal_filtra_por_tipo_garantia(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/technicians/internal?fields=options&tipo='.Ticket::TIPO_WARRANTY)
            ->assertStatus(200);

        $names = collect($response->json('data'))->map(fn (array $t) => $t['first_name'])->all();
        $this->assertSame(['Miguel'], $names);
    }

    public function test_technicians_internal_filtra_por_tipo_no_garantia(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/technicians/internal?fields=options&tipo='.Ticket::TIPO_RETROFIT)
            ->assertStatus(200);

        $names = collect($response->json('data'))->map(fn (array $t) => $t['first_name'])->all();
        $this->assertCount(3, $names);
        $this->assertNotContains('Miguel', $names);
    }
}
