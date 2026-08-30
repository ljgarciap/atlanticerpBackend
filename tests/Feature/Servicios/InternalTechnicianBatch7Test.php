<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\CommissionCapture;
use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\Ticket;
use App\Shared\Multitenancy\Tenant;
use Carbon\Carbon;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 7 (REQ-261/262/292). Mismos usuarios reales y mismo patrón de reloj
 * congelado que InternalTechnicianBatch6Test.
 */
class InternalTechnicianBatch7Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'servicio@atlantic.com.pa';

    private const TECNICO_SERVICIOS = 'carlos@atlantic.com.pa';

    private const MANAGEMENT = 'daniela@atlantic.com.pa';

    // 2026-08-10 15:00:00 UTC == 2026-08-10 10:00:00 America/Panama (UTC-5), dentro de horario laboral.
    private const FROZEN_NOW = '2026-08-10 15:00:00';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
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

    private function carlosId(): int
    {
        // Cualquier request HTTP previa (loginAs()) limpia el search_path que setUp() dejó
        // activo — reactivar siempre acá, sea o no la primera llamada del test (mismo gotcha
        // documentado en TicketBatch4Test/ExternalTechnicianBatch5Test).
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return InternalTechnician::where('nombre', 'Carlos Vergara')->firstOrFail()->id;
    }

    // ── REQ-261 — Estadísticas generales del equipo ──────────────────────────────────────────

    public function test_stats_activos_y_promedio_con_4_tecnicos_sin_visitas(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/stats')
            ->assertStatus(200);

        $response->assertJsonPath('total_tecnicos', 4);
        $response->assertJsonPath('activos_hoy', 4); // sin visitas hoy pero en horario laboral => "available"
        $response->assertJsonPath('visitas_hoy_total', 0);
        // RN3 — ningún técnico tiene pct_resuelto_primera_visita todavía (Batch 15/REQ-211 pendiente).
        $response->assertJsonPath('promedio_primera_visita', null);
    }

    public function test_stats_suma_visitas_de_todo_el_equipo(): void
    {
        Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(), 'tipo' => Ticket::TIPO_INSTALLATION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL, 'descripcion' => 'x', 'cliente' => 'x',
            'estado' => Ticket::ESTADO_SCHEDULED, 'quote_status' => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
            'internal_technician_id' => InternalTechnician::where('nombre', 'Carlos Vergara')->firstOrFail()->user_id,
            'scheduled_at' => $this->panama('14:00'), 'scheduled_ends_at' => $this->panama('15:00'),
        ]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/stats')
            ->assertStatus(200)
            ->assertJsonPath('visitas_hoy_total', 1);
    }

    public function test_stats_fuera_de_horario_nadie_esta_activo(): void
    {
        Carbon::setTestNow($this->panama('20:00'));

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/stats')
            ->assertStatus(200)
            ->assertJsonPath('activos_hoy', 0);
    }

    // ── REQ-292 — Captura mensual de comisión ─────────────────────────────────────────────────

    public function test_captura_pendiente_sin_registro(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $id    = $this->carlosId();

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/internal-technicians/{$id}/commission-capture")
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    /** Escenario 1 (REQ-292) — conversión automática al guardar. */
    public function test_guardar_captura_convierte_satisfaccion_y_puntualidad(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $id    = $this->carlosId();

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/internal-technicians/{$id}/commission-capture", [
                'year' => 2026, 'month' => 8,
                'satisfaccion_promedio' => 4.7, 'incidencias_puntualidad' => 0, 'calificacion_actitud' => 4.0,
            ])
            ->assertStatus(200);

        $response->assertJsonPath('data.satisfaccion_pct', 100);
        $response->assertJsonPath('data.puntualidad_pct', 100);
        $response->assertJsonPath('data.actitud_pct', 80);
    }

    public function test_captura_guardada_se_puede_consultar_despues(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $id    = $this->carlosId();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/internal-technicians/{$id}/commission-capture", [
                'year' => 2026, 'month' => 8,
                'satisfaccion_promedio' => 4.0, 'incidencias_puntualidad' => 1, 'calificacion_actitud' => 3.5,
            ])
            ->assertStatus(200);

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/internal-technicians/{$id}/commission-capture?year=2026&month=8")
            ->assertStatus(200)
            ->assertJsonPath('data.puntualidad_pct', 50);
    }

    /** RN5 — editar un registro existente marca updated_by. */
    public function test_editar_captura_existente_registra_quien_edito(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $id    = $this->carlosId();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/internal-technicians/{$id}/commission-capture", [
                'year' => 2026, 'month' => 8,
                'satisfaccion_promedio' => 4.0, 'incidencias_puntualidad' => 0, 'calificacion_actitud' => 4.0,
            ])->assertStatus(200);

        $managementToken = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');
        $this->withHeaders($this->authHeaders($managementToken))
            ->putJson("/api/servicios/internal-technicians/{$id}/commission-capture", [
                'year' => 2026, 'month' => 8,
                'satisfaccion_promedio' => 5.0, 'incidencias_puntualidad' => 0, 'calificacion_actitud' => 5.0,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.updated_by', 'Daniela');

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(1, CommissionCapture::where('internal_technician_id', $id)->count());
    }

    public function test_tecnico_interno_no_puede_capturar_comision(): void
    {
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico_servicios');
        $id    = $this->carlosId();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/internal-technicians/{$id}/commission-capture", [
                'year' => 2026, 'month' => 8,
                'satisfaccion_promedio' => 4.0, 'incidencias_puntualidad' => 0, 'calificacion_actitud' => 4.0,
            ])
            ->assertStatus(403);
    }

    /** REQ-258 RN7 — hoy solo Carlos Vergara tiene plan de bonificación activo. */
    public function test_tecnico_sin_plan_de_bonificacion_retorna_422(): void
    {
        $token   = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $miguelId = InternalTechnician::where('nombre', 'Miguel Castillo')->firstOrFail()->id;

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/internal-technicians/{$miguelId}/commission-capture")
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/internal-technicians/{$miguelId}/commission-capture", [
                'year' => 2026, 'month' => 8,
                'satisfaccion_promedio' => 4.0, 'incidencias_puntualidad' => 0, 'calificacion_actitud' => 4.0,
            ])
            ->assertStatus(422);
    }

    public function test_captura_con_valores_invalidos_retorna_422(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $id    = $this->carlosId();

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/internal-technicians/{$id}/commission-capture", [
                'year' => 2026, 'month' => 8,
                'satisfaccion_promedio' => 6, // fuera de la escala 1-5
                'incidencias_puntualidad' => 0, 'calificacion_actitud' => 4.0,
            ])
            ->assertStatus(422);
    }

    // ── REQ-292 RN2 — SLA por tipo de ticket ──────────────────────────────────────────────────

    public function test_sla_settings_trae_defaults(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/internal-technicians/sla-settings')
            ->assertStatus(200)
            ->assertJsonPath('data.claim', 2)
            ->assertJsonPath('data.warranty', 3)
            ->assertJsonPath('data.installation', 7)
            ->assertJsonPath('data.retrofit', 10);
    }

    /** Escenario 2 (REQ-292) — SLA configurable por Gerencia. */
    public function test_gerencia_puede_ajustar_sla(): void
    {
        $token = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/servicios/internal-technicians/sla-settings', ['warranty' => 4])
            ->assertStatus(200)
            ->assertJsonPath('data.warranty', 4)
            ->assertJsonPath('data.claim', 2); // el resto no cambia
    }

    public function test_lider_servicios_no_puede_ajustar_sla(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/servicios/internal-technicians/sla-settings', ['warranty' => 4])
            ->assertStatus(403);
    }
}
