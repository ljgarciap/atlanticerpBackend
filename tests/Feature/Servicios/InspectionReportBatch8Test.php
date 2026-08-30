<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\InspectionReport;
use App\Modules\Servicios\Models\Ticket;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 8 (REQ-238→243, SCRUM-301→306). Regresión de 2 hallazgos reales de
 * Pre-QA (2026-08-10, docs/pre-qa/scrum301-306-servicios-batch8-informe-inspeccion-20260810.md):
 *
 * 1) REQ-240 RN2 — una línea de `materiales` vacía (dejada por "+ Agregar material usado" sin
 *    completar) rechazaba TODO el guardado con 422 en vez de omitirse en silencio. La UI manda el
 *    array completo tal cual, así que era 100% alcanzable en el flujo real.
 * 2) REQ-238 RN1 — doble submit real (2 requests concurrentes sobre un ticket sin informe
 *    todavía) producía un 500 con stack trace expuesto (`UniqueConstraintViolationException`
 *    sobre `servicios_inspection_reports_ticket_uq`) en vez de actualizar la fila que el otro
 *    request ya creó. La concurrencia real no es reproducible de forma determinística en PHPUnit
 *    (proceso único, sin threads) — cubierta en vivo con curl concurrente durante Pre-QA; acá se
 *    cubre el camino secuencial (guardar dos veces seguidas no debe duplicar el folio ni la fila).
 */
class InspectionReportBatch8Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'servicio@illuminations.com.pa';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $overrides */
    private function makeTicket(array $overrides = []): Ticket
    {
        return Ticket::create([
            'numero'                   => 'INS-2026-'.static::uniqueTestNumber(),
            'tipo'                     => Ticket::TIPO_INSTALLATION,
            'subtipo'                  => Ticket::SUBTIPO_INSPECTION,
            'tipo_instalacion'         => Ticket::TIPO_INSTALACION_INTERNAL,
            'descripcion'              => 'Ticket de prueba — Pre-QA Batch 8',
            'cliente'                  => 'Cliente de prueba',
            'estado'                   => Ticket::ESTADO_ON_SITE,
            'quote_status'             => Ticket::QUOTE_STATUS_NOT_APPLICABLE,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
            ...$overrides,
        ]);
    }

    public function test_materiales_con_linea_vacia_se_omite_en_vez_de_rechazar_el_guardado(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", [
                'conclusion'  => 'Conclusión de prueba',
                'materiales'  => [
                    ['nombre_material' => 'Cinta aislante', 'cantidad' => '2'],
                    ['nombre_material' => '', 'cantidad' => ''],
                    ['nombre_material' => 'Conectores Wago', 'cantidad' => '5'],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonCount(2, 'materiales');
        $response->assertJsonPath('materiales.0.nombre_material', 'Cinta aislante');
        $response->assertJsonPath('materiales.1.nombre_material', 'Conectores Wago');
    }

    public function test_hallazgo_adicional_vacio_no_se_guarda(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", [
                'conclusion' => 'Conclusión de prueba',
                'findings'   => [
                    ['label' => 'Extra 1', 'value' => '   ', 'is_additional' => true],
                    ['label' => 'Extra 2', 'value' => 'Hallazgo real', 'is_additional' => true],
                ],
            ]);

        $response->assertStatus(200);
        $response->assertJsonCount(1, 'findings');
        $response->assertJsonPath('findings.0.label', 'Extra 2');
    }

    public function test_conclusion_vacia_o_solo_espacios_rechaza_con_422(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", ['conclusion' => '   '])
            ->assertStatus(422);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", [])
            ->assertStatus(422);
    }

    public function test_guardar_dos_veces_seguidas_actualiza_el_mismo_informe_sin_duplicar_folio(): void
    {
        $ticket = $this->makeTicket();
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $first = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", ['conclusion' => 'Primer guardado'])
            ->assertStatus(200);

        $second = $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", ['conclusion' => 'Segundo guardado'])
            ->assertStatus(200);

        self::assertSame($first->json('id'), $second->json('id'));
        self::assertSame($first->json('numero'), $second->json('numero'));
        self::assertSame('Segundo guardado', $second->json('conclusion'));

        // El request HTTP anterior corre en su propio ciclo de tenancy — reestablecer el tenant
        // actual antes de consultar directo con Eloquent (mismo patrón que TicketFlowTest).
        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        self::assertSame(1, InspectionReport::where('ticket_id', $ticket->id)->count());
    }

    public function test_completar_informe_actualiza_inspection_report_status_del_ticket_req218(): void
    {
        $ticket = $this->makeTicket(['quote_status' => Ticket::QUOTE_STATUS_NOT_APPLICABLE]);
        $token  = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        self::assertSame(Ticket::INSPECTION_STATUS_PENDING, $ticket->inspection_report_status);

        $this->withHeaders($this->authHeaders($token))
            ->putJson("/api/servicios/tickets/{$ticket->id}/inspection-report", ['conclusion' => 'Listo'])
            ->assertStatus(200);

        Tenant::where('slug', 'illuminations')->first()?->makeCurrent();
        $ticket->refresh();
        self::assertSame(Ticket::INSPECTION_STATUS_COMPLETED, $ticket->inspection_report_status);

        // Gate REQ-218 — ambas condiciones satisfechas, el ticket ahora sí puede cerrarse.
        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => Ticket::ESTADO_RESOLVED])
            ->assertStatus(200);
    }
}
