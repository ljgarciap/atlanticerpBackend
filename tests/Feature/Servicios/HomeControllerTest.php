<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Models\User;
use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\ServiceQuote;
use App\Modules\Servicios\Models\ServiciosSetting;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Models\TicketProduct;
use App\Modules\Servicios\Services\TicketService;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 15 (REQ-207/208/210/211) — pantalla "Inicio". Mismos usuarios reales
 * que el resto de la suite de Servicios (InternalTechnicianSeeder/CoreUserSeeder).
 */
class HomeControllerTest extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const CARLOS = 'tecnicoservicios@test.com';

    private const MIGUEL = 'garantiasservicios@test.com';

    private const MANAGEMENT = 'gerencia@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function makeCurrentTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function userIdByEmail(string $email): int
    {
        return (int) User::where('email', $email)->value('id');
    }

    private function authHeadersFor(string $email, string $role): array
    {
        $token = $this->loginAs($email, $email, $role);

        return $this->authHeaders($token);
    }

    /** @param array<string, mixed> $overrides */
    private function makeTicket(array $overrides = []): Ticket
    {
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $ticket = Ticket::create([
            'numero' => Ticket::PREFIX_BY_TIPO[$overrides['tipo'] ?? Ticket::TIPO_INSTALLATION].'-2026-'.static::uniqueTestNumber(),
            'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion' => 'Ticket de prueba',
            'cliente' => 'Cliente de prueba',
            'estado' => Ticket::ESTADO_REPORTED,
            // Gate REQ-218 no es el foco de este batch — se satisface a propósito para poder
            // cerrar el ticket directo desde el servicio real (TicketService::changeStage()).
            'quote_status' => Ticket::QUOTE_STATUS_NOT_APPLICABLE,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_NOT_APPLICABLE,
            ...$overrides,
        ]);

        if ($createdAt !== null) {
            $ticket->created_at = $createdAt;
            $ticket->save();
        }

        return $ticket;
    }

    /** @param array<string, mixed> $overrides */
    private function makeTicketProduct(Ticket $ticket, array $overrides = []): TicketProduct
    {
        $createdAt = $overrides['created_at'] ?? null;
        unset($overrides['created_at']);

        $ticketProduct = TicketProduct::create([
            'ticket_id' => $ticket->id,
            'marca' => 'Marca de prueba',
            'referencia' => 'REF-001',
            'descripcion' => 'Producto de prueba',
            'cantidad_reclamo' => 5,
            'cantidad_recibida' => 0,
            'cantidad_pendiente' => 5,
            ...$overrides,
        ]);

        if ($createdAt !== null) {
            $ticketProduct->created_at = $createdAt;
            $ticketProduct->save();
        }

        return $ticketProduct;
    }

    // ── REQ-208 — Rutas del día ──────────────────────────────────────────────────────────────

    public function test_rutas_dia_limita_a_5_y_marca_has_more(): void
    {
        $carlosId = $this->userIdByEmail(self::CARLOS);
        $today = Carbon::now('America/Panama')->startOfDay()->addHours(9);

        for ($i = 0; $i < 8; $i++) {
            $this->makeTicket([
                'estado' => Ticket::ESTADO_SCHEDULED,
                'internal_technician_id' => $carlosId,
                'scheduled_at' => $today->copy()->addHours($i)->utc(),
            ]);
        }

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertCount(5, $response->json('rutas_dia.visitas'));
        $this->assertSame(8, $response->json('rutas_dia.total'));
        $this->assertTrue($response->json('rutas_dia.has_more'));
    }

    public function test_rutas_dia_ordena_globalmente_por_hora_entre_tecnicos(): void
    {
        $carlosId = $this->userIdByEmail(self::CARLOS);
        $miguelId = $this->userIdByEmail(self::MIGUEL);
        $today = Carbon::now('America/Panama')->startOfDay();

        $late = $this->makeTicket([
            'estado' => Ticket::ESTADO_SCHEDULED, 'internal_technician_id' => $carlosId,
            'scheduled_at' => $today->copy()->addHours(15)->utc(),
        ]);
        $early = $this->makeTicket([
            'estado' => Ticket::ESTADO_SCHEDULED, 'internal_technician_id' => $miguelId, 'tipo' => Ticket::TIPO_WARRANTY,
            'scheduled_at' => $today->copy()->addHours(8)->utc(),
        ]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $ids = collect($response->json('rutas_dia.visitas'))->pluck('ticket_id')->all();
        $this->assertSame([$early->id, $late->id], $ids);
    }

    /** RN3 — el color debe ser EXACTAMENTE el mismo que internal_technicians.color (Garantias Servicios Test = ámbar). */
    public function test_rutas_dia_color_de_tecnico_coincide_con_tecnicos_internos(): void
    {
        $miguel = InternalTechnician::where('email', self::MIGUEL)->firstOrFail();
        $today = Carbon::now('America/Panama')->startOfDay()->addHours(9);

        $this->makeTicket([
            'estado' => Ticket::ESTADO_SCHEDULED, 'tipo' => Ticket::TIPO_WARRANTY,
            'internal_technician_id' => $miguel->user_id, 'scheduled_at' => $today->utc(),
        ]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertSame('#F59E0B', $response->json('rutas_dia.visitas.0.tecnico.color'));
        $this->assertSame($miguel->color, $response->json('rutas_dia.visitas.0.tecnico.color'));
    }

    /** RN4 — reagendar a otro día lo saca de Rutas del día sin ninguna acción manual sobre el panel. */
    public function test_rutas_dia_no_incluye_ticket_reagendado_a_otro_dia(): void
    {
        $carlosId = $this->userIdByEmail(self::CARLOS);
        $ticket = $this->makeTicket([
            'estado' => Ticket::ESTADO_SCHEDULED, 'internal_technician_id' => $carlosId,
            'scheduled_at' => Carbon::now('America/Panama')->startOfDay()->addHours(9)->utc(),
        ]);

        // Reagendar a mañana.
        $ticket->scheduled_at = Carbon::now('America/Panama')->addDay()->startOfDay()->addHours(9)->utc();
        $ticket->save();

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertSame(0, $response->json('rutas_dia.total'));
    }

    // ── REQ-210 — Pendientes ─────────────────────────────────────────────────────────────────

    public function test_pendientes_marca_ticket_sin_agendar_pasado_el_umbral(): void
    {
        $ticket = $this->makeTicket(['created_at' => now()->subDays(4)]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $items = collect($response->json('pendientes.items'));
        $found = $items->first(fn (array $i) => $i['ticket_id'] === $ticket->id);
        $this->assertNotNull($found);
        $this->assertSame('ticket_sin_agendar', $found['type']);
        $this->assertSame(1, $response->json('pendientes.count'));
    }

    public function test_pendientes_no_marca_ticket_dentro_del_umbral(): void
    {
        $this->makeTicket(['created_at' => now()->subDay()]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertSame(0, $response->json('pendientes.count'));
    }

    /** Regla del proyecto: nunca hardcodear el umbral — reusa el MISMO key que TicketService::stats(). */
    public function test_pendientes_umbral_es_configurable_via_servicios_settings(): void
    {
        ServiciosSetting::updateOrCreate(['key' => 'sin_agendar_umbral_dias'], ['value' => '1']);
        $this->makeTicket(['created_at' => now()->subDays(2)]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('pendientes.count'));
    }

    /** Escenario 4 del REQ-210 — la tarjeta obsoleta "Cliente sin confirmar cita" no debe existir. */
    public function test_pendientes_nunca_incluye_cliente_sin_confirmar_cita(): void
    {
        $this->makeTicket(['created_at' => now()->subDays(10)]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $types = collect($response->json('pendientes.items'))->pluck('type')->unique()->all();
        $this->assertNotContains('cliente_sin_confirmar_cita', $types);
    }

    // ── REQ-212 — Servicios sin responder ───────────────────────────────────────────────────

    public function test_sin_responder_marca_ticket_reported_pasado_el_umbral(): void
    {
        $ticket = $this->makeTicket(['created_at' => now()->subDays(6)]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $items = collect($response->json('sin_responder.items'));
        $found = $items->first(fn (array $i) => $i['ticket_id'] === $ticket->id);
        $this->assertNotNull($found);
        $this->assertSame($ticket->numero, $found['numero']);
        $this->assertSame('Instalación', $found['tipo_label']);
        $this->assertSame(1, $response->json('sin_responder.count'));
    }

    public function test_sin_responder_no_marca_ticket_dentro_del_umbral(): void
    {
        $this->makeTicket(['created_at' => now()->subDays(2)]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertSame(0, $response->json('sin_responder.count'));
    }

    /** RN1 — más angosto que "Sin agendar": un ticket con CUALQUIER avance de estado ya no aplica, aunque siga viejo. */
    public function test_sin_responder_no_incluye_ticket_con_avance_de_estado(): void
    {
        $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED, 'created_at' => now()->subDays(10)]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertSame(0, $response->json('sin_responder.count'));
    }

    /** Regla del proyecto: nunca hardcodear el umbral — key propia, distinta de sin_agendar_umbral_dias. */
    public function test_sin_responder_umbral_es_configurable_via_servicios_settings(): void
    {
        ServiciosSetting::updateOrCreate(['key' => 'sin_responder_umbral_dias'], ['value' => '1']);
        $this->makeTicket(['created_at' => now()->subDays(2)]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('sin_responder.count'));
    }

    // ── REQ-213 — Insumos pendientes de llegar ──────────────────────────────────────────────

    public function test_insumos_pendientes_incluye_producto_con_cantidad_pendiente(): void
    {
        $ticket = $this->makeTicket();
        $this->makeTicketProduct($ticket, ['created_at' => now()->subDays(3)]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $items = collect($response->json('insumos_pendientes.items'));
        $found = $items->first(fn (array $i) => $i['ticket_id'] === $ticket->id);
        $this->assertNotNull($found);
        $this->assertSame('Marca de prueba', $found['producto']['marca']);
        $this->assertSame(3, $found['solicitado_hace_dias']);
        $this->assertNull($found['llegada_estimada']);
        $this->assertSame(1, $response->json('insumos_pendientes.count'));
    }

    public function test_insumos_pendientes_excluye_cantidad_pendiente_cero(): void
    {
        $ticket = $this->makeTicket();
        $this->makeTicketProduct($ticket, ['cantidad_pendiente' => 0]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertSame(0, $response->json('insumos_pendientes.count'));
    }

    /** Un ticket ya cerrado/cancelado no debe seguir mostrando sus productos pendientes en Inicio. */
    public function test_insumos_pendientes_excluye_ticket_cerrado_o_cancelado(): void
    {
        $closed = $this->makeTicket(['estado' => Ticket::ESTADO_CLOSED]);
        $this->makeTicketProduct($closed);

        $cancelled = $this->makeTicket(['estado' => Ticket::ESTADO_CANCELLED, 'cancellation_reason' => 'Cliente desistió']);
        $this->makeTicketProduct($cancelled);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertSame(0, $response->json('insumos_pendientes.count'));
    }

    // ── REQ-215 — Estado de tickets ──────────────────────────────────────────────────────────

    public function test_estado_tickets_cuenta_por_cada_uno_de_los_6_estados(): void
    {
        $this->makeTicket(['estado' => Ticket::ESTADO_REPORTED]);
        $this->makeTicket(['estado' => Ticket::ESTADO_REPORTED]);
        $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/estado-tickets')
            ->assertStatus(200);

        $this->assertSame(2, $response->json('counts.reported'));
        $this->assertSame(1, $response->json('counts.scheduled'));
        $this->assertSame(0, $response->json('counts.on_site'));
        $this->assertNull($response->json('tipo'));
    }

    public function test_estado_tickets_filtra_por_tipo(): void
    {
        $this->makeTicket(['tipo' => Ticket::TIPO_INSTALLATION, 'estado' => Ticket::ESTADO_REPORTED]);
        $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY, 'estado' => Ticket::ESTADO_REPORTED]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/estado-tickets?tipo='.Ticket::TIPO_WARRANTY)
            ->assertStatus(200);

        $this->assertSame(1, $response->json('counts.reported'));
        $this->assertSame(Ticket::TIPO_WARRANTY, $response->json('tipo'));
    }

    /** RN4 — "Cerrado" cuenta solo el mes en curso, a diferencia de los otros 5 estados (histórico total). */
    public function test_estado_tickets_cerrado_solo_cuenta_el_mes_en_curso(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'UTC'));
        $user = User::where('email', self::LIDER_SERVICIOS)->firstOrFail();
        $service = app(TicketService::class);

        $thisMonth = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED]);
        $service->changeStage($thisMonth, Ticket::ESTADO_CLOSED, $user);

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'UTC'));
        $lastMonth = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED]);
        $service->changeStage($lastMonth, Ticket::ESTADO_CLOSED, $user);

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'UTC'));
        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/estado-tickets')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('counts.closed'));
    }

    // ── REQ-211 — Indicadores del mes ────────────────────────────────────────────────────────

    public function test_instalaciones_completadas_cuenta_solo_cerradas_este_mes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'UTC'));
        $user = User::where('email', self::LIDER_SERVICIOS)->firstOrFail();
        $service = app(TicketService::class);

        $thisMonth = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED]);
        $service->changeStage($thisMonth, Ticket::ESTADO_CLOSED, $user);

        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'UTC'));
        $lastMonth = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED]);
        $service->changeStage($lastMonth, Ticket::ESTADO_CLOSED, $user);

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'UTC'));
        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('indicadores_mes.instalaciones.completadas'));
    }

    public function test_meta_usa_default_manual_sin_historial_suficiente(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'UTC'));
        // Único ticket del sistema, creado hoy — no hay 3 meses de historial real todavía.
        $this->makeTicket();

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertSame('manual_default', $response->json('indicadores_mes.instalaciones.meta_source'));
        $this->assertSame(36, $response->json('indicadores_mes.instalaciones.meta'));
    }

    public function test_meta_se_calcula_con_historial_suficiente(): void
    {
        $user = User::where('email', self::LIDER_SERVICIOS)->firstOrFail();
        $service = app(TicketService::class);

        // Ticket "fundacional" 4 meses atrás para que hasSufficientHistory() vea >= 3 meses reales.
        Carbon::setTestNow(Carbon::parse('2026-04-01 12:00:00', 'UTC'));
        $this->makeTicket();

        // Mayo=30, Junio=34, Julio=32 instalaciones cerradas -> promedio 32 * 1.10 = 35.2 -> 35.
        foreach (['2026-05-15' => 30, '2026-06-15' => 34, '2026-07-15' => 32] as $date => $count) {
            Carbon::setTestNow(Carbon::parse("{$date} 12:00:00", 'UTC'));
            for ($i = 0; $i < $count; $i++) {
                $t = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED]);
                $service->changeStage($t, Ticket::ESTADO_CLOSED, $user);
            }
        }

        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'UTC'));
        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertSame('calculated', $response->json('indicadores_mes.instalaciones.meta_source'));
        $this->assertSame(35, $response->json('indicadores_mes.instalaciones.meta'));
    }

    public function test_gerencia_puede_sobrescribir_la_meta_de_un_mes_puntual(): void
    {
        $response = $this->withHeaders($this->authHeadersFor(self::MANAGEMENT, 'management'))
            ->putJson('/api/servicios/home/installation-goal', ['year' => 2026, 'month' => 7, 'value' => 20])
            ->assertStatus(200);

        $this->assertSame('manual_override', $response->json('source'));
        $this->assertSame(20, $response->json('value'));
    }

    public function test_lider_servicios_no_puede_sobrescribir_la_meta(): void
    {
        $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->putJson('/api/servicios/home/installation-goal', ['year' => 2026, 'month' => 7, 'value' => 20])
            ->assertStatus(403);
    }

    public function test_resuelto_primera_visita_pct_excluye_tickets_con_mas_de_una_visita(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-15 12:00:00', 'UTC'));
        $user = User::where('email', self::LIDER_SERVICIOS)->firstOrFail();
        $service = app(TicketService::class);

        // 3 garantías resueltas en 1ra visita.
        for ($i = 0; $i < 3; $i++) {
            $t = $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY, 'estado' => Ticket::ESTADO_SCHEDULED]);
            $service->changeStage($t, Ticket::ESTADO_ON_SITE, $user);
            $service->changeStage($t, Ticket::ESTADO_CLOSED, $user);
        }

        // 1 garantía que necesitó 2 visitas (on_site dos veces).
        $revisit = $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY, 'estado' => Ticket::ESTADO_SCHEDULED]);
        $service->changeStage($revisit, Ticket::ESTADO_ON_SITE, $user);
        $service->changeStage($revisit, Ticket::ESTADO_SCHEDULED, $user);
        $service->changeStage($revisit, Ticket::ESTADO_ON_SITE, $user);
        $service->changeStage($revisit, Ticket::ESTADO_CLOSED, $user);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        // json()/assertSame no distingue 75 (int) de 75.0 (float) tras el round-trip JSON — PHP no
        // serializa la fracción ".0" de un float entero por defecto. assertEquals compara el valor
        // numérico real, que es lo que importa acá.
        $this->assertEquals(75.0, $response->json('indicadores_mes.resuelto_primera_visita_pct'));
    }

    public function test_tiempo_promedio_resolucion_solo_considera_garantias(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'UTC'));
        $user = User::where('email', self::LIDER_SERVICIOS)->firstOrFail();
        $service = app(TicketService::class);

        $warranty = $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY, 'estado' => Ticket::ESTADO_SCHEDULED, 'created_at' => now()->subDays(5)]);
        $service->changeStage($warranty, Ticket::ESTADO_CLOSED, $user);

        // Instalación cerrada el mismo mes NO debe contar para "tiempo prom. resolución (Garantías)".
        $installation = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED, 'created_at' => now()->subDays(20)]);
        $service->changeStage($installation, Ticket::ESTADO_CLOSED, $user);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertEquals(5.0, $response->json('indicadores_mes.tiempo_promedio_resolucion_dias'));
    }

    /**
     * RN5 — reconciliado 2026-08-12 tras el cierre de Batch 11 (Cotización). Suma solo cotizaciones
     * APROBADAS de tickets tipo Instalación cerrados dentro del mes en curso — descarta una
     * cotización en borrador (no cuenta) y una instalación cerrada en un mes distinto (no cuenta).
     */
    public function test_ingresos_instalaciones_suma_solo_cotizaciones_aprobadas_del_mes(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'UTC'));
        $user = User::where('email', self::LIDER_SERVICIOS)->firstOrFail();
        $service = app(TicketService::class);

        $approvedThisMonth = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED, 'created_at' => now()->subDays(5)]);
        $service->changeStage($approvedThisMonth, Ticket::ESTADO_CLOSED, $user);
        $this->makeQuote($approvedThisMonth, ServiceQuote::ESTADO_APPROVED, 1500.00);

        // Cotización en borrador del mismo ticket/mes — no debe sumar.
        $this->makeQuote($approvedThisMonth, ServiceQuote::ESTADO_DRAFT, 999.00);

        // Instalación aprobada pero cerrada el mes pasado — no debe sumar.
        Carbon::setTestNow(Carbon::parse('2026-07-15 12:00:00', 'UTC'));
        $approvedLastMonth = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED, 'created_at' => now()->subDays(5)]);
        $service->changeStage($approvedLastMonth, Ticket::ESTADO_CLOSED, $user);
        $this->makeQuote($approvedLastMonth, ServiceQuote::ESTADO_APPROVED, 800.00);
        Carbon::setTestNow(Carbon::parse('2026-08-20 12:00:00', 'UTC'));

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/summary')
            ->assertStatus(200);

        $this->assertEquals(1500.00, $response->json('indicadores_mes.ingresos_instalaciones.value'));
        $this->assertTrue($response->json('indicadores_mes.ingresos_instalaciones.available'));
    }

    private function makeQuote(Ticket $ticket, string $estado, float $total): ServiceQuote
    {
        return ServiceQuote::create([
            'numero' => 'COT-2026-'.static::uniqueTestNumber(),
            'ticket_id' => $ticket->id,
            'estado' => $estado,
            'subtotal' => $total,
            'itbms' => 0,
            'total' => $total,
        ]);
    }

    // ------------------------------------------------------- SCRUM-908 "Aprobaciones Pendientes" ----

    public function test_approvals_pending_incluye_cotizacion_para_lider_servicios(): void
    {
        $ticket = $this->makeTicket();
        $this->makeQuote($ticket, ServiceQuote::ESTADO_SENT, 500.00);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/home/approvals-pending')
            ->assertStatus(200);

        $items = collect($response->json('data'));
        $this->assertTrue($items->contains(fn (array $i) => $i['tipo'] === 'service_quote'));
    }

    public function test_approvals_pending_vacio_para_quien_no_es_lider_servicios(): void
    {
        $ticket = $this->makeTicket();
        $this->makeQuote($ticket, ServiceQuote::ESTADO_SENT, 500.00);

        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/home/approvals-pending')
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }
}
