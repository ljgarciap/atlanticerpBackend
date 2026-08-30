<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-500 — umbrales configurables del Home de Bodega (ver `BodegaSettingsService`). Calcado
 * de `Tests\Feature\Compras\ComprasSettingsControllerTest`; la diferencia real de implementación
 * (key/value vs. fila única de columnas fijas) es interna al servicio, el contrato de API es
 * análogo.
 */
class BodegaSettingsControllerTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    public function test_show_devuelve_los_valores_por_defecto(): void
    {
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', ['bodega.read']);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/settings')
            ->assertStatus(200);

        $response->assertJsonPath('urgente_ventana_dias', 1);
        $response->assertJsonPath('pendientes_picking_horas', 3);
        $response->assertJsonPath('pendientes_guia_dias', 2);
        $response->assertJsonPath('rotacion_ventana_dias', 30);
        $response->assertJsonPath('rotacion_top_n', 5);
        $response->assertJsonPath('capacidad_concentracion_pct', 75);
    }

    public function test_update_cambia_el_umbral_de_concentracion_de_capacidad(): void
    {
        // SCRUM-493 (REQ-423 RN1) — mismo umbral que consume el reporte "Capacidad por bodega",
        // ver `BodegaReportsControllerTest::test_capacidad_por_bodega_umbral_configurable`.
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', [
            'bodega.read', 'bodega.settings.configure',
        ]);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/bodega/settings', ['capacidad_concentracion_pct' => 60])
            ->assertStatus(200)
            ->assertJsonPath('capacidad_concentracion_pct', 60);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/settings')
            ->assertStatus(200);
        $response->assertJsonPath('capacidad_concentracion_pct', 60);
        $response->assertJsonPath('rotacion_top_n', 5);
    }

    public function test_update_concentracion_por_encima_del_maximo_retorna_422(): void
    {
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', [
            'bodega.read', 'bodega.settings.configure',
        ]);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/bodega/settings', ['capacidad_concentracion_pct' => 101])
            ->assertStatus(422);
    }

    public function test_update_cambia_la_ventana_de_urgente_y_persiste(): void
    {
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', [
            'bodega.read', 'bodega.settings.configure',
        ]);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/bodega/settings', ['urgente_ventana_dias' => 2])
            ->assertStatus(200)
            ->assertJsonPath('urgente_ventana_dias', 2);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/settings')
            ->assertStatus(200);
        $response->assertJsonPath('urgente_ventana_dias', 2);
        // Los otros 4 valores no se tocan por un update parcial (reglas `sometimes`).
        $response->assertJsonPath('rotacion_top_n', 5);
    }

    public function test_update_cambia_los_umbrales_de_pendientes_y_rotacion(): void
    {
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', [
            'bodega.read', 'bodega.settings.configure',
        ]);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/bodega/settings', [
                'pendientes_picking_horas' => 6,
                'pendientes_guia_dias'     => 4,
                'rotacion_ventana_dias'    => 60,
                'rotacion_top_n'           => 10,
            ])
            ->assertStatus(200)
            ->assertJsonPath('pendientes_picking_horas', 6)
            ->assertJsonPath('pendientes_guia_dias', 4)
            ->assertJsonPath('rotacion_ventana_dias', 60)
            ->assertJsonPath('rotacion_top_n', 10);

        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/settings')
            ->assertStatus(200);
        $response->assertJsonPath('pendientes_picking_horas', 6);
        $response->assertJsonPath('pendientes_guia_dias', 4);
        $response->assertJsonPath('rotacion_ventana_dias', 60);
        $response->assertJsonPath('rotacion_top_n', 10);
    }

    public function test_update_sin_permiso_configure_retorna_403(): void
    {
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', ['bodega.read']);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/bodega/settings', ['urgente_ventana_dias' => 5])
            ->assertStatus(403);
    }

    public function test_update_con_valor_cero_retorna_422(): void
    {
        // Regla dura del ticket: `intSetting()` descarta silenciosamente cualquier valor
        // persistido <= 0 y cae al default — 0 debe rechazarse acá, no devolver 200 confuso.
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', [
            'bodega.read', 'bodega.settings.configure',
        ]);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/bodega/settings', ['urgente_ventana_dias' => 0])
            ->assertStatus(422);

        // El valor no debe haber cambiado.
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/bodega/settings')
            ->assertStatus(200);
        $response->assertJsonPath('urgente_ventana_dias', 1);
    }

    public function test_update_con_valor_negativo_retorna_422(): void
    {
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', [
            'bodega.read', 'bodega.settings.configure',
        ]);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/bodega/settings', ['rotacion_top_n' => -1])
            ->assertStatus(422);
    }

    public function test_update_por_encima_del_maximo_retorna_422(): void
    {
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', [
            'bodega.read', 'bodega.settings.configure',
        ]);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        $this->withHeaders($this->authHeaders($token))
            ->putJson('/api/bodega/settings', ['pendientes_picking_horas' => 200])
            ->assertStatus(422);
    }
}
