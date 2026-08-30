<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\AdminCont\Models\AdminContInvoice;
use Tests\Feature\Bodega\Concerns\BodegaOrderTestHelpers;
use Tests\Feature\FeatureTestCase;

/**
 * SCRUM-401 (REQ-331) — desde el Batch 2 de Facturación (SCRUM-513→518) `invoice_ready` refleja
 * la existencia real de un `AdminContInvoice`, no un stub independiente — ver docblock de
 * `OrderInvoiceController`.
 */
class OrderInvoiceControllerTest extends FeatureTestCase
{
    use BodegaOrderTestHelpers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->makeCurrentTenant();
    }

    private function authHeadersForBodegaReader(): array
    {
        $this->grantBodegaPermissions('almacen@illuminations.com.pa', ['bodega.read']);
        $token = $this->loginAs('almacen@illuminations.com.pa', 'almacen@illuminations.com.pa', 'lider_bodega');

        return $this->authHeaders($token);
    }

    public function test_invoice_ready_false_muestra_mensaje_de_espera(): void
    {
        $order = $this->makeOrder(['invoice_ready' => false]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/{$order->id}/invoice")
            ->assertStatus(200);

        $this->assertFalse($response->json('invoice_ready'));
        $this->assertSame('Esperando a Administración.', $response->json('status_message'));
        // RN — nunca inventa precios: ninguno de estos campos existe en la respuesta.
        $this->assertArrayNotHasKey('subtotal', $response->json());
        $this->assertArrayNotHasKey('itbms', $response->json());
        $this->assertArrayNotHasKey('total', $response->json());
    }

    public function test_invoice_ready_true_muestra_numero_de_factura_real(): void
    {
        $order = $this->makeOrder(['invoice_ready' => true]);
        AdminContInvoice::create([
            'numero' => 'F-0001', 'order_id' => $order->id, 'cliente' => 'Cliente de prueba', 'monto' => 500,
        ]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/{$order->id}/invoice")
            ->assertStatus(200);

        $this->assertTrue($response->json('invoice_ready'));
        $this->assertSame('F-0001', $response->json('invoice_numero'));
        $this->assertStringContainsString('Factura F-0001 generada', $response->json('status_message'));
    }

    public function test_invoice_de_pedido_inexistente_retorna_404(): void
    {
        $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson('/api/bodega/orders/999999/invoice')
            ->assertStatus(404);
    }
}
