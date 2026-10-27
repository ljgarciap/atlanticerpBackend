<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Modules\Servicios\Models\Ticket;
use App\Modules\Servicios\Services\TicketTypes;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * Fase 4 — Servicios, Batch 3, parte 1 (REQ-227 Cancelar ticket, REQ-228 Ver/Imprimir PDF).
 * Mismos usuarios reales que TicketBatch2Test.
 */
class TicketBatch3Test extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const TECNICO_SERVICIOS = 'tecnicoservicios@test.com';

    private const MANAGEMENT = 'gerencia@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    /** @param array<string, mixed> $overrides */
    private function makeTicket(array $overrides = []): Ticket
    {
        return Ticket::create([
            'numero' => TicketTypes::prefijo($overrides['tipo'] ?? Ticket::TIPO_INSTALLATION).'-2026-'.static::uniqueTestNumber(),
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
    }

    private function makeCurrentTenant(): void
    {
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    // ── REQ-227 — Cancelar ticket ────────────────────────────────────────────────────────────

    public function test_cancelar_exitoso_cambia_estado_y_guarda_motivo(): void
    {
        $ticket = $this->makeTicket(['estado' => Ticket::ESTADO_SCHEDULED]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/cancelar", ['motivo' => 'Cliente ya no requiere el servicio'])
            ->assertStatus(200);

        $response->assertJsonPath('estado', Ticket::ESTADO_CANCELLED);
        $response->assertJsonPath('cancellation_reason', 'Cliente ya no requiere el servicio');
        $this->makeCurrentTenant();
        $this->assertSame(Ticket::ESTADO_CANCELLED, $ticket->fresh()->estado);
    }

    public function test_cancelar_sin_motivo_retorna_422(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/cancelar", [])
            ->assertStatus(422);
    }

    public function test_cancelar_con_motivo_solo_espacios_retorna_422(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/cancelar", ['motivo' => '   '])
            ->assertStatus(422);
    }

    public function test_cancelar_sin_gate_de_cotizacion_ni_informe(): void
    {
        // A diferencia de resolved/closed, cancelar no exige cotización/informe resueltos.
        $ticket = $this->makeTicket([
            'quote_status' => Ticket::QUOTE_STATUS_PENDING,
            'inspection_report_status' => Ticket::INSPECTION_STATUS_PENDING,
        ]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/cancelar", ['motivo' => 'Duplicado'])
            ->assertStatus(200);
    }

    public function test_cancelar_un_ticket_ya_cancelado_retorna_422(): void
    {
        $ticket = $this->makeTicket(['estado' => Ticket::ESTADO_CANCELLED]);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/cancelar", ['motivo' => 'Otra vez'])
            ->assertStatus(422);
    }

    public function test_reactivar_ticket_cancelado_cambiando_estado_manualmente(): void
    {
        $ticket = $this->makeTicket(['estado' => Ticket::ESTADO_CANCELLED, 'cancellation_reason' => 'Motivo previo']);
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/estado", ['estado' => Ticket::ESTADO_REPORTED])
            ->assertStatus(200)
            ->assertJsonPath('estado', Ticket::ESTADO_REPORTED);
    }

    public function test_tecnico_interno_no_puede_cancelar(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/cancelar", ['motivo' => 'Intento no autorizado'])
            ->assertStatus(403);
    }

    public function test_management_no_puede_cancelar(): void
    {
        $ticket = $this->makeTicket();
        $token = $this->loginAs(self::MANAGEMENT, self::MANAGEMENT, 'management');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson("/api/servicios/tickets/{$ticket->id}/cancelar", ['motivo' => 'Intento no autorizado'])
            ->assertStatus(403);
    }

    public function test_cancelar_ticket_inexistente_retorna_404(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->patchJson('/api/servicios/tickets/999999/cancelar', ['motivo' => 'x'])
            ->assertStatus(404);
    }

    // ── REQ-228 — Ver/Imprimir PDF ───────────────────────────────────────────────────────────

    public function test_pdf_se_genera_para_cualquier_rol_que_pueda_ver_el_detalle(): void
    {
        $ticket = $this->makeTicket(['descripcion' => 'Fuga en producto de techo']);
        $token = $this->loginAs(self::TECNICO_SERVICIOS, self::TECNICO_SERVICIOS, 'tecnico');

        $response = $this->withHeaders($this->authHeaders($token))
            ->get("/api/servicios/tickets/{$ticket->id}/pdf")
            ->assertStatus(200);

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
    }

    public function test_pdf_404_si_no_existe(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $this->withHeaders($this->authHeaders($token))
            ->get('/api/servicios/tickets/999999/pdf')
            ->assertStatus(404);
    }
}
