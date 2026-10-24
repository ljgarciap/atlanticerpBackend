<?php

declare(strict_types=1);

namespace Tests\Feature\Servicios;

use App\Shared\Multitenancy\Tenant;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\Concerns\DemoModeTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * docs/specs/demo-mode.md — ProductLookupController es el buscador COMPARTIDO por Cotización
 * (ítem Producto, Batch 11) y Servicios (Productos reclamados/afectados, REQ-247) — un solo
 * endpoint cubre ambas pantallas, ver docblock de la clase.
 */
class ProductLookupDemoModeTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;
    use DemoModeTestHelpers;

    private const LIDER_SERVICIOS = 'liderservicios@test.com';

    protected function setUp(): void
    {
        parent::setUp();
        Tenant::where('slug', 'atlantic')->first()?->makeCurrent();
    }

    public function test_gate_global_apagado_muestra_catalogo_completo_aunque_preferencia_personal_este_activa(): void
    {
        $this->setUserDemoModePreference(self::LIDER_SERVICIOS, true);
        $this->makeCatalogProduct(['reference' => 'SIN-FOTO', 'photo_url' => null]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/lookup/products')
            ->assertStatus(200);

        $this->assertContains('SIN-FOTO', collect($response->json('data'))->pluck('reference'));
    }

    public function test_gate_global_activo_pero_preferencia_personal_apagada_muestra_catalogo_completo(): void
    {
        $this->enableDemoModeGlobally();
        $this->setUserDemoModePreference(self::LIDER_SERVICIOS, false);
        $this->makeCatalogProduct(['reference' => 'SIN-FOTO', 'photo_url' => null]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/lookup/products')
            ->assertStatus(200);

        $this->assertContains('SIN-FOTO', collect($response->json('data'))->pluck('reference'));
    }

    public function test_ambos_gates_activos_excluye_producto_sin_imagen(): void
    {
        $this->enableDemoModeGlobally();
        $this->setUserDemoModePreference(self::LIDER_SERVICIOS, true);
        $this->makeCatalogProduct(['reference' => 'SIN-FOTO', 'photo_url' => null, 'photo_key' => null]);
        $this->makeCatalogProduct(['reference' => 'CON-FOTO', 'photo_url' => 'https://cdn.test/foto.jpg']);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/lookup/products')
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference');
        $this->assertNotContains('SIN-FOTO', $refs);
        $this->assertContains('CON-FOTO', $refs);
    }

    public function test_ambos_gates_activos_excluye_producto_con_precio_cero(): void
    {
        $this->enableDemoModeGlobally();
        $this->setUserDemoModePreference(self::LIDER_SERVICIOS, true);
        $this->makeCatalogProduct(['reference' => 'SIN-PRECIO', 'photo_url' => 'https://cdn.test/foto.jpg', 'price_full' => 0]);
        $this->makeCatalogProduct(['reference' => 'CON-PRECIO', 'photo_url' => 'https://cdn.test/foto.jpg', 'price_full' => 150]);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/lookup/products')
            ->assertStatus(200);

        $refs = collect($response->json('data'))->pluck('reference');
        $this->assertNotContains('SIN-PRECIO', $refs);
        $this->assertContains('CON-PRECIO', $refs);
    }

    public function test_ambos_gates_activos_incluye_producto_con_photo_key_pero_photo_url_vacio(): void
    {
        $this->enableDemoModeGlobally();
        $this->setUserDemoModePreference(self::LIDER_SERVICIOS, true);
        $this->makeCatalogProduct(['reference' => 'FOTO-NUEVA', 'photo_url' => null, 'photo_key' => 'ventas_diseno/foo.jpg']);

        $token = $this->loginAs(self::LIDER_SERVICIOS, self::LIDER_SERVICIOS, 'lider_servicios');
        $response = $this->withHeaders($this->authHeaders($token))
            ->getJson('/api/servicios/lookup/products')
            ->assertStatus(200);

        $this->assertContains('FOTO-NUEVA', collect($response->json('data'))->pluck('reference'));
    }
}
