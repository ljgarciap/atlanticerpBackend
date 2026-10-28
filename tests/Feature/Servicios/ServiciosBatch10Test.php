<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\InternalTechnician;
use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\ServiciosSettingsService;
use App\Shared\Multitenancy\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 10 (REQ-251→254 Informe móvil + REQ-258 Comisión Tecnico Test,
 * decisión de Luis 2026-08-11). Cubre lo más propenso a bugs reales de este batch: la fórmula
 * ponderada de comisión (RN1/RN5/RN6) y el gap de validación real encontrado en Senior Review —
 * `saveMobile()` decodifica el payload a mano (JSON string dentro de multipart) y por eso NO pasa
 * por `SaveInspectionReportRequest` automáticamente, había que revalidar explícito.
 */
class ServiciosBatch10Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const TECNICO_CARLOS = 'tecnico@test.com';

    private const MANAGEMENT = 'gerencia@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        // Mismo motivo que TicketBatch4Test — sin esto, saveMobile() intenta escribir a S3 real
        // (el disco s3-private no está configurado en CI, solo en local contra el bucket real
        // atlanticerp-dev, ver CLAUDE.md "Local S3 conectado").
        Storage::fake('s3-private');
    }

    private function carlos(): InternalTechnician
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();

        return InternalTechnician::where('nombre', 'Tecnico Test')->firstOrFail();
    }

    /** @param array<string, mixed> $overrides */
    private function makeTicket(array $overrides = []): Ticket
    {
        return Ticket::create([
            'numero' => 'INS-2026-'.static::uniqueTestNumber(),
            'tipo' => Ticket::TIPO_INSTALLATION,
            'subtipo' => Ticket::SUBTIPO_INSPECTION,
            'tipo_instalacion' => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion' => 'Ticket de prueba — Batch 10',
            'cliente' => 'Cliente de prueba',
            'estado' => Ticket::ESTADO_ON_SITE,
            'quote_status' => Ticket::QUOTE_STATUS_NOT_APPLICABLE,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
            ...$overrides,
        ]);
    }

    // ── REQ-258 — Comisión ponderada ──────────────────────────────────────────────────────────

    /** RN1 — sin captura del período, "Pendiente de captura" (mismo criterio que REQ-292 RN6). */
    public function test_comision_sin_captura_retorna_null(): void
    {
        $token = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');
        $id = $this->carlos()->id;

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/internal-technicians/{$id}/commission?year=2020&month=1")
            ->assertStatus(200)
            ->assertJsonPath('data', null);
    }

    /**
     * RN1/RN3/RN6 — total ponderado con SLA=0% (sin tickets cerrados en el período, caso
     * determinístico) y Calidad capturada manualmente (RN2, sin auto-sugerencia en este batch).
     * Con los defaults reales del plan (25/25/25/15/10 → $100/$100/$100/$60/$40): Calidad 100% +
     * Satisfacción 100% + SLA 0% (sin tickets) + Puntualidad 100% + Actitud 80% =
     * 100+100+0+60+32 = $292.
     */
    public function test_total_ponderado_con_defaults_del_plan_real(): void
    {
        $carlos = $this->carlos();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/internal-technicians/{$carlos->id}/commission-capture", [
                'year' => 2026, 'month' => 8,
                'satisfaccion_promedio' => 5.0, 'incidencias_puntualidad' => 0,
                'calificacion_actitud' => 4.0, 'calidad_pct' => 100,
            ])
            ->assertStatus(200);

        $mgmtToken = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');
        $response = $this->withHeaders($this->authHeaders($mgmtToken))
            ->getJson("/api/servicios/internal-technicians/{$carlos->id}/commission?year=2026&month=8")
            ->assertStatus(200);

        $response->assertJsonPath('data.total', 292);
        $response->assertJsonPath('data.capture.calidad_pct', 100);
    }

    /**
     * RN5 — licencia médica pondera Puntualidad a 0% y redistribuye su monto ($60) proporcionalmente
     * entre los otros 4 según sus propios montos configurados: Calidad/Satisfacción/SLA a $100 c/u y
     * Actitud a $40 (suma de los 4 = $340), no un reparto igualitario.
     *
     * Satisfacción 1.0 (< umbral 3.5) → 0%. Actitud 1.0 (mínimo válido, min:1 en la Request) → 20%
     * (1/5 × 100, no 0% — la escala no tiene un piso de 0). SLA → 0% (sin tickets en el período).
     * Calidad capturada directo en 100%.
     *
     * Montos ajustados = monto_max + 60 × (monto_max / 340):
     *   Calidad/Satisfacción/SLA → 100 + 60×(100/340) = 117.6470... → $117.65
     *   Actitud                  → 40 + 60×(40/340)   = 47.0588...  → $47.06
     * Total = Calidad 100%×117.65 + Satisfacción 0% + SLA 0% + Puntualidad 0% (forzado) +
     *         Actitud 20%×47.06 = 117.65 + 9.41 = $127.06.
     */
    public function test_licencia_medica_redistribuye_el_monto_de_puntualidad(): void
    {
        $carlos = $this->carlos();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/internal-technicians/{$carlos->id}/commission-capture", [
                'year' => 2026, 'month' => 9,
                'satisfaccion_promedio' => 1.0, 'incidencias_puntualidad' => 5,
                'calificacion_actitud' => 1.0, 'calidad_pct' => 100, 'licencia_medica' => true,
            ])
            ->assertStatus(200);

        $mgmtToken = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');
        $response = $this->withHeaders($this->authHeaders($mgmtToken))
            ->getJson("/api/servicios/internal-technicians/{$carlos->id}/commission?year=2026&month=9")
            ->assertStatus(200);

        $response->assertJsonPath('data.total', 127.06);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $desglose = collect($response->json('data.desglose'))->keyBy('criterio');
        $this->assertSame(0, $desglose['puntualidad']['pct_obtenido']);
        $this->assertSame(117.65, $desglose['calidad']['monto_max']);
        $this->assertSame(47.06, $desglose['actitud']['monto_max']);
    }

    /** RN8 — exclusivo del propio técnico o Gerencia; Lider Servicios (lider_servicios) puede CAPTURAR pero no ver el resultado. */
    public function test_aaron_no_puede_ver_el_resultado_calculado(): void
    {
        $id = $this->carlos()->id;
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/internal-technicians/{$id}/commission")
            ->assertStatus(403);
    }

    public function test_carlos_puede_ver_su_propio_resultado(): void
    {
        $id = $this->carlos()->id;
        $token = $this->loginAs(self::TECNICO_CARLOS, self::TECNICO_CARLOS, 'tecnico');

        $this->withHeaders($this->authHeaders($token))
            ->getJson("/api/servicios/internal-technicians/{$id}/commission")
            ->assertStatus(200);
    }

    /** Los pesos/montos son configurables (decisión de Luis 2026-08-11) — no constantes de código. */
    public function test_montos_de_comision_son_configurables_via_ajustes(): void
    {
        $mgmtToken = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');

        $this->withHeaders($this->authHeaders($mgmtToken))
            ->patchJson('/api/servicios/settings', ['commission_monto_max_calidad' => 200])
            ->assertStatus(200)
            ->assertJsonFragment(['key' => 'commission_monto_max_calidad', 'value' => 200]);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $this->assertSame(200, app(ServiciosSettingsService::class)->commissionMontoMax('calidad'));
    }

    public function test_lider_servicios_no_puede_editar_ajustes(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/servicios/settings', ['sin_agendar_umbral_dias' => 5])
            ->assertStatus(403);
    }

    // ── REQ-251→254 — Informe móvil ───────────────────────────────────────────────────────────

    public function test_save_mobile_sin_conclusion_retorna_422(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->post("/api/servicios/tickets/{$ticket->id}/inspection-report/mobile", [
                'payload' => json_encode(['firma_cliente_nombre' => 'Cliente de prueba']),
                'firma_cliente_imagen' => UploadedFile::fake()->image('firma.png', 100, 60),
            ])
            ->assertStatus(422);
    }

    public function test_save_mobile_sin_nombre_de_firma_retorna_422(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->post("/api/servicios/tickets/{$ticket->id}/inspection-report/mobile", [
                'payload' => json_encode(['conclusion' => 'Todo en orden']),
                'firma_cliente_imagen' => UploadedFile::fake()->image('firma.png', 100, 60),
            ])
            ->assertStatus(422);
    }

    /** RN1 REQ-254 — completa el informe en el MISMO registro, con la firma gráfica real. */
    public function test_save_mobile_completa_el_informe_con_firma_grafica(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->post("/api/servicios/tickets/{$ticket->id}/inspection-report/mobile", [
                'payload' => json_encode([
                    'conclusion' => 'Instalación completada en sitio.',
                    'firma_cliente_nombre' => 'Cliente de prueba',
                ]),
                'firma_cliente_imagen' => UploadedFile::fake()->image('firma.png', 100, 60),
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('estado', 'completed');
        $response->assertJsonPath('firma_cliente_nombre', 'Cliente de prueba');
        $this->assertNotNull($response->json('firma_cliente_imagen_url'));

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $ticket->refresh();
        $this->assertSame(Ticket::INSPECTION_STATUS_COMPLETED, $ticket->inspection_report_status);
    }

    /**
     * SCRUM-316 (rebote QA 2026-08-14) — RN4 ("no se puede modificar una firma ya confirmada")
     * solo se hacía cumplir dentro de la sesión que acababa de firmar; reentrar en una sesión
     * nueva reemplazaba la firma del cliente en silencio. Reproducido con 2 llamadas reales a
     * `saveMobile()` sobre el mismo ticket, la segunda con una imagen y un nombre distintos.
     */
    public function test_save_mobile_rechaza_reemplazar_una_firma_ya_confirmada(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->post("/api/servicios/tickets/{$ticket->id}/inspection-report/mobile", [
                'payload' => json_encode([
                    'conclusion' => 'Instalación completada en sitio.',
                    'firma_cliente_nombre' => 'Cliente Original',
                ]),
                'firma_cliente_imagen' => UploadedFile::fake()->image('firma.png', 100, 60),
            ])->assertStatus(200);

        $response = $this->withHeaders($this->authHeaders($token))
            ->post("/api/servicios/tickets/{$ticket->id}/inspection-report/mobile", [
                'payload' => json_encode([
                    'conclusion' => 'Instalación completada en sitio.',
                    'firma_cliente_nombre' => 'Otra Persona',
                ]),
                'firma_cliente_imagen' => UploadedFile::fake()->image('firma2.png', 100, 60),
            ]);
        $response->assertStatus(409);

        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
        $report = InspectionReport::where('ticket_id', $ticket->id)->firstOrFail();
        $this->assertSame('Cliente Original', $report->firma_cliente_nombre);
    }

    public function test_tecnico_no_asignado_no_puede_completar_informe_movil(): void
    {
        $ticket = $this->makeTicket(); // sin internal_technician_id asignado a Tecnico
        $token = $this->loginAs(self::TECNICO_CARLOS, self::TECNICO_CARLOS, 'tecnico');

        $this->withHeaders($this->authHeaders($token))
            ->post("/api/servicios/tickets/{$ticket->id}/inspection-report/mobile", [
                'payload' => json_encode([
                    'conclusion' => 'x', 'firma_cliente_nombre' => 'Cliente',
                ]),
                'firma_cliente_imagen' => UploadedFile::fake()->image('firma.png', 100, 60),
            ])
            ->assertStatus(403);
    }
}
