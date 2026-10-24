<?php

declare(strict_types=1);

namespace Tests\Feature\Bodega;

use App\Modules\AdminCont\Models\AdminContInvoice;
use App\Modules\Bodega\Models\Order;
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
        $this->grantBodegaPermissions('liderbodega@test.com', ['bodega.read']);
        $token = $this->loginAs('liderbodega@test.com', 'liderbodega@test.com', 'lider_bodega');

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

    /** SCRUM-832 — el bug real: `show()` solo exponía `status_message`, nunca el detalle fiscal
     *  completo que `InvoiceService::detail()` ya arma desde el Batch 2 de Facturación. */
    public function test_invoice_ready_true_expone_el_detalle_fiscal_completo_no_solo_el_mensaje(): void
    {
        $order = $this->makeOrder(['invoice_ready' => true]);
        AdminContInvoice::create([
            'numero' => 'F-0002', 'order_id' => $order->id, 'cliente' => 'Cliente de prueba', 'monto' => 500,
        ]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/{$order->id}/invoice")
            ->assertStatus(200);

        // Mismas claves que arma InvoiceService::detail() para el modal de Admin&Cont — antes de
        // este fix, ninguna de estas existía en la respuesta de Bodega.
        $this->assertSame('facturada', $response->json('estado'));
        $this->assertArrayHasKey('cuenta_pago', $response->json());
        $this->assertArrayHasKey('responsable', $response->json());
        $this->assertArrayHasKey('total_a_pagar', $response->json());
    }

    /** SCRUM-832 — decisión de diseño propia (Senior Review, por favor confirmar): en vez de que
     *  el frontend de Bodega llame directo a las rutas HTTP de `admin-contab/invoices/*`
     *  (gateadas por `role:superadmin,lider_admin_contab,asistente_administrativa,management`),
     *  `OrderInvoiceController` llama a `InvoiceService`/`InvoicePdfService` directo — el único
     *  gate de autorización sigue siendo `permission:bodega.read`. Este test prueba exactamente
     *  eso: un `lider_bodega` sin ningún rol de Admin&Cont puede ver el PDF de la factura de SU
     *  propio pedido.
     */
    public function test_pdf_devuelve_documento_fiscal_para_un_lider_bodega_sin_rol_de_admin_cont(): void
    {
        $order = $this->makeOrder(['invoice_ready' => true]);
        AdminContInvoice::create([
            'numero' => 'F-0003', 'order_id' => $order->id, 'cliente' => 'Cliente de prueba', 'monto' => 500,
        ]);

        $response = $this->withHeaders($this->authHeadersForBodegaReader())
            ->get("/api/bodega/orders/{$order->id}/invoice/pdf")
            ->assertStatus(200);

        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));
    }

    public function test_876_entrega_hermana_de_un_pedido_facturado_muestra_la_misma_factura(): void
    {
        $root = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR, 'sequence_in_family' => 1, 'total_in_family' => 3]);
        $child2 = $this->makeOrder([
            'order_number' => $root->order_number, 'stage' => Order::STAGE_POR_DESPACHAR,
            'family_id' => $root->id, 'sequence_in_family' => 2, 'total_in_family' => 3,
        ]);
        $child3 = $this->makeOrder([
            'order_number' => $root->order_number, 'stage' => Order::STAGE_EN_PICKING,
            'family_id' => $root->id, 'sequence_in_family' => 3, 'total_in_family' => 3,
        ]);

        // La única factura del pedido vive en la tarjeta raíz.
        AdminContInvoice::create([
            'numero' => 'F-0009', 'order_id' => $root->id, 'cliente' => 'Cliente de prueba', 'monto' => 500,
        ]);

        // Cualquier tarjeta de la familia (incluida la que ni siquiera está en Por Despachar)
        // muestra la MISMA factura.
        foreach ([$root, $child2, $child3] as $tarjeta) {
            $this->withHeaders($this->authHeadersForBodegaReader())
                ->getJson("/api/bodega/orders/{$tarjeta->id}/invoice")
                ->assertStatus(200)
                ->assertJsonPath('invoice_ready', true)
                ->assertJsonPath('invoice_numero', 'F-0009');
        }
    }

    public function test_876_entrega_hermana_sin_factura_del_pedido_sigue_esperando(): void
    {
        $root = $this->makeOrder(['stage' => Order::STAGE_POR_DESPACHAR]);
        $child = $this->makeOrder([
            'order_number' => $root->order_number, 'stage' => Order::STAGE_POR_DESPACHAR, 'family_id' => $root->id,
        ]);

        $this->withHeaders($this->authHeadersForBodegaReader())
            ->getJson("/api/bodega/orders/{$child->id}/invoice")
            ->assertStatus(200)
            ->assertJsonPath('invoice_ready', false)
            ->assertJsonPath('status_message', 'Esperando a Administración.');
    }
}
