<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Models\Tool;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 6 (REQ-255→260) — Técnicos internos. Mismos usuarios reales que
 * TicketFlowTest/ExternalTechnicianBatch5Test. Congela el reloj a un instante fijo en horario
 * laboral (10:00am hora de Panamá) para que el cálculo de estado (REQ-256) sea determinístico sin
 * importar la hora real en que corra la suite.
 */
class InternalTechnicianBatch6Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'servicio@illuminations.com.pa';

    private const TECNICO_SERVICIOS = 'carlos@illuminations.com.pa';

    // 2026-08-10 15:00:00 UTC == 2026-08-10 10:00:00 America/Panama (UTC-5).
    private const FROZEN_NOW = '2026-08-10 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        Carbon::setTestNow(Carbon::parse(self::FROZEN_NOW, 'UTC'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function panama(string $time): Carbon
    {
        return Carbon::parse('2026-08-10 '.$time, 'America/Panama')->utc();
    }

    /** @param array<string, mixed> $overrides */
    private function makeTicket(array $overrides = []): Ticket
    {
        return Ticket::create([
            'numero'                   => 'INS-2026-'.static::uniqueTestNumber(),
            'tipo'                     => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion'         => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion'              => 'Ticket de prueba',
            'cliente'                  => 'Cliente de prueba',
            'estado'                   => Ticket::ESTADO_SCHEDULED,
            'quote_status'             => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
            ...$overrides,
        ]);
    }

    private function carlosUserId(): int
    {
        /** @var InternalTechnician $carlos */
        $carlos = InternalTechnician::where('nombre', 'Carlos Vergara')->firstOrFail();

        return (int) $carlos->user_id;
    }

    // ── REQ-255 — Vista Equipo ───────────────────────────────────────────────────────────────

    public function test_index_lista_los_4_tecnicos_reales_sembrados(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians')
            ->assertStatus(200);

        $nombres = collect($response->json('data'))->pluck('nombre')->all();
        $this->assertContains('Carlos Vergara', $nombres);
        $this->assertContains('Miguel Castillo', $nombres);
    }

    /** pct_resuelto_primera_visita sigue siendo placeholder hasta REQ-211 (Batch 15). */
    public function test_index_trae_placeholder_de_pct_resuelto(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians')
            ->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertSame(0, $carlos['herramientas_asignadas']);
        $this->assertNull($carlos['pct_resuelto_primera_visita']);
    }

    /**
     * SCRUM-777 (rebote de Daniela 2026-08-19) — herramientas_asignadas quedó hardcodeado a 0
     * desde Batch 6 (docblock decía "Herramientas es Batch 13, no existe tools todavía") y nunca
     * se actualizó cuando Batch 13 sí creó tools.assigned_to_technician_id. Debe reflejar el
     * conteo real, sincronizado con Insumos y Herramientas.
     */
    public function test_herramientas_asignadas_refleja_el_conteo_real(): void
    {
        /** @var InternalTechnician $carlos */
        $carlos = InternalTechnician::where('nombre', 'Carlos Vergara')->firstOrFail();
        Tool::create([
            'nombre' => 'Taladro', 'codigo_unico' => 'TL-'.uniqid(), 'estado' => Tool::ESTADO_GOOD,
            'assigned_to_technician_id' => $carlos->id, 'assigned_since' => now()->toDateString(),
        ]);
        Tool::create([
            'nombre' => 'Escalera', 'codigo_unico' => 'TL-'.uniqid(), 'estado' => Tool::ESTADO_GOOD,
            'assigned_to_technician_id' => $carlos->id, 'assigned_since' => now()->toDateString(),
        ]);
        // Sin asignar — no debe contar para ningún técnico.
        Tool::create([
            'nombre' => 'Multímetro', 'codigo_unico' => 'TL-'.uniqid(), 'estado' => Tool::ESTADO_GOOD,
            'assigned_to_technician_id' => null, 'assigned_since' => now()->toDateString(),
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians')
            ->assertStatus(200);

        $row = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertSame(2, $row['herramientas_asignadas']);
    }

    public function test_tecnico_interno_puede_ver_vista_equipo(): void
    {
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians')
            ->assertStatus(200);
    }

    // ── REQ-256 — cálculo automático de estado ───────────────────────────────────────────────

    public function test_estado_ocupado_cuando_hay_visita_en_curso(): void
    {
        $userId = $this->carlosUserId();
        $this->makeTicket([
            'internal_technician_id' => $userId,
            'scheduled_at'           => $this->panama('09:45'),
            'scheduled_ends_at'      => $this->panama('10:30'),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/servicios/internal-technicians')->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertSame('on_site', $carlos['estado']);
    }

    public function test_estado_en_ruta_cuando_la_proxima_visita_empieza_dentro_de_una_hora(): void
    {
        $userId = $this->carlosUserId();
        $this->makeTicket([
            'internal_technician_id' => $userId,
            'scheduled_at'           => $this->panama('10:45'),
            'scheduled_ends_at'      => $this->panama('11:30'),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/servicios/internal-technicians')->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertSame('en_route', $carlos['estado']);
    }

    public function test_estado_disponible_cuando_la_proxima_visita_es_mas_tarde(): void
    {
        $userId = $this->carlosUserId();
        $this->makeTicket([
            'internal_technician_id' => $userId,
            'scheduled_at'           => $this->panama('14:00'),
            'scheduled_ends_at'      => $this->panama('15:00'),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/servicios/internal-technicians')->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertSame('available', $carlos['estado']);
    }

    public function test_estado_fuera_cuando_no_hay_mas_visitas_hoy(): void
    {
        $userId = $this->carlosUserId();
        $this->makeTicket([
            'internal_technician_id' => $userId,
            'scheduled_at'           => $this->panama('07:00'),
            'scheduled_ends_at'      => $this->panama('08:00'),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/servicios/internal-technicians')->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertSame('off', $carlos['estado']);
    }

    public function test_estado_fuera_cuando_esta_fuera_de_horario_laboral(): void
    {
        Carbon::setTestNow($this->panama('20:00')); // fuera de 08:00-18:00

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))->getJson('/api/servicios/internal-technicians')->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertSame('off', $carlos['estado']);
    }

    /** RN5 — no existe ningún endpoint para editar el estado a mano. */
    public function test_no_existe_endpoint_para_editar_estado_manualmente(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        // loginAs() ya disparó una request HTTP real, que limpia el search_path que setUp() dejó
        // activo (mismo gotcha documentado en TicketBatch4Test/ExternalTechnicianBatch5Test).
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $id = InternalTechnician::where('nombre', 'Carlos Vergara')->firstOrFail()->id;

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/internal-technicians/{$id}/estado", ['estado' => 'available'])
            ->assertStatus(404);
    }

    // ── REQ-257 — Modal "Visitas hoy" ─────────────────────────────────────────────────────────

    public function test_visitas_hoy_en_orden_cronologico(): void
    {
        $userId = $this->carlosUserId();
        $this->makeTicket([
            'internal_technician_id' => $userId, 'numero' => 'INS-2026-11111',
            'scheduled_at' => $this->panama('14:00'), 'scheduled_ends_at' => $this->panama('15:00'),
        ]);
        $this->makeTicket([
            'internal_technician_id' => $userId, 'numero' => 'INS-2026-22222',
            'scheduled_at' => $this->panama('09:00'), 'scheduled_ends_at' => $this->panama('09:30'),
        ]);

        $id    = InternalTechnician::where('nombre', 'Carlos Vergara')->firstOrFail()->id;
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/internal-technicians/{$id}/visitas-hoy")
            ->assertStatus(200);

        $numeros = collect($response->json('data'))->pluck('numero')->all();
        $this->assertSame(['INS-2026-22222', 'INS-2026-11111'], $numeros);
    }

    public function test_visitas_hoy_vacio_sin_ticket(): void
    {
        $id    = InternalTechnician::where('nombre', 'Miguel Castillo')->firstOrFail()->id;
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/internal-technicians/{$id}/visitas-hoy")
            ->assertStatus(200);

        $this->assertSame([], $response->json('data'));
    }

    public function test_visitas_hoy_de_tecnico_inexistente_retorna_404(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/999999/visitas-hoy')
            ->assertStatus(404);
    }

    // ── REQ-259 — Registrar nuevo técnico interno ────────────────────────────────────────────

    public function test_registrar_tecnico_interno(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/internal-technicians', [
                'nombre'       => 'Luis Fernández',
                'especialidad' => InternalTechnician::ESPECIALIDAD_GENERAL,
            ])
            ->assertStatus(201);

        $response->assertJsonPath('estado', 'available');
        $this->assertNotNull($response->json('color'));
    }

    /**
     * SCRUM-322 — regresión real reportada por QA (marly.rangel, 2026-08-10): el POST respondía
     * 'available' (hardcodeado), pero un GET /internal-technicians inmediatamente después (lo que
     * la tarjeta de Vista Equipo realmente muestra tras refrescar) devolvía 'off' para SIEMPRE,
     * porque `TechnicianStatusService::statusFor()` forzaba "Fuera" a cualquier técnico sin
     * `user_id` — y un técnico dado de alta por REQ-259 nunca tiene `user_id` (no reusa el flujo
     * de Usuarios). No alcanza con verificar el 201 de creación (ver
     * test_registrar_tecnico_interno arriba, que no detectó este bug) — hay que verificar la
     * vista Equipo real después.
     */
    public function test_tecnico_recien_creado_aparece_disponible_en_vista_equipo(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/internal-technicians', [
                'nombre' => 'QA Tecnico Regresion', 'especialidad' => InternalTechnician::ESPECIALIDAD_GENERAL,
            ])
            ->assertStatus(201)
            ->assertJsonPath('estado', 'available');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians')
            ->assertStatus(200);

        $nuevo = collect($response->json('data'))->firstWhere('nombre', 'QA Tecnico Regresion');
        $this->assertNotNull($nuevo, 'El técnico recién creado debe aparecer en la Vista Equipo.');
        $this->assertSame('available', $nuevo['estado']);
        $this->assertSame(0, $nuevo['visitas_hoy']);
    }

    /** Fuera de horario laboral, un técnico recién creado (sin visitas, sin user_id) es 'off' — consistente con cualquier técnico sin agenda, no un caso especial. */
    public function test_tecnico_recien_creado_fuera_de_horario_laboral_es_fuera(): void
    {
        Carbon::setTestNow($this->panama('20:00'));

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/internal-technicians', [
                'nombre' => 'QA Tecnico Nocturno', 'especialidad' => InternalTechnician::ESPECIALIDAD_GENERAL,
            ])
            ->assertStatus(201)
            ->assertJsonPath('estado', 'off');
    }

    public function test_registrar_sin_nombre_retorna_422(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/internal-technicians', ['especialidad' => InternalTechnician::ESPECIALIDAD_GENERAL])
            ->assertStatus(422);
    }

    /** Escenario 2 (REQ-259) — color único incluso dentro de la misma especialidad. */
    public function test_color_unico_incluso_en_misma_especialidad(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $r1 = $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/internal-technicians', [
                'nombre' => 'Técnico Garantías Dos', 'especialidad' => InternalTechnician::ESPECIALIDAD_WARRANTY,
            ])->assertStatus(201);

        // Miguel Castillo (seed real) ya tiene ámbar — el nuevo NO debe recibir el mismo color.
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $this->assertNotSame(
            InternalTechnician::where('nombre', 'Miguel Castillo')->firstOrFail()->color,
            $r1->json('color'),
        );
    }

    public function test_tecnico_interno_no_puede_registrar_otro_tecnico(): void
    {
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->postJson('/api/servicios/internal-technicians', [
                'nombre' => 'X', 'especialidad' => InternalTechnician::ESPECIALIDAD_GENERAL,
            ])
            ->assertStatus(403);
    }

    // ── REQ-260 — Agenda equipo ───────────────────────────────────────────────────────────────

    public function test_agenda_agrupa_visitas_por_tecnico(): void
    {
        $userId = $this->carlosUserId();
        $this->makeTicket([
            'internal_technician_id' => $userId,
            'scheduled_at' => $this->panama('14:00'), 'scheduled_ends_at' => $this->panama('15:00'),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/agenda')
            ->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertCount(1, $carlos['visitas']);
    }

    /** Escenario 2 (REQ-260) — técnico sin visitas hoy trae array vacío (RN3, mensaje lo resuelve el frontend). */
    public function test_agenda_tecnico_sin_visitas_trae_array_vacio(): void
    {
        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/agenda')
            ->assertStatus(200);

        $miguel = collect($response->json('data'))->firstWhere('nombre', 'Miguel Castillo');
        $this->assertSame([], $miguel['visitas']);
    }

    public function test_agenda_filtra_por_technician_id(): void
    {
        $carlosId = InternalTechnician::where('nombre', 'Carlos Vergara')->firstOrFail()->id;

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/internal-technicians/agenda?technician_id={$carlosId}")
            ->assertStatus(200);

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Carlos Vergara', $response->json('data.0.nombre'));
    }

    /** RN5 (REQ-260) — Vendedor/Diseñador tiene acceso de solo vista. */
    public function test_vendedor_disenador_puede_ver_agenda_equipo(): void
    {
        $token = $this->loginAs('milena.e@grupolafayette.com', 'milena.e@grupolafayette.com', 'vendedor_disenador');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/agenda')
            ->assertStatus(200);
    }

    // ── SCRUM-803 — Agenda equipo con vistas Día/Semana/Mes ─────────────────────────────────────

    /** FROZEN_NOW es lunes 2026-08-10 (semana domingo 09 → sábado 15, mismo mes agosto). */
    public function test_agenda_vista_day_no_incluye_visitas_de_otro_dia_de_la_semana(): void
    {
        $userId = $this->carlosUserId();
        $this->makeTicket([
            'internal_technician_id' => $userId,
            'scheduled_at' => Carbon::parse('2026-08-13 14:00', 'America/Panama')->utc(),
            'scheduled_ends_at' => Carbon::parse('2026-08-13 15:00', 'America/Panama')->utc(),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/agenda?view=day')
            ->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertSame([], $carlos['visitas']);
    }

    public function test_agenda_vista_week_incluye_visitas_de_toda_la_semana(): void
    {
        $userId = $this->carlosUserId();
        $this->makeTicket([
            'internal_technician_id' => $userId,
            'scheduled_at' => Carbon::parse('2026-08-13 14:00', 'America/Panama')->utc(),
            'scheduled_ends_at' => Carbon::parse('2026-08-13 15:00', 'America/Panama')->utc(),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/agenda?view=week')
            ->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertCount(1, $carlos['visitas']);
        $this->assertSame('2026-08-13', $carlos['visitas'][0]['fecha']);
    }

    public function test_agenda_vista_week_no_incluye_visitas_de_otra_semana(): void
    {
        $userId = $this->carlosUserId();
        $this->makeTicket([
            'internal_technician_id' => $userId,
            'scheduled_at' => Carbon::parse('2026-08-20 14:00', 'America/Panama')->utc(),
            'scheduled_ends_at' => Carbon::parse('2026-08-20 15:00', 'America/Panama')->utc(),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/agenda?view=week')
            ->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertSame([], $carlos['visitas']);
    }

    public function test_agenda_vista_month_incluye_visitas_de_todo_el_mes(): void
    {
        $userId = $this->carlosUserId();
        $this->makeTicket([
            'internal_technician_id' => $userId,
            'scheduled_at' => Carbon::parse('2026-08-28 09:00', 'America/Panama')->utc(),
            'scheduled_ends_at' => Carbon::parse('2026-08-28 10:00', 'America/Panama')->utc(),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/agenda?view=month')
            ->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertCount(1, $carlos['visitas']);
    }

    public function test_agenda_vista_month_no_incluye_visitas_de_otro_mes(): void
    {
        $userId = $this->carlosUserId();
        $this->makeTicket([
            'internal_technician_id' => $userId,
            'scheduled_at' => Carbon::parse('2026-09-01 09:00', 'America/Panama')->utc(),
            'scheduled_ends_at' => Carbon::parse('2026-09-01 10:00', 'America/Panama')->utc(),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/agenda?view=month')
            ->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertSame([], $carlos['visitas']);
    }

    public function test_agenda_vista_month_con_date_navega_a_otro_mes(): void
    {
        $userId = $this->carlosUserId();
        $this->makeTicket([
            'internal_technician_id' => $userId,
            'scheduled_at' => Carbon::parse('2026-09-15 09:00', 'America/Panama')->utc(),
            'scheduled_ends_at' => Carbon::parse('2026-09-15 10:00', 'America/Panama')->utc(),
        ]);

        $token    = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/agenda?view=month&date=2026-09-01')
            ->assertStatus(200);

        $carlos = collect($response->json('data'))->firstWhere('nombre', 'Carlos Vergara');
        $this->assertCount(1, $carlos['visitas']);
    }
}
