<?php

declare(strict_types=1);

namespace Tests\Feature\Legacy;

use App\Modules\Legacy\Models\Pago;
use App\Shared\Multitenancy\Tenant;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-827 — vista de solo lectura de legacy.pagos (TESORERIA). Gate `role:superadmin` exclusivo
 * (audiencia solo equipo técnico), distinto del dashboard SCRUM-816 que también admite
 * `management`.
 */
final class LegacyPagosControllerTest extends FeatureTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    private function crearPago(array $overrides = []): Pago
    {
        static $seq = 0;
        $seq++;

        return Pago::query()->create(array_merge([
            'icg_origen'        => 'C',
            'icg_tipodocumento' => 'F',
            'icg_serie'         => 'A',
            'icg_numero'        => $seq,
            'icg_n'             => '1',
            'icg_posicion'      => 1,
            'fecha_documento'   => '2024-01-15',
            'fecha_vencimiento' => '2024-02-15',
            'importe'           => 100.50,
            'icg_codformapago'  => 1,
            'estado'            => 'S',
            'fecha_saldado'     => '2024-01-20',
        ], $overrides));
    }

    public function test_management_no_puede_ver_pagos_solo_superadmin(): void
    {
        $token = $this->loginAs('gerencia@test.com', 'gerencia@test.com', 'management');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/pagos')
            ->assertStatus(403);
    }

    public function test_designer_no_puede_ver_pagos(): void
    {
        $token = $this->loginAs('designer@atlantic.test', 'Password123!', 'designer');

        $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/pagos')
            ->assertStatus(403);
    }

    public function test_superadmin_ve_el_listado_paginado(): void
    {
        $this->crearPago();
        $this->crearPago();

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/pagos')
            ->assertStatus(200)
            ->json();

        $this->assertSame(2, $response['total']);
        $this->assertArrayHasKey('icg_origen', $response['data'][0]);
        $this->assertArrayHasKey('estado', $response['data'][0]);
    }

    public function test_filtra_por_estado_origen_y_tipodocumento(): void
    {
        $this->crearPago(['estado' => 'S', 'icg_origen' => 'C', 'icg_tipodocumento' => 'F']);
        $this->crearPago(['estado' => 'P', 'icg_origen' => 'E', 'icg_tipodocumento' => 'E']);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $porEstado = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/pagos?estado=P')
            ->assertStatus(200)
            ->json();
        $this->assertSame(1, $porEstado['total']);
        $this->assertSame('P', $porEstado['data'][0]['estado']);

        $porOrigenYTipo = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/pagos?icg_origen=E&icg_tipodocumento=E')
            ->assertStatus(200)
            ->json();
        $this->assertSame(1, $porOrigenYTipo['total']);

        // Valor fuera del whitelist se ignora silenciosamente (no 422, no filtra nada).
        $invalido = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/pagos?estado=ZZZ')
            ->assertStatus(200)
            ->json();
        $this->assertSame(2, $invalido['total']);
    }

    public function test_filtra_por_rango_de_fecha_documento(): void
    {
        $this->crearPago(['fecha_documento' => '2024-01-01']);
        $this->crearPago(['fecha_documento' => '2024-06-01']);

        $token = $this->loginAs('superadmin@atlantic.test', 'Password123!', 'superadmin');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/legacy/migracion/pagos?fecha_desde=2024-03-01&fecha_hasta=2024-12-31')
            ->assertStatus(200)
            ->json();

        $this->assertSame(1, $response['total']);
        $this->assertSame('2024-06-01', $response['data'][0]['fecha_documento']);
    }
}
