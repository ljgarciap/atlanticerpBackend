<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-721 — GET /servicios/technicians/internal. Los 4 usuarios reales (`tecnico_servicios`
 * x3 + `garantias_servicios` x1) ya vienen sembrados por `BusinessRoleUserSeeder` en todos los
 * entornos incl. testing (ver docblock de `TicketFlowTest`).
 */
class TechnicianControllerTest extends FeatureTestCase
{
    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    private const VENDEDOR_DISENADOR = 'vendedordisenador@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_devuelve_los_4_tecnicos_internos_reales(): void
    {
        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/technicians/internal?fields=options')
            ->assertStatus(200);

        $names = collect($response->json('data'))
            ->map(fn (array $t) => "{$t['first_name']} {$t['last_name']}")
            ->all();

        $this->assertContains('Tecnico Servicios Test', $names);
        $this->assertContains('Tecnico Servicios Test 2', $names);
        $this->assertContains('Tecnico Servicios Test 3', $names);
        $this->assertContains('Garantias Servicios Test', $names);
        $this->assertCount(4, $names);
    }

    public function test_vendedor_disenador_con_servicios_read_puede_ver_tecnicos(): void
    {
        $token = $this->loginAs(self::VENDEDOR_DISENADOR, self::VENDEDOR_DISENADOR, 'vendedor_disenador');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/technicians/internal?fields=options')
            ->assertStatus(200);
    }

    public function test_requiere_autenticacion(): void
    {
        $this->getJson('/api/servicios/technicians/internal?fields=options')
            ->assertStatus(401);
    }
}
