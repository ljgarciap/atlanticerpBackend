<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Models\User;
use App\Modules\Servicios\Models\ClaimSheet;
use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\ServiceQuote;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\TicketTypes;
use App\Modules\Servicios\Services\TicketService;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, pantalla "Reportes" (SCRUM-350→356, REQ-280→286). Cubre los 7 endpoints,
 * con foco en los 2 criterios de aceptación de paridad explícitos (RN2 de REQ-280, RN1 de
 * REQ-285 — nunca un cálculo paralelo al de Inicio/Técnicos Internos) y el gate de visibilidad
 * de REQ-285 RN2.
 */
class ReportsControllerTest extends FeatureTestCase
{
    use \Tests\Support\RecorreEstadosDeTicket;

    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const TECNICO = 'tecnico@test.com';

    private const MANAGEMENT = 'gerencia@test.com';

    private const FROZEN_NOW = '2026-08-15 12:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW, 'UTC'));
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

    /** @return array<string, string> */
    private function authHeadersFor(string $email, string $role): array
    {
        $token = $this->loginAs($email, $email, $role);

        return $this->authHeaders($token);
    }

    /**
     * Mismo patrón que `HomeControllerTest::makeTicket()` — `created_at`/`closed_at` no son
     * mass-assignable (no están en `Ticket::$fillable`), se extraen y se aplican por asignación
     * directa + save() después.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function makeTicket(array $overrides = []): Ticket
    {
        $createdAt = $overrides['created_at'] ?? null;
        $closedAt = $overrides['closed_at'] ?? null;
        unset($overrides['created_at'], $overrides['closed_at']);

        $ticket = Ticket::create([
            'numero' => TicketTypes::prefijo($overrides['tipo'] ?? Ticket::TIPO_INSTALLATION).'-2026-'.static::uniqueTestNumber(),
            'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion' => 'Ticket de prueba',
            'cliente' => 'Cliente de prueba',
            'estado' => Ticket::ESTADO_REPORTED,
            'quote_status' => Ticket::QUOTE_STATUS_NOT_APPLICABLE,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_NOT_APPLICABLE,
            ...$overrides,
        ]);

        if ($createdAt !== null) {
            $ticket->created_at = $createdAt;
        }
        if ($closedAt !== null) {
            $ticket->closed_at = $closedAt;
        }
        if ($createdAt !== null || $closedAt !== null) {
            $ticket->save();
        }

        return $ticket;
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

    // ── REQ-280 — Panorama del mes ────────────────────────────────────────────────────────────

    public function test_servicios_completados_pct_relaciona_cerrados_del_mes_con_reportados_del_mes(): void
    {
        $user = User::where('email', self::LIDER_SERVICIOS)->firstOrFail();
        $service = app(TicketService::class);

        // 4 tickets reportados este mes, 2 de ellos cerrados este mes.
        $a = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED]);
        $b = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED]);
        $this->makeTicket();
        $this->makeTicket();

        $this->cerrarTicket($service, $a, $user);
        $this->cerrarTicket($service, $b, $user);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/panorama-mes')
            ->assertStatus(200);

        $this->assertEquals(50.0, $response->json('servicios_completados_pct'));

        // Escenario 1 del ticket — la tarjeta dice literalmente "2 de 4 tickets del mes", así que
        // los 2 conteos crudos viajan en la respuesta, no solo el porcentaje (hallazgo Pre-QA).
        $this->assertSame(2, $response->json('servicios_completados'));
        $this->assertSame(4, $response->json('servicios_completados_total'));
    }

    /** Escenario 1 (REQ-280) — mes sin ningún ticket reportado: 0 de 0, `_pct` null, nunca /0. */
    public function test_panorama_mes_sin_tickets_devuelve_conteos_en_cero_y_pct_null(): void
    {
        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/panorama-mes?year=2021&month=3')
            ->assertStatus(200);

        $this->assertSame(0, $response->json('servicios_completados'));
        $this->assertSame(0, $response->json('servicios_completados_total'));
        $this->assertNull($response->json('servicios_completados_pct'));
    }

    /** RN2 (REQ-280) — paridad exacta con `HomeService::resueltoPrimeraVisitaPct()`. */
    public function test_resuelto_primera_visita_pct_coincide_exactamente_con_inicio(): void
    {
        $user = User::where('email', self::LIDER_SERVICIOS)->firstOrFail();
        $service = app(TicketService::class);

        for ($i = 0; $i < 3; $i++) {
            $t = $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY, 'estado' => Ticket::ESTADO_SCHEDULED]);
            $this->llevarTicketA($service, $t, $user, Ticket::ESTADO_ON_SITE);
            $this->cerrarTicket($service, $t, $user);
        }

        $revisit = $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY, 'estado' => Ticket::ESTADO_SCHEDULED]);
        $this->llevarTicketA($service, $revisit, $user, Ticket::ESTADO_ON_SITE);
        $this->llevarTicketA($service, $revisit, $user, Ticket::ESTADO_SCHEDULED);
        $this->llevarTicketA($service, $revisit, $user, Ticket::ESTADO_ON_SITE);
        $this->cerrarTicket($service, $revisit, $user);

        $headers = $this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios');

        $home = $this->withHeaders($headers)->getJson('/api/servicios/home/summary')->assertStatus(200);
        $this->makeCurrentTenant();
        $reportes = $this->withHeaders($headers)->getJson('/api/servicios/reportes/panorama-mes')->assertStatus(200);

        $this->assertEquals(75.0, $home->json('indicadores_mes.resuelto_primera_visita_pct'));
        $this->assertEquals(
            $home->json('indicadores_mes.resuelto_primera_visita_pct'),
            $reportes->json('resuelto_primera_visita_pct'),
        );
    }

    /** RN3 (REQ-280) — a diferencia de Inicio (solo Garantías), acá promedia TODOS los tipos. */
    public function test_tiempo_promedio_resolucion_incluye_todos_los_tipos_a_diferencia_de_inicio(): void
    {
        $user = User::where('email', self::LIDER_SERVICIOS)->firstOrFail();
        $service = app(TicketService::class);

        $warranty = $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY, 'estado' => Ticket::ESTADO_SCHEDULED, 'created_at' => now()->subDays(3)]);
        $this->cerrarTicket($service, $warranty, $user);

        $installation = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED, 'created_at' => now()->subDays(7)]);
        $this->cerrarTicket($service, $installation, $user);

        $headers = $this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios');

        $home = $this->withHeaders($headers)->getJson('/api/servicios/home/summary')->assertStatus(200);
        $this->makeCurrentTenant();
        $reportes = $this->withHeaders($headers)->getJson('/api/servicios/reportes/panorama-mes')->assertStatus(200);

        // Inicio: solo Garantías -> 3.0. Reportes: ambos tipos -> (3+7)/2 = 5.0.
        $this->assertEquals(3.0, $home->json('indicadores_mes.tiempo_promedio_resolucion_dias'));
        $this->assertEquals(5.0, $reportes->json('tiempo_promedio_resolucion_dias'));
        $this->assertTrue($reportes->json('tiempo_promedio_resolucion_todos_los_tipos'));
    }

    /** RN4 — mismo cálculo centralizado que `TicketService::stats()` (informes_por_generar). */
    public function test_informes_pendientes_suma_informe_de_inspeccion_y_hoja_de_reclamo(): void
    {
        $this->makeTicket(['inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING]);

        $claimTicket = $this->makeTicket(['tipo' => Ticket::TIPO_CLAIM, 'inspection_report_status' => Ticket::INSPECTION_STATUS_NOT_APPLICABLE]);
        ClaimSheet::create(['ticket_id' => $claimTicket->id, 'estado' => ClaimSheet::ESTADO_PENDING]);

        // Ya completado — no debe sumar.
        ClaimSheet::create(['ticket_id' => $this->makeTicket(['tipo' => Ticket::TIPO_CLAIM])->id, 'estado' => ClaimSheet::ESTADO_COMPLETED]);

        $headers = $this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios');

        $reportes = $this->withHeaders($headers)->getJson('/api/servicios/reportes/panorama-mes')->assertStatus(200);
        $this->assertSame(2, $reportes->json('informes_pendientes'));

        // Mismo número que expone Tickets (TicketController::stats()) — nunca un cálculo paralelo.
        $this->makeCurrentTenant();
        $stats = $this->withHeaders($headers)->getJson('/api/servicios/tickets/stats')->assertStatus(200);
        $this->assertSame(2, $stats->json('informes_por_generar'));
    }

    /**
     * Selector de período (decisión de producto sin RN propio, ver docblock de `ReportsService`)
     * — `?year=&month=` explícito debe ignorar por completo los datos del mes en curso.
     */
    public function test_panorama_mes_acepta_year_month_explicito_y_excluye_el_mes_actual(): void
    {
        $user = User::where('email', self::LIDER_SERVICIOS)->firstOrFail();
        $service = app(TicketService::class);

        // Junio 2026: 2 reportados, 1 cerrado -> 50%.
        Carbon::setTestNow(Carbon::parse('2026-06-15 12:00:00', 'UTC'));
        $june = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED]);
        $this->makeTicket();
        $this->cerrarTicket($service, $june, $user);

        // Mes en curso (agosto, frozen por setUp): 1 reportado, 1 cerrado -> 100% si se incluyera.
        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW, 'UTC'));
        $august = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED]);
        $this->cerrarTicket($service, $august, $user);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/panorama-mes?year=2026&month=6')
            ->assertStatus(200);

        $this->assertEquals(50.0, $response->json('servicios_completados_pct'));
    }

    /** 422 automático (mismo patrón que `HomeController::updateInstallationGoal()`) para rangos inválidos. */
    public function test_panorama_mes_year_month_fuera_de_rango_retorna_422(): void
    {
        $headers = $this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($headers)->getJson('/api/servicios/reportes/panorama-mes?month=13')->assertStatus(422);
        $this->makeCurrentTenant();
        $this->withHeaders($headers)->getJson('/api/servicios/reportes/panorama-mes?year=2019')->assertStatus(422);
    }

    // ── REQ-281 — Instalaciones cotizadas vs realizadas ──────────────────────────────────────

    public function test_cotizadas_cuenta_solo_instalaciones_subtipo_inspeccion_con_cotizacion_del_mes(): void
    {
        $inspeccion = $this->makeTicket(['subtipo' => Ticket::SUBTIPO_INSPECTION]);
        $this->makeQuote($inspeccion, ServiceQuote::ESTADO_DRAFT, 1000.00);

        // Subtipo "Instalación" venta previa NUNCA requiere cotización — no debe contarse aunque
        // exista una fila de ServiceQuote asociada.
        $ventaPrevia = $this->makeTicket(['subtipo' => Ticket::SUBTIPO_INSTALLATION]);
        $this->makeQuote($ventaPrevia, ServiceQuote::ESTADO_DRAFT, 500.00);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/instalaciones-cotizadas-vs-realizadas')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('cotizadas.cantidad'));
        $this->assertEquals(1000.00, $response->json('cotizadas.total'));
    }

    /** Mismo patrón que `HomeService::ingresosInstalaciones()`. */
    public function test_realizadas_suma_total_de_cotizaciones_aprobadas_de_instalaciones_cerradas_este_mes(): void
    {
        $user = User::where('email', self::LIDER_SERVICIOS)->firstOrFail();
        $service = app(TicketService::class);

        $closed = $this->makeTicket(['subtipo' => Ticket::SUBTIPO_INSPECTION, 'estado' => Ticket::ESTADO_SCHEDULED]);
        $this->cerrarTicket($service, $closed, $user);
        $this->makeQuote($closed, ServiceQuote::ESTADO_APPROVED, 1500.00);
        // Borrador del mismo ticket — no debe sumar.
        $this->makeQuote($closed, ServiceQuote::ESTADO_DRAFT, 999.00);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/instalaciones-cotizadas-vs-realizadas')
            ->assertStatus(200);

        $this->assertSame(1, $response->json('realizadas.cantidad'));
        $this->assertEquals(1500.00, $response->json('realizadas.total'));
    }

    // ── REQ-282 — Distribución por tipo ───────────────────────────────────────────────────────

    public function test_distribucion_tipo_cuenta_los_4_tipos_reportados_este_mes_con_sus_colores(): void
    {
        $this->makeTicket();
        $this->makeTicket();
        $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/distribucion-tipo')
            ->assertStatus(200);

        $byTipo = collect($response->json('data'))->keyBy('tipo');
        $this->assertSame(2, $byTipo['installation']['count']);
        $this->assertSame(1, $byTipo['warranty']['count']);
        $this->assertSame(0, $byTipo['claim']['count']);
        $this->assertSame(0, $byTipo['retrofit']['count']);
        $this->assertSame('#5BA5A0', $byTipo['installation']['color']);
        $this->assertSame('#9fc54d', $byTipo['warranty']['color']);
    }

    /** Selector de período — `?year=&month=` explícito excluye por completo el mes en curso. */
    public function test_distribucion_tipo_acepta_year_month_explicito_y_excluye_el_mes_actual(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-05-10 12:00:00', 'UTC'));
        $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY]);
        $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY]);

        // Mes en curso (agosto) — no debe aparecer en la respuesta de mayo.
        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW, 'UTC'));
        $this->makeTicket();

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/distribucion-tipo?year=2026&month=5')
            ->assertStatus(200);

        $byTipo = collect($response->json('data'))->keyBy('tipo');
        $this->assertSame(2, $byTipo['warranty']['count']);
        $this->assertSame(0, $byTipo['installation']['count']);
    }

    // ── REQ-283 — Distribución por técnico ────────────────────────────────────────────────────

    public function test_distribucion_tecnico_cuenta_tickets_agendados_este_mes_por_tecnico(): void
    {
        $tecnicoId = $this->userIdByEmail(self::TECNICO);

        $this->makeTicket([
            'internal_technician_id' => $tecnicoId,
            'scheduled_at' => now()->addDays(2),
        ]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/distribucion-tecnico')
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $tecnico = InternalTechnician::where('email', self::TECNICO)->firstOrFail();
        $byId = collect($response->json('data'))->keyBy('id');

        $this->assertSame(1, $byId[$tecnico->id]['count']);
        $this->assertSame($tecnico->color, $byId[$tecnico->id]['color']);
        // Los 4 técnicos internos activos siempre aparecen, incluso sin tickets.
        $this->assertCount(4, $byId);
    }

    // ── REQ-284 — Completados en el año ───────────────────────────────────────────────────────

    public function test_completados_anio_desde_enero_hasta_mes_actual_con_desglose_por_tipo(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-03-15 15:00:00', 'UTC'));

        // Enero: 1 instalación cerrada.
        Carbon::setTestNow(Carbon::parse('2026-01-10 15:00:00', 'UTC'));
        $jan = $this->makeTicket(['estado' => Ticket::ESTADO_CLOSED]);
        $jan->closed_at = Carbon::parse('2026-01-10 15:00:00', 'UTC');
        $jan->save();

        // Febrero: 1 garantía cerrada.
        $feb = $this->makeTicket(['tipo' => Ticket::TIPO_WARRANTY, 'estado' => Ticket::ESTADO_CLOSED]);
        $feb->closed_at = Carbon::parse('2026-02-10 15:00:00', 'UTC');
        $feb->save();

        // Marzo (mes "actual" para este test): 1 reclamo resuelto.
        $mar = $this->makeTicket(['tipo' => Ticket::TIPO_CLAIM, 'estado' => Ticket::ESTADO_RESOLVED]);
        $mar->closed_at = Carbon::parse('2026-03-10 15:00:00', 'UTC');
        $mar->save();

        Carbon::setTestNow(Carbon::parse('2026-03-15 15:00:00', 'UTC'));

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/completados-anio')
            ->assertStatus(200);

        $data = collect($response->json('data'))->keyBy('mes');
        $this->assertCount(3, $data); // enero, febrero, marzo — nunca una ventana móvil.
        $this->assertSame(1, $data[1]['por_tipo']['installation']);
        $this->assertSame(1, $data[1]['total']);
        $this->assertSame(1, $data[2]['por_tipo']['warranty']);
        $this->assertSame(1, $data[3]['por_tipo']['claim']);
    }

    /**
     * Selector de período — un año PASADO ya cerró completo, así que RN1 ("enero → mes actual",
     * específica del año en curso) no aplica: siempre los 12 meses, incluso diciembre.
     */
    public function test_completados_anio_con_year_pasado_devuelve_los_12_meses_completos(): void
    {
        Carbon::setTestNow(Carbon::parse('2025-12-20 15:00:00', 'UTC'));
        $dec2025 = $this->makeTicket(['estado' => Ticket::ESTADO_CLOSED]);
        $dec2025->closed_at = Carbon::parse('2025-12-20 15:00:00', 'UTC');
        $dec2025->save();

        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW, 'UTC'));

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/completados-anio?year=2025')
            ->assertStatus(200);

        $data = collect($response->json('data'))->keyBy('mes');
        $this->assertCount(12, $data); // año pasado -> siempre los 12 meses, no "hasta el mes actual".
        $this->assertSame(1, $data[12]['por_tipo']['installation']);
        $this->assertSame(0, $data[6]['total']); // meses sin datos igual aparecen, en 0.
    }

    // ── REQ-285 — Comisión Tecnico Test ─────────────────────────────────────────────────────

    public function test_comision_tecnico_gerencia_puede_verla_y_coincide_con_el_endpoint_de_tecnicos(): void
    {
        $tecnico = InternalTechnician::where('email', self::TECNICO)->firstOrFail();

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/internal-technicians/{$tecnico->id}/commission-capture", [
                'year' => 2026, 'month' => 8,
                'satisfaccion_promedio' => 5.0, 'incidencias_puntualidad' => 0,
                'calificacion_actitud' => 4.0, 'calidad_pct' => 100,
            ])
            ->assertStatus(200);

        $mgmtHeaders = $this->authHeadersFor(self::MANAGEMENT, 'management');

        $direct = $this->withHeaders($mgmtHeaders)
            ->getJson("/api/servicios/internal-technicians/{$tecnico->id}/commission?year=2026&month=8")
            ->assertStatus(200);

        $this->makeCurrentTenant();
        $reportes = $this->withHeaders($mgmtHeaders)
            ->getJson('/api/servicios/reportes/comision-tecnico?year=2026&month=8')
            ->assertStatus(200);

        $this->assertEquals($direct->json('data.total'), $reportes->json('data.total'));
        $this->assertNotNull($reportes->json('data.total'));
    }

    public function test_comision_tecnico_el_propio_tecnico_puede_verla(): void
    {
        $this->withHeaders($this->authHeadersFor(self::TECNICO, 'tecnico'))
            ->getJson('/api/servicios/reportes/comision-tecnico')
            ->assertStatus(200);
    }

    /** RN2 — cualquier otro usuario (ej. Lider Servicios/lider_servicios) recibe 403, nunca un objeto vacío. */
    public function test_comision_tecnico_otro_usuario_recibe_403(): void
    {
        $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/comision-tecnico')
            ->assertStatus(403);
    }

    // ── REQ-286 — Biblioteca de documentos ────────────────────────────────────────────────────

    private function makeInspectionReport(Ticket $ticket, string $estado = InspectionReport::ESTADO_PENDING): InspectionReport
    {
        return InspectionReport::create([
            'numero' => 'INF-2026-'.static::uniqueTestNumber(),
            'ticket_id' => $ticket->id,
            'estado' => $estado,
        ]);
    }

    public function test_biblioteca_lista_informes_de_inspeccion_y_hojas_de_reclamo(): void
    {
        $ticketInforme = $this->makeTicket(['cliente' => 'Cliente Informe']);
        $this->makeInspectionReport($ticketInforme);

        $ticketReclamo = $this->makeTicket(['tipo' => Ticket::TIPO_CLAIM, 'cliente' => 'Cliente Reclamo']);
        ClaimSheet::create(['ticket_id' => $ticketReclamo->id, 'estado' => ClaimSheet::ESTADO_PENDING]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/biblioteca?per_page=20')
            ->assertStatus(200);

        $tipos = collect($response->json('data'))->pluck('tipo_documento')->unique()->sort()->values()->all();
        $this->assertSame(['claim_sheet', 'inspection_report'], $tipos);
        $this->assertArrayHasKey('total', $response->json('meta'));
    }

    public function test_biblioteca_filtra_por_tipo_documento(): void
    {
        $this->makeInspectionReport($this->makeTicket());
        ClaimSheet::create(['ticket_id' => $this->makeTicket(['tipo' => Ticket::TIPO_CLAIM])->id, 'estado' => ClaimSheet::ESTADO_PENDING]);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/biblioteca?tipo_documento=inspection_report&per_page=20')
            ->assertStatus(200);

        $tipos = collect($response->json('data'))->pluck('tipo_documento')->unique()->all();
        $this->assertSame(['inspection_report'], $tipos);
    }

    public function test_biblioteca_filtra_por_estado(): void
    {
        $this->makeInspectionReport($this->makeTicket(), InspectionReport::ESTADO_PENDING);
        $this->makeInspectionReport($this->makeTicket(), InspectionReport::ESTADO_COMPLETED);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/biblioteca?estado=completed&per_page=20')
            ->assertStatus(200);

        $estados = collect($response->json('data'))->pluck('estado')->unique()->all();
        $this->assertSame(['completed'], $estados);
    }

    public function test_biblioteca_filtra_por_texto_libre_de_cliente(): void
    {
        $match = $this->makeTicket(['cliente' => 'Constructora Panama SA']);
        $noMatch = $this->makeTicket(['cliente' => 'Otro Cliente']);
        $this->makeInspectionReport($match);
        $this->makeInspectionReport($noMatch);

        $response = $this->withHeaders($this->authHeadersFor(self::LIDER_SERVICIOS, 'lider_servicios'))
            ->getJson('/api/servicios/reportes/biblioteca?search=Panama&per_page=20')
            ->assertStatus(200);

        $clientes = collect($response->json('data'))->pluck('cliente')->all();
        $this->assertSame(['Constructora Panama SA'], $clientes);
    }
}
